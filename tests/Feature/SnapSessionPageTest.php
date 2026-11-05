<?php

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Http;
use Tests\CommittedMySqlDatabase;

uses(CommittedMySqlDatabase::class);

beforeEach(function () {
    config(['services.midtrans.is_production' => false, 'services.midtrans.server_key' => 'synthetic-page-key']);
    $this->travelTo(CarbonImmutable::parse('2026-10-25 03:00:00', 'UTC'));
    $this->withoutVite();
    Http::preventStrayRequests();
});

function sessionPageOrder(): Order
{
    $order = Order::factory()->create(['product_total' => 400000]);
    Payment::factory()->for($order)->create(['merchant_reference' => $order->order_number]);
    OrderItem::factory()->create(['order_id' => $order->id, 'unit_price' => 400000, 'line_total' => 400000]);

    return $order->load('user', 'payment', 'item');
}

test('owned order GET displays a CSRF protected BCA action without creating or exposing a session', function () {
    $order = sessionPageOrder();
    Http::fake();

    $this->actingAs($order->user)->get('/orders/'.$order->id)->assertOk()->assertSee('Bayar dengan BCA VA')
        ->assertSee('name="_token"', false)->assertSee('data-payment-submit', false)
        ->assertSee('action="'.route('orders.payment-session.store', $order).'"', false)
        ->assertDontSee('synthetic-page-key')->assertDontSee('Credit Card')->assertDontSee('Google Pay');

    expect($order->payment->fresh()->snap_requested_at)->toBeNull();
    Http::assertNothingSent();
});

test('payment POST ignores browser amount status channel and return URL overrides and reuses one token', function () {
    $order = sessionPageOrder();
    $token = '123e4567-e89b-42d3-a456-426614174000';
    $url = 'https://app.sandbox.midtrans.com/snap/v2/vtweb/'.$token;
    Http::fake(['https://app.sandbox.midtrans.com/snap/v1/transactions' => Http::response(['token' => $token, 'redirect_url' => $url], 201)]);

    $this->actingAs($order->user)->post('/orders/'.$order->id.'/payment-session', [
        'gross_amount' => 1, 'status' => 'paid', 'enabled_payments' => ['credit_card'], 'redirect_url' => 'https://example.test',
        'merchant_reference' => 'REPLACEMENT', 'payment_expires_at' => '2026-10-26 00:00:00',
    ])->assertRedirect($url);
    $this->get('/orders/'.$order->id.'?transaction_status=settlement&status=paid')->assertOk()
        ->assertSee('Menunggu pembayaran')->assertDontSee('Pembayaran terverifikasi')->assertDontSee($token);
    $this->post('/orders/'.$order->id.'/payment-session')->assertRedirect($url);

    expect($order->fresh()->status->value)->toBe('pending');
    Http::assertSentCount(1);
    Http::assertSent(fn (Request $request): bool => $request['transaction_details']['gross_amount'] === 400000
        && $request['transaction_details']['order_id'] === $order->order_number
        && $request['expiry'] === ['start_time' => '2026-10-25 10:00:00 +0700', 'unit' => 'minutes', 'duration' => 30]
        && $request['enabled_payments'] === ['bca_va']
        && $request['callbacks']['finish'] === route('orders.show', $order));
});

test('guest cannot start a payment session', function () {
    $order = sessionPageOrder();
    Http::fake();

    $this->post('/orders/'.$order->id.'/payment-session')->assertRedirect(route('login'));

    Http::assertNothingSent();
});

test('another customer or staff cannot start payment for the order', function (string $role) {
    $order = sessionPageOrder();
    $actor = match ($role) {
        'admin' => User::factory()->admin()->create(),
        'owner' => User::factory()->owner()->create(),
        default => User::factory()->create(),
    };
    Http::fake();

    $this->actingAs($actor)->post('/orders/'.$order->id.'/payment-session')->assertNotFound();

    expect($order->payment->fresh()->snap_requested_at)->toBeNull();
    Http::assertNothingSent();
})->with(['customer', 'admin', 'owner']);

