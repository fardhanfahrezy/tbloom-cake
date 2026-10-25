<?php

namespace App;

use Carbon\CarbonImmutable;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use InvalidArgumentException;
use JsonException;

final readonly class MidtransSnapSession
{
    public function __construct(private MidtransPaymentChannels $channels) {}

    public function configured(): bool
    {
        $key = config('services.midtrans.server_key');

        return app()->environment(['local', 'testing']) && config('services.midtrans.is_production') === false
            && is_string($key) && preg_match('/\A[\x21-\x39\x3b-\x7e]{1,512}\z/', $key) === 1;
    }

    /** The caller must durably reserve the attempt before calling; this method never retries. */
    public function create(string $reference, int $amount, DateTimeImmutable $createdAt, DateTimeImmutable $deadline, ?string $finishUrl = null): ?string
    {
        if (! preg_match('/\A[A-Za-z0-9][A-Za-z0-9_-]{0,49}\z/', $reference) || $amount < 1
            || $createdAt->format('u') !== '000000' || $deadline->format('u') !== '000000'
            || $deadline->getTimestamp() - $createdAt->getTimestamp() !== 1800) {
            throw new InvalidArgumentException('Invalid Snap payment context.');
        }
        if ($finishUrl !== null && (! filter_var($finishUrl, FILTER_VALIDATE_URL)
            || ! in_array(parse_url($finishUrl, PHP_URL_SCHEME), ['http', 'https'], true)
            || parse_url($finishUrl, PHP_URL_USER) !== null || parse_url($finishUrl, PHP_URL_FRAGMENT) !== null)) {
            throw new InvalidArgumentException('Invalid Snap return URL.');
        }
        $instant = CarbonImmutable::now('UTC');
        if (! $this->configured() || $instant < $createdAt || $instant >= $deadline) {
            return null;
        }
        try {
            $response = Http::acceptJson()->withBasicAuth(config('services.midtrans.server_key'), '')
                ->connectTimeout(5)->timeout(15)->withoutRedirecting()
                ->post('https://app.sandbox.midtrans.com/snap/v1/transactions', [
                    'transaction_details' => ['order_id' => $reference, 'gross_amount' => $amount],
                    'enabled_payments' => $this->channels->enabledForCheckout(),
                    'expiry' => ['start_time' => $createdAt->setTimezone(new DateTimeZone('Asia/Jakarta'))->format('Y-m-d H:i:s O'), 'unit' => 'minutes', 'duration' => 30],
                ] + ($finishUrl === null ? [] : ['callbacks' => ['finish' => $finishUrl]]));
            if ($response->status() !== 201) {
                return null;
            }
            $body = json_decode($response->body(), true, 64, JSON_THROW_ON_ERROR);
        } catch (ConnectionException|RequestException|JsonException) {
            return null;
        }
        $token = is_array($body) ? ($body['token'] ?? null) : null;
        $url = is_array($body) ? ($body['redirect_url'] ?? null) : null;
        if (! is_string($token) || ! Str::isUuid($token) || ! is_string($url)
            || ! preg_match('~\Ahttps://app\.sandbox\.midtrans\.com/snap/v[1-9][0-9]*/(?:vtweb|redirection)/'.preg_quote($token, '~').'\z~', $url)) {
            return null;
        }

        return $token;
    }

    public function urlForToken(#[\SensitiveParameter] string $token): ?string
    {
        return $this->configured() && Str::isUuid($token) ? 'https://app.sandbox.midtrans.com/snap/v2/vtweb/'.$token : null;
    }
}
