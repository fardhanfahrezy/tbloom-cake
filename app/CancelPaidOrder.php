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
use App\Models\PaymentStatus;
use App\Models\QuotaReleaseReason;
use App\Models\RefundLog;
use App\Models\RefundLogStatus;
use App\Models\RefundReasonType;
use App\Models\RefundTransfer;
use App\Models\User;
use App\Models\UserRole;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;
use LogicException;
use OverflowException;

class CancelPaidOrder
{
    public function __construct(private PaymentFunds $funds, private PaymentNotices $notices, private VerifiedPaidOrder $verifiedPaidOrder) {}

    public function approve(int $requestId, User $actor, string $reason): ?RefundLog
    {
        $this->authorize($actor, $actor->fresh());
        $request = OrderRequest::query()->with('order.item', 'order.payment')->findOrFail($requestId);

        return $this->execute($request->order, $request->id, $actor, $reason, RefundReasonType::CustomerCancel);
    }

    public function byStore(int $orderId, User $actor, string $reason): ?RefundLog
    {
        $this->authorize($actor, $actor->fresh());

        return $this->execute(Order::query()->with('item', 'payment')->findOrFail($orderId), null, $actor, $reason, RefundReasonType::StoreCancel);
    }

    private function execute(Order $snapshot, ?int $requestId, User $actor, string $reason, RefundReasonType $cause): ?RefundLog
    {
        $reason = trim(Validator::make(['reason' => $reason], CancellationRequests::reasonRules())->validate()['reason']);
        $connection = DB::connection();
        if ($connection->getDriverName() !== 'mysql' || $connection->transactionLevel() !== 0) {
            throw new LogicException('Paid cancellation requires MySQL and no caller transaction.');
        }
        $settings = $connection->selectOne('SELECT @@transaction_isolation AS isolation_level, @@session.time_zone AS time_zone');
        if ($settings->isolation_level !== 'READ-COMMITTED' || $settings->time_zone !== '+00:00') {
            throw new LogicException('Paid cancellation requires READ COMMITTED and UTC.');
        }
        if ($snapshot->item === null || $snapshot->payment === null) {
            return null;
        }
        for ($attempt = 1; ; $attempt++) {
            try {
                return $connection->transaction(fn (): ?RefundLog => $this->persist($snapshot, $requestId, $actor, $reason, $cause));
            } catch (QueryException $exception) {
                if ($attempt >= 3 || ! in_array((int) ($exception->errorInfo[1] ?? 0), [1205, 1213], true)) {
                    throw $exception;
                }
                Sleep::for(random_int(20, 60) * $attempt)->milliseconds();
            }
        }
    }

