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
use App\Models\PaymentMovementCorrection;
use App\Models\PaymentMovementKind;
use App\Models\PaymentMovementSource;
use App\Models\PaymentStatus;
use App\Models\RefundLog;
use App\Models\RefundTransfer;
use App\Models\User;
use App\Models\UserRole;
use Carbon\CarbonImmutable;
use DateTimeImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;
use InvalidArgumentException;
use LogicException;
use OverflowException;

class RecoverTimelyPayment
{
    public function __construct(private MidtransBankTransferStatus $provider, private PaymentFunds $funds, private PaymentNotices $notices) {}

    public function recover(int $paymentId, User $actor, string $evidenceReference): bool
    {
        $this->authorizeOwner($actor, $actor->fresh());
        if (! preg_match('/\A[\x21-\x7e]{1,128}\z/', $evidenceReference)) {
            throw new InvalidArgumentException('Private operational reconciliation evidence is required.');
        }
        $connection = DB::connection();
        if ($connection->getDriverName() !== 'mysql' || $connection->transactionLevel() !== 0) {
            throw new LogicException('Timely recovery requires MySQL and no caller transaction.');
        }
        $settings = $connection->selectOne('SELECT @@transaction_isolation AS isolation_level, @@session.time_zone AS time_zone');
        if ($settings->isolation_level !== 'READ-COMMITTED' || $settings->time_zone !== '+00:00') {
            throw new LogicException('Timely recovery requires READ COMMITTED and UTC.');
        }
        $snapshot = Payment::query()->with('order.item')->findOrFail($paymentId);
        if ($snapshot->provider !== 'midtrans' || $snapshot->currency !== 'IDR' || $snapshot->provider_transaction_id === null || $snapshot->order->item === null) {
            return false;
        }
        $observation = $this->provider->observeActual($snapshot->merchant_reference, $snapshot->order->payment_expires_at, $snapshot->provider_transaction_id);
        for ($attempt = 1; ; $attempt++) {
            try {
                return $connection->transaction(fn (): bool => $this->persist($snapshot, $actor, $evidenceReference, $observation));
            } catch (QueryException $exception) {
                if ($attempt >= 3 || ! in_array((int) ($exception->errorInfo[1] ?? 0), [1205, 1213], true)) {
                    throw $exception;
                }
                Sleep::for(random_int(20, 60) * $attempt)->milliseconds();
            }
        }
    }

