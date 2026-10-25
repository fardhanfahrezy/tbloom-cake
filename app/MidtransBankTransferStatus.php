<?php

namespace App;

use DateTimeImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use InvalidArgumentException;
use JsonException;

/** Read-only Sandbox boundary. Call outside domain transactions; observations authorize no mutations. */
final readonly class MidtransBankTransferStatus
{
    public function __construct(private BankTransferVerifier $verifier, private MidtransPaymentChannels $channels) {}

    public function observe(
        string $expectedReference,
        int $expectedAmount,
        DateTimeImmutable $deadline,
        ?string $expectedTransactionId = null,
        ?string $expectedChannel = MidtransPaymentChannels::BCA_VA,
    ): BankTransferObservation {
        if (! preg_match('/\A[A-Za-z0-9][A-Za-z0-9_-]{0,99}\z/', $expectedReference) || $expectedAmount < 1
            || $deadline->format('u') !== '000000'
            || ($expectedTransactionId !== null && ! Str::isUuid($expectedTransactionId))) {
            throw new InvalidArgumentException('Invalid expected payment context.');
        }

        if (! $this->channels->supportsStatusObservation($expectedChannel)) {
            return new BankTransferObservation(BankTransferOutcome::Unknown, 'unsupported_channel');
        }

        $serverKey = config('services.midtrans.server_key');
        if (! app()->environment(['local', 'testing']) || config('services.midtrans.is_production') !== false
            || ! is_string($serverKey) || ! preg_match('/\A[\x21-\x39\x3b-\x7e]{1,512}\z/', $serverKey)) {
            return new BankTransferObservation(BankTransferOutcome::Unknown, 'sandbox_not_configured');
        }

        try {
            $response = Http::acceptJson()->withBasicAuth($serverKey, '')
                ->connectTimeout(5)->timeout(15)->withoutRedirecting()
                ->get('https://api.sandbox.midtrans.com/v2/'.rawurlencode($expectedReference).'/status');
        } catch (ConnectionException|RequestException) {
            return new BankTransferObservation(BankTransferOutcome::Unknown, 'provider_unavailable');
        }

        $payload = null;
        if ($response->status() === 200) {
            try {
                $payload = json_decode($response->body(), true, 64, JSON_THROW_ON_ERROR);
            } catch (JsonException) {
                return new BankTransferObservation(BankTransferOutcome::Unknown, 'malformed_provider_response');
            }
        }

        return $this->verifier->verify($response->status(), $payload, $expectedReference, $expectedAmount, $deadline, $expectedTransactionId);
    }
}
