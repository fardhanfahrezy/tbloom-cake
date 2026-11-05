<?php

use App\MidtransSandboxProbe;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

function sandboxProbeReference(): string
{
    return 'TBLOOM-GPAY-123e4567-e89b-42d3-a456-426614174000';
}

beforeEach(function () {
    config(['services.midtrans.is_production' => false, 'services.midtrans.server_key' => 'synthetic-status-key']);
});

test('reads only approved synthetic status fields without interpreting settlement time as paid time', function () {
    Http::preventStrayRequests();
    Http::fake(['https://api.sandbox.midtrans.com/v2/'.sandboxProbeReference().'/status' => Http::response([
        'status_code' => '200', 'order_id' => sandboxProbeReference(), 'gross_amount' => '500000.00', 'currency' => 'IDR',
        'transaction_status' => 'settlement', 'payment_type' => 'bank_transfer', 'transaction_time' => '2026-10-24 10:00:00',
        'settlement_time' => '2026-10-24 10:29:59', 'expiry_time' => '2026-10-24 10:30:00',
        'signature_key' => 'private-signature', 'merchant_id' => 'private-merchant', 'customer_details' => ['email' => 'private@example.test'],
    ])]);

    $this->artisan('midtrans:sandbox-status', ['reference' => sandboxProbeReference(), '--expected-expiry' => '2026-10-24 10:30:00'])
        ->expectsOutput('Transaction status: settlement')
        ->expectsOutput('Payment type: bank_transfer')
        ->expectsOutput('expiry_time (Asia/Jakarta): 2026-10-24 10:30:00')
        ->expectsOutput('Expected expiry match: yes')
        ->expectsOutput('Observation only. G-PAY remains open; payment action timing and final closure require channel evidence.')
        ->doesntExpectOutputToContain('private-signature')->doesntExpectOutputToContain('private-merchant')
        ->doesntExpectOutputToContain('private@example.test')->doesntExpectOutputToContain('synthetic-status-key')
        ->assertSuccessful();

    Http::assertSentCount(1);
    Http::assertSent(fn (Request $request): bool => $request->method() === 'GET'
        && $request->url() === 'https://api.sandbox.midtrans.com/v2/'.sandboxProbeReference().'/status'
        && $request->hasHeader('Authorization', 'Basic '.base64_encode('synthetic-status-key:')));
});

test('keeps not found and missing expiry observations inconclusive', function (string $case) {
    Http::preventStrayRequests();
    $body = $case === 'not found' ? ['status_code' => '404'] : [
        'status_code' => '200', 'order_id' => sandboxProbeReference(), 'gross_amount' => '500000.00', 'currency' => 'IDR',
        'transaction_status' => 'pending', 'payment_type' => 'bank_transfer',
    ];
    Http::fake(['https://api.sandbox.midtrans.com/v2/*/status' => Http::response($body)]);

    $command = $this->artisan('midtrans:sandbox-status', ['reference' => sandboxProbeReference(), '--expected-expiry' => '2026-10-24 10:30:00']);
    if ($case === 'not found') {
        $command->expectsOutput('No selected instrument was found. This is not evidence of final unpaid closure.')->assertFailed();
    } else {
        $command->expectsOutput('Expected expiry match: unknown')
            ->expectsOutput('Observation only. G-PAY remains open; payment action timing and final closure require channel evidence.')->assertSuccessful();
    }
    $command->run();

    Http::assertSentCount(1);
})->with(['not found', 'missing expiry']);

test('refuses business or malformed references before sending any request', function (string $reference) {
    Http::preventStrayRequests();
    Http::fake();

    $this->artisan('midtrans:sandbox-status', ['reference' => $reference])->assertFailed();

    Http::assertNothingSent();
})->with(['TB-123e4567-e89b-42d3-a456-426614174000', '../private', 'TBLOOM-GPAY-invalid']);

test('refuses unsafe status configuration before HTTP', function (array $overrides) {
    config($overrides);
    Http::preventStrayRequests();
    Http::fake();

    $this->artisan('midtrans:sandbox-status', ['reference' => sandboxProbeReference()])->assertFailed();

    Http::assertNothingSent();
})->with([
    [['services.midtrans.is_production' => true]], [['services.midtrans.is_production' => 'false']],
    [['services.midtrans.server_key' => null]], [['services.midtrans.server_key' => '  ']],
]);

test('refuses status observation outside approved local environments', function () {
    Http::preventStrayRequests();
    Http::fake();
    $this->app->detectEnvironment(fn (): string => 'production');
    try {
        $this->artisan('midtrans:sandbox-status', ['reference' => sandboxProbeReference()])->assertFailed();
    } finally {
        $this->app->detectEnvironment(fn (): string => 'testing');
    }

    Http::assertNothingSent();
});