    private function persist(Payment $snapshot, User $actor, string $evidenceReference, BankTransferObservation $observation): bool
    {
        $orderSnapshot = $snapshot->order;
        $itemSnapshot = $orderSnapshot->item;
        $users = User::query()->whereIn('id', [$actor->id, $orderSnapshot->user_id])->orderBy('id')->lockForUpdate()->get()->keyBy('id');
        $availability = DailyAvailability::query()->whereKey($itemSnapshot->daily_availability_id)->lockForUpdate()->firstOrFail();
        $order = Order::query()->whereKey($orderSnapshot->id)->lockForUpdate()->firstOrFail();
        $payment = Payment::query()->whereKey($snapshot->id)->lockForUpdate()->firstOrFail();
        $requests = OrderRequest::query()->where('order_id', $order->id)->orderBy('id')->lockForUpdate()->get();
        $refund = RefundLog::query()->where('payment_id', $payment->id)->lockForUpdate()->first();
        $transfers = RefundTransfer::query()->where('payment_id', $payment->id)->orderBy('id')->lockForUpdate()->get();
        $item = OrderItem::query()->whereKey($itemSnapshot->id)->lockForUpdate()->firstOrFail();
        $this->authorizeOwner($actor, $users->get($actor->id));
        if ($payment->order_id !== $order->id || $order->user_id !== $orderSnapshot->user_id || $item->order_id !== $order->id
            || $item->daily_availability_id !== $availability->id || $item->product_id !== $availability->product_id
            || ! $availability->availability_date->equalTo($order->pickup_date) || $item->quantity !== $itemSnapshot->quantity
            || $payment->provider !== $snapshot->provider || $payment->currency !== $snapshot->currency
            || $payment->merchant_reference !== $snapshot->merchant_reference || $payment->provider_transaction_id !== $snapshot->provider_transaction_id
            || $payment->expected_amount !== $snapshot->expected_amount || $order->product_total !== $payment->expected_amount || $item->line_total !== $order->product_total
            || ! $order->payment_expires_at->equalTo($orderSnapshot->payment_expires_at)
            || $observation->outcome !== BankTransferOutcome::SuccessObserved || $observation->transactionId !== $payment->provider_transaction_id
            || $observation->amount !== $payment->expected_amount || $observation->transactionTime === null || $observation->settlementTime === null
            || $observation->transactionTime < $order->created_at || $observation->settlementTime > CarbonImmutable::now('UTC')
            || $payment->provider_paid_at === null || $payment->is_late !== false || $payment->provider_paid_at >= $order->payment_expires_at
            || $payment->provider_paid_at < $observation->transactionTime || $payment->provider_paid_at > $observation->settlementTime
            || $refund !== null || $transfers->isNotEmpty() || $this->hasUnresolvedObservations($payment)) {
            return false;
        }
        $proof = $payment->closureEvent;
        $closedAt = $this->originalCloseTime($payment);
        if ($proof === null || $closedAt === null || $payment->closure_result !== PaymentClosureResult::UnpaidClosed
            || $proof->payment_id !== $payment->id || $proof->provider !== $payment->provider || $proof->provider_reference !== $payment->merchant_reference
            || $proof->processing_status !== PaymentEventStatus::Processed || $proof->verified_at === null || ! $proof->verified_at->equalTo($payment->closure_verified_at)
            || $payment->provider_paid_at >= $closedAt || ! $this->hasTimingAttestation($payment)) {
            return false;
        }
        $receipt = PaymentMovement::query()->where('payment_id', $payment->id)
            ->where('canonical_key', PaymentMovementKey::fromIdentity('midtrans:bca:receipt:v1', [$observation->transactionId]))->first();
        $source = $receipt?->sourceEvent;
        if ($receipt === null || $receipt->kind !== PaymentMovementKind::Receipt || $receipt->source !== PaymentMovementSource::GatewayReceipt
            || $receipt->amount !== $observation->amount || ! $receipt->occurred_at->equalTo($observation->settlementTime)
            || $source === null || $source->verified_at === null || $source->payment_id !== $payment->id || $source->provider !== $payment->provider
            || $source->provider_reference !== $payment->merchant_reference
            || PaymentMovementCorrection::query()->whereIn('payment_movement_id', PaymentMovement::query()->select('id')->where('payment_id', $payment->id))->exists()) {
            return false;
        }
        try {
            $funds = $this->funds->snapshot($payment);
        } catch (OverflowException) {
            return false;
        }
        if ($funds['receipts'] !== $payment->expected_amount || $funds['returns'] !== 0 || $funds['reserved'] !== 0 || $funds['excess_returns']) {
            return false;
        }
        $evidenceHash = hash('sha256', $evidenceReference);
        if ($order->status === OrderStatus::Paid) {
            return $payment->status === PaymentStatus::Paid && $payment->review_reason === null && $item->quota_released_at === null && $item->quota_release_reason === null
                && $order->paid_at?->equalTo($payment->provider_paid_at)
                && AuditLog::query()->where('entity_type', 'payment')->where('entity_id', $payment->id)->where('actor_user_id', $actor->id)
                    ->where('action', 'payment.timely_recovery_applied')->where('after_redacted->evidence_sha256', $evidenceHash)
                    ->where('after_redacted->closure_event_id', $proof->id)->exists();
        }
        $terminalAt = $order->status === OrderStatus::Expired ? $order->expired_at : $order->cancelled_at;
        if (! in_array($order->status, [OrderStatus::Expired, OrderStatus::Cancelled], true) || $terminalAt === null
            || $item->quota_released_at === null || ! $item->quota_released_at->equalTo($payment->closure_verified_at)
            || ! $terminalAt->equalTo($payment->closure_verified_at) || $item->quota_release_reason?->value !== $order->status->value
            || ! in_array($payment->review_reason, [PaymentReviewReason::TerminalReceipt->value, PaymentReviewReason::CapacityReassigned->value], true)) {
            return false;
        }
        $toCorrect = [];
        foreach ($requests as $request) {
            if ($request->status === OrderRequestStatus::Rejected) {
                continue;
            }
            if ($request->type !== OrderRequestType::Cancellation || $request->decision_mode !== OrderRequestDecisionMode::Automatic
                || $request->submitted_at > $payment->closure_verified_at
                || ($request->status === OrderRequestStatus::Applied && ($order->status !== OrderStatus::Cancelled || $request->decision_reason !== 'unpaid_closed'
                    || ! $request->applied_at?->equalTo($payment->closure_verified_at) || ! $request->reviewed_at?->equalTo($payment->closure_verified_at)))) {
                return false;
            }
            $toCorrect[] = $request;
        }
        if ($order->status === OrderStatus::Cancelled && count($toCorrect) !== 1) {
            return false;
        }
        $used = (int) OrderItem::query()->where('daily_availability_id', $availability->id)->whereNull('quota_released_at')->sum('quantity');
        if ($used > $availability->quota_limit - $item->quantity) {
            if ($payment->review_reason !== PaymentReviewReason::CapacityReassigned->value) {
                $payment->forceFill(['review_reason' => PaymentReviewReason::CapacityReassigned->value])->save();
            }
            if (! AuditLog::query()->where('entity_type', 'payment')->where('entity_id', $payment->id)->where('action', 'payment.recovery_capacity_unavailable')->exists()) {
                $this->audit($payment, $actor, 'payment.recovery_capacity_unavailable', [], ['closure_event_id' => $proof->id, 'evidence_sha256' => $evidenceHash]);
                $this->notices->review($order, PaymentReviewReason::CapacityReassigned);
            }

            return false;
        }
        $before = ['status' => $order->status->value, 'quota_released_at' => $item->quota_released_at->format(DATE_ATOM), 'quota_release_reason' => $item->quota_release_reason->value];
        foreach ($toCorrect as $request) {
            $previous = ['request_id' => $request->id, 'status' => $request->status->value, 'applied_at' => $request->applied_at?->format(DATE_ATOM)];
            $request->forceFill(['status' => OrderRequestStatus::Rejected, 'reviewed_by' => $actor->id, 'reviewed_at' => now()->startOfSecond(),
                'decision_reason' => 'payment_won_recovery', 'applied_at' => null])->save();
            $this->audit($payment, $actor, 'payment.recovery_cancellation_corrected', $previous, ['request_id' => $request->id, 'closure_event_id' => $proof->id]);
        }
        $item->forceFill(['quota_released_at' => null, 'quota_release_reason' => null])->save();
        $order->forceFill(['status' => OrderStatus::Paid, 'paid_at' => $payment->provider_paid_at, 'expired_at' => null, 'cancelled_at' => null])->save();
        $payment->forceFill(['status' => PaymentStatus::Paid, 'review_reason' => null])->save();
        $this->audit($payment, $actor, 'payment.timely_recovery_applied', $before, ['status' => 'paid', 'closure_event_id' => $proof->id,
            'evidence_sha256' => $evidenceHash, 'availability_id' => $availability->id, 'quantity' => $item->quantity]);
        $this->notices->paid($order);

        return true;
    }

