<?php

namespace App;

use App\Models\AuditLog;
use App\Models\DailyAvailability;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderRequest;
use App\Models\OrderRequestDecisionMode;
use App\Models\OrderRequestStatus;
use App\Models\OrderRequestType;
use App\Models\OrderStatus;
use App\Models\Payment;
use App\Models\PaymentClosureResult;
use App\Models\PaymentEvent;
use App\Models\PaymentEventStatus;
use App\Models\PaymentMovement;
use App\Models\PaymentStatus;
use App\Models\QuotaReleaseReason;
use App\Models\RefundLog;
use App\Models\RefundTransfer;
use App\Models\User;
use App\Models\UserRole;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;
use LogicException;

class CloseUnpaidPayment
{
    public const NO_SESSION = 'application_no_session';

    public const RECONCILED = 'owner_final_reconciliation';

    public function __construct(private PaymentNotices $notices, private MidtransBankTransferStatus $provider) {}

    /**
     * Only the approved creation path may issue instruments. Its durable marker
     * and audit precede the sole POST, serialized by the same parent locks.
     * Any contradictory history invalidates this application proof.
     */
    public function closeNeverRequested(int $paymentId): bool
    {
        $this->assertDatabaseReady();
        $snapshot = Payment::query()->with('order.item')->find($paymentId);
        if ($snapshot === null || $snapshot->order->item === null) {
            return false;
        }

        return $this->transaction(fn (): bool => $this->persistNeverRequested($snapshot));
    }

    /** Requires genuine finality/no-pending-credit evidence reconciled by this Owner. */
    public function closeReconciled(int $paymentId, User $actor, ReconciledUnpaidClosure $evidence): bool
    {
        $this->authorizeOwner($actor, $actor->fresh());
        $this->assertDatabaseReady();
        $snapshot = Payment::query()->with('order.item')->find($paymentId);
        if ($snapshot === null || $snapshot->order->item === null || ! $this->issuedContext($snapshot, $snapshot->order, $evidence)) {
            return false;
        }
        $observation = $this->provider->observeActual($snapshot->merchant_reference, $snapshot->order->payment_expires_at, $evidence->transactionId);
        $this->assertDatabaseReady();
        try {
            return $this->transaction(fn (): bool => $this->persistReconciled($snapshot, $actor, $evidence, $observation));
        } catch (UniqueConstraintViolationException $exception) {
            if (! str_contains($exception->getMessage(), 'payments_provider_provider_transaction_id_unique')) {
                throw $exception;
            }

            return false;
        }
    }

