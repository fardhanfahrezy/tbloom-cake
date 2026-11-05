<?php

use App\CreateOrder;
use App\Models\Order;
use App\Models\ProductVariant;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;

uses(LazilyRefreshDatabase::class);

beforeEach(function () {
    if (config('database.default') !== 'mysql_testing') {
        $this->markTestSkipped('Checkout pages require isolated MySQL.');
    }
    config(['notifications.recipient_hmac_key' => 'base64:'.base64_encode(str_repeat('p', 32)), 'notifications.previous_recipient_hmac_keys' => []]);
    $this->travelTo(CarbonImmutable::parse('2026-10-23 09:00:00', 'UTC'));
    $this->withoutVite();
});

function checkoutPageInput(ProductVariant $variant, array $overrides = []): array
{
    return array_replace(['product_id' => $variant->product_id, 'product_variant_id' => $variant->id, 'quantity' => 1,
        'flavor_code' => 'chocolate', 'filling_code' => 'strawberry', 'cover_color' => 'Putih', 'inscription_choice' => 'none',
        'pickup_date' => '2026-10-24', 'pickup_time' => '09:00', 'pickup_method' => 'self_pickup', 'idempotency_key' => 'page-checkout-1'], $overrides);
}

test('renders the checkout form with official choices identity pickup policy and unchecked consent', function () {
    $customer = User::factory()->create();
    $variant = ProductVariant::factory()->create(['price' => 100001]);

    $response = $this->actingAs($customer)->get('/checkout/'.$variant->product->slug.'?pickup_date=2026-10-24');

    $response->assertOk()->assertSee($customer->name)->assertSee($customer->email)->assertSee($customer->whatsapp_number)
        ->assertSee('Chocolate (brownies)')->assertSee('Vanilla Cream')->assertSee('Redvelvet (butter cake)')
        ->assertSee('Nama Kurir / Kode Booking / Catatan Penyerahan')->assertSee('09.00–21.00 WIB')
        ->assertSee('name="_token"', false)->assertSee('name="idempotency_key"', false)
        ->assertDontSee('name="shipping_address"', false)->assertDontSee('name="unit_price"', false);
    expect(preg_match('/<input[^>]*id="courier_terms_accepted"[^>]*>/', $response->getContent(), $match))->toBe(1);
    expect($match[0])->not->toContain('checked');
    $this->assertDatabaseCount('orders', 0);
    $this->assertDatabaseCount('daily_availabilities', 0);
});

test('redirects browser checkout to the owned order and displays immutable review details', function () {
    $customer = User::factory()->create();
    $variant = ProductVariant::factory()->create(['price' => 100001]);
    $response = $this->actingAs($customer)->post('/checkout', checkoutPageInput($variant, [
        'quantity' => 2, 'pickup_method' => 'online_courier', 'courier_terms_accepted' => 'on',
        'inscription_choice' => 'text', 'inscription' => '<script>alert(1)</script>',
    ]));
    $order = Order::query()->sole();

    $response->assertRedirect(route('orders.show', $order));
    $this->get(route('orders.show', $order).'?status=paid')->assertOk()->assertSee('Menunggu pembayaran')->assertSee('Rp200.002')
        ->assertSee('Chocolate (brownies)')->assertSee('Strawberry jam (selai)')->assertSee('09:00')
        ->assertSee('data-remaining-seconds="1800"', false)->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false)
        ->assertDontSee('<script>alert(1)</script>', false)->assertSee('Kurir online');
});

test('does not expose another customer order or trust a browser payment result', function () {
    $customer = User::factory()->create();
    $other = User::factory()->create();
    $variant = ProductVariant::factory()->create();
    $order = app(CreateOrder::class)->create($customer, checkoutPageInput($variant));

    $this->actingAs($other)->get('/orders/'.$order->id)->assertNotFound();
    $this->actingAs($customer)->get('/orders/'.$order->id.'?transaction_status=settlement&status=paid')
        ->assertOk()->assertSee('Menunggu pembayaran')->assertDontSee('Pembayaran terverifikasi');

    expect($order->fresh()->status->value)->toBe('pending');
});

test('keeps pending allocation when its display countdown reaches zero', function () {
    $customer = User::factory()->create();
    $variant = ProductVariant::factory()->create();
    $order = app(CreateOrder::class)->create($customer, checkoutPageInput($variant));
    $this->travelTo(CarbonImmutable::parse('2026-10-23 09:31:00', 'UTC'));

    $this->actingAs($customer)->get('/orders/'.$order->id)->assertOk()->assertSee('Menunggu kepastian pembayaran')
        ->assertSee('data-remaining-seconds="0"', false);

    expect($order->fresh()->status->value)->toBe('pending');
    expect($order->item->quota_released_at)->toBeNull();
});

test('requires customer authentication for the form and displays closed quota without a submit button', function () {
    $variant = ProductVariant::factory()->create();
    $url = '/checkout/'.$variant->product->slug;
    $this->get($url)->assertRedirect(route('login'));
    $this->actingAs(User::factory()->admin()->create())->get($url)->assertForbidden();
    $variant->product->update(['default_daily_quota' => 0]);

    $this->actingAs(User::factory()->create())->get($url)->assertOk()->assertSee('Kuota tanggal ini penuh')->assertDontSee('Buat pesanan');
});

test('discloses paid cancellation terms and warns when the Jakarta H2 cutoff has passed', function (string $instant, bool $passed) {
    $customer = User::factory()->create();
    $variant = ProductVariant::factory()->create();
    $this->travelTo(CarbonImmutable::parse($instant, 'UTC'));

    $response = $this->actingAs($customer)->get('/checkout/'.$variant->product->slug.'?pickup_date=2026-10-25');

    $response->assertOk()->assertSee('23-10-2026 23:59:59 WIB')->assertSee('80%')->assertSee('persetujuan admin');
    if ($passed) {
        $response->assertSee('Pengambilan besok: batas pembatalan customer untuk pesanan yang sudah dibayar telah lewat.');
    } else {
        $response->assertDontSee('batas pembatalan customer untuk pesanan yang sudah dibayar telah lewat.');
    }
})->with([['2026-10-23 16:59:59.999999', false], ['2026-10-23 17:00:00', true]]);

test('shows the same cancellation disclosure on the owned payment review page', function () {
    $customer = User::factory()->create();
    $variant = ProductVariant::factory()->create();
    $order = app(CreateOrder::class)->create($customer, checkoutPageInput($variant));

    $this->actingAs($customer)->get('/orders/'.$order->id)->assertOk()->assertSee('22-10-2026 23:59:59 WIB')
        ->assertSee('Pengambilan besok: batas pembatalan customer untuk pesanan yang sudah dibayar telah lewat.')
        ->assertSee('80%')->assertSee('persetujuan admin');
});