    private function originalCloseTime(Payment $payment): ?DateTimeImmutable
    {
        $proof = $payment->closureEvent;
        if ($proof === null) {
            return null;
        }
        if (($proof->payload_redacted['verification_source'] ?? null) === CloseUnpaidPayment::NO_SESSION) {
            return $payment->closure_verified_at;
        }
        $confirmedAt = $proof->payload_redacted['confirmed_at'] ?? null;
        if (($proof->payload_redacted['verification_source'] ?? null) !== CloseUnpaidPayment::RECONCILED || ! is_string($confirmedAt)) {
            return null;
        }
        $time = DateTimeImmutable::createFromFormat('!'.DATE_ATOM, $confirmedAt);

        return $time !== false && $time->format(DATE_ATOM) === $confirmedAt && $time >= $payment->order->created_at && $time <= $payment->closure_verified_at ? $time : null;
    }

    private function hasTimingAttestation(Payment $payment): bool
    {
        $audits = AuditLog::query()->where('entity_type', 'payment')->where('entity_id', $payment->id)->where('action', 'payment.time_attested')
            ->where('actor_type', 'user')->whereNotNull('actor_user_id')->where('after_redacted->paid_at', $payment->provider_paid_at->format(DATE_ATOM))->get();
        foreach ($audits as $audit) {
            $eventId = $audit->after_redacted['event_id'] ?? null;
            if (is_int($eventId) && PaymentEvent::query()->whereKey($eventId)->where('payment_id', $payment->id)->where('provider', $payment->provider)
                ->where('provider_reference', $payment->merchant_reference)->whereNotNull('verified_at')->exists()) {
                return true;
            }
        }

        return false;
    }

    private function hasUnresolvedObservations(Payment $payment): bool
    {
        $events = PaymentEvent::query()->where(fn ($query) => $query->where('payment_id', $payment->id)
            ->orWhere(fn ($reference) => $reference->where('provider', $payment->provider)->where('provider_reference', $payment->merchant_reference)))->get();
        foreach ($events as $event) {
            if ($event->payment_id !== $payment->id || $event->provider !== $payment->provider || $event->provider_reference !== $payment->merchant_reference || $event->verified_at === null
                || ! in_array($event->processing_status, [PaymentEventStatus::Processed, PaymentEventStatus::Review], true)
                || ($event->processing_status === PaymentEventStatus::Review && ! in_array($event->error, [PaymentReviewReason::TerminalReceipt->value, PaymentReviewReason::TimeUnverified->value], true))) {
                return true;
            }
        }

        return false;
    }

    private function authorizeOwner(User $actor, ?User $current): void
    {
        $time = CarbonImmutable::now('Asia/Jakarta')->format('H:i:s');
        if ((int) Auth::id() !== $actor->id || $current === null || ! $current->is_active || $current->role !== UserRole::Owner
            || $time < '09:00:00' || $time > '17:00:00') {
            throw new AuthorizationException('Active authenticated Owner recovery requires 09:00–17:00 WIB.');
        }
    }

    /** @param array<string, int|string|null> $before
     * @param  array<string, int|string|null>  $after
     */
    private function audit(Payment $payment, User $actor, string $action, array $before, array $after): void
    {
        $audit = new AuditLog;
        $audit->forceFill(['actor_type' => 'user', 'actor_user_id' => $actor->id, 'entity_type' => 'payment', 'entity_id' => $payment->id,
            'action' => $action, 'before_redacted' => $before, 'after_redacted' => $after, 'correlation_id' => (string) Str::uuid()])->save();
    }
}
