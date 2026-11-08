<?php

namespace App;

use App\Models\NotificationDelivery;
use App\Models\Order;
use App\Models\OrderStatus;
use App\Models\User;
use App\Models\UserRole;
use Illuminate\Support\Facades\DB;
use LogicException;

class PaymentNotices
{
    public function __construct(private NotificationRecipientIdentity $identities) {}

    public function paid(Order $order): void
    {
        $this->write($order, 'order:'.$order->id.':paid', 'customer.payment_paid.v1', $order->email_snapshot, $order->whatsapp_snapshot);
    }

    public function completed(Order $order): void
    {
        $this->write($order, 'order:'.$order->id.':completed', 'customer.order_completed.v1', $order->email_snapshot, $order->whatsapp_snapshot);
    }

    public function review(Order $order, PaymentReviewReason $reason): void
    {
        $this->write($order, 'order:'.$order->id.':payment_exception:'.$reason->value, 'customer.payment_exception.v1', $order->email_snapshot, $order->whatsapp_snapshot, $reason);
        foreach (User::query()->where('is_active', true)->whereIn('role', [UserRole::Admin, UserRole::Owner])->orderBy('id')->get() as $staff) {
            $this->write($order, 'order:'.$order->id.':payment_exception:'.$reason->value, 'admin.payment_exception.v1', $staff->email, $staff->whatsapp_number, $reason);
        }
    }

    public function refundPending(Order $order): void
    {
        $eventKey = 'order:'.$order->id.':refund_pending';
        $this->write($order, $eventKey, 'customer.refund_pending.v1', $order->email_snapshot, $order->whatsapp_snapshot);
        foreach (User::query()->where('is_active', true)->whereIn('role', [UserRole::Admin, UserRole::Owner])->orderBy('id')->get() as $staff) {
            $this->write($order, $eventKey, 'admin.refund.v1', $staff->email, $staff->whatsapp_number);
        }
    }

    public function paidCancelled(Order $order): void
    {
        $key = 'order:'.$order->id.':paid_cancelled';
        $this->write($order, $key, 'customer.order_cancelled.v1', $order->email_snapshot, $order->whatsapp_snapshot);
        foreach (User::query()->where('is_active', true)->whereIn('role', [UserRole::Admin, UserRole::Owner])->orderBy('id')->get() as $staff) {
            $this->write($order, $key, 'admin.cancellation.v1', $staff->email, $staff->whatsapp_number);
        }
    }

    public function unpaidClosed(Order $order): void
    {
        $cancelled = $order->status === OrderStatus::Cancelled;
        $eventKey = 'order:'.$order->id.($cancelled ? ':unpaid_cancelled' : ':expired');
        $this->write($order, $eventKey, $cancelled ? 'customer.order_cancelled.v1' : 'customer.order_expired.v1', $order->email_snapshot, $order->whatsapp_snapshot);
        if ($cancelled) {
            foreach (User::query()->where('is_active', true)->whereIn('role', [UserRole::Admin, UserRole::Owner])->orderBy('id')->get() as $staff) {
                $this->write($order, $eventKey, 'admin.cancellation.v1', $staff->email, $staff->whatsapp_number);
            }
        }
    }

    public function refundRecorded(Order $order, int $transferId, bool $completed): void
    {
        $key = 'refund-transfer:'.$transferId.':succeeded';
        $this->write($order, $key, $completed ? 'customer.refund_completed.v1' : 'customer.refund_transfer_recorded.v1', $order->email_snapshot, $order->whatsapp_snapshot);
        foreach (User::query()->where('is_active', true)->whereIn('role', [UserRole::Admin, UserRole::Owner])->orderBy('id')->get() as $staff) {
            $this->write($order, $key, 'admin.refund.v1', $staff->email, $staff->whatsapp_number);
        }
    }

    public function reconciliationRequired(Order $order): void
    {
        foreach (User::query()->where('is_active', true)->whereIn('role', [UserRole::Admin, UserRole::Owner])->orderBy('id')->get() as $staff) {
            $this->write($order, 'order:'.$order->id.':reconciliation_exhausted', 'admin.payment_exception.v1', $staff->email, $staff->whatsapp_number, PaymentReviewReason::ReconciliationExhausted);
        }
    }

    private function write(Order $order, string $eventKey, string $template, string $email, string $whatsapp, ?PaymentReviewReason $reason = null): void
    {
        if (DB::connection()->getDriverName() !== 'mysql' || DB::transactionLevel() === 0) {
            throw new LogicException('Payment outbox requires a MySQL parent lock transaction.');
        }
        foreach ([NotificationChannel::Email, NotificationChannel::WhatsApp] as $channel) {
            $address = $channel === NotificationChannel::Email ? $email : $whatsapp;
            $keys = $this->identities->candidateKeys($channel, $address);
            if (NotificationDelivery::query()->where('event_key', $eventKey)->where('template_key', $template)->whereIn('recipient_key', $keys)->exists()) {
                continue;
            }
            $delivery = new NotificationDelivery;
            $delivery->forceFill(['event_key' => $eventKey, 'template_key' => $template,
                'recipient_key' => $keys[0], 'recipient_ciphertext' => ['channel' => $channel->value, 'address' => $address],
                'entity_type' => 'order', 'entity_id' => $order->id,
                'payload_minimal' => ['order_number' => $order->order_number, 'status' => $order->status->value, 'reason' => $reason?->value],
                'status' => 'pending'])->save();
            NotificationOutbox::enqueue($delivery);
        }
    }
}
