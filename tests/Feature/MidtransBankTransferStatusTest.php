<?php

use App\MidtransBankTransferStatus;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config(['services.midtrans.is_production' => false, 'services.midtrans.server_key' => 'synthetic-client-key']);
});

/** @return array<string, mixed> */
function bankStatusPayload(): array
{
    return [
        'order_id' => 'TB-20261025-0001', 'transaction_id' => '123e4567-e89b-42d3-a456-426614174000',
        'gross_amount' => '400000.00', 'currency' => 'IDR', 'payment_type' => 'bank_transfer',
        'status_code' => '200', 'transaction_status' => 'settlement', 'va_numbers' => [['bank' => 'bca']],
        'transaction_time' => '2026-10-25 10:00:00', 'settlement_time' => '2026-10-25 10:10:00',
    ];
}

test('reads fixed Sandbox Core endpoint once and returns a normalized observation', function () {
    Http::preventStrayRequests();
    $options = [];
    Http::fake(['https://api.sandbox.midtrans.com/v2/TB-20261025-0001/status' => function (Request $request, array $requestOptions) use (&$options) {
        $options = $requestOptions;

        return Http::response(bankStatusPayload());
    }]);

    $result = app(MidtransBankTransferStatus::class)->observe('TB-20261025-0001', 400000,
        new DateTimeImmutable('2026-10-25 10:30:00+07:00'), '123e4567-e89b-42d3-a456-426614174000');

    expect($result->outcome->value)->toBe('success_observed')->and($result->timing->value)->toBe('unknown');
    expect($options)->toMatchArray(['connect_timeout' => 5, 'timeout' => 15, 'allow_redirects' => false]);
    Http::assertSentCount(1);
    Http::assertSent(fn (Request $request): bool => $request->method() === 'GET'
        && $request->url() === 'https://api.sandbox.midtrans.com/v2/TB-20261025-0001/status'
        && $request->hasHeader('Authorization', 'Basic '.base64_encode('synthetic-client-key:'))
        && $request->hasHeader('Accept', 'application/json'));
});

test('unsafe reference or expected context is rejected before any request', function (string $reference, int $amount, string $deadline, ?string $transactionId) {
    Http::preventStrayRequests();
    Http::fake();

    expect(fn () => app(MidtransBankTransferStatus::class)->observe($reference, $amount, new DateTimeImmutable($deadline), $transactionId))
        ->toThrow(InvalidArgumentException::class, 'Invalid expected payment context.');
    Http::assertNothingSent();
})->with([
    ['', 400000, '2026-10-25 10:30:00+07:00', null],
    ['https://example.test/secret', 400000, '2026-10-25 10:30:00+07:00', null],
    ['../cancel', 400000, '2026-10-25 10:30:00+07:00', null],
    ['A%2Fstatus', 400000, '2026-10-25 10:30:00+07:00', null],
    ['A?x=y', 400000, '2026-10-25 10:30:00+07:00', null],
    ["A\r\nsecret", 400000, '2026-10-25 10:30:00+07:00', null],
    [str_repeat('A', 101), 400000, '2026-10-25 10:30:00+07:00', null],
    ['A', 0, '2026-10-25 10:30:00+07:00', null],
    ['A', 400000, '2026-10-25 10:30:00.000001+07:00', null],
    ['A', 400000, '2026-10-25 10:30:00+07:00', 'invalid-id'],
]);

test('unsafe configuration remains unknown without sending credentials', function (mixed $production, mixed $key, string $environment) {
    config(['services.midtrans.is_production' => $production, 'services.midtrans.server_key' => $key]);
    app()->instance('env', $environment);
    Http::preventStrayRequests();
    Http::fake();

    $result = app(MidtransBankTransferStatus::class)->observe('TB-20261025-0001', 400000, new DateTimeImmutable('2026-10-25 10:30:00+07:00'));

    expect($result->outcome->value)->toBe('unknown')->and($result->reason)->toBe('sandbox_not_configured');
    Http::assertNothingSent();
})->with([
    [true, 'synthetic-key', 'testing'], ['false', 'synthetic-key', 'testing'], [null, 'synthetic-key', 'testing'],
    [false, null, 'testing'], [false, '', 'testing'], [false, ['synthetic-key'], 'testing'],
    [false, ' key ', 'testing'], [false, "key\nsecret", 'testing'], [false, 'key:secret', 'testing'],
    [false, 'synthetic-key', 'production'], [false, 'synthetic-key', 'staging'],
]);

test('HTTP failures redirects and not found stay unknown without retry or fallback', function (int $status) {
    Http::preventStrayRequests();
    Http::fake(['https://api.sandbox.midtrans.com/v2/TB-20261025-0001/status' => Http::response(bankStatusPayload(), $status, [
        'Location' => 'https://example.test/secret',
    ])]);

    $result = app(MidtransBankTransferStatus::class)->observe('TB-20261025-0001', 400000, new DateTimeImmutable('2026-10-25 10:30:00+07:00'));

    expect($result->outcome->value)->toBe('unknown')->and($result->reason)->toBe('provider_unavailable');
    Http::assertSentCount(1);
})->with([201, 302, 401, 404, 407, 429, 500, 503]);

