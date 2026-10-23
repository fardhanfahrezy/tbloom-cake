<?php

namespace App;

use Illuminate\Contracts\Config\Repository;
use InvalidArgumentException;
use LogicException;

class NotificationRecipientIdentity
{
    public function __construct(private Repository $configuration) {}

    public function key(NotificationChannel $channel, string $canonicalRecipient): string
    {
        return $this->candidateKeys($channel, $canonicalRecipient)[0];
    }

    /**
     * Writers must match every candidate under the domain parent lock before inserting a delivery.
     *
     * @return list<string>
     */
    public function candidateKeys(NotificationChannel $channel, string $canonicalRecipient): array
    {
        if ($canonicalRecipient === '' || trim($canonicalRecipient) !== $canonicalRecipient) {
            throw new InvalidArgumentException('A nonempty canonical recipient is required.');
        }

        $previous = $this->configuration->get('notifications.previous_recipient_hmac_keys', []);
        if (! is_array($previous) || ! array_is_list($previous)) {
            throw new LogicException('Recipient identity requires a valid HMAC keyring.');
        }

        $encoded = 'recipient:v1:'.pack('N', strlen($channel->value)).$channel->value
            .pack('N', strlen($canonicalRecipient)).$canonicalRecipient;
        $digests = [];
        foreach ([$this->configuration->get('notifications.recipient_hmac_key'), ...$previous] as $key) {
            $digests[] = hash_hmac('sha256', $encoded, $this->decodeKey($key));
        }

        return array_values(array_unique($digests));
    }

    private function decodeKey(mixed $encoded): string
    {
        if (! is_string($encoded) || ! str_starts_with($encoded, 'base64:')) {
            throw new LogicException('Recipient identity requires a dedicated base64 HMAC key.');
        }

        $decoded = base64_decode(substr($encoded, 7), true);
        if ($decoded === false || strlen($decoded) !== 32) {
            throw new LogicException('Recipient identity requires a dedicated 32-byte HMAC key.');
        }

        return $decoded;
    }
}
