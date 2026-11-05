<?php

namespace App;

use App\Models\AuditLog;
use App\Models\Order;
use App\Models\OrderRequest;
use App\Models\OrderRequestDecisionMode;
use App\Models\OrderRequestStatus;
use App\Models\OrderRequestType;
use App\Models\OrderStatus;
use App\Models\Payment;
use App\Models\PaymentEvent;
use App\Models\PaymentMovement;
use App\Models\PaymentMovementCorrection;
use App\Models\PaymentMovementKind;
use App\Models\PaymentMovementSource;
use App\Models\PaymentStatus;
use App\Models\RefundLog;
use App\Models\RefundLogStatus;
use App\Models\RefundTransfer;
use App\Models\User;
use App\Models\UserRole;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use InvalidArgumentException;
use OverflowException;

class ProcessPaymentEvent
{
    public function __construct(
        private PaymentEventInbox $inbox,
        private MidtransBankTransferStatus $provider,
        private PaymentFunds $funds,
        private PaymentNotices $notices,
    ) {}

    /**
     * The optional attestation is accepted only from the actual authenticated Owner.
     * Provider HTTP runs after durable claim and before the domain transaction.
     */
    public function process(int $eventId, ?User $actor = null, ?ReconciledPaymentTime $time = null): bool
    {
        if ($time !== null && $actor === null) {
            throw new InvalidArgumentException('Timing evidence requires an authenticated Owner.');
        }
        if ($actor !== null) {
            $this->authorizeOwner($actor, $actor->fresh());
        }
        $claim = $this->inbox->claim($eventId);
        if ($claim === null) {
            return false;
        }
        $payment = Payment::query()->findOrFail($claim->payment_id);
        $order = Order::query()->findOrFail($payment->order_id);
        $observation = $this->provider->observeActual($payment->merchant_reference, $order->payment_expires_at, $payment->provider_transaction_id);
        if (in_array($observation->reason, ['provider_unavailable', 'sandbox_not_configured', 'malformed_provider_response'], true)) {
            $this->inbox->retry($claim, 'provider_unavailable');

            return false;
        }
        try {
            return $this->inbox->complete($claim, fn (PaymentEvent $event): ?PaymentReviewReason => $this->apply($event, $payment, $order, $observation, $actor, $time));
        } catch (UniqueConstraintViolationException $exception) {
            if (! str_contains($exception->getMessage(), 'payments_provider_provider_transaction_id_unique')) {
                throw $exception;
            }

            return $this->inbox->complete($claim, fn (PaymentEvent $event): ?PaymentReviewReason => $this->apply(
                $event, $payment, $order, new BankTransferObservation(BankTransferOutcome::Conflict, 'transaction_identity_conflict'), $actor, $time,
            ));
        }
    }