test('connection errors become redacted unknown observations without retry', function () {
    Http::preventStrayRequests();
    $attempts = 0;
    Http::fake(['https://api.sandbox.midtrans.com/v2/TB-20261025-0001/status' => function () use (&$attempts) {
        $attempts++;
        throw new ConnectionException('private-provider-body synthetic-client-key');
    }]);

    $result = app(MidtransBankTransferStatus::class)->observe('TB-20261025-0001', 400000, new DateTimeImmutable('2026-10-25 10:30:00+07:00'));

    expect($result->outcome->value)->toBe('unknown')->and($result->reason)->toBe('provider_unavailable');
    expect($attempts)->toBe(1);
    expect(json_encode($result))->not->toContain('private-provider-body', 'synthetic-client-key');
});

test('malformed provider JSON remains unknown', function (string $body) {
    Http::preventStrayRequests();
    Http::fake(['https://api.sandbox.midtrans.com/v2/TB-20261025-0001/status' => Http::response($body, 200)]);

    $result = app(MidtransBankTransferStatus::class)->observe('TB-20261025-0001', 400000, new DateTimeImmutable('2026-10-25 10:30:00+07:00'));

    expect($result->outcome->value)->toBe('unknown');
    Http::assertSentCount(1);
})->with(['<html>private</html>', 'null', '[]', '{"status_code":"200"', '{"status_code":200}']);

test('request exceptions never expose the provider body', function () {
    Http::preventStrayRequests();
    $attempts = 0;
    Http::fake(['https://api.sandbox.midtrans.com/v2/TB-20261025-0001/status' => function () use (&$attempts) {
        $attempts++;
        throw Http::failedRequest(['private' => 'synthetic-client-key'], 500);
    }]);

    $result = app(MidtransBankTransferStatus::class)->observe('TB-20261025-0001', 400000, new DateTimeImmutable('2026-10-25 10:30:00+07:00'));

    expect($result->outcome->value)->toBe('unknown')->and($result->reason)->toBe('provider_unavailable');
    expect($attempts)->toBe(1);
    expect(json_encode($result))->not->toContain('synthetic-client-key');
});

test('repeated local reads preserve observations without creating a new payment window', function () {
    app()->instance('env', 'local');
    Http::preventStrayRequests();
    Http::fake(['https://api.sandbox.midtrans.com/v2/TB-20261025-0001/status' => Http::sequence()
        ->push(array_replace(bankStatusPayload(), [
            'status_code' => '201', 'transaction_status' => 'pending', 'settlement_time' => null,
            'expiry_time' => '2026-10-25 10:05:00',
        ]))
        ->push(bankStatusPayload())
        ->push(bankStatusPayload())]);
    $deadline = new DateTimeImmutable('2026-10-25 10:30:00+07:00');
    $client = app(MidtransBankTransferStatus::class);

    $pending = $client->observe('TB-20261025-0001', 400000, $deadline);
    $success = $client->observe('TB-20261025-0001', 400000, $deadline);
    $replay = $client->observe('TB-20261025-0001', 400000, $deadline);

    expect($pending->outcome->value)->toBe('pending');
    expect($success->outcome->value)->toBe('success_observed')->and($success->timing->value)->toBe('unknown');
    expect($replay)->toEqual($success);
    expect($deadline->format(DATE_ATOM))->toBe('2026-10-25T10:30:00+07:00');
    Http::assertSentCount(3);
    foreach (Http::recorded() as [$request]) {
        expect($request->method())->toBe('GET')->and($request->url())->toBe('https://api.sandbox.midtrans.com/v2/TB-20261025-0001/status');
    }
});

test('provider observations pass through identity checks without writing domain data', function (array $overrides, string $expectedOutcome) {
    Http::preventStrayRequests();
    Http::fake(['https://api.sandbox.midtrans.com/v2/TB-20261025-0001/status' => Http::response(array_replace(bankStatusPayload(), $overrides))]);
    $queries = [];
    DB::listen(function ($query) use (&$queries): void {
        $queries[] = $query->sql;
    });

    $result = app(MidtransBankTransferStatus::class)->observe('TB-20261025-0001', 400000, new DateTimeImmutable('2026-10-25 10:30:00+07:00'),
        '123e4567-e89b-42d3-a456-426614174000');

    expect($result->outcome->value)->toBe($expectedOutcome)->and($result->timing->value)->toBe('unknown');
    expect($queries)->toBe([]);
    Http::assertSentCount(1);
})->with([
    [['order_id' => 'OTHER'], 'conflict'], [['gross_amount' => '500000.00'], 'conflict'],
    [['transaction_id' => '2967a077-17a7-4d5d-93ec-7cca66edd379'], 'conflict'],
    [['status_code' => '201', 'transaction_status' => 'pending', 'settlement_time' => null, 'expiry_time' => '2026-10-25 10:05:00'], 'pending'],
    [['status_code' => '407', 'transaction_status' => 'expire', 'settlement_time' => null], 'expired_observed'],
    [['payment_type' => 'credit_card'], 'conflict'], [['va_numbers' => [['bank' => 'bni']]], 'unknown'],
]);
