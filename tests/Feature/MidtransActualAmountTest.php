<?php

use App\MidtransBankTransferStatus;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config(['services.midtrans.is_production' => false, 'services.midtrans.server_key' => 'synthetic-actual-key']);
    Http::preventStrayRequests();
});

/** @return array<string, mixed> */
function actualBankPayload(array $changes = []): array
{
    return array_replace(['order_id' => 'TB-ACTUAL-001', 'transaction_id' => '123e4567-e89b-42d3-a456-426614174000',
        'gross_amount' => '90000.00', 'currency' => 'IDR', 'payment_type' => 'bank_transfer',
        'status_code' => '200', 'transaction_status' => 'settlement', 'va_numbers' => [['bank' => 'bca']],
        'transaction_time' => '2026-10-27 10:00:00', 'settlement_time' => '2026-10-27 10:10:00'], $changes);
}

test('observes authenticated actual funds independently of the expected invoice without weakening the old observer', function () {
    Http::fake(['https://api.sandbox.midtrans.com/v2/TB-ACTUAL-001/status' => Http::response(actualBankPayload())]);
    $client = app(MidtransBankTransferStatus::class);
    $deadline = new DateTimeImmutable('2026-10-27 10:30:00+07:00');
    $actual = $client->observeActual('TB-ACTUAL-001', $deadline);
    expect($actual->outcome->value)->toBe('success_observed');
    expect($actual->amount)->toBe(90000);
    expect($actual->timing->value)->toBe('unknown');
    expect($client->observe('TB-ACTUAL-001', 100000, $deadline)->outcome->value)->toBe('conflict');
    Http::assertSentCount(2);
});

test('parses integer IDR amounts without floats or overflow', function (mixed $raw, ?int $amount) {
    Http::fake(['https://api.sandbox.midtrans.com/v2/TB-ACTUAL-001/status' => Http::response(actualBankPayload(['gross_amount' => $raw]))]);
    $result = app(MidtransBankTransferStatus::class)->observeActual('TB-ACTUAL-001', new DateTimeImmutable('2026-10-27 10:30:00+07:00'));
    expect($result->amount)->toBe($amount);
    expect($result->outcome->value)->toBe($amount === null ? 'unknown' : 'success_observed');
    Http::assertSentCount(1);
})->with([
    ['1', 1], ['1.00', 1], ['9223372036854775807.00', PHP_INT_MAX],
    ['9223372036854775808', null], ['0.00', null], ['90.50', null], ['090000', null],
    ['9e4', null], [90000, null], [null, null], [[], null],
]);

test('unsupported returns remain unknown instead of inventing canonical return facts', function (string $status) {
    Http::fake(['https://api.sandbox.midtrans.com/v2/TB-ACTUAL-001/status' => Http::response(actualBankPayload(['transaction_status' => $status]))]);
    $result = app(MidtransBankTransferStatus::class)->observeActual('TB-ACTUAL-001', new DateTimeImmutable('2026-10-27 10:30:00+07:00'));
    expect($result->outcome->value)->toBe('unknown');
    expect($result->amount)->toBeNull();
    Http::assertSentCount(1);
})->with(['refund', 'partial_refund', 'chargeback', 'deny']);

test('rejects untrusted identity currency channel and fraud contexts', function (array $changes, string $outcome) {
    Http::fake(['https://api.sandbox.midtrans.com/v2/TB-ACTUAL-001/status' => Http::response(actualBankPayload($changes))]);
    $result = app(MidtransBankTransferStatus::class)->observeActual('TB-ACTUAL-001', new DateTimeImmutable('2026-10-27 10:30:00+07:00'), '123e4567-e89b-42d3-a456-426614174000');
    expect($result->outcome->value)->toBe($outcome);
    expect($result->amount)->toBeNull();
    Http::assertSentCount(1);
})->with([
    [['order_id' => 'FOREIGN-001'], 'conflict'], [['currency' => 'USD'], 'conflict'],
    [['transaction_id' => '223e4567-e89b-42d3-a456-426614174000'], 'conflict'],
    [['va_numbers' => [['bank' => 'bni']]], 'unknown'], [['fraud_status' => 'deny'], 'unknown'],
]);