    private function persist(Order $snapshot, ?int $requestId, User $actor, string $reason, RefundReasonType $cause): ?RefundLog
    {
        $users = User::query()->whereIn('id', [$actor->id, $snapshot->user_id])->orderBy('id')->lockForUpdate()->get()->keyBy('id');
        $availability = DailyAvailability::query()->whereKey($snapshot->item->daily_availability_id)->lockForUpdate()->firstOrFail();
        $order = Order::query()->whereKey($snapshot->id)->lockForUpdate()->firstOrFail();
        $payment = Payment::query()->whereKey($snapshot->payment->id)->lockForUpdate()->firstOrFail();
        $requests = OrderRequest::query()->where('order_id', $order->id)->orderBy('id')->lockForUpdate()->get();
        $refund = RefundLog::query()->where('payment_id', $payment->id)->lockForUpdate()->first();
        $transfers = RefundTransfer::query()->where('payment_id', $payment->id)->orderBy('id')->lockForUpdate()->get();
        $item = OrderItem::query()->whereKey($snapshot->item->id)->lockForUpdate()->firstOrFail();
        $this->authorize($actor, $users->get($actor->id));
        if ($order->user_id !== $snapshot->user_id || $payment->order_id !== $order->id || $item->order_id !== $order->id
            || $item->daily_availability_id !== $availability->id || $item->product_id !== $availability->product_id
            || ! $availability->availability_date->equalTo($order->pickup_date) || ! $order->pickup_date->equalTo($snapshot->pickup_date)
            || $item->line_total !== $order->product_total || $payment->expected_amount !== $order->product_total
            || $order->product_total !== $snapshot->product_total || $item->quantity !== $snapshot->item->quantity
            || $payment->provider !== 'midtrans' || $payment->currency !== 'IDR' || $payment->provider_transaction_id === null
            || $payment->merchant_reference !== $snapshot->payment->merchant_reference || $payment->provider_transaction_id !== $snapshot->payment->provider_transaction_id
            || ! $order->payment_expires_at->equalTo($snapshot->payment_expires_at)) {
            return null;
        }
        $percentage = $cause === RefundReasonType::CustomerCancel ? 80 : 100;
        $amount = $percentage === 100 ? $order->product_total : intdiv($order->product_total, 100) * 80 + intdiv(($order->product_total % 100) * 80, 100);
        $request = $requestId === null ? null : $requests->firstWhere('id', $requestId);
        $reasonHash = hash('sha256', $reason);
        if ($refund !== null) {
            $original = $refund->order_id === $order->id && $refund->reason_type === $cause && $refund->percentage === $percentage
                && $refund->basis_amount === $order->product_total && $refund->amount === $amount
                && $order->status === OrderStatus::Cancelled && $order->cancellation_reason === $reason
                && $order->cancelled_at !== null && $item->quota_released_at?->equalTo($order->cancelled_at)
                && $item->quota_release_reason === QuotaReleaseReason::Cancelled
                && $requests->where('status', OrderRequestStatus::Pending)->isEmpty()
                && ($requestId === null || ($request !== null && $request->type === OrderRequestType::Cancellation
                    && $request->decision_mode === OrderRequestDecisionMode::Manual && $request->status === OrderRequestStatus::Applied
                    && $request->applied_at?->equalTo($order->cancelled_at)))
                && AuditLog::query()->where('entity_type', 'order')->where('entity_id', $order->id)->where('action', 'order.paid_cancelled')
                    ->where('after_redacted->refund_log_id', $refund->id)->where('after_redacted->reason_sha256', $reasonHash)
                    ->where('after_redacted->request_id', $requestId)->exists();

            return $original ? $refund : null;
        }
        $now = CarbonImmutable::now('UTC')->startOfSecond();
        if ($order->status !== OrderStatus::Paid || $order->paid_at === null || $item->quota_released_at !== null || $item->quota_release_reason !== null
            || $payment->status !== PaymentStatus::Paid || $payment->review_reason !== null || $payment->verified_at === null || $payment->verified_at > $now
            || $payment->provider_paid_at === null || ! $payment->provider_paid_at->equalTo($order->paid_at) || $payment->is_late !== false
            || $order->paid_at < $order->created_at || $order->paid_at >= $order->payment_expires_at || $order->paid_at > $now
            || $transfers->isNotEmpty() || ! $this->verifiedPaidOrder->allows($payment, $order, $now)) {
            return null;
        }
        try {
            $funds = $this->funds->snapshot($payment);
        } catch (OverflowException) {
            return null;
        }
        if ($funds['receipts'] !== $order->product_total || $funds['returns'] !== 0 || $funds['reserved'] !== 0 || $funds['excess_returns']) {
            return null;
        }
        if ($cause === RefundReasonType::CustomerCancel && ($request === null || $request->type !== OrderRequestType::Cancellation
            || $request->decision_mode !== OrderRequestDecisionMode::Manual || $request->status !== OrderRequestStatus::Pending
            || $request->user_id !== $order->user_id || $request->submitted_at < $order->created_at || $request->submitted_at > $now
            || $request->submitted_at->setTimezone('Asia/Jakarta')->toDateString() > $order->pickup_date->subDays(2)->toDateString())) {
            return null;
        }
        foreach ($requests as $pending) {
            if ($pending->status !== OrderRequestStatus::Pending) {
                continue;
            }
            if ($pending->decision_mode !== OrderRequestDecisionMode::Manual || ($requestId !== null && $pending->id !== $requestId)) {
                return null;
            }
        }
        $order->forceFill(['status' => OrderStatus::Cancelled, 'cancelled_at' => $now, 'cancellation_reason' => $reason])->save();
        $item->forceFill(['quota_released_at' => $now, 'quota_release_reason' => QuotaReleaseReason::Cancelled])->save();
        foreach ($requests as $pending) {
            if ($pending->status !== OrderRequestStatus::Pending) {
                continue;
            }
            $applied = $pending->id === $requestId;
            $pending->forceFill(['status' => $applied ? OrderRequestStatus::Applied : OrderRequestStatus::Rejected,
                'reviewed_by' => $actor->id, 'reviewed_at' => $now, 'applied_at' => $applied ? $now : null,
                'decision_reason' => $applied ? $reason : 'superseded_by_store_cancellation'])->save();
            $this->audit('order_request', $pending->id, $actor, $applied ? 'cancellation.applied' : 'order_request.superseded',
                ['status' => 'pending'], ['status' => $pending->status->value, 'reason_sha256' => $reasonHash]);
        }
        $refund = new RefundLog;
        $refund->forceFill(['order_id' => $order->id, 'payment_id' => $payment->id, 'reason_type' => $cause,
            'basis_amount' => $funds['receipts'], 'percentage' => $percentage, 'amount' => $amount,
            'status' => $amount === 0 ? RefundLogStatus::Completed : RefundLogStatus::Pending,
            'completed_by' => $amount === 0 ? $actor->id : null, 'completed_at' => $amount === 0 ? $now : null])->save();
        $this->audit('order', $order->id, $actor, 'order.paid_cancelled', ['status' => 'paid', 'quota_released_at' => null],
            ['status' => 'cancelled', 'refund_log_id' => $refund->id, 'request_id' => $requestId, 'cause' => $cause->value,
                'basis_amount' => $funds['receipts'], 'percentage' => $percentage, 'gross_obligation' => $amount, 'reason_sha256' => $reasonHash]);
        $this->notices->paidCancelled($order);
        if ($amount > 0) {
            $this->notices->refundPending($order);
        }

        return $refund;
    }

    private function authorize(User $actor, ?User $current): void
    {
        $time = CarbonImmutable::now('Asia/Jakarta')->format('H:i:s');
        if ((int) Auth::id() !== $actor->id || $current === null || ! $current->is_active || ! in_array($current->role, [UserRole::Admin, UserRole::Owner], true)
            || $time < '09:00:00' || $time > '17:00:00') {
            throw new AuthorizationException('Paid cancellation requires active staff during 09:00–17:00 WIB.');
        }
    }

    /** @param array<string, mixed> $before @param array<string, mixed> $after */
    private function audit(string $entityType, int $entityId, User $actor, string $action, array $before, array $after): void
    {
        $audit = new AuditLog;
        $audit->forceFill(['actor_type' => 'user', 'actor_user_id' => $actor->id, 'entity_type' => $entityType, 'entity_id' => $entityId,
            'action' => $action, 'before_redacted' => $before, 'after_redacted' => $after, 'correlation_id' => (string) Str::uuid()])->save();
    }
}