test('deadline hides the payment form and rejects POST without marking order expired', function () {
    $order = sessionPageOrder();
    $this->travelTo($order->payment_expires_at);
    Http::fake();

    $this->actingAs($order->user)->get('/orders/'.$order->id)->assertOk()->assertDontSee('data-payment-submit', false)
        ->assertSee('Menunggu kepastian pembayaran');
    $this->post('/orders/'.$order->id.'/payment-session')->assertRedirect(route('orders.show', $order));

    expect($order->fresh()->status->value)->toBe('pending')->and($order->item->fresh()->quota_released_at)->toBeNull();
    Http::assertNothingSent();
});

test('unknown creation displays checking instructions and only reads status on the next POST', function () {
    $order = sessionPageOrder();
    $posts = 0;
    Http::fake([
        'https://app.sandbox.midtrans.com/snap/v1/transactions' => function () use (&$posts) {
            $posts++;
            throw new ConnectionException('private-key-and-provider-body');
        },
        'https://api.sandbox.midtrans.com/v2/'.$order->order_number.'/status' => Http::response(['status_code' => '404'], 404),
    ]);

    $this->actingAs($order->user)->post('/orders/'.$order->id.'/payment-session')->assertRedirect(route('orders.show', $order));
    $this->get('/orders/'.$order->id)->assertOk()->assertSee('Pembayaran sedang diperiksa')->assertSee('Periksa pembayaran')
        ->assertSee('Jangan membayar ulang')->assertDontSee('Bayar dengan BCA VA')->assertDontSee('private-key-and-provider-body');
    $this->post('/orders/'.$order->id.'/payment-session')->assertRedirect(route('orders.show', $order));

    expect($posts)->toBe(1);
    Http::assertSent(fn (Request $request): bool => $request->method() === 'GET');
    $this->assertDatabaseCount('payment_movements', 0);
});

test('unconfigured payment stays unavailable without reserving a provider attempt', function () {
    $order = sessionPageOrder();
    config(['services.midtrans.is_production' => true]);
    Http::fake();

    $this->actingAs($order->user)->get('/orders/'.$order->id)->assertOk()->assertDontSee('data-payment-submit', false)
        ->assertSee('Pembayaran online belum tersedia');
    $this->post('/orders/'.$order->id.'/payment-session')->assertRedirect(route('orders.show', $order));

    expect($order->payment->fresh()->snap_requested_at)->toBeNull();
    Http::assertNothingSent();
});

test('staff may view but cannot pay even for an order attached to their own account', function (string $role) {
    $actor = $role === 'admin' ? User::factory()->admin()->create() : User::factory()->owner()->create();
    $order = Order::factory()->for($actor)->create();

    expect(Gate::forUser($actor)->allows('view', $order))->toBeTrue();
    expect(Gate::forUser($actor)->allows('pay', $order))->toBeFalse();
})->with(['admin', 'owner']);

test('deactivated customer is logged out before session creation', function () {
    $order = sessionPageOrder();
    $this->actingAs($order->user);
    User::query()->whereKey($order->user_id)->update(['is_active' => false]);
    Http::fake();

    $this->post('/orders/'.$order->id.'/payment-session')->assertRedirect(route('login'));

    $this->assertGuest();
    Http::assertNothingSent();
});

test('payment endpoint bounds repeated requests without sending another provider POST', function () {
    $order = sessionPageOrder();
    $token = '123e4567-e89b-42d3-a456-426614174000';
    $url = 'https://app.sandbox.midtrans.com/snap/v2/vtweb/'.$token;
    Http::fake(['https://app.sandbox.midtrans.com/snap/v1/transactions' => Http::response(['token' => $token, 'redirect_url' => $url], 201)]);
    $this->actingAs($order->user);

    for ($request = 0; $request < 6; $request++) {
        $this->post('/orders/'.$order->id.'/payment-session')->assertRedirect($url);
    }
    $this->post('/orders/'.$order->id.'/payment-session')->assertTooManyRequests();

    Http::assertSentCount(1);
});