    private function persistNeverRequested(Payment $snapshot): bool
    {
        $orderSnapshot = $snapshot->order;
        $itemSnapshot = $orderSnapshot->item;
        User::query()->whereKey($orderSnapshot->user_id)->lockForUpdate()->firstOrFail();
        $availability = DailyAvailability::query()->whereKey($itemSnapshot->daily_availability_id)->lockForUpdate()->firstOrFail();
        $order = Order::query()->whereKey($orderSnapshot->id)->lockForUpdate()->firstOrFail();
        $payment = Payment::query()->whereKey($snapshot->id)->lockForUpdate()->firstOrFail();
        $requests = OrderRequest::query()->where('order_id', $order->id)->orderBy('id')->lockForUpdate()->get();
        $refund = RefundLog::query()->where('payment_id', $payment->id)->lockForUpdate()->first();
        $transfers = RefundTransfer::query()->where('payment_id', $payment->id)->orderBy('id')->lockForUpdate()->get();
        $item = OrderItem::query()->whereKey($itemSnapshot->id)->lockForUpdate()->firstOrFail();
        $instant = CarbonImmutable::now('UTC')->startOfSecond();

        if ($payment->order_id !== $order->id || $order->user_id !== $orderSnapshot->user_id || $item->order_id !== $order->id
            || $item->daily_availability_id !== $availability->id || $item->product_id !== $availability->product_id
            || $payment->merchant_reference !== $snapshot->merchant_reference || $payment->expected_amount !== $snapshot->expected_amount
            || $payment->expected_amount !== $order->product_total || $payment->provider !== 'midtrans' || $payment->currency !== 'IDR'
            || ! $order->payment_expires_at->equalTo($orderSnapshot->payment_expires_at) || $instant < $order->created_at
            || $order->payment_expires_at->getTimestamp() - $order->created_at->getTimestamp() !== 1800
            || $payment->snap_requested_at !== null || $payment->getRawOriginal('snap_token') !== null || $payment->provider_transaction_id !== null
            || $payment->provider_status !== null || $payment->fraud_status !== null || $payment->provider_paid_at !== null || $payment->verified_at !== null || $payment->is_late !== null
            || $payment->review_reason !== null || $refund !== null || $transfers->isNotEmpty()
            || PaymentMovement::query()->where('payment_id', $payment->id)->exists()
            || AuditLog::query()->where('entity_type', 'payment')->where('entity_id', $payment->id)->where('action', 'payment.snap_requested')->exists()) {
            return false;
        }

        if ($payment->closure_result !== null) {
            $proof = $payment->closureEvent;
            $terminalAt = $order->status === OrderStatus::Expired ? $order->expired_at : $order->cancelled_at;
            if ($payment->closure_result !== PaymentClosureResult::UnpaidClosed || $proof === null
                || $proof->payment_id !== $payment->id || $proof->provider !== $payment->provider || $proof->provider_reference !== $payment->merchant_reference
                || $proof->processing_status !== PaymentEventStatus::Processed || $proof->verified_at === null
                || ! $proof->verified_at->equalTo($payment->closure_verified_at) || ($proof->payload_redacted['verification_source'] ?? null) !== self::NO_SESSION
                || ! in_array($order->status, [OrderStatus::Expired, OrderStatus::Cancelled], true) || $payment->status->value !== $order->status->value
                || $terminalAt === null || $item->quota_released_at === null || ! $terminalAt->equalTo($item->quota_released_at)
                || ! $terminalAt->equalTo($payment->closure_verified_at) || $item->quota_release_reason?->value !== $order->status->value
                || $requests->contains(fn (OrderRequest $request): bool => $request->status === OrderRequestStatus::Pending)) {
                return false;
            }
            if ($order->status === OrderStatus::Cancelled && ! $requests->contains(fn (OrderRequest $request): bool => $request->type === OrderRequestType::Cancellation && $request->decision_mode === OrderRequestDecisionMode::Automatic
                && $request->status === OrderRequestStatus::Applied && $request->decision_reason === 'unpaid_closed'
                && $request->applied_at?->equalTo($payment->closure_verified_at))) {
                return false;
            }

            return ! $this->hasInboxEvidence($payment, $proof->id);
        }
        if ($order->status !== OrderStatus::Pending || $payment->status !== PaymentStatus::Pending || $item->quota_released_at !== null
            || $this->hasInboxEvidence($payment)) {
            return false;
        }
        $cancellation = null;
        foreach ($requests as $request) {
            if ($request->status !== OrderRequestStatus::Pending) {
                continue;
            }
            if ($request->type !== OrderRequestType::Cancellation || $request->decision_mode !== OrderRequestDecisionMode::Automatic) {
                return false;
            }
            $cancellation = $request;
        }
        if ($cancellation === null && $instant < $order->payment_expires_at) {
            return false;
        }

        return $this->commitClosure($order, $payment, $item, $cancellation, $instant, self::NO_SESSION, ['instrument_requested' => false]);
    }

