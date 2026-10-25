<?php

namespace App;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use LogicException;
use RuntimeException;

class MidtransSandboxProbe
{
    public const DIRECTORY = 'midtrans-phase5-option-b-20261024';

    /** @return array<string, mixed> */
    public function start(string $scenario): array
    {
        $serverKey = config('services.midtrans.server_key');
        if (! app()->environment(['local', 'testing']) || config('services.midtrans.is_production') !== false
            || ! is_string($serverKey) || trim($serverKey) === '' || ! in_array($scenario, ['A', 'B'], true)) {
            throw new LogicException('Only the two approved local Sandbox scenarios A and B are allowed.');
        }
        $disk = Storage::disk('local');
        $disk->makeDirectory(self::DIRECTORY);
        $path = self::DIRECTORY.'/'.$scenario.'.json';
        $reservation = @fopen($disk->path($path), 'x');
        if ($reservation === false) {
            throw new LogicException('Approval slot already reserved or unavailable; no replacement transaction is allowed.');
        }
        $instant = now('Asia/Jakarta')->startOfSecond();
        $minutes = $scenario === 'A' ? 30 : 5;
        $record = [
            'scenario' => $scenario, 'reference' => 'TBLOOM-GPAY-'.Str::uuid(), 'channel' => 'bca_va',
            'amount' => $scenario === 'A' ? 400000 : 500000, 'currency' => 'IDR',
            'created_at' => $instant->format('Y-m-d H:i:s'),
            'business_deadline' => $instant->copy()->addMinutes(30)->format('Y-m-d H:i:s'),
            'provider_expiry' => $instant->copy()->addMinutes($minutes)->format('Y-m-d H:i:s'),
            'creation_result' => 'reserved',
        ];
        try {
            $json = json_encode($record, JSON_THROW_ON_ERROR);
            if (fwrite($reservation, $json) !== strlen($json) || ! fflush($reservation)) {
                throw new RuntimeException('Cannot persist the reference; no HTTP request was sent.');
            }
        } finally {
            fclose($reservation);
        }
        try {
            $response = Http::withBasicAuth($serverKey, '')->acceptJson()->connectTimeout(5)->timeout(15)->withoutRedirecting()
                ->post('https://app.sandbox.midtrans.com/snap/v1/transactions', [
                    'transaction_details' => ['order_id' => $record['reference'], 'gross_amount' => $record['amount']],
                    'item_details' => [['id' => 'PHASE5-'.$scenario, 'price' => $record['amount'], 'quantity' => 1, 'name' => '[TEST] TBloom Phase5 '.$scenario]],
                    'enabled_payments' => ['bca_va'],
                    'expiry' => ['start_time' => $instant->format('Y-m-d H:i:s O'), 'unit' => 'minutes', 'duration' => $minutes],
                    'page_expiry' => ['unit' => 'minutes', 'duration' => $minutes],
                ]);
            $url = $response->json('redirect_url');
            $validUrl = is_string($url) && preg_match('~\Ahttps://app\.sandbox\.midtrans\.com/snap/v[0-9]+/redirection/[0-9a-f-]{36}\z~', $url);
            $record['http_status'] = $response->status();
            $record['creation_result'] = $response->successful() && $validUrl ? 'created' : 'unverified';
            if ($record['creation_result'] === 'created') {
                $record['encrypted_redirect_url'] = Crypt::encryptString($url);
            }
        } catch (ConnectionException) {
            $record['creation_result'] = 'unknown';
        }
        if (! $disk->put($path, json_encode($record, JSON_THROW_ON_ERROR))) {
            throw new RuntimeException('Outcome could not be saved; reserved reference must be reconciled without another POST.');
        }

        return Arr::except($record, ['encrypted_redirect_url']);
    }
}
