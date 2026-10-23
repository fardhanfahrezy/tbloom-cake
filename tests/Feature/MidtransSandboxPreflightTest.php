<?php

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

test('refuses Sandbox discovery when production mode is enabled', function () {
    config(['services.midtrans.is_production' => true, 'services.midtrans.server_key' => 'SB-Mid-server-test-only']);
    Http::preventStrayRequests();
    Http::fake(['https://app.sandbox.midtrans.com/snap/v3/merchant-preferences' => Http::response()]);

    $this->artisan('midtrans:sandbox-preflight')
        ->expectsOutput('Sandbox preflight requires local/testing, sandbox mode, and a sandbox server key.')
        ->assertFailed();

    Http::assertNothingSent();
});

test('discovers enabled Sandbox channels without exposing merchant data or changing preferences', function () {
    config(['services.midtrans.is_production' => false, 'services.midtrans.server_key' => 'opaque-sandbox-test-key']);
    Http::preventStrayRequests();
    Http::fake([
        'https://app.sandbox.midtrans.com/snap/v3/merchant-preferences' => Http::response([
            'merchant_id' => 'private-merchant-id',
            'display_name' => 'Private Merchant Name',
            'payment_channels' => [
                ['name' => 'qris', 'enabled' => true],
                ['name' => 'bca_va', 'enabled' => true],
                ['name' => 'credit_card', 'enabled' => false],
            ],
        ]),
    ]);

    $this->artisan('midtrans:sandbox-preflight')
        ->expectsOutput('Enabled Sandbox channels: bca_va, qris')
        ->expectsOutput('Discovery only. G-PAY remains open until per-channel expiry, timing, and finality are verified.')
        ->doesntExpectOutputToContain('private-merchant-id')
        ->doesntExpectOutputToContain('Private Merchant Name')
        ->doesntExpectOutputToContain('opaque-sandbox-test-key')
        ->assertSuccessful();

    Http::assertSentCount(1);
    Http::assertSent(fn (Request $request): bool => $request->method() === 'GET'
        && $request->url() === 'https://app.sandbox.midtrans.com/snap/v3/merchant-preferences'
        && $request->hasHeader('Authorization', 'Basic '.base64_encode('opaque-sandbox-test-key:')));
});

test('rejects invalid Sandbox channel records without displaying provider payloads', function (mixed $channels) {
    config(['services.midtrans.is_production' => false, 'services.midtrans.server_key' => 'SB-Mid-server-test-only']);
    Http::preventStrayRequests();
    Http::fake([
        'https://app.sandbox.midtrans.com/snap/v3/merchant-preferences' => Http::response(['payment_channels' => $channels]),
    ]);

    $this->artisan('midtrans:sandbox-preflight')
        ->expectsOutput('Sandbox returned invalid channel preferences; G-PAY remains open.')
        ->assertFailed();

    Http::assertSentCount(1);
})->with([
    'missing list' => [null],
    'scalar list' => ['private-provider-body'],
    'map instead of list' => [['bca_va' => ['name' => 'bca_va', 'enabled' => true]]],
    'scalar record' => [['private-provider-body']],
    'missing channel name' => [[['enabled' => true]]],
    'non boolean activation' => [[['name' => 'bca_va', 'enabled' => 'false']]],
    'terminal escape in channel name' => [[['name' => "bca_va\033[31m", 'enabled' => true]]],
]);

test('refuses ambiguous Sandbox configuration before any HTTP request', function (array $overrides) {
    config(array_replace([
        'services.midtrans.is_production' => false,
        'services.midtrans.server_key' => 'opaque-sandbox-test-key',
    ], $overrides));
    Http::preventStrayRequests();
    Http::fake(['https://app.sandbox.midtrans.com/snap/v3/merchant-preferences' => Http::response()]);

    $this->artisan('midtrans:sandbox-preflight')->assertFailed();

    Http::assertNothingSent();
})->with([
    'unset mode' => [['services.midtrans.is_production' => null]],
    'string instead of boolean mode' => [['services.midtrans.is_production' => 'false']],
    'missing key' => [['services.midtrans.server_key' => null]],
    'empty key' => [['services.midtrans.server_key' => '']],
    'whitespace key' => [['services.midtrans.server_key' => '   ']],
    'non string key' => [['services.midtrans.server_key' => 123]],
]);

test('refuses Sandbox discovery outside local and testing', function (string $environment) {
    config(['services.midtrans.is_production' => false, 'services.midtrans.server_key' => 'opaque-sandbox-test-key']);
    Http::preventStrayRequests();
    Http::fake(['https://app.sandbox.midtrans.com/snap/v3/merchant-preferences' => Http::response()]);
    $this->app->detectEnvironment(fn (): string => $environment);

    try {
        $this->artisan('midtrans:sandbox-preflight')->assertFailed();
        Http::assertNothingSent();
    } finally {
        $this->app->detectEnvironment(fn (): string => 'testing');
    }
})->with(['production', 'staging']);

test('reports failed preference requests without leaking the response or retrying', function (int $status) {
    config(['services.midtrans.is_production' => false, 'services.midtrans.server_key' => 'opaque-sandbox-test-key']);
    Http::preventStrayRequests();
    Http::fake([
        'https://app.sandbox.midtrans.com/snap/v3/merchant-preferences' => Http::response([
            'error_messages' => ['private-provider-error opaque-sandbox-test-key'],
        ], $status, ['Location' => 'https://example.invalid/private']),
    ]);

    $this->artisan('midtrans:sandbox-preflight')
        ->expectsOutput('Sandbox preference request failed (HTTP '.$status.'); G-PAY remains open.')
        ->doesntExpectOutputToContain('private-provider-error')
        ->doesntExpectOutputToContain('opaque-sandbox-test-key')
        ->assertFailed();

    Http::assertSentCount(1);
})->with([401, 403, 429, 500, 302]);

test('handles a failed Sandbox connection without exposing exception details', function () {
    config(['services.midtrans.is_production' => false, 'services.midtrans.server_key' => 'opaque-sandbox-test-key']);
    Http::preventStrayRequests();
    Http::fake([
        'https://app.sandbox.midtrans.com/snap/v3/merchant-preferences' => Http::failedConnection(),
    ]);

    $this->artisan('midtrans:sandbox-preflight')
        ->expectsOutput('Sandbox connection failed; no channel capability was verified.')
        ->doesntExpectOutputToContain('opaque-sandbox-test-key')
        ->assertFailed();

    Http::assertSentCount(1);
});

test('reports an empty enabled channel set while keeping G-PAY open', function () {
    config(['services.midtrans.is_production' => false, 'services.midtrans.server_key' => 'opaque-sandbox-test-key']);
    Http::preventStrayRequests();
    Http::fake([
        'https://app.sandbox.midtrans.com/snap/v3/merchant-preferences' => Http::response(['payment_channels' => []]),
    ]);

    $this->artisan('midtrans:sandbox-preflight')
        ->expectsOutput('Enabled Sandbox channels: (none)')
        ->expectsOutput('Discovery only. G-PAY remains open until per-channel expiry, timing, and finality are verified.')
        ->assertSuccessful();

    Http::assertSentCount(1);
});