    private function persistReconciled(Payment $snapshot, User $actor, ReconciledUnpaidClosure $evidence, BankTransferObservation $observation): bool
    {
        $orderSnapshot = $snapshot->order;
        $itemSnapshot = $orderSnapshot->item;
        $users = User::query()->whereIn('id', [$orderSnapshot->user_id, $actor->id])->orderBy('id')->lockForUpdate()->get()->keyBy('id');
        $this->authorizeOwner($actor, $users->get($actor->id));
        $availability = DailyAvailability::query()->whereKey($itemSnapshot->daily_availability_id)->lockForUpdate()->firstOrFail();
        $order = Order::query()->whereKey($orderSnapshot->id)->lockForUpdate()->firstOrFail();
        $payment = Payment::query()->whereKey($snapshot->id)->lockForUpdate()->firstOrFail();
        $requests = OrderRequest::query()->where('order_id', $order->id)->orderBy('id')->lockForUpdate()->get();
        $refund = RefundLog::query()->where('payment_id', $payment->id)->lockForUpdate()->first();
        $transfers = RefundTransfer::query()->where('payment_id', $payment->id)->orderBy('id')->lockForUpdate()->get();
        $item = OrderItem::query()->whereKey($itemSnapshot->id)->lockForUpdate()->firstOrFail();
        $instant = CarbonImmutable::now('UTC')->startOfSecond();

        if ($payment->order_id !== $order->id || $order->user_id !== $orderSnapshot->user_id || $item->order_id !== $order->id
            || $item->daily_availability_id !== $availability->id || $item->product_id !== $availability->product_id
            || $payment->merchant_reference !== $snapshot->merchant_reference || $payment->expected_amount !== $snapshot->expected_amount
            || $payment->expected_amount !== $order->product_total || $payment->provider_transaction_id !== $snapshot->provider_transaction_id
            || $payment->getRawOriginal('snap_token') !== $snapshot->getRawOriginal('snap_token')
            || ! $order->payment_expires_at->equalTo($orderSnapshot->payment_expires_at) || ! $this->issuedContext($payment, $order, $evidence)
            || $payment->provider_paid_at !== null || $payment->is_late !== null || $refund !== null || $transfers->isNotEmpty()
            || ! in_array($payment->provider_status, [null, 'pending', 'expire'], true) || ! in_array($payment->fraud_status, [null, 'accept'], true)
            || PaymentMovement::query()->where('payment_id', $payment->id)->exists()
            || $observation->outcome !== BankTransferOutcome::ExpiredObserved || $observation->transactionId !== $evidence->transactionId
            || $observation->amount !== $payment->expected_amount || $observation->transactionTime === null
            || $observation->transactionTime < $order->created_at || $observation->transactionTime > $evidence->confirmedAt
            || ($observation->expiryTime !== null && $observation->expiryTime > $evidence->confirmedAt)
            || ! AuditLog::query()->where('entity_type', 'payment')->where('entity_id', $payment->id)->where('action', 'payment.snap_requested')
                ->where('created_at', '<=', $evidence->confirmedAt)->exists()
            || Payment::query()->where('provider', $payment->provider)->where('provider_transaction_id', $evidence->transactionId)->whereKeyNot($payment->id)->exists()) {
            return false;
        }
        $payload = ['transaction_id' => $evidence->transactionId, 'confirmed_at' => $evidence->confirmedAt->format(DATE_ATOM),
            'owner_id' => $actor->id, 'evidence_sha256' => hash('sha256', $evidence->evidenceReference),
            'transaction_status' => 'expire', 'transaction_time' => $observation->transactionTime->format(DATE_ATOM)];
        if ($this->hasUnresolvedInbox($payment, $payment->closure_event_id)) {
            return false;
        }
        if ($payment->closure_result !== null) {
            return $this->matchesReconciledReplay($order, $payment, $item, $requests, $payload);
        }
        if ($order->status !== OrderStatus::Pending || $payment->status !== PaymentStatus::Pending || $item->quota_released_at !== null
            || ! in_array($payment->review_reason, [null, 'snap_creation_unknown', 'closure_verification_required'], true)) {
            return false;
        }
        $cancellation = null;
        foreach ($requests as $request) {
            if ($request->status !== OrderRequestStatus::Pending) {
                continue;
            }
            if ($request->type !== OrderRequestType::Cancellation || $request->decision_mode !== OrderRequestDecisionMode::Automatic) {
                return false;
            }
            $cancellation = $request;
        }
        if ($cancellation === null && ($instant < $order->payment_expires_at || $evidence->confirmedAt < $order->payment_expires_at)) {
            return false;
        }

        return $this->commitClosure($order, $payment, $item, $cancellation, $instant, self::RECONCILED, $payload, $actor, $observation);
    }