test('rejects invalid expected expiry before HTTP', function (string $expiry) {
    Http::preventStrayRequests();
    Http::fake();

    $this->artisan('midtrans:sandbox-status', ['reference' => sandboxProbeReference(), '--expected-expiry' => $expiry])->assertFailed();

    Http::assertNothingSent();
})->with(['2026-02-30 10:30:00', '2026-10-24T10:30:00Z', "2026-10-24 10:30:00\0"]);

test('does not trust mismatched or malformed provider status data', function (array $overrides) {
    Http::preventStrayRequests();
    Http::fake(['https://api.sandbox.midtrans.com/v2/*/status' => Http::response(array_replace([
        'status_code' => '200', 'order_id' => sandboxProbeReference(), 'gross_amount' => '500000.00', 'currency' => 'IDR',
        'transaction_status' => 'pending', 'payment_type' => 'bank_transfer',
    ], $overrides))]);

    $this->artisan('midtrans:sandbox-status', ['reference' => sandboxProbeReference()])
        ->doesntExpectOutputToContain('private-provider')->assertFailed();

    Http::assertSentCount(1);
})->with([
    [['order_id' => 'private-provider-other-order']], [['gross_amount' => '1.00']], [['gross_amount' => '5e5']],
    [['currency' => 'USD']], [['transaction_status' => 'private-provider-status']],
    [['payment_type' => "private-provider\033[31m"]], [['expiry_time' => '2026-02-30 10:30:00']],
    [['settlement_time' => ['private-provider-value']]], [['transaction_time' => "private-provider\n"]],
]);

test('reports transport failures without leaking provider bodies or retrying', function (int $status) {
    Http::preventStrayRequests();
    Http::fake(['https://api.sandbox.midtrans.com/v2/*/status' => Http::response(['message' => 'private-provider synthetic-status-key'], $status)]);

    $this->artisan('midtrans:sandbox-status', ['reference' => sandboxProbeReference()])
        ->doesntExpectOutputToContain('private-provider')->doesntExpectOutputToContain('synthetic-status-key')->assertFailed();

    Http::assertSentCount(1);
})->with([401, 403, 429, 500, 302, 404, 407]);

test('reports failed connections as unknown without repeating the request', function () {
    Http::preventStrayRequests();
    Http::fake(['https://api.sandbox.midtrans.com/v2/*/status' => Http::failedConnection()]);

    $this->artisan('midtrans:sandbox-status', ['reference' => sandboxProbeReference()])
        ->expectsOutput('Sandbox status connection failed; the result remains unknown.')->assertFailed();

    Http::assertSentCount(1);
});

test('reports an expiry mismatch without treating the observation as a verified contract', function () {
    Http::preventStrayRequests();
    Http::fake(['https://api.sandbox.midtrans.com/v2/*/status' => Http::response([
        'status_code' => '200', 'order_id' => sandboxProbeReference(), 'gross_amount' => '500000.00', 'currency' => 'IDR',
        'transaction_status' => 'expire', 'payment_type' => 'bank_transfer', 'expiry_time' => '2026-10-24 11:00:00',
    ])]);

    $this->artisan('midtrans:sandbox-status', ['reference' => sandboxProbeReference(), '--expected-expiry' => '2026-10-24 10:30:00'])
        ->expectsOutput('Expected expiry match: no')
        ->expectsOutput('Observation only. G-PAY remains open; payment action timing and final closure require channel evidence.')->assertSuccessful();

    Http::assertSentCount(1);
});

test('observes pending instruments returned with provider status code 201', function () {
    Http::preventStrayRequests();
    Http::fake(['https://api.sandbox.midtrans.com/v2/*/status' => Http::response([
        'status_code' => '201', 'order_id' => sandboxProbeReference(), 'gross_amount' => '500000.00', 'currency' => 'IDR',
        'transaction_status' => 'pending', 'payment_type' => 'bank_transfer', 'transaction_time' => '2026-10-24 14:55:10',
        'expiry_time' => '2026-10-24 15:14:46',
    ])]);

    $this->artisan('midtrans:sandbox-status', ['reference' => sandboxProbeReference(), '--expected-expiry' => '2026-10-24 15:14:46'])
        ->expectsOutput('Transaction status: pending')->expectsOutput('Expected expiry match: yes')
        ->expectsOutput('Observation only. G-PAY remains open; payment action timing and final closure require channel evidence.')
        ->assertSuccessful();

    Http::assertSentCount(1);
});

