<?php

use App\MidtransSandboxProbe;
use Illuminate\Foundation\Inspiring;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

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

Artisan::command('midtrans:sandbox-phase5-probe {scenario}', function (): int {
    try {
        $result = app(MidtransSandboxProbe::class)->start($this->argument('scenario'));
    } catch (Throwable) {
        $this->error('Probe refused or outcome unknown; inspect the private approval manifest. Never create a replacement reference.');

        return 1;
    }
    $this->line(json_encode($result, JSON_THROW_ON_ERROR));

    return $result['creation_result'] === 'created' ? 0 : 1;
})->purpose('Reserve and create only the two approved Phase5 Option B Sandbox probes; URLs stay encrypted');

Artisan::command('midtrans:sandbox-status {reference} {--expected-expiry=} {--expected-amount=500000} {--record}', function (): int {
    $reference = $this->argument('reference');
    $serverKey = config('services.midtrans.server_key');
    if (! app()->environment(['local', 'testing']) || config('services.midtrans.is_production') !== false
        || ! is_string($serverKey) || trim($serverKey) === '') {
        $this->error('Sandbox status requires local/testing, sandbox mode, and a sandbox server key.');

        return 1;
    }
    if (! is_string($reference) || strlen($reference) !== 48 || ! str_starts_with($reference, 'TBLOOM-GPAY-')
        || ! Str::isUuid(substr($reference, 12))) {
        $this->error('Only a TBLOOM-GPAY synthetic UUID reference is allowed.');

        return 1;
    }
    $expectedAmount = $this->option('expected-amount');
    if (! in_array($expectedAmount, ['400000', '500000'], true)) {
        $this->error('Only the approved synthetic amounts 400000 and 500000 IDR are allowed.');

        return 1;
    }
    $validTimestamp = static function (mixed $value): bool {
        if (! is_string($value) || ! preg_match('/\A[0-9]{4}-[0-9]{2}-[0-9]{2} [0-9]{2}:[0-9]{2}:[0-9]{2}\z/', $value)) {
            return false;
        }
        $date = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $value, new DateTimeZone('Asia/Jakarta'));

        return $date !== false && $date->format('Y-m-d H:i:s') === $value;
    };
    $expectedExpiry = $this->option('expected-expiry');
    if ($expectedExpiry !== null && ! $validTimestamp($expectedExpiry)) {
        $this->error('Expected expiry must be a valid Asia/Jakarta date and time: Y-m-d H:i:s.');

        return 1;
    }
    try {
        $response = Http::withBasicAuth($serverKey, '')->acceptJson()->connectTimeout(5)->timeout(15)->withoutRedirecting()
            ->get('https://api.sandbox.midtrans.com/v2/'.$reference.'/status');
    } catch (ConnectionException) {
        $this->error('Sandbox status connection failed; the result remains unknown.');

        return 1;
    }
    if ($response->status() === 404 || ($response->successful() && $response->json('status_code') === '404')) {
        $this->warn('No selected instrument was found. This is not evidence of final unpaid closure.');

        return 1;
    }
    if (! $response->successful()) {
        $this->error('Sandbox status request failed (HTTP '.$response->status().'); G-PAY remains open.');

        return 1;
    }
    $data = $response->json();
    $isSupportedObservation = is_array($data) && (
        in_array($data['status_code'] ?? null, ['200', '201'], true)
        || (($data['status_code'] ?? null) === '407' && ($data['transaction_status'] ?? null) === 'expire')
    );
    if (! $isSupportedObservation || ($data['order_id'] ?? null) !== $reference
        || ! in_array($data['gross_amount'] ?? null, [$expectedAmount, $expectedAmount.'.00'], true) || ($data['currency'] ?? null) !== 'IDR'
        || ! in_array($data['transaction_status'] ?? null, ['pending', 'capture', 'settlement', 'deny', 'cancel', 'expire', 'refund', 'partial_refund', 'authorize'], true)
        || ! is_string($data['payment_type'] ?? null) || ! preg_match('/\A[a-z][a-z0-9_]{0,49}\z/', $data['payment_type'])) {
        $this->error('Sandbox status identity, amount, currency, or fields are invalid; no contract was verified.');

        return 1;
    }
    foreach (['transaction_time', 'settlement_time', 'expiry_time'] as $field) {
        if (array_key_exists($field, $data) && ! $validTimestamp($data[$field])) {
            $this->error('Sandbox status contains invalid timestamp fields; no contract was verified.');

            return 1;
        }
    }
    $this->line('Synthetic reference: '.$reference);
    $this->line('Provider status code: '.$data['status_code']);
    $this->line('Transaction status: '.$data['transaction_status']);
    $this->line('Payment type: '.$data['payment_type']);
    foreach (['transaction_time', 'settlement_time', 'expiry_time'] as $field) {
        $this->line($field.' (Asia/Jakarta): '.($data[$field] ?? '(not supplied)'));
    }
    if ($expectedExpiry !== null) {
        $this->line('Expected expiry match: '.(! isset($data['expiry_time']) ? 'unknown' : ($data['expiry_time'] === $expectedExpiry ? 'yes' : 'no')));
    }
    if ($this->option('record')) {
        $evidence = [
            'observed_at_utc' => now('UTC')->toIso8601String(), 'reference' => $reference,
            'expected_amount' => $expectedAmount, 'http_status' => $response->status(),
            'response_sha256' => hash('sha256', $response->body()),
            'fields' => array_intersect_key($data, array_flip(['status_code', 'transaction_status', 'payment_type', 'gross_amount', 'currency', 'transaction_time', 'settlement_time', 'expiry_time'])),
        ];
        if (! Storage::disk('local')->append(MidtransSandboxProbe::DIRECTORY.'/'.$reference.'.jsonl', json_encode($evidence, JSON_THROW_ON_ERROR))) {
            $this->error('Unable to save private redacted evidence; no contract was verified.');

            return 1;
        }
    }
    $this->warn('Observation only. G-PAY remains open; payment action timing and final closure require channel evidence.');

    return 0;
})->purpose('Read redacted status for approved synthetic 400000/500000 IDR probes, without domain mutations');
