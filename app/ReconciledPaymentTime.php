<?php

namespace App;

use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * An application attestation after authorized reconciliation of its underlying evidence.
 * This value object validates shape, not bank authenticity or actor permissions. Never
 * construct it from webhook/redirect flags or generic Core transaction/settlement times.
 */
final readonly class ReconciledPaymentTime
{
    private function __construct(
        public string $transactionId,
        public DateTimeImmutable $paidAt,
        public string $evidenceReference,
    ) {}

    public static function fromReconciliation(string $transactionId, DateTimeImmutable $paidAt, string $evidenceReference): self
    {
        if (! Str::isUuid($transactionId) || ! preg_match('/\A[\x21-\x7e]{1,128}\z/', $evidenceReference)
            || $paidAt->format('u') !== '000000') {
            throw new InvalidArgumentException('Reconciliation requires a transaction UUID, evidence reference and second precision.');
        }

        return new self($transactionId, $paidAt->setTimezone(new DateTimeZone('UTC')), $evidenceReference);
    }
}
