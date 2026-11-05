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
use App\Models\PaymentMovement;
use App\Models\PaymentMovementCorrection;
use App\Models\PaymentMovementKind;
use App\Models\PaymentMovementSource;
use App\Models\PaymentStatus;
use App\Models\QuotaReleaseReason;
use App\Models\RefundLog;
use App\Models\RefundReasonType;
use App\Models\RefundTransfer;
use App\Models\User;
use App\Models\UserRole;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;
use InvalidArgumentException;
use LogicException;
use OverflowException;

class ResolvePaymentException
{
    public function __construct(private MidtransBankTransferStatus $provider, private PaymentFunds $funds, private PaymentNotices $notices) {}

    /**
     * Explicit Owner reconciliation creates an obligation, never a bank transfer.
     * The private evidence reference is attested by the caller and only hashed in audit.
     */
    public function resolve(int $paymentId, User $actor, string $evidenceReference): bool
    {
        $this->authorize($actor, $actor->fresh());
        if (! preg_match('/\A[\x21-\x7e]{1,128}\z/', $evidenceReference)) {
            throw new InvalidArgumentException('A private reconciliation evidence reference is required.');
        }
        $connection = DB::connection();
        if ($connection->getDriverName() !== 'mysql' || $connection->transactionLevel() !== 0) {
            throw new LogicException('Exception resolution requires MySQL and no caller transaction.');
        }
        $settings = $connection->selectOne('SELECT @@transaction_isolation AS isolation_level, @@session.time_zone AS time_zone');
        if ($settings->isolation_level !== 'READ-COMMITTED' || $settings->time_zone !== '+00:00') {
            throw new LogicException('Exception resolution requires READ COMMITTED and UTC.');
        }
        $snapshot = Payment::query()->with('order.item')->findOrFail($paymentId);
        $order = $snapshot->order;
        $item = $order->item;
        if ($item === null || $snapshot->provider !== 'midtrans' || $snapshot->currency !== 'IDR' || $snapshot->provider_transaction_id === null
            || ! $this->withinResolutionWindow($order)) {
            return false;
        }
        $observation = $this->provider->observeActual($snapshot->merchant_reference, $order->payment_expires_at, $snapshot->provider_transaction_id);
        for ($attempt = 1; ; $attempt++) {
            try {
                return $connection->transaction(fn (): bool => $this->persist($snapshot, $order, $item, $actor, $evidenceReference, $observation));
            } catch (QueryException $exception) {
                if ($attempt >= 3 || ! in_array((int) ($exception->errorInfo[1] ?? 0), [1205, 1213], true)) {
                    throw $exception;
                }
                Sleep::for(random_int(20, 60) * $attempt)->milliseconds();
            }
        }
    }