    /** @param array<string, bool|int|string> $payload */
    private function commitClosure(Order $order, Payment $payment, OrderItem $item, ?OrderRequest $cancellation, CarbonImmutable $instant, string $source, array $payload, ?User $actor = null, ?BankTransferObservation $observation = null): bool
    {
        $proof = new PaymentEvent;
        $proof->forceFill(['payment_id' => $payment->id, 'provider' => $payment->provider, 'provider_reference' => $payment->merchant_reference,
            'event_fingerprint' => hash('sha256', $source.':v1:'.$payment->id.':'.$payment->merchant_reference),
            'payload_redacted' => ['verification_source' => $source] + $payload,
            'received_at' => $instant, 'verified_at' => $instant, 'processing_status' => PaymentEventStatus::Processed, 'processed_at' => $instant])->save();
        $order->forceFill($cancellation === null ? ['status' => OrderStatus::Expired, 'expired_at' => $instant]
            : ['status' => OrderStatus::Cancelled, 'cancelled_at' => $instant, 'cancellation_reason' => 'unpaid_closed'])->save();
        if ($observation !== null) {
            $payment->forceFill(['provider_transaction_id' => $observation->transactionId, 'provider_status' => 'expire', 'verified_at' => $instant, 'review_reason' => null]);
        }
        $payment->forceFill(['status' => $cancellation === null ? PaymentStatus::Expired : PaymentStatus::Cancelled,
            'closure_result' => PaymentClosureResult::UnpaidClosed, 'closure_verified_at' => $instant, 'closure_event_id' => $proof->id])->save();
        $item->forceFill(['quota_released_at' => $instant, 'quota_release_reason' => $cancellation === null ? QuotaReleaseReason::Expired : QuotaReleaseReason::Cancelled])->save();
        if ($cancellation !== null) {
            $cancellation->forceFill(['status' => OrderRequestStatus::Applied, 'reviewed_at' => $instant, 'reviewed_by' => $actor?->id,
                'applied_at' => $instant, 'decision_reason' => 'unpaid_closed'])->save();
        }
        $audit = new AuditLog;
        $audit->forceFill(['actor_type' => $actor === null ? 'system' : 'user', 'actor_user_id' => $actor?->id,
            'entity_type' => 'payment', 'entity_id' => $payment->id, 'action' => 'payment.unpaid_closed',
            'after_redacted' => ['closure_source' => $source, 'closure_event_id' => $proof->id, 'order_status' => $order->status->value, 'request_id' => $cancellation?->id],
            'correlation_id' => (string) Str::uuid()])->save();
        $this->notices->unpaidClosed($order);

        return true;
    }

    private function issuedContext(Payment $payment, Order $order, ReconciledUnpaidClosure $evidence): bool
    {
        $instant = CarbonImmutable::now('UTC')->startOfSecond();
        $time = $instant->setTimezone('Asia/Jakarta')->format('H:i:s');
        if ($payment->provider !== 'midtrans' || $payment->currency !== 'IDR' || $payment->snap_requested_at === null
            || $payment->getRawOriginal('snap_token') === null || $evidence->confirmedAt > $instant || $evidence->confirmedAt < $payment->snap_requested_at
            || $evidence->confirmedAt < $order->created_at || $time < '09:00:00' || $time > '17:00:00'
            || $order->payment_expires_at->getTimestamp() - $order->created_at->getTimestamp() !== 1800
            || ($payment->provider_transaction_id !== null && $payment->provider_transaction_id !== $evidence->transactionId)) {
            return false;
        }
        try {
            return is_string($payment->snap_token) && Str::isUuid($payment->snap_token);
        } catch (DecryptException) {
            return false;
        }
    }

    private function authorizeOwner(User $actor, ?User $current): void
    {
        if ((int) Auth::id() !== $actor->id || $current === null || ! $current->is_active || $current->role !== UserRole::Owner) {
            throw new AuthorizationException('Active authenticated Owner final reconciliation required.');
        }
    }

    private function hasUnresolvedInbox(Payment $payment, ?int $excludingId): bool
    {
        $events = PaymentEvent::query()->when($excludingId !== null, fn ($query) => $query->whereKeyNot($excludingId))
            ->where(fn ($query) => $query->where('payment_id', $payment->id)
                ->orWhere(fn ($query) => $query->where('provider', $payment->provider)->where('provider_reference', $payment->merchant_reference)))->orderBy('id')->get();
        foreach ($events as $event) {
            if ($event->payment_id !== $payment->id || $event->provider !== $payment->provider || $event->provider_reference !== $payment->merchant_reference
                || $event->processing_status !== PaymentEventStatus::Processed || $event->verified_at === null || $event->claim_token !== null
                || (! in_array($event->payload_redacted['transaction_status'] ?? null, ['pending', 'expire'], true)
                    && ! $this->hasResolvedReconciliationObservation($payment, $event))) {
                return true;
            }
        }

        return false;
    }

