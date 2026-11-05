<?php

namespace App;

use App\Models\AuditLog;
use App\Models\Order;
use App\Models\OrderStatus;
use App\Models\Payment;
use App\Models\PaymentEvent;
use App\Models\PaymentEventStatus;
use App\Models\RefundLog;
use App\Models\User;
use App\Models\UserRole;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;
use InvalidArgumentException;
use LogicException;

class ReconcilePayments
{
    public const MAX_CYCLES = 5;

    public const SOURCE = 'application_reconciliation';

    private const BACKOFF_SECONDS = [30, 120, 600, 1800, 1800];

    public function __construct(private CloseUnpaidPayment $closure, private PaymentNotices $notices, private ProcessPaymentEvent $consumer) {}

    public function dispatch(int $limit = 100): int
    {
        if ($limit < 1 || $limit > 100) {
            throw new InvalidArgumentException('Reconciliation limit must be between 1 and 100.');
        }
        $this->assertDatabaseReady();
        $now = CarbonImmutable::now('UTC')->startOfSecond();
        $ids = Payment::query()->where('provider', 'midtrans')
            ->where(fn ($query) => $query->whereNotNull('review_reason')->orWhereNotIn('id', $this->resolvedPaidCancellations()->select('payment_id')))
            ->where(fn ($query) => $query->whereNull('closure_result')->orWhereNotNull('snap_requested_at')->orWhereNotNull('review_reason'))
            ->where(fn ($query) => $query->where('reconciliation_attempts', 0)->orWhereNotNull('next_reconcile_at'))
            ->where(fn ($query) => $query->whereNull('next_reconcile_at')->orWhere('next_reconcile_at', '<=', $now))
            ->where(fn ($query) => $query->whereNotNull('review_reason')->orWhereHas('order', fn ($orders) => $orders->whereIn('status', ['pending', 'expired', 'cancelled'])))
            ->where(fn ($query) => $query->whereNotNull('snap_requested_at')->orWhereNotNull('review_reason')
                ->orWhereHas('order', fn ($orders) => $orders->where('payment_expires_at', '<=', $now)
                    ->orWhereHas('requests', fn ($requests) => $requests->where('type', 'cancellation')->where('decision_mode', 'automatic')->where('status', 'pending'))))
            ->whereNotExists(fn ($events) => $events->selectRaw('1')->from('payment_events')
                ->whereIn('payment_events.processing_status', ['pending', 'processing'])
                ->where(fn ($identity) => $identity->whereColumn('payment_events.payment_id', 'payments.id')
                    ->orWhere(fn ($reference) => $reference->whereColumn('payment_events.provider', 'payments.provider')
                        ->whereColumn('payment_events.provider_reference', 'payments.merchant_reference'))))
            ->orderBy('id')->limit($limit)->pluck('id');
        $queued = 0;
        foreach ($ids as $id) {
            $event = $this->prepare($id);
            if ($event !== null) {
                ProcessPaymentEventJob::dispatch($event->id);
                $queued++;
            }
        }

        return $queued;
    }

