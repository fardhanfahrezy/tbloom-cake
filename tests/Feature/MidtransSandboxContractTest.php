<?php

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

beforeEach(function () {
    if (config('services.midtrans.run_sandbox_contract_tests') !== true) {
        $this->markTestSkipped('Live Sandbox probes require explicit MIDTRANS_SANDBOX_CONTRACT_TESTS=true.');
    }

    if (! app()->environment('testing')
        || config('database.default') !== 'mysql_testing'
        || config('services.midtrans.is_production') !== false
        || ! is_string(config('services.midtrans.server_key'))
        || trim(config('services.midtrans.server_key')) === '') {
        $this->fail('Live probes require testing, tbloom_testing, sandbox mode, and a server key.');
    }
});

test('accepts an absolute Snap expiry payload while an unselected session remains not found in Core API', function (array $channels, int $ageMinutes) {
    $reference = 'TBLOOM-GPAY-'.Str::uuid();
    $http = Http::withBasicAuth(config('services.midtrans.server_key'), '')
        ->acceptJson()->connectTimeout(5)->timeout(15)->withoutRedirecting();

    try {
        $response = $http->post('https://app.sandbox.midtrans.com/snap/v1/transactions', [
            'transaction_details' => ['order_id' => $reference, 'gross_amount' => 500000],
            'item_details' => [['id' => 'SYNTHETIC-PROBE', 'price' => 500000, 'quantity' => 1, 'name' => '[TEST] Sandbox contract probe']],
            'enabled_payments' => $channels,
            'credit_card' => ['secure' => true],
            'expiry' => [
                'start_time' => now('Asia/Jakarta')->subMinutes($ageMinutes)->format('Y-m-d H:i:s O'),
                'unit' => 'minutes',
                'duration' => 30,
            ],
        ]);

        $this->assertTrue($response->successful(), 'Snap creation failed for synthetic reference '.$reference.' (HTTP '.$response->status().').');
        $this->assertTrue(is_string($response->json('token')) && $response->json('token') !== '', 'Snap did not return a session token.');

        $status = $http->get('https://api.sandbox.midtrans.com/v2/'.$reference.'/status');
        $this->assertTrue(in_array($status->status(), [200, 404], true), 'Unexpected transport response for Core status. Reference '.$reference);
        $this->assertTrue($status->json('status_code') === '404');
    } catch (ConnectionException) {
        $this->fail('Sandbox connection failed for synthetic reference '.$reference.'; the result is unknown and must not be retried with a new reference automatically.');
    }
})->with([
    'merchant channels at session creation' => [[
        'akulaku', 'alfamart', 'bca_va', 'bni_va', 'bri_va', 'bsi_va', 'cimb_va',
        'credit_card', 'dana', 'danamon_va', 'echannel', 'google_pay', 'gopay',
        'indomaret', 'kredivo', 'other_qris', 'other_va', 'ovo', 'permata_va',
        'saqu_va', 'seabank_va', 'shopeepay',
    ], 0],
    'BCA session creation delayed ten minutes' => [['bca_va'], 10],
]);
