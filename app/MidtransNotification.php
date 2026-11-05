<?php

namespace App;

use JsonException;

class MidtransNotification
{
    /** @return array<string, string> */
    public function verify(string $body): array
    {
        abort_if(strlen($body) > 65536, 413, 'Notification too large.');
        try {
            $decoded = json_decode($body, false, 16, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            abort(422, 'Invalid notification.');
        }
        abort_unless($decoded instanceof \stdClass, 422, 'Invalid notification.');
        $payload = (array) $decoded;
        $patterns = [
            'order_id' => '/\A[A-Za-z0-9][A-Za-z0-9_-]{0,127}\z/',
            'status_code' => '/\A[0-9]{3}\z/',
            'gross_amount' => '/\A(?:0|[1-9][0-9]{0,18})(?:\.[0-9]{2})?\z/',
            'signature_key' => '/\A[0-9a-f]{128}\z/',
            'transaction_status' => '/\A[a-z_]{1,64}\z/',
        ];
        foreach ($patterns as $field => $pattern) {
            abort_unless(is_string($payload[$field] ?? null) && preg_match($pattern, $payload[$field]) === 1, 422, 'Invalid notification.');
        }
        $key = config('services.midtrans.server_key');
        abort_unless(is_string($key) && preg_match('/\A[\x21-\x39\x3b-\x7e]{1,512}\z/', $key) === 1, 503, 'Notification verification unavailable.');
        $signature = hash('sha512', $payload['order_id'].$payload['status_code'].$payload['gross_amount'].$key);
        abort_unless(hash_equals($signature, $payload['signature_key']), 403, 'Invalid notification signature.');

        $redacted = [];
        foreach (['order_id', 'status_code', 'gross_amount', 'transaction_status', 'transaction_id', 'payment_type', 'currency', 'fraud_status', 'transaction_time', 'settlement_time', 'expiry_time', 'refund_key', 'refund_amount'] as $field) {
            if (! array_key_exists($field, $payload)) {
                continue;
            }
            abort_unless(is_string($payload[$field]) && preg_match('/\A[\x20-\x7e]{1,128}\z/', $payload[$field]) === 1, 422, 'Invalid notification.');
            $redacted[$field] = $payload[$field];
        }

        return $redacted;
    }
}