    private function apply(PaymentEvent $event, Payment $snapshot, Order $orderSnapshot, BankTransferObservation $observation, ?User $actor, ?ReconciledPaymentTime $time): ?PaymentReviewReason
    {
        $users = User::query()->whereIn('id', array_filter([$orderSnapshot->user_id, $actor?->id]))->orderBy('id')->lockForUpdate()->get()->keyBy('id');
        if ($actor !== null) {
            $this->authorizeOwner($actor, $users->get($actor->id));
        }
        $order = Order::query()->whereKey($orderSnapshot->id)->lockForUpdate()->firstOrFail();
        $payment = Payment::query()->whereKey($snapshot->id)->lockForUpdate()->firstOrFail();
        $requests = OrderRequest::query()->where('order_id', $order->id)->orderBy('id')->lockForUpdate()->get();
        $refund = RefundLog::query()->where('payment_id', $payment->id)->lockForUpdate()->first();
        RefundTransfer::query()->where('payment_id', $payment->id)->orderBy('id')->lockForUpdate()->get();
        if ($actor !== null) {
            $this->authorizeOwner($actor, $users->get($actor->id));
        }

        if ($payment->order_id !== $order->id || $order->user_id !== $orderSnapshot->user_id
            || $payment->provider !== 'midtrans' || $payment->currency !== 'IDR'
            || $event->provider !== $payment->provider || $event->provider_reference !== $payment->merchant_reference
            || $payment->merchant_reference !== $snapshot->merchant_reference || $payment->expected_amount !== $snapshot->expected_amount
            || ! $order->payment_expires_at->equalTo($orderSnapshot->payment_expires_at)
            || $order->product_total !== $orderSnapshot->product_total || $observation->outcome === BankTransferOutcome::Conflict) {
            return $this->review($event, $payment, $order, $refund, PaymentReviewReason::ContextConflict, $actor);
        }
        if ($observation->outcome === BankTransferOutcome::Unknown) {
            return $this->review($event, $payment, $order, $refund, PaymentReviewReason::EvidenceUnverified, $actor);
        }
        if ($observation->outcome !== BankTransferOutcome::SuccessObserved) {
            $this->audit($event, $payment, 'payment.non_success_observed', ['outcome' => $observation->outcome->value], $actor);

            return null;
        }
        if ($observation->transactionId === null || $observation->amount === null || $observation->settlementTime === null
            || $observation->transactionTime === null || $observation->transactionTime < $order->created_at
            || $observation->settlementTime > CarbonImmutable::now('UTC')) {
            return $this->review($event, $payment, $order, $refund, PaymentReviewReason::FundsTimeUnverified, $actor);
        }
        if (($payment->provider_transaction_id !== null && $payment->provider_transaction_id !== $observation->transactionId)
            || Payment::query()->where('provider', $payment->provider)->where('provider_transaction_id', $observation->transactionId)->whereKeyNot($payment->id)->exists()) {
            return $this->review($event, $payment, $order, $refund, PaymentReviewReason::ContextConflict, $actor);
        }

        $key = PaymentMovementKey::fromIdentity('midtrans:bca:receipt:v1', [$observation->transactionId]);
        $movement = PaymentMovement::query()->where('payment_id', $payment->id)->where('canonical_key', $key)->first();
        $matched = $movement !== null;
        if ($movement !== null && ($movement->kind !== PaymentMovementKind::Receipt || $movement->source !== PaymentMovementSource::GatewayReceipt
            || $movement->amount !== $observation->amount || ! $movement->occurred_at->equalTo($observation->settlementTime))) {
            return $this->review($event, $payment, $order, $refund, PaymentReviewReason::IdentityConflict, $actor);
        }
        $payment->forceFill(['provider_transaction_id' => $observation->transactionId, 'provider_status' => 'settlement', 'verified_at' => now()->startOfSecond()])->save();
        if ($movement === null) {
            $movement = new PaymentMovement;
            $movement->forceFill(['payment_id' => $payment->id, 'canonical_key' => $key, 'kind' => PaymentMovementKind::Receipt,
                'source' => PaymentMovementSource::GatewayReceipt, 'amount' => $observation->amount, 'occurred_at' => $observation->settlementTime,
                'verified_at' => now()->startOfSecond(), 'source_event_id' => $event->id])->save();
        }
        $this->audit($event, $payment, $matched ? 'payment_event_matched_movement' : 'payment.receipt_recorded', ['movement_id' => $movement->id, 'amount' => $movement->amount], $actor);
        try {
            $funds = $this->funds->snapshot($payment);
        } catch (OverflowException) {
            return $this->review($event, $payment, $order, $refund, PaymentReviewReason::LedgerOverflow, $actor);
        }
        if ($funds['receipts'] > 0) {
            $payment->forceFill(['status' => $funds['returns'] === 0 ? PaymentStatus::Paid : ($funds['returns'] >= $funds['receipts'] ? PaymentStatus::Refunded : PaymentStatus::PartiallyRefunded)])->save();
        }
        if ($funds['excess_returns']) {
            return $this->review($event, $payment, $order, $refund, PaymentReviewReason::ExcessReturn, $actor);
        }
        if (PaymentMovementCorrection::query()->whereIn('payment_movement_id', PaymentMovement::query()->select('id')->where('payment_id', $payment->id))->exists()) {
            return $this->review($event, $payment, $order, $refund, PaymentReviewReason::CorrectionReview, $actor);
        }
        $knownTime = $time?->paidAt ?? $payment->provider_paid_at;
        if (($knownTime !== null && ($knownTime < $observation->transactionTime || $knownTime > $observation->settlementTime))
            || ($time !== null && ($time->transactionId !== $observation->transactionId
                || ($payment->provider_paid_at !== null && ! $payment->provider_paid_at->equalTo($time->paidAt))))) {
            return $this->review($event, $payment, $order, $refund, PaymentReviewReason::ReconciliationConflict, $actor);
        }
        $terminalAt = match ($order->status) {
            OrderStatus::Expired => $order->expired_at,
            OrderStatus::Cancelled => $order->cancelled_at,
            default => null,
        };
        $contradictsClosure = $matched && $refund === null && $payment->closure_result !== null
            && $terminalAt?->equalTo($payment->closure_verified_at);
        if ($contradictsClosure && $time === null) {
            return $this->review($event, $payment, $order, $refund, PaymentReviewReason::TerminalReceipt, $actor);
        }
        if ($matched && ! $contradictsClosure && $order->status !== OrderStatus::Pending
            && ($time === null || ($payment->provider_paid_at !== null && $payment->provider_paid_at->equalTo($time->paidAt)
                && $time->transactionId === $observation->transactionId))) {
            return null;
        }
        if ($observation->amount !== $payment->expected_amount || $payment->expected_amount !== $order->product_total || $funds['receipts'] !== $payment->expected_amount) {
            return $this->review($event, $payment, $order, $refund, PaymentReviewReason::AmountAnomaly, $actor);
        }
        if ($funds['returns'] > 0 || $funds['reserved'] > 0 || $refund !== null) {
            return $this->review($event, $payment, $order, $refund, PaymentReviewReason::ReturnReview, $actor);
        }
        if ($time === null) {
            return $this->review($event, $payment, $order, $refund, PaymentReviewReason::TimeUnverified, $actor);
        }
        if ($time->paidAt == $order->payment_expires_at) {
            return $this->review($event, $payment, $order, $refund, PaymentReviewReason::TimeUnverified, $actor);
        }
        $payment->forceFill(['provider_paid_at' => $time->paidAt, 'is_late' => $time->paidAt > $order->payment_expires_at])->save();
        $this->audit($event, $payment, 'payment.time_attested', ['evidence_sha256' => hash('sha256', $time->evidenceReference), 'paid_at' => $time->paidAt->format(DATE_ATOM)], $actor);
        if ($payment->is_late) {
            return $this->review($event, $payment, $order, $refund, PaymentReviewReason::ActualLate, $actor);
        }
        if ($order->status !== OrderStatus::Pending || $payment->closure_result !== null) {
            return $this->review($event, $payment, $order, $refund, PaymentReviewReason::TerminalReceipt, $actor);
        }
        $item = $order->item;
        if ($item === null || $item->quota_released_at !== null) {
            return $this->review($event, $payment, $order, $refund, PaymentReviewReason::AllocationConflict, $actor);
        }
        if (! in_array($payment->review_reason, [null, 'snap_creation_unknown', 'closure_verification_required', PaymentReviewReason::TimeUnverified->value], true)) {
            return $this->review($event, $payment, $order, $refund, PaymentReviewReason::ExistingReview, $actor);
        }
        $order->forceFill(['status' => OrderStatus::Paid, 'paid_at' => $time->paidAt])->save();
        $payment->forceFill(['status' => PaymentStatus::Paid, 'review_reason' => null])->save();
        foreach ($requests as $request) {
            if ($request->status === OrderRequestStatus::Pending && $request->type === OrderRequestType::Cancellation && $request->decision_mode === OrderRequestDecisionMode::Automatic) {
                $request->forceFill(['status' => OrderRequestStatus::Rejected, 'reviewed_at' => now()->startOfSecond(), 'decision_reason' => 'payment_won'])->save();
                $this->audit($event, $payment, 'payment.automatic_cancellation_rejected', ['request_id' => $request->id], $actor);
            }
        }
        $this->audit($event, $payment, 'payment.timely_paid', [], $actor);
        $this->notices->paid($order);

        return null;
    }

