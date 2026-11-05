<?php

namespace App;

use App\Models\AuditLog;
use App\Models\Order;
use App\Models\OrderStatus;
use App\Models\Payment;
use App\Models\PaymentStatus;
use App\Models\User;
use App\Models\UserRole;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;

class OpenSnapSession
{
    public function __construct(private MidtransSnapSession $sessions, private MidtransBankTransferStatus $statuses) {}

    public function open(User $actor, Order $requestedOrder): ?string
    {
        $connection = DB::connection();
        if ($connection->getDriverName() !== 'mysql' || $connection->transactionLevel() !== 0) {
            throw new LogicException('Snap sessions require MySQL and no caller transaction.');
        }
        $settings = $connection->selectOne('SELECT @@transaction_isolation AS isolation_level, @@session.time_zone AS time_zone');
        if ($settings->isolation_level !== 'READ-COMMITTED' || $settings->time_zone !== '+00:00') {
            throw new LogicException('Snap sessions require READ COMMITTED and UTC.');
        }
        $claim = $connection->transaction(function () use ($actor, $requestedOrder): ?array {
            [$order, $payment] = $this->lockOwnedPayment($actor, $requestedOrder->id);
            if (! $this->eligible($order, $payment) || ! $this->sessions->configured()) {
                return null;
            }
            if ($payment->getRawOriginal('snap_token') !== null) {
                return ['action' => 'reuse', 'order' => $order, 'payment' => $payment];
            }
            if ($payment->snap_requested_at !== null || $payment->provider_transaction_id !== null) {
                return ['action' => 'recover', 'order' => $order, 'payment' => $payment];
            }
            if (! $this->beforeDeadline($order)) {
                return null;
            }
            $payment->forceFill(['snap_requested_at' => CarbonImmutable::now('UTC')->startOfSecond(), 'review_reason' => 'snap_creation_unknown'])->save();
            $this->audit($actor, $payment, 'payment.snap_requested', 'reserved');

            return ['action' => 'create', 'order' => $order, 'payment' => $payment];
        }, 3);
        if ($claim === null) {
            return null;
        }
        if ($claim['action'] === 'reuse') {
            return $this->usableUrl($claim['order'], $claim['payment']);
        }
        if ($claim['action'] === 'recover') {
            $this->recover($actor, $claim['order'], $claim['payment']);

            return null;
        }
        $order = $claim['order'];
        $payment = $claim['payment'];
        $token = $this->sessions->create($payment->merchant_reference, $payment->expected_amount,
            CarbonImmutable::parse($order->getRawOriginal('created_at'), 'UTC'), $order->payment_expires_at, route('orders.show', $order));

        return $connection->transaction(function () use ($actor, $order, $payment, $token): ?string {
            [$currentOrder, $current] = $this->lockOwnedPayment($actor, $order->id);
            if (! $this->sameContext($payment, $current) || $current->snap_requested_at === null) {
                return null;
            }
            if ($current->getRawOriginal('snap_token') === null && $token !== null) {
                $current->forceFill(['snap_token' => $token]);
                if ($current->review_reason === 'snap_creation_unknown') {
                    $current->review_reason = null;
                }
                $current->save();
            }
            $this->audit($actor, $current, 'payment.snap_response', $token === null ? 'unknown' : 'token_received');

            return $this->usableUrl($currentOrder, $current);
        }, 3);
    }

    /** @return array{Order, Payment} */
    private function lockOwnedPayment(User $actor, int $orderId): array
    {
        $customer = User::query()->whereKey($actor->id)->lockForUpdate()->first();
        $order = Order::query()->whereKey($orderId)->lockForUpdate()->firstOrFail();
        abort_unless($customer && $customer->is_active && $customer->role === UserRole::Customer && $customer->id === $order->user_id, 404);

        return [$order, Payment::query()->where('order_id', $order->id)->lockForUpdate()->firstOrFail()];
    }

    private function eligible(Order $order, Payment $payment): bool
    {
        return $order->status === OrderStatus::Pending && $payment->status === PaymentStatus::Pending
            && $payment->provider === 'midtrans' && $payment->currency === 'IDR'
            && $payment->expected_amount === $order->product_total && $payment->expected_amount > 0
            && preg_match('/\A[A-Za-z0-9][A-Za-z0-9_-]{0,49}\z/', $payment->merchant_reference) === 1
            && ($payment->provider_transaction_id === null || Str::isUuid($payment->provider_transaction_id))
            && $payment->closure_result === null
            && in_array($payment->review_reason, [null, 'snap_creation_unknown'], true);
    }

    private function beforeDeadline(Order $order): bool
    {
        $instant = CarbonImmutable::now('UTC');

        return $instant >= $order->created_at && $instant < $order->payment_expires_at
            && $order->payment_expires_at->getTimestamp() - $order->created_at->getTimestamp() === 1800;
    }

    private function usableUrl(Order $order, Payment $payment): ?string
    {
        if (! $this->eligible($order, $payment) || ! $this->beforeDeadline($order) || $payment->review_reason !== null) {
            return null;
        }
        try {
            $token = $payment->snap_token;
        } catch (DecryptException) {
            return null;
        }

        return is_string($token) ? $this->sessions->urlForToken($token) : null;
    }

    private function sameContext(Payment $claimed, Payment $current): bool
    {
        return $claimed->id === $current->id && $claimed->merchant_reference === $current->merchant_reference
            && $claimed->expected_amount === $current->expected_amount && $claimed->currency === $current->currency;
    }

    private function recover(User $actor, Order $order, Payment $payment): void
    {
        $observation = $this->statuses->observe($payment->merchant_reference, $payment->expected_amount, $order->payment_expires_at, $payment->provider_transaction_id);
        DB::transaction(function () use ($actor, $order, $payment, $observation): void {
            [$currentOrder, $current] = $this->lockOwnedPayment($actor, $order->id);
            if ($current->getRawOriginal('snap_token') !== null
                || ! $this->sameContext($payment, $current) || ! $this->eligible($currentOrder, $current)) {
                return;
            }
            $reason = match ($observation->outcome) {
                BankTransferOutcome::SuccessObserved => 'payment_time_unverified',
                BankTransferOutcome::ExpiredObserved => 'closure_verification_required',
                BankTransferOutcome::Conflict => 'provider_context_conflict',
                default => 'snap_creation_unknown',
            };
            $current->forceFill(['review_reason' => $reason])->save();
            $this->audit($actor, $current, 'payment.snap_recovery', $observation->outcome->value);
        }, 3);
    }

    private function audit(User $actor, Payment $payment, string $action, string $state): void
    {
        $audit = new AuditLog;
        $audit->forceFill(['actor_user_id' => $actor->id, 'actor_type' => 'user', 'entity_type' => 'payment', 'entity_id' => $payment->id,
            'action' => $action, 'after_redacted' => ['state' => $state], 'correlation_id' => (string) Str::uuid(),
            'created_at' => CarbonImmutable::now('UTC')->startOfSecond()])->save();
    }
}
