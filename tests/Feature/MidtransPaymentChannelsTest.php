<?php

use App\MidtransBankTransferStatus;
use App\MidtransPaymentChannels;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config(['services.midtrans.is_production' => false, 'services.midtrans.server_key' => 'synthetic-channel-key']);
});

test('checkout channel scope is BCA VA and merchant preferences cannot broaden it', function () {
    config(['services.midtrans.enabled_payments' => ['credit_card', 'google_pay', 'other_va', 'bca_va']]);

    $channels = app(MidtransPaymentChannels::class)->enabledForCheckout();

    expect($channels)->toBe(['bca_va']);
    $channels[] = 'credit_card';
    expect(app(MidtransPaymentChannels::class)->enabledForCheckout())->toBe(['bca_va']);
});

test('explicit BCA scope preserves normalized status without promoting timing or changing the deadline', function (string $status, string $code, string $outcome) {
    $this->travelTo(new DateTimeImmutable('2026-10-25 10:35:00+07:00'));
    Http::preventStrayRequests();
    Http::fake(['https://api.sandbox.midtrans.com/v2/TB-CHANNEL-1/status' => Http::response([
        'order_id' => 'TB-CHANNEL-1', 'transaction_id' => '123e4567-e89b-42d3-a456-426614174000',
        'gross_amount' => '400000.00', 'currency' => 'IDR', 'payment_type' => 'bank_transfer',
        'va_numbers' => [['bank' => 'bca']], 'transaction_status' => $status, 'status_code' => $code,
        'transaction_time' => '2026-10-25 10:00:00', 'expiry_time' => '2026-10-25 10:05:00',
        'settlement_time' => $status === 'settlement' ? '2026-10-25 10:31:00' : null,
        'paid_at' => '2026-10-25 10:29:59', 'verified' => true,
    ])]);
    $deadline = new DateTimeImmutable('2026-10-25 10:30:00+07:00');

    $result = app(MidtransBankTransferStatus::class)->observe('TB-CHANNEL-1', 400000, $deadline, expectedChannel: 'bca_va');

    expect($result->outcome->value)->toBe($outcome)->and($result->timing->value)->toBe('unknown')->and($result->actionTime)->toBeNull();
    expect($deadline->format(DATE_ATOM))->toBe('2026-10-25T10:30:00+07:00');
    Http::assertSentCount(1);
    Http::assertSent(fn (Request $request): bool => $request->method() === 'GET'
        && $request->url() === 'https://api.sandbox.midtrans.com/v2/TB-CHANNEL-1/status');
})->with([
    'settlement after deadline is not automatically late or timely' => ['settlement', '200', 'success_observed'],
    'pending after expiry stays pending' => ['pending', '201', 'pending'],
    'provider expiry is only an observation' => ['expire', '407', 'expired_observed'],
]);

test('other merchant channels remain unknown without dispatching BCA requests', function (string $channel) {
    Http::preventStrayRequests();
    Http::fake();

    $result = app(MidtransBankTransferStatus::class)->observe('TB-CHANNEL-1', 400000,
        new DateTimeImmutable('2026-10-25 10:30:00+07:00'), expectedChannel: $channel);

    expect($result->outcome->value)->toBe('unknown')->and($result->reason)->toBe('unsupported_channel')
        ->and($result->timing->value)->toBe('unknown')->and($result->transactionId)->toBeNull();
    Http::assertNothingSent();
})->with([
    'akulaku', 'alfamart', 'bni_va', 'bri_va', 'bsi_va', 'cimb_va', 'credit_card', 'dana', 'danamon_va',
    'echannel', 'google_pay', 'gopay', 'indomaret', 'kredivo', 'other_qris', 'other_va', 'ovo',
    'permata_va', 'saqu_va', 'seabank_va', 'shopeepay',
]);

test('unknown aliases and unselected channels cannot inherit BCA capabilities', function (?string $channel) {
    Http::preventStrayRequests();
    Http::fake();

    $result = app(MidtransBankTransferStatus::class)->observe('TB-CHANNEL-1', 400000,
        new DateTimeImmutable('2026-10-25 10:30:00+07:00'), expectedChannel: $channel);

    expect($result->outcome->value)->toBe('unknown')->and($result->reason)->toBe('unsupported_channel');
    Http::assertNothingSent();
})->with([null, '', 'bca', 'bank_transfer', 'BCA_VA', ' bca_va', 'bca_va ', 'new_provider_channel', "bca_va\0"]);

test('BCA scope does not relabel a different bank returned by Core', function () {
    Http::preventStrayRequests();
    Http::fake(['https://api.sandbox.midtrans.com/v2/TB-CHANNEL-1/status' => Http::response([
        'order_id' => 'TB-CHANNEL-1', 'transaction_id' => '123e4567-e89b-42d3-a456-426614174000',
        'gross_amount' => '400000.00', 'currency' => 'IDR', 'payment_type' => 'bank_transfer',
        'va_numbers' => [['bank' => 'bni']], 'transaction_status' => 'settlement', 'status_code' => '200',
        'transaction_time' => '2026-10-25 10:00:00', 'channel' => 'bca_va',
    ])]);

    $result = app(MidtransBankTransferStatus::class)->observe('TB-CHANNEL-1', 400000,
        new DateTimeImmutable('2026-10-25 10:30:00+07:00'), expectedChannel: 'bca_va');

    expect($result->outcome->value)->toBe('unknown')->and($result->reason)->toBe('unsupported_bank');
    Http::assertSentCount(1);
});
