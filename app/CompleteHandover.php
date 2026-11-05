<?php

namespace App;

use App\Models\AuditLog;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderRequest;
use App\Models\OrderRequestStatus;
use App\Models\OrderRequestType;
use App\Models\OrderStatus;
use App\Models\Payment;
use App\Models\PaymentStatus;
use App\Models\RefundLog;
use App\Models\RefundTransfer;
use App\Models\User;
use App\Models\UserRole;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use LogicException;

class CompleteHandover
{
    public function __construct(private VerifiedPaidOrder $verifiedPaidOrder, private PaymentNotices $notices) {}

    /** @return array<string, list<string>> */
    public static function rules(): array
    {
        return ['order_number' => ['required', 'string', 'max:40'], 'recipient_confirmed' => ['accepted'], 'handover_confirmed' => ['accepted'],
            'expected_version' => ['required', 'string', 'regex:/^[a-f0-9]{64}$/D'],
            'idempotency_key' => ['required', 'string', 'max:128', 'regex:/^[A-Za-z0-9][A-Za-z0-9._:-]*$/D']];
    }

    public function version(Order $order): string
    {
        $data = Arr::only($order->attributesToArray(), ['id', 'order_number', 'user_id', 'status', 'customer_name_snapshot', 'whatsapp_snapshot',
            'pickup_date', 'pickup_time', 'pickup_method', 'handover_note']);
        $data['item'] = $order->item ? Arr::only($order->item->attributesToArray(), ['id', 'product_id', 'product_variant_id', 'quantity',
            'product_name_snapshot', 'size_snapshot', 'sales_unit_snapshot', 'flavor_label_snapshot', 'filling_label_snapshot', 'cover_color', 'inscription']) : null;
        $data['audit_id'] = AuditLog::query()->where('entity_type', 'order')->where('entity_id', $order->id)->max('id');

        return hash('sha256', json_encode($data, JSON_THROW_ON_ERROR));
    }

    /** @param array<string, mixed> $input */
    public function complete(int $id, User $actor, array $input): Order
    {
        $data = Validator::make($input, self::rules())->validate();
        $data['recipient_confirmed'] = true;
        $data['handover_confirmed'] = true;
        $connection = DB::connection();
        if ($connection->getDriverName() !== 'mysql' || $connection->transactionLevel() !== 0) {
            throw new LogicException('Handover requires an independent MySQL transaction.');
        }
        $session = $connection->selectOne('SELECT @@transaction_isolation AS isolation_level, @@session.time_zone AS time_zone');
        if ($session->isolation_level !== 'READ-COMMITTED' || $session->time_zone !== '+00:00') {
            throw new LogicException('Handover requires READ COMMITTED and UTC.');
        }
        $snapshot = Order::query()->with(['item', 'payment'])->findOrFail($id);
        $key = hash('sha256', $data['idempotency_key']);
        $intent = hash('sha256', json_encode(Arr::except($data, ['idempotency_key']), JSON_THROW_ON_ERROR));

        return DB::transaction(function () use ($snapshot, $actor, $data, $key, $intent): Order {
            $users = User::query()->whereIn('id', [$actor->id, $snapshot->user_id])->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $order = Order::query()->whereKey($snapshot->id)->lockForUpdate()->firstOrFail();
            $payment = Payment::query()->where('order_id', $order->id)->lockForUpdate()->first();
            $requests = OrderRequest::query()->where('order_id', $order->id)->orderBy('id')->lockForUpdate()->get();
            $refund = $payment ? RefundLog::query()->where('payment_id', $payment->id)->lockForUpdate()->first() : null;
            $transfers = $payment ? RefundTransfer::query()->where('payment_id', $payment->id)->orderBy('id')->lockForUpdate()->get() : collect();
            $item = OrderItem::query()->where('order_id', $order->id)->lockForUpdate()->first();
            $current = $users->get($actor->id);
            if ((int) Auth::id() !== $actor->id || ! $current || ! $current->is_active || ! in_array($current->role, [UserRole::Admin, UserRole::Owner], true)) {
                throw new AuthorizationException('Penyerahan hanya untuk staf aktif yang sedang masuk.');
            }
            abort_unless($order->user_id === $snapshot->user_id && $order->order_number === $data['order_number'], 409, 'Nomor pesanan atau pemilik sudah tidak sesuai.');
            $prior = AuditLog::query()->where('entity_type', 'order')->where('entity_id', $order->id)->where('action', 'order.completed')
                ->where('after_redacted->operation_key_hash', $key)->first();
            if ($prior) {
                abort_unless($order->status === OrderStatus::Completed && $order->completed_at !== null && $order->handed_over_by === $current->id
                    && $prior->actor_user_id === $current->id && hash_equals($prior->after_redacted['intent_hash'], $intent), 409);

                return $order;
            }
            $now = CarbonImmutable::now('UTC')->startOfSecond();
            $time = $now->setTimezone('Asia/Jakarta')->format('H:i:s');
            if ($time < '09:00:00' || $time > '21:00:00') {
                throw new AuthorizationException('Penyerahan hanya pada 09.00–21.00 WIB.');
            }
            $order->setRelation('item', $item);
            abort_unless(hash_equals($this->version($order), $data['expected_version']), 409, 'Detail pengambil atau pesanan berubah. Muat ulang checklist.');
            abort_if($requests->contains(fn (OrderRequest $request): bool => $request->type === OrderRequestType::Cancellation && $request->status === OrderRequestStatus::Pending), 409, 'Putuskan pengajuan pembatalan sebelum penyerahan.');
            abort_unless($order->status === OrderStatus::Paid && $order->paid_at !== null && $order->completed_at === null && $order->handed_over_by === null
                && $item !== null && $item->quota_released_at === null && $item->quota_release_reason === null && $item->line_total === $order->product_total
                && $payment !== null && $payment->status === PaymentStatus::Paid && $payment->review_reason === null && $payment->verified_at !== null
                && $payment->verified_at <= $now && $payment->provider_paid_at?->equalTo($order->paid_at) && $payment->is_late === false
                && $payment->provider === 'midtrans' && $payment->currency === 'IDR' && $payment->expected_amount === $order->product_total
                && $payment->merchant_reference === $order->order_number && $payment->provider_transaction_id !== null
                && $order->paid_at >= $order->created_at && $order->paid_at < $order->payment_expires_at && $order->paid_at <= $now
                && $refund === null && $transfers->isEmpty() && $this->verifiedPaidOrder->allows($payment, $order, $now), 409, 'Pesanan atau pembayaran belum memenuhi syarat penyerahan.');
            $order->forceFill(['status' => OrderStatus::Completed, 'completed_at' => $now, 'handed_over_by' => $current->id])->save();
            $audit = new AuditLog;
            $audit->forceFill(['actor_type' => 'user', 'actor_user_id' => $current->id, 'entity_type' => 'order', 'entity_id' => $order->id,
                'action' => 'order.completed', 'before_redacted' => ['status' => 'paid'],
                'after_redacted' => ['status' => 'completed', 'completed_at' => $now->toIso8601String(), 'handed_over_by' => $current->id,
                    'pickup_method' => $order->pickup_method->value, 'operation_key_hash' => $key, 'intent_hash' => $intent],
                'correlation_id' => (string) Str::uuid()])->save();
            $this->notices->completed($order);

            return $order;
        }, 3);
    }
}