    /** Durable kickoff authority is internal; only the consumer's authenticated GET establishes provider facts. */
    public function prepare(int $paymentId): ?PaymentEvent
    {
        $this->assertDatabaseReady();
        if ($this->closure->closeNeverRequested($paymentId)) {
            return null;
        }
        $snapshot = Payment::query()->with('order')->findOrFail($paymentId);

        return $this->transaction(function () use ($snapshot): ?PaymentEvent {
            User::query()->whereKey($snapshot->order->user_id)->lockForUpdate()->firstOrFail();
            $order = Order::query()->whereKey($snapshot->order_id)->lockForUpdate()->firstOrFail();
            $payment = Payment::query()->whereKey($snapshot->id)->lockForUpdate()->firstOrFail();
            $now = CarbonImmutable::now('UTC')->startOfSecond();
            if ($payment->order_id !== $order->id || $order->user_id !== $snapshot->order->user_id
                || $payment->provider !== 'midtrans' || $payment->merchant_reference !== $snapshot->merchant_reference
                || ($payment->next_reconcile_at !== null && $payment->next_reconcile_at > $now)
                || ($payment->reconciliation_attempts > 0 && $payment->next_reconcile_at === null)
                || (in_array($order->status, [OrderStatus::Paid, OrderStatus::Completed], true) && $payment->review_reason === null)
                || ($payment->review_reason === null && $this->resolvedPaidCancellations()->where('payment_id', $payment->id)->exists())
                || ($payment->snap_requested_at === null && $payment->review_reason === null && $order->payment_expires_at > $now)) {
                return null;
            }
            if (PaymentEvent::query()->whereIn('processing_status', ['pending', 'processing'])
                ->where(fn ($query) => $query->where('payment_id', $payment->id)
                    ->orWhere(fn ($reference) => $reference->where('provider', $payment->provider)->where('provider_reference', $payment->merchant_reference)))->exists()) {
                return null;
            }
            $last = PaymentEvent::query()->where('payment_id', $payment->id)->where('payload_redacted->verification_source', self::SOURCE)->latest('id')->first();
            if ($payment->reconciliation_attempts >= self::MAX_CYCLES || ($last !== null && in_array($last->processing_status, [PaymentEventStatus::Review, PaymentEventStatus::Failed], true))) {
                $this->escalate($payment, $order);

                return null;
            }
            $cycle = $payment->reconciliation_attempts + 1;
            $event = new PaymentEvent;
            $event->forceFill(['payment_id' => $payment->id, 'provider' => $payment->provider, 'provider_reference' => $payment->merchant_reference,
                'event_fingerprint' => hash('sha256', self::SOURCE.':v1:'.$payment->id.':'.$cycle),
                'payload_redacted' => ['verification_source' => self::SOURCE, 'cycle' => $cycle],
                'received_at' => $now, 'verified_at' => $now, 'processing_status' => PaymentEventStatus::Pending])->save();
            $payment->forceFill(['reconciliation_attempts' => $cycle, 'next_reconcile_at' => $now->addSeconds(self::BACKOFF_SECONDS[$cycle - 1])])->save();

            return $event;
        });
    }

    public function manually(int $paymentId, User $actor, ?ReconciledPaymentTime $time = null): bool
    {
        $this->assertDatabaseReady();
        $this->authorizeOwner($actor, $actor->fresh());
        if ($this->closure->closeNeverRequested($paymentId)) {
            return true;
        }
        $snapshot = Payment::query()->with('order')->findOrFail($paymentId);
        if ($snapshot->snap_requested_at === null && $snapshot->getRawOriginal('snap_token') === null
            && ! $snapshot->events()->exists()
            && ! AuditLog::query()->where('entity_type', 'payment')->where('entity_id', $snapshot->id)->where('action', 'payment.snap_requested')->exists()) {
            return false;
        }
        $event = $this->transaction(function () use ($snapshot, $actor): PaymentEvent {
            $users = User::query()->whereIn('id', [$actor->id, $snapshot->order->user_id])->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $this->authorizeOwner($actor, $users->get($actor->id));
            $order = Order::query()->whereKey($snapshot->order_id)->lockForUpdate()->firstOrFail();
            $payment = Payment::query()->whereKey($snapshot->id)->lockForUpdate()->firstOrFail();
            $this->authorizeOwner($actor, $users->get($actor->id));
            if ($payment->order_id !== $order->id || $order->user_id !== $snapshot->order->user_id || $payment->provider !== 'midtrans'
                || $payment->merchant_reference !== $snapshot->merchant_reference) {
                throw new LogicException('Manual reconciliation context changed.');
            }
            $event = new PaymentEvent;
            $event->forceFill(['payment_id' => $payment->id, 'provider' => $payment->provider, 'provider_reference' => $payment->merchant_reference,
                'event_fingerprint' => hash('sha256', 'owner_reconciliation:v1:'.Str::uuid()),
                'payload_redacted' => ['verification_source' => 'owner_reconciliation', 'owner_id' => $actor->id],
                'received_at' => now()->startOfSecond(), 'verified_at' => now()->startOfSecond(), 'processing_status' => PaymentEventStatus::Pending])->save();
            $audit = new AuditLog;
            $audit->forceFill(['actor_type' => 'user', 'actor_user_id' => $actor->id, 'entity_type' => 'payment', 'entity_id' => $payment->id,
                'action' => 'payment.manual_reconciliation_requested', 'after_redacted' => ['event_id' => $event->id],
                'correlation_id' => (string) Str::uuid()])->save();

            return $event;
        });

        return $this->consumer->process($event->id, $actor, $time);
    }

