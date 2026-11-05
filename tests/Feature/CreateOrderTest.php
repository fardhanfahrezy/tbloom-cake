<?php

use App\CreateOrder;
use App\Models\AuditLog;
use App\Models\DailyAvailability;
use App\Models\NotificationDelivery;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\QuotaReleaseReason;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;

uses(LazilyRefreshDatabase::class);

beforeEach(function () {
    if (config('database.default') !== 'mysql_testing') {
        $this->markTestSkipped('Atomic checkout requires isolated MySQL.');
    }
    config(['notifications.recipient_hmac_key' => 'base64:'.base64_encode(str_repeat('c', 32)), 'notifications.previous_recipient_hmac_keys' => []]);
    $this->travelTo(CarbonImmutable::parse('2026-10-23 09:00:00', 'UTC'));
});

function createOrderInput(ProductVariant $variant, array $overrides = []): array
{
    return array_replace(['product_id' => $variant->product_id, 'product_variant_id' => $variant->id,
        'quantity' => 1, 'flavor_code' => 'chocolate', 'filling_code' => 'strawberry', 'cover_color' => 'Putih',
        'inscription_choice' => 'none', 'pickup_date' => '2026-10-24', 'pickup_time' => '09:00',
        'pickup_method' => 'self_pickup', 'idempotency_key' => 'order-test-1'], $overrides);
}

test('commits the order allocation payment audit and private outbox using server data', function () {
    Http::preventStrayRequests();
    Mail::fake();
    Queue::fake();
    $customer = User::factory()->create();
    $admin = User::factory()->admin()->create();
    User::factory()->admin()->inactive()->create();
    User::factory()->create();
    $product = Product::factory()->create(['sales_unit_label' => 'BOX']);
    $variant = ProductVariant::factory()->for($product)->create(['price' => 100001]);

    $this->actingAs($customer)->postJson('/checkout', createOrderInput($variant, [
        'quantity' => 3, 'unit_price' => 1, 'product_total' => 1, 'status' => 'paid', 'user_id' => $admin->id,
    ]))->assertCreated()->assertJsonPath('data.product_total', 300003)->assertJsonPath('data.status', 'pending');

    $order = Order::query()->sole();
    expect($order->user_id)->toBe($customer->id);
    expect($order->customer_name_snapshot)->toBe($customer->name);
    expect($order->payment_expires_at->format('Y-m-d H:i:s'))->toBe('2026-10-23 09:30:00');
    expect($order->item->quantity)->toBe(3);
    expect($order->item->sales_unit_snapshot)->toBe('BOX');
    expect($order->item->unit_price)->toBe(100001);
    expect($order->payment->expected_amount)->toBe(300003);
    expect($order->payment->merchant_reference)->toBe($order->order_number);
    expect($order->payment->snap_token)->toBeNull();
    $this->assertDatabaseCount('audit_logs', 1);
    $this->assertDatabaseCount('notification_deliveries', 4);
    $addresses = NotificationDelivery::all()->map(fn ($row) => $row->recipient_ciphertext['address'])->all();
    expect($addresses)->toEqualCanonicalizing([$customer->email, $customer->whatsapp_number, $admin->email, $admin->whatsapp_number]);
    expect(DB::table('notification_deliveries')->value('recipient_ciphertext'))->not->toContain($customer->email);
    expect(json_encode(NotificationDelivery::first()->payload_minimal))->not->toContain($customer->email, $customer->whatsapp_number);
    Http::assertNothingSent();
    Mail::assertNothingSent();
    Queue::assertNothingPushed();
});