    private function authorizeOwner(User $actor, ?User $current): void
    {
        $time = CarbonImmutable::now('Asia/Jakarta')->format('H:i:s');
        if ((int) Auth::id() !== $actor->id || $current === null || ! $current->is_active || $current->role !== UserRole::Owner
            || $time < '09:00:00' || $time > '17:00:00') {
            throw new AuthorizationException('Active authenticated Owner reconciliation required.');
        }
    }

    private function review(PaymentEvent $event, Payment $payment, Order $order, ?RefundLog $refund, PaymentReviewReason $reason, ?User $actor): PaymentReviewReason
    {
        if ($reason !== PaymentReviewReason::ExistingReview
            && in_array($payment->review_reason, [null, 'snap_creation_unknown', 'closure_verification_required', PaymentReviewReason::TimeUnverified->value], true)) {
            $payment->forceFill(['review_reason' => $reason->value])->save();
        }
        if ($refund !== null && $refund->status !== RefundLogStatus::Completed) {
            $refund->forceFill(['status' => RefundLogStatus::Review, 'review_reason' => $reason->value])->save();
        }
        $this->audit($event, $payment, 'payment.review_required', ['reason' => $reason->value], $actor);
        $this->notices->review($order, $reason);

        return $reason;
    }

    /** @param array<string, int|string> $details */
    private function audit(PaymentEvent $event, Payment $payment, string $action, array $details, ?User $actor): void
    {
        $audit = new AuditLog;
        $audit->forceFill(['actor_type' => $actor === null ? 'provider' : 'user', 'actor_user_id' => $actor?->id,
            'entity_type' => 'payment', 'entity_id' => $payment->id, 'action' => $action,
            'after_redacted' => ['event_id' => $event->id] + $details, 'correlation_id' => (string) Str::uuid()])->save();
    }
}