    private function authorizeOwner(User $actor, ?User $current): void
    {
        $time = CarbonImmutable::now('Asia/Jakarta')->format('H:i:s');
        if ((int) Auth::id() !== $actor->id || $current === null || ! $current->is_active || $current->role !== UserRole::Owner
            || $time < '09:00:00' || $time > '17:00:00') {
            throw new AuthorizationException('Active authenticated Owner reconciliation requires 09:00–17:00 WIB.');
        }
    }

    private function escalate(Payment $payment, Order $order): void
    {
        $payment->forceFill(['next_reconcile_at' => null])->save();
        if (AuditLog::query()->where('entity_type', 'payment')->where('entity_id', $payment->id)->where('action', 'payment.reconciliation_escalated')->exists()) {
            return;
        }
        $audit = new AuditLog;
        $audit->forceFill(['actor_type' => 'system', 'entity_type' => 'payment', 'entity_id' => $payment->id,
            'action' => 'payment.reconciliation_escalated', 'after_redacted' => ['cycles' => $payment->reconciliation_attempts],
            'correlation_id' => (string) Str::uuid()])->save();
        $this->notices->reconciliationRequired($order);
    }

    /** @return Builder<RefundLog> */
    private function resolvedPaidCancellations(): Builder
    {
        return RefundLog::query()->whereIn('reason_type', ['customer_cancel', 'store_cancel'])
            ->whereHas('order', fn ($orders) => $orders->where('status', 'cancelled')->whereHas('item', fn ($items) => $items
                ->where('quota_release_reason', 'cancelled')->whereColumn('quota_released_at', 'orders.cancelled_at')))
            ->whereExists(fn ($audit) => $audit->selectRaw('1')->from('audit_logs')->where('entity_type', 'order')
                ->whereColumn('entity_id', 'refund_logs.order_id')->where('action', 'order.paid_cancelled')
                ->whereColumn('audit_logs.after_redacted->refund_log_id', 'refund_logs.id')
                ->whereColumn('audit_logs.after_redacted->gross_obligation', 'refund_logs.amount')
                ->whereColumn('audit_logs.after_redacted->percentage', 'refund_logs.percentage')
                ->whereColumn('audit_logs.after_redacted->basis_amount', 'refund_logs.basis_amount')
                ->whereColumn('audit_logs.after_redacted->cause', 'refund_logs.reason_type'));
    }

    private function assertDatabaseReady(): void
    {
        $connection = DB::connection();
        if ($connection->getDriverName() !== 'mysql' || $connection->transactionLevel() !== 0) {
            throw new LogicException('Reconciliation requires MySQL and no caller transaction.');
        }
        $settings = $connection->selectOne('SELECT @@transaction_isolation AS isolation_level, @@session.time_zone AS time_zone');
        if ($settings->isolation_level !== 'READ-COMMITTED' || $settings->time_zone !== '+00:00') {
            throw new LogicException('Reconciliation requires READ COMMITTED and UTC.');
        }
    }

    /** @param Closure(): ?PaymentEvent $operation */
    private function transaction(Closure $operation): ?PaymentEvent
    {
        for ($attempt = 1; ; $attempt++) {
            try {
                return DB::transaction($operation);
            } catch (QueryException $exception) {
                if ($attempt >= 3 || ! in_array((int) ($exception->errorInfo[1] ?? 0), [1205, 1213], true)) {
                    throw $exception;
                }
                Sleep::for(random_int(20, 60) * $attempt)->milliseconds();
            }
        }
    }
}