    private function hasResolvedReconciliationObservation(Payment $payment, PaymentEvent $event): bool
    {
        if (! in_array($event->payload_redacted['verification_source'] ?? null, ['application_reconciliation', 'owner_reconciliation'], true)) {
            return false;
        }

        return AuditLog::query()->where('entity_type', 'payment')->where('entity_id', $payment->id)
            ->where('action', 'payment.non_success_observed')->where('after_redacted->event_id', $event->id)
            ->whereIn('after_redacted->outcome', [BankTransferOutcome::Pending->value, BankTransferOutcome::ExpiredObserved->value])->exists();
    }

    /** @param Collection<int, OrderRequest> $requests
     * @param  array<string, bool|int|string>  $payload
     */
    private function matchesReconciledReplay(Order $order, Payment $payment, OrderItem $item, Collection $requests, array $payload): bool
    {
        $proof = $payment->closureEvent;
        $terminalAt = $order->status === OrderStatus::Expired ? $order->expired_at : $order->cancelled_at;
        if ($payment->closure_result !== PaymentClosureResult::UnpaidClosed || $payment->review_reason !== null || $proof === null
            || $proof->payment_id !== $payment->id || $proof->provider !== $payment->provider || $proof->provider_reference !== $payment->merchant_reference
            || $proof->processing_status !== PaymentEventStatus::Processed || $proof->verified_at === null
            || ! $proof->verified_at->equalTo($payment->closure_verified_at) || ($proof->payload_redacted['verification_source'] ?? null) !== self::RECONCILED
            || ! in_array($order->status, [OrderStatus::Expired, OrderStatus::Cancelled], true) || $payment->status->value !== $order->status->value
            || $terminalAt === null || $item->quota_released_at === null || ! $terminalAt->equalTo($item->quota_released_at)
            || ! $terminalAt->equalTo($payment->closure_verified_at) || $item->quota_release_reason?->value !== $order->status->value
            || $requests->contains(fn (OrderRequest $request): bool => $request->status === OrderRequestStatus::Pending)) {
            return false;
        }
        foreach ($payload as $key => $value) {
            if (($proof->payload_redacted[$key] ?? null) !== $value) {
                return false;
            }
        }

        return $order->status !== OrderStatus::Cancelled || $requests->contains(fn (OrderRequest $request): bool => $request->type === OrderRequestType::Cancellation && $request->decision_mode === OrderRequestDecisionMode::Automatic
            && $request->status === OrderRequestStatus::Applied && $request->decision_reason === 'unpaid_closed'
            && $request->applied_at?->equalTo($payment->closure_verified_at));
    }

    private function hasInboxEvidence(Payment $payment, ?int $excludingId = null): bool
    {
        return PaymentEvent::query()->when($excludingId !== null, fn ($query) => $query->whereKeyNot($excludingId))
            ->where(fn ($query) => $query->where('payment_id', $payment->id)
                ->orWhere(fn ($query) => $query->where('provider', $payment->provider)->where('provider_reference', $payment->merchant_reference)))->exists();
    }

    private function assertDatabaseReady(): void
    {
        $connection = DB::connection();
        if ($connection->getDriverName() !== 'mysql' || $connection->transactionLevel() !== 0) {
            throw new LogicException('Unpaid closure requires MySQL and no caller transaction.');
        }
        $settings = $connection->selectOne('SELECT @@transaction_isolation AS isolation_level, @@session.time_zone AS time_zone');
        if ($settings->isolation_level !== 'READ-COMMITTED' || $settings->time_zone !== '+00:00') {
            throw new LogicException('Unpaid closure requires READ COMMITTED and UTC.');
        }
    }

    /** @param Closure(): bool $operation */
    private function transaction(Closure $operation): bool
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