test('observes provider expiry code 407 without verifying the channel contract', function () {
    Http::preventStrayRequests();
    Http::fake(['https://api.sandbox.midtrans.com/v2/'.sandboxProbeReference().'/status' => Http::response([
        'status_code' => '407', 'order_id' => sandboxProbeReference(), 'gross_amount' => '500000.00', 'currency' => 'IDR',
        'transaction_status' => 'expire', 'payment_type' => 'credit_card', 'transaction_time' => '2026-10-24 14:59:05',
        'expiry_time' => '2026-11-01 14:59:05', 'signature_key' => 'private-expiry-signature',
    ], 200)]);

    $this->artisan('midtrans:sandbox-status', ['reference' => sandboxProbeReference(), '--expected-expiry' => '2026-10-24 15:00:41'])
        ->expectsOutput('Provider status code: 407')
        ->expectsOutput('Transaction status: expire')
        ->expectsOutput('expiry_time (Asia/Jakarta): 2026-11-01 14:59:05')
        ->expectsOutput('Expected expiry match: no')
        ->expectsOutput('Observation only. G-PAY remains open; payment action timing and final closure require channel evidence.')
        ->doesntExpectOutputToContain('private-expiry-signature')->doesntExpectOutputToContain('synthetic-status-key')
        ->assertSuccessful();

    Http::assertSentCount(1);
    Http::assertSent(fn (Request $request): bool => $request->method() === 'GET');
});

test('rejects contradictory expiry codes and modification errors', function (mixed $code, string $status) {
    Http::preventStrayRequests();
    Http::fake(['https://api.sandbox.midtrans.com/v2/'.sandboxProbeReference().'/status' => Http::response([
        'status_code' => $code, 'order_id' => sandboxProbeReference(), 'gross_amount' => '500000.00', 'currency' => 'IDR',
        'transaction_status' => $status, 'payment_type' => 'credit_card',
    ], 200)]);

    $this->artisan('midtrans:sandbox-status', ['reference' => sandboxProbeReference()])->assertFailed();

    Http::assertSentCount(1);
})->with([
    'expiry code with pending' => ['407', 'pending'],
    'expiry code with capture' => ['407', 'capture'],
    'expiry code with settlement' => ['407', 'settlement'],
    'modification refused' => ['412', 'expire'],
    'untyped provider code' => [407, 'expire'],
]);

test('records only redacted evidence for the approved 400000 IDR scenario', function () {
    Storage::fake('local');
    Http::preventStrayRequests();
    Http::fake(['https://api.sandbox.midtrans.com/v2/'.sandboxProbeReference().'/status' => Http::response([
        'status_code' => '200', 'order_id' => sandboxProbeReference(), 'gross_amount' => '400000.00', 'currency' => 'IDR',
        'transaction_status' => 'settlement', 'payment_type' => 'bank_transfer', 'settlement_time' => '2026-10-24 16:01:00',
        'signature_key' => 'private-signature', 'customer_details' => ['email' => 'private@example.test'],
    ])]);

    $this->artisan('midtrans:sandbox-status', ['reference' => sandboxProbeReference(), '--expected-amount' => '400000', '--record' => true])
        ->expectsOutput('Transaction status: settlement')->doesntExpectOutputToContain('private-signature')->assertSuccessful();

    $evidence = Storage::disk('local')->get(MidtransSandboxProbe::DIRECTORY.'/'.sandboxProbeReference().'.jsonl');
    expect($evidence)->not->toContain('private-signature', 'private@example.test', 'synthetic-status-key');
    $record = json_decode(trim($evidence), true);
    expect($record['fields']['gross_amount'])->toBe('400000.00')->and(strlen($record['response_sha256']))->toBe(64);
    Http::assertSentCount(1);
});

test('unapproved observation amounts send no request', function (string $amount) {
    Http::preventStrayRequests();
    Http::fake();

    $this->artisan('midtrans:sandbox-status', ['reference' => sandboxProbeReference(), '--expected-amount' => $amount])->assertFailed();

    Http::assertNothingSent();
})->with(['1', '10002', '400000.00', '500000.0']);

test('an elapsed Snap expiry does not turn provider pending into final unpaid', function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-24 22:35:00', 'Asia/Jakarta'));
    Http::preventStrayRequests();
    Http::fake(['https://api.sandbox.midtrans.com/v2/'.sandboxProbeReference().'/status' => Http::response([
        'status_code' => '201', 'order_id' => sandboxProbeReference(), 'gross_amount' => '500000.00', 'currency' => 'IDR',
        'transaction_status' => 'pending', 'payment_type' => 'bank_transfer', 'expiry_time' => '2026-10-24 22:34:25',
    ])]);

    $this->artisan('midtrans:sandbox-status', ['reference' => sandboxProbeReference(), '--expected-expiry' => '2026-10-24 22:34:25'])
        ->expectsOutput('Transaction status: pending')->doesntExpectOutputToContain('Transaction status: expire')
        ->expectsOutput('Observation only. G-PAY remains open; payment action timing and final closure require channel evidence.')
        ->assertSuccessful();

    Http::assertSentCount(1);
});