test('shares capacity across sizes and keeps overdue pending and completed allocations held', function () {
    $product = Product::factory()->create();
    $first = ProductVariant::factory()->for($product)->create(['price' => 100000]);
    $second = ProductVariant::factory()->for($product)->create(['price' => 200000]);
    $availability = DailyAvailability::factory()->for($product)->create(['availability_date' => '2026-10-24', 'quota_limit' => 5]);
    $completed = Order::factory()->completed()->create(['pickup_date' => '2026-10-24']);
    OrderItem::factory()->create(['order_id' => $completed->id, 'product_variant_id' => $first->id, 'daily_availability_id' => $availability->id, 'quantity' => 2]);
    $pending = Order::factory()->create(['pickup_date' => '2026-10-24', 'created_at' => '2026-10-23 08:00:00']);
    OrderItem::factory()->create(['order_id' => $pending->id, 'product_variant_id' => $first->id, 'daily_availability_id' => $availability->id, 'quantity' => 2]);

    $customer = User::factory()->create();
    expect(fn () => app(CreateOrder::class)->create($customer, createOrderInput($second, ['quantity' => 2])))->toThrow(ValidationException::class);

    $this->assertDatabaseCount('orders', 2);
    $this->assertDatabaseCount('payments', 0);
    $this->assertDatabaseCount('audit_logs', 0);
    $this->assertDatabaseCount('notification_deliveries', 0);
});

test('rolls back every checkout write if the outbox cannot be constructed', function () {
    $customer = User::factory()->create();
    $variant = ProductVariant::factory()->create();
    config(['notifications.recipient_hmac_key' => null]);

    expect(fn () => app(CreateOrder::class)->create($customer, createOrderInput($variant)))->toThrow(LogicException::class);

    foreach (['orders', 'order_items', 'payments', 'audit_logs', 'notification_deliveries', 'daily_availabilities'] as $table) {
        $this->assertDatabaseCount($table, 0);
    }
});

test('denies a fourth held pending order including payment review after the deadline', function () {
    $customer = User::factory()->create();
    $variant = ProductVariant::factory()->create();
    for ($number = 0; $number < 3; $number++) {
        $item = OrderItem::factory()->for(Order::factory()->for($customer)->create(['created_at' => '2026-10-23 08:00:00']))->create();
        Payment::factory()->for($item->order)->create(['review_reason' => 'unknown_time']);
    }

    $this->actingAs($customer)->postJson('/checkout', createOrderInput($variant))->assertUnprocessable()->assertJsonValidationErrors('checkout');

    $this->assertDatabaseCount('orders', 3);
    $this->assertDatabaseCount('audit_logs', 0);
});

test('rejects inactive archived or mismatched catalog choices without writes', function (string $change) {
    $customer = User::factory()->create();
    $variant = ProductVariant::factory()->create();
    $input = createOrderInput($variant);
    match ($change) {
        'product' => $variant->product->update(['is_active' => false]),
        'archived' => $variant->product->update(['archived_at' => now()]),
        'variant' => $variant->update(['is_active' => false]),
        'mismatch' => $input['product_id'] = Product::factory()->create()->id,
        'missing' => $input['product_variant_id'] = 999999,
    };

    $this->actingAs($customer)->postJson('/checkout', $input)->assertUnprocessable()->assertJsonValidationErrors('product_variant_id');

    $this->assertDatabaseCount('orders', 0);
    $this->assertDatabaseCount('daily_availabilities', 0);
})->with(['product', 'archived', 'variant', 'mismatch', 'missing']);

test('rechecks customer authorization against locked current account state', function (string $change) {
    $customer = User::factory()->create();
    $variant = ProductVariant::factory()->create();
    DB::table('users')->where('id', $customer->id)->update($change === 'inactive' ? ['is_active' => false] : ['role' => 'admin']);

    expect(fn () => app(CreateOrder::class)->create($customer, createOrderInput($variant)))->toThrow(AuthorizationException::class);

    $this->assertDatabaseCount('orders', 0);
})->with(['inactive', 'role']);