    private function persist(Payment $snapshot, Order $orderSnapshot, OrderItem $itemSnapshot, User $actor, string $evidenceReference, BankTransferObservation $observation): bool
    {
        $users = User::query()->whereIn('id', [$orderSnapshot->user_id, $actor->id])->orderBy('id')->lockForUpdate()->get()->keyBy('id');
        $this->authorize($actor, $users->get($actor->id));
        $availability = DailyAvailability::query()->whereKey($itemSnapshot->daily_availability_id)->lockForUpdate()->firstOrFail();
        $order = Order::query()->whereKey($orderSnapshot->id)->lockForUpdate()->firstOrFail();
        $payment = Payment::query()->whereKey($snapshot->id)->lockForUpdate()->firstOrFail();
        $requests = OrderRequest::query()->where('order_id', $order->id)->orderBy('id')->lockForUpdate()->get();
        $refund = RefundLog::query()->where('payment_id', $payment->id)->lockForUpdate()->first();
        RefundTransfer::query()->where('payment_id', $payment->id)->orderBy('id')->lockForUpdate()->get();
        $item = OrderItem::query()->whereKey($itemSnapshot->id)->lockForUpdate()->firstOrFail();

        if ($payment->order_id !== $order->id || $order->user_id !== $orderSnapshot->user_id || $item->order_id !== $order->id
            || $item->daily_availability_id !== $availability->id || $item->product_id !== $availability->product_id
            || $payment->provider !== $snapshot->provider || $payment->currency !== $snapshot->currency
            || $payment->merchant_reference !== $snapshot->merchant_reference || $payment->provider_transaction_id !== $snapshot->provider_transaction_id
            || $payment->expected_amount !== $snapshot->expected_amount || $order->product_total !== $payment->expected_amount
            || ! $order->payment_expires_at->equalTo($orderSnapshot->payment_expires_at) || ! $this->withinResolutionWindow($order)
            || $observation->outcome !== BankTransferOutcome::SuccessObserved || $observation->transactionId !== $payment->provider_transaction_id
            || $observation->amount === null || $observation->transactionTime === null || $observation->settlementTime === null
            || $observation->transactionTime < $order->created_at || $observation->settlementTime > CarbonImmutable::now('UTC')) {
            return false;
        }
        $key = PaymentMovementKey::fromIdentity('midtrans:bca:receipt:v1', [$observation->transactionId]);
        $receipt = PaymentMovement::query()->where('payment_id', $payment->id)->where('canonical_key', $key)->first();
        $source = $receipt?->sourceEvent;
        if ($receipt === null || $receipt->kind !== PaymentMovementKind::Receipt || $receipt->source !== PaymentMovementSource::GatewayReceipt
            || $receipt->amount !== $observation->amount || ! $receipt->occurred_at->equalTo($observation->settlementTime)
            || $source === null || $source->verified_at === null || $source->payment_id !== $payment->id
            || $source->provider !== $payment->provider || $source->provider_reference !== $payment->merchant_reference
            || PaymentMovementCorrection::query()->whereIn('payment_movement_id', PaymentMovement::query()->select('id')->where('payment_id', $payment->id))->exists()) {
            return false;
        }
        try {
            $funds = $this->funds->snapshot($payment);
        } catch (OverflowException) {
            return false;
        }
        if ($funds['receipts'] !== $observation->amount || $funds['excess_returns'] || $funds['reserved'] > 0) {
            return false;
        }
        if ($payment->provider_paid_at !== null && ($payment->provider_paid_at < $observation->transactionTime
            || $payment->provider_paid_at > $observation->settlementTime)) {
            return false;
        }
        $anomaly = $observation->amount !== $payment->expected_amount;
        $reason = $anomaly ? RefundReasonType::AmountAnomaly : RefundReasonType::LatePayment;
        if (! $anomaly && ($payment->provider_paid_at === null || $payment->is_late !== true
            || $payment->provider_paid_at <= $order->payment_expires_at)) {
            return false;
        }
        if ($refund !== null) {
            return $refund->reason_type === $reason && $refund->percentage === 100 && $refund->basis_amount === $funds['receipts']
                && $refund->amount === $funds['receipts'] && $payment->review_reason === null
                && in_array($order->status, [OrderStatus::Expired, OrderStatus::Cancelled], true) && $item->quota_released_at !== null;
        }
        $expectedReview = $anomaly ? PaymentReviewReason::AmountAnomaly : PaymentReviewReason::ActualLate;
        if ($order->status !== OrderStatus::Pending || $payment->closure_result !== null || $item->quota_released_at !== null
            || $payment->review_reason !== $expectedReview->value || $funds['returns'] >= $funds['receipts']) {
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
        $instant = CarbonImmutable::now('UTC')->startOfSecond();
        $order->forceFill($cancellation === null
            ? ['status' => OrderStatus::Expired, 'expired_at' => $instant]
            : ['status' => OrderStatus::Cancelled, 'cancelled_at' => $instant, 'cancellation_reason' => 'payment_exception_resolved'])->save();
        $item->forceFill(['quota_released_at' => $instant, 'quota_release_reason' => $cancellation === null ? QuotaReleaseReason::Expired : QuotaReleaseReason::Cancelled])->save();
        $refund = new RefundLog;
        $refund->forceFill(['order_id' => $order->id, 'payment_id' => $payment->id, 'reason_type' => $reason,
            'basis_amount' => $funds['receipts'], 'percentage' => 100, 'amount' => $funds['receipts']])->save();
        $payment->forceFill(['review_reason' => null, 'verified_at' => $instant,
            'status' => $funds['returns'] > 0 ? PaymentStatus::PartiallyRefunded : PaymentStatus::Paid])->save();
        if ($cancellation !== null) {
            $cancellation->forceFill(['status' => OrderRequestStatus::Applied, 'reviewed_at' => $instant, 'reviewed_by' => $actor->id,
                'applied_at' => $instant, 'decision_reason' => 'payment_exception_resolved'])->save();
        }
        $audit = new AuditLog;
        $audit->forceFill(['actor_type' => 'user', 'actor_user_id' => $actor->id, 'entity_type' => 'payment', 'entity_id' => $payment->id,
            'action' => 'payment.exception_resolved', 'correlation_id' => (string) Str::uuid(),
            'after_redacted' => ['reason' => $reason->value, 'receipt_id' => $receipt->id, 'refund_log_id' => $refund->id,
                'gross_amount' => $refund->amount, 'returns' => $funds['returns'], 'order_status' => $order->status->value,
                'request_id' => $cancellation?->id, 'evidence_sha256' => hash('sha256', $evidenceReference)]])->save();
        $this->notices->refundPending($order);

        return true;
    }

    private function authorize(User $actor, ?User $current): void
    {
        if ((int) Auth::id() !== $actor->id || $current === null || ! $current->is_active || $current->role !== UserRole::Owner) {
            throw new AuthorizationException('Active authenticated Owner reconciliation required.');
        }
    }

    private function withinResolutionWindow(Order $order): bool
    {
        $instant = CarbonImmutable::now('UTC')->startOfSecond();
        $localTime = $instant->setTimezone('Asia/Jakarta')->format('H:i:s');

        return $instant >= $order->payment_expires_at && $localTime >= '09:00:00' && $localTime <= '17:00:00';
    }
}
