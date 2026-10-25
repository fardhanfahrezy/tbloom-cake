<?php

namespace App;

use App\Models\AuditLog;
use App\Models\DailyAvailability;
use App\Models\NotificationDelivery;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderStatus;
use App\Models\Payment;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use App\Models\UserRole;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use LogicException;

class CreateOrder
{
    public function __construct(private CheckoutDetails $details, private NotificationRecipientIdentity $recipients) {}

    /** @param array<string, mixed> $input */
    public function create(User $actor, array $input): Order
    {
        $input = $this->details->canonicalInput($input);
        $hash = hash('sha256', json_encode(Arr::except($input, ['idempotency_key']), JSON_THROW_ON_ERROR));
        $connection = DB::connection();
        if ($connection->getDriverName() !== 'mysql') {
            throw new LogicException('Checkout requires MySQL row locks.');
        }
        $session = $connection->selectOne('SELECT @@transaction_isolation AS isolation_level, @@session.time_zone AS time_zone');
        if ($session->isolation_level !== 'READ-COMMITTED' || $session->time_zone !== '+00:00') {
            throw new LogicException('Checkout requires READ COMMITTED and UTC on the database connection.');
        }
        $nested = $connection->transactionLevel() > 0;
        for ($attempt = 1; ; $attempt++) {
            try {
                return $connection->transaction(fn (): Order => $this->persist($actor, $input, $hash));
            } catch (QueryException $exception) {
                if ($nested || $attempt >= 3 || ! in_array($exception->errorInfo[1] ?? null, [1205, 1213], true)) {
                    throw $exception;
                }
                Sleep::usleep(random_int(20000, 60000) * $attempt);
            }
        }
    }