test('excludes paid and explicitly released pending allocations from the pending limit', function () {
    $customer = User::factory()->create();
    OrderItem::factory()->for(Order::factory()->for($customer)->paid())->create();
    OrderItem::factory()->for(Order::factory()->for($customer))->released(QuotaReleaseReason::Expired)->create();
    for ($number = 0; $number < 2; $number++) {
        OrderItem::factory()->for(Order::factory()->for($customer))->create();
    }
    $variant = ProductVariant::factory()->create();

    $this->actingAs($customer)->postJson('/checkout', createOrderInput($variant))->assertCreated();

    $this->assertDatabaseCount('orders', 5);
});

test('rejects a closed date and arithmetic overflow without partial writes', function (string $case) {
    $customer = User::factory()->create();
    $product = Product::factory()->create(['default_daily_quota' => $case === 'closed' ? 0 : 5]);
    $variant = ProductVariant::factory()->for($product)->create(['price' => $case === 'overflow' ? PHP_INT_MAX : 100000]);

    $this->actingAs($customer)->postJson('/checkout', createOrderInput($variant, ['quantity' => 2]))
        ->assertUnprocessable()->assertJsonValidationErrors('quantity');

    foreach (['orders', 'order_items', 'payments', 'audit_logs', 'notification_deliveries', 'daily_availabilities'] as $table) {
        $this->assertDatabaseCount($table, 0);
    }
})->with(['closed', 'overflow']);

test('uses the decision clock after waiting on catalog locks', function () {
    $customer = User::factory()->create();
    $variant = ProductVariant::factory()->create();
    $this->travelTo(CarbonImmutable::parse('2026-10-23 10:00:00', 'UTC'));
    DB::listen(function ($query): void {
        if (str_contains($query->sql, 'daily_availabilities') && str_contains($query->sql, 'for update')) {
            $this->travelTo(CarbonImmutable::parse('2026-10-23 10:00:01', 'UTC'));
        }
    });

    $this->actingAs($customer)->postJson('/checkout', createOrderInput($variant))
        ->assertUnprocessable()->assertJsonValidationErrors('pickup_date');

    $this->assertDatabaseCount('orders', 0);
    $this->assertDatabaseCount('daily_availabilities', 0);
});

test('rolls back if a mandatory child insert fails', function (string $model) {
    $customer = User::factory()->create();
    $variant = ProductVariant::factory()->create();
    $event = 'eloquent.creating: '.$model;
    Event::listen($event, function (): never {
        throw new RuntimeException('Synthetic write failure.');
    });
    try {
        expect(fn () => app(CreateOrder::class)->create($customer, createOrderInput($variant)))->toThrow(RuntimeException::class);
    } finally {
        Event::forget($event);
    }

    foreach (['orders', 'order_items', 'payments', 'audit_logs', 'notification_deliveries', 'daily_availabilities'] as $table) {
        $this->assertDatabaseCount($table, 0);
    }
})->with([OrderItem::class, Payment::class, AuditLog::class, NotificationDelivery::class]);

test('requires authentication and the customer role on the checkout endpoint', function (string $kind, int $status) {
    $variant = ProductVariant::factory()->create();
    if ($kind !== 'guest') {
        $user = User::factory()->create(['role' => $kind === 'inactive' ? 'customer' : $kind, 'is_active' => $kind !== 'inactive']);
        $this->actingAs($user);
    }

    $this->postJson('/checkout', createOrderInput($variant))->assertStatus($status);

    $this->assertDatabaseCount('orders', 0);
})->with([['guest', 401], ['admin', 403], ['owner', 403], ['inactive', 401]]);

