<?php

use Dotenv\Dotenv;
use Illuminate\Support\Facades\Http;

test('copying the environment template cannot authenticate a Midtrans request', function () {
    $template = Dotenv::parse(file_get_contents(base_path('.env.example')));
    config(['services.midtrans.is_production' => false, 'services.midtrans.server_key' => $template['MIDTRANS_SERVER_KEY'] ?? null]);
    Http::preventStrayRequests();
    Http::fake(['https://app.sandbox.midtrans.com/snap/v3/merchant-preferences' => Http::response(['payment_channels' => []])]);

    $this->artisan('midtrans:sandbox-preflight')
        ->expectsOutput('Sandbox preflight requires local/testing, sandbox mode, and a sandbox server key.')
        ->assertFailed();

    Http::assertNothingSent();
});
