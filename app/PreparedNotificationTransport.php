<?php

namespace App;

use App\Models\CustomCakeRequest;
use App\Models\NotificationDelivery;
use App\Models\NotificationDeliveryStatus;
use App\Models\Order;
use App\Models\OrderRequest;
use App\Models\OrderRequestStatus;
use App\Models\OrderStatus;
use App\Models\RefundLog;
use App\Models\RefundLogStatus;
use App\Models\User;
use App\Models\UserRole;
use Illuminate\Support\Facades\DB;
use Throwable;

class PreparedNotificationTransport implements NotificationTransport
{
    public function __construct(private NotificationMessage $messages, private NotificationRecipientIdentity $identities,
        private FonnteClient $whatsapp, private EmailNotificationClient $email) {}

    public function send(NotificationDelivery $delivery): NotificationSendResult
    {
        if (config('notifications.enabled') !== true || DB::transactionLevel() !== 0) {
            return new NotificationSendResult(NotificationDeliveryStatus::Failed);
        }
        try {
            $recipient = $delivery->recipient_ciphertext;
            $channel = is_array($recipient) && is_string($recipient['channel'] ?? null) ? NotificationChannel::tryFrom($recipient['channel']) : null;
            $address = is_array($recipient) ? ($recipient['address'] ?? null) : null;
            $payload = is_array($delivery->payload_minimal) ? $delivery->payload_minimal : null;
            $message = $payload !== null ? $this->messages->render($delivery->template_key, $payload) : null;
            if ($channel === null || ! is_string($address) || $address === '' || $message === null
                || ! in_array($delivery->recipient_key, $this->identities->candidateKeys($channel, $address), true)
                || $this->shouldSuppress($delivery, $channel, $address, $payload)) {
                return new NotificationSendResult(NotificationDeliveryStatus::Suppressed);
            }

            return $channel === NotificationChannel::WhatsApp ? $this->whatsapp->send($address, $message) : $this->email->send($address, $message);
        } catch (Throwable) {
            return new NotificationSendResult(NotificationDeliveryStatus::Unknown);
        }
    }

    /** @param array<string, mixed> $payload */
    private function shouldSuppress(NotificationDelivery $delivery, NotificationChannel $channel, string $address, array $payload): bool
    {
        if ($delivery->entity_type === 'user') {
            return false;
        }
        if (str_starts_with($delivery->template_key, 'admin.')) {
            $column = $channel === NotificationChannel::Email ? 'email' : 'whatsapp_number';
            if (! User::query()->where('is_active', true)->whereIn('role', [UserRole::Admin, UserRole::Owner])->where($column, $address)->exists()) {
                return true;
            }
        }
        if ($delivery->entity_type === 'custom_request') {
            $custom = CustomCakeRequest::query()->with('user')->find($delivery->entity_id);
            if ($custom === null || ($payload['request_number'] ?? null) !== $custom->request_number) {
                return true;
            }
            if (str_starts_with($delivery->template_key, 'customer.')) {
                $owner = $custom->user;
                $expected = $channel === NotificationChannel::Email ? $owner?->email : $owner?->whatsapp_number;
                if ($owner === null || ! $owner->is_active || $owner->role !== UserRole::Customer || ! is_string($expected)
                    || ! in_array($delivery->recipient_key, $this->identities->candidateKeys($channel, $expected), true)) {
                    return true;
                }
            }

            return false;
        }
        $request = $delivery->entity_type === 'order_request' ? OrderRequest::query()->with(['order.payment', 'order.refundLog'])->find($delivery->entity_id) : null;
        $refund = $delivery->entity_type === 'refund_log' ? RefundLog::query()->with(['order.payment', 'order.refundLog'])->find($delivery->entity_id) : null;
        $order = match ($delivery->entity_type) {
            'order' => Order::query()->with(['payment', 'refundLog'])->find($delivery->entity_id),
            'order_request' => $request?->order,
            'refund_log' => $refund?->order,
            default => null,
        };
        if ($order === null || ($payload['order_number'] ?? null) !== $order->order_number) {
            return true;
        }
        if (str_starts_with($delivery->template_key, 'customer.')) {
            $expected = $channel === NotificationChannel::Email ? $order->email_snapshot : $order->whatsapp_snapshot;
            if (! in_array($delivery->recipient_key, $this->identities->candidateKeys($channel, $expected), true)) {
                return true;
            }
        }

        return match ($delivery->template_key) {
            'customer.order_created.v1' => $order->status !== OrderStatus::Pending,
            'customer.payment_exception.v1', 'admin.payment_exception.v1' => $order->payment?->review_reason === null
                && in_array($order->status, [OrderStatus::Paid, OrderStatus::Completed, OrderStatus::Expired, OrderStatus::Cancelled], true),
            'customer.cancellation_submitted.v1', 'customer.order_change_submitted.v1' => $request === null || $request->status !== OrderRequestStatus::Pending,
            'customer.refund_pending.v1' => $order->refundLog?->status === RefundLogStatus::Completed,
            default => false,
        };
    }
}