    /** @param array<string, mixed> $input */
    private function persist(User $actor, array $input, string $hash): Order
    {
        $customer = User::query()->whereKey($actor->id)->lockForUpdate()->first();
        if (! $customer || ! $customer->is_active || $customer->role !== UserRole::Customer) {
            throw new AuthorizationException('Checkout hanya untuk customer aktif.');
        }
        $existing = Order::query()->where('user_id', $customer->id)->where('idempotency_key', $input['idempotency_key'])->first();
        if ($existing) {
            $existing = Order::query()->whereKey($existing->id)->lockForUpdate()->firstOrFail();
            if (! hash_equals($existing->request_hash, $hash)) {
                abort(409, 'Kunci checkout sudah dipakai untuk detail pesanan yang berbeda.');
            }

            return $existing;
        }
        $product = Product::query()->whereKey($input['product_id'])->lockForUpdate()->first();
        $variant = ProductVariant::query()->where('product_id', $input['product_id'])->whereKey($input['product_variant_id'])->lockForUpdate()->first();
        if (! $product || ! $product->is_active || $product->archived_at !== null || ! $variant || ! $variant->is_active) {
            throw ValidationException::withMessages(['product_variant_id' => 'Produk atau ukuran sudah tidak tersedia.']);
        }
        $availability = DailyAvailability::query()->where('product_id', $product->id)->where('availability_date', $input['pickup_date'])->lockForUpdate()->first();
        if (! $availability) {
            $availability = DailyAvailability::create(['product_id' => $product->id, 'availability_date' => $input['pickup_date'], 'quota_limit' => $product->default_daily_quota]);
        }
        $instant = CarbonImmutable::now('UTC')->startOfSecond();
        $data = $this->details->validate($input, $instant);
        $held = Order::query()->where('user_id', $customer->id)->where('status', OrderStatus::Pending)
            ->whereHas('item', fn ($query) => $query->whereNull('quota_released_at'))->count();
        if ($held >= 3) {
            throw ValidationException::withMessages(['checkout' => 'Maksimal tiga pesanan pending yang masih menahan kuota.']);
        }
        $used = (int) OrderItem::query()->where('daily_availability_id', $availability->id)->whereNull('quota_released_at')->sum('quantity');
        if ($data['quantity'] > $availability->quota_limit - $used) {
            throw ValidationException::withMessages(['quantity' => 'Kuota untuk produk dan tanggal ini tidak mencukupi.']);
        }
        if ($variant->price < 1 || $data['quantity'] > intdiv(PHP_INT_MAX, $variant->price)) {
            throw ValidationException::withMessages(['quantity' => 'Total pesanan melebihi batas yang didukung.']);
        }
        $total = $variant->price * $data['quantity'];
        $timestamp = $instant->format('Y-m-d H:i:s');
        $order = new Order;
        $order->forceFill(Arr::only($data, ['pickup_date', 'pickup_time', 'pickup_method', 'handover_note', 'policy_version', 'policy_text_snapshot', 'courier_terms_accepted_at', 'idempotency_key']) + [
            'order_number' => 'TB-'.Str::uuid(), 'user_id' => $customer->id, 'status' => 'pending',
            'customer_name_snapshot' => $customer->name, 'email_snapshot' => $customer->email, 'whatsapp_snapshot' => $customer->whatsapp_number,
            'product_total' => $total, 'payment_expires_at' => $instant->addMinutes(30), 'request_hash' => $hash,
            'created_at' => $timestamp, 'updated_at' => $timestamp,
        ])->save();
        $item = new OrderItem;
        $item->forceFill(Arr::only($data, ['flavor_code', 'flavor_label_snapshot', 'filling_code', 'filling_label_snapshot', 'cover_color', 'inscription', 'quantity']) + [
            'order_id' => $order->id, 'product_id' => $product->id, 'product_variant_id' => $variant->id, 'daily_availability_id' => $availability->id,
            'product_name_snapshot' => $product->name, 'size_snapshot' => $variant->size_label, 'sales_unit_snapshot' => $product->sales_unit_label,
            'unit_price' => $variant->price, 'line_total' => $total, 'created_at' => $timestamp,
        ])->save();
        $payment = new Payment;
        $payment->forceFill(['order_id' => $order->id, 'provider' => 'midtrans', 'merchant_reference' => $order->order_number,
            'expected_amount' => $total, 'currency' => 'IDR', 'status' => 'pending', 'created_at' => $timestamp, 'updated_at' => $timestamp])->save();
        $audit = new AuditLog;
        $audit->forceFill(['actor_user_id' => $customer->id, 'actor_type' => 'user', 'entity_type' => 'order', 'entity_id' => $order->id,
            'action' => 'order.created', 'after_redacted' => ['status' => 'pending', 'product_total' => $total, 'quantity' => $data['quantity']],
            'correlation_id' => (string) Str::uuid(), 'created_at' => $timestamp])->save();
        $this->outbox($order, $customer, 'customer.order_created.v1', $timestamp);
        foreach (User::query()->where('is_active', true)->whereIn('role', [UserRole::Admin, UserRole::Owner])->orderBy('id')->get() as $staff) {
            $this->outbox($order, $staff, 'admin.new_order.v1', $timestamp);
        }

        return $order;
    }

    private function outbox(Order $order, User $recipient, string $template, string $timestamp): void
    {
        foreach ([NotificationChannel::Email, NotificationChannel::WhatsApp] as $channel) {
            $address = $channel === NotificationChannel::Email ? $recipient->email : $recipient->whatsapp_number;
            $delivery = new NotificationDelivery;
            $delivery->forceFill(['event_key' => 'order:'.$order->id.':created', 'recipient_key' => $this->recipients->key($channel, $address),
                'recipient_ciphertext' => ['channel' => $channel->value, 'address' => $address], 'template_key' => $template,
                'entity_type' => 'order', 'entity_id' => $order->id, 'payload_minimal' => ['order_number' => $order->order_number, 'status' => 'pending'],
                'status' => 'pending', 'created_at' => $timestamp, 'updated_at' => $timestamp])->save();
        }
    }
}
