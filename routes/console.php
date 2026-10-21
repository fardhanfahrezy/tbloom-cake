<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('midtrans:sandbox-preflight', function (): int {
    $serverKey = config('services.midtrans.server_key');

    if (! app()->environment(['local', 'testing'])
        || config('services.midtrans.is_production') !== false
        || ! is_string($serverKey)
        || trim($serverKey) === '') {
        $this->error('Sandbox preflight requires local/testing, sandbox mode, and a sandbox server key.');

        return 1;
    }

    try {
        $response = Http::withBasicAuth($serverKey, '')
            ->acceptJson()
            ->connectTimeout(5)
            ->timeout(15)
            ->withoutRedirecting()
            ->get('https://app.sandbox.midtrans.com/snap/v3/merchant-preferences');
    } catch (ConnectionException) {
        $this->error('Sandbox connection failed; no channel capability was verified.');

        return 1;
    }

    if (! $response->successful()) {
        $this->error('Sandbox preference request failed (HTTP '.$response->status().'); G-PAY remains open.');

        return 1;
    }

    $channels = $response->json('payment_channels');
    if (! is_array($channels) || ! array_is_list($channels)) {
        $this->error('Sandbox returned invalid channel preferences; G-PAY remains open.');

        return 1;
    }

    $enabledChannels = [];
    foreach ($channels as $channel) {
        if (! is_array($channel)
            || ! is_string($channel['name'] ?? null)
            || ! preg_match('/\A[a-z][a-z0-9_]{0,49}\z/', $channel['name'])
            || ! is_bool($channel['enabled'] ?? null)) {
            $this->error('Sandbox returned invalid channel preferences; G-PAY remains open.');

            return 1;
        }

        if ($channel['enabled']) {
            $enabledChannels[] = $channel['name'];
        }
    }

    $enabledChannels = array_unique($enabledChannels);
    sort($enabledChannels);
    $this->line('Enabled Sandbox channels: '.($enabledChannels === [] ? '(none)' : implode(', ', $enabledChannels)));
    $this->warn('Discovery only. G-PAY remains open until per-channel expiry, timing, and finality are verified.');

    return 0;
})->purpose('Inspect Sandbox channel preferences without closing the G-PAY gate');