test('replays an identical checkout after the deadline calendar and catalog have changed without new effects', function () {
    $customer = User::factory()->create(['name' => 'Pembeli awal']);
    $variant = ProductVariant::factory()->create(['price' => 100001, 'size_label' => '16 cm']);
    $input = createOrderInput($variant, ['quantity' => 2]);
    $original = app(CreateOrder::class)->create($customer, $input);
    $snapshots = $original->fresh()->load(['item', 'payment'])->toArray();
    $customer->update(['name' => 'Pembeli berubah', 'email' => 'changed@example.test']);
    $variant->update(['price' => 200000, 'size_label' => '20 cm', 'is_active' => false]);
    $variant->product->update(['name' => 'Katalog berubah', 'is_active' => false, 'archived_at' => now()]);
    config(['checkout.flavors' => [], 'checkout.fillings' => []]);
    $this->travelTo(CarbonImmutable::parse('2026-10-25 10:00:01', 'UTC'));

    $this->actingAs($customer)->postJson('/checkout', $input)->assertOk()->assertJsonPath('data.id', $original->id)
        ->assertJsonPath('data.product_total', 200002)->assertJsonPath('data.payment_expires_at', '2026-10-23T09:30:00+00:00');

    expect($original->fresh()->load(['item', 'payment'])->toArray())->toBe($snapshots);
    $this->assertDatabaseCount('orders', 1);
    $this->assertDatabaseCount('order_items', 1);
    $this->assertDatabaseCount('payments', 1);
    $this->assertDatabaseCount('audit_logs', 1);
    $this->assertDatabaseCount('notification_deliveries', 2);
});

test('rejects a reused key with a different payload even after the original date is past', function () {
    $customer = User::factory()->create();
    $variant = ProductVariant::factory()->create();
    $input = createOrderInput($variant);
    $original = app(CreateOrder::class)->create($customer, $input);
    $this->travelTo(CarbonImmutable::parse('2026-10-25 10:00:01', 'UTC'));

    $this->actingAs($customer)->postJson('/checkout', array_replace($input, ['quantity' => 2]))->assertConflict();

    expect($original->fresh()->item->quantity)->toBe(1);
    $this->assertDatabaseCount('payments', 1);
    $this->assertDatabaseCount('audit_logs', 1);
});

test('normalizes equivalent input representations for idempotent replay and scopes the key by customer', function () {
    $customer = User::factory()->create();
    $other = User::factory()->create();
    $variant = ProductVariant::factory()->create();
    $input = createOrderInput($variant, ['inscription_choice' => 'text', 'inscription' => " Selamat\tulang hari "]);
    $original = app(CreateOrder::class)->create($customer, $input);
    $equivalent = array_reverse(array_replace($input, ['quantity' => '1', 'product_id' => (string) $variant->product_id,
        'inscription' => 'Selamat ulang hari', 'handover_note' => null]), true);

    $this->actingAs($customer)->postJson('/checkout', $equivalent)->assertOk()->assertJsonPath('data.id', $original->id);
    $this->actingAs($other)->postJson('/checkout', $input)->assertCreated();

    $this->assertDatabaseCount('orders', 2);
    expect(Order::query()->pluck('user_id')->all())->toEqualCanonicalizing([$customer->id, $other->id]);
    expect(Payment::query()->distinct()->count('merchant_reference'))->toBe(2);
});

test('returns the current paid state on replay without reopening the payment window', function () {
    $customer = User::factory()->create();
    $variant = ProductVariant::factory()->create();
    $input = createOrderInput($variant);
    $order = app(CreateOrder::class)->create($customer, $input);
    $order->forceFill(['status' => 'paid', 'paid_at' => '2026-10-23 09:10:00'])->save();
    $order->payment->forceFill(['status' => 'paid', 'provider_paid_at' => '2026-10-23 09:10:00', 'verified_at' => '2026-10-23 09:11:00', 'is_late' => false])->save();
    $this->travelTo(CarbonImmutable::parse('2026-10-23 10:00:00', 'UTC'));

    $this->actingAs($customer)->postJson('/checkout', $input)->assertOk()->assertJsonPath('data.status', 'paid')
        ->assertJsonPath('data.payment_expires_at', '2026-10-23T09:30:00+00:00');

    expect($order->payment->fresh()->status->value)->toBe('paid');
    $this->assertDatabaseCount('payments', 1);
    $this->assertDatabaseCount('audit_logs', 1);
});
