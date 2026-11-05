<?php

namespace App;

use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Records an authorized human's reconciliation of genuine evidence that the
 * instrument cannot accept payment again AND no earlier credit remains pending
 * or undiscovered. Shape validation is not bank authentication or permission;
 * a reference, expired status, token, or local zero balance proves neither fact.
 */
final readonly class ReconciledUnpaidClosure
{
    private function __construct(public string $transactionId, public DateTimeImmutable $confirmedAt, public string $evidenceReference) {}

    public static function fromFinalReconciliation(string $transactionId, DateTimeImmutable $confirmedAt, string $evidenceReference): self
    {
        if (! Str::isUuid($transactionId) || ! preg_match('/\A[\x21-\x7e]{1,128}\z/', $evidenceReference) || $confirmedAt->format('u') !== '000000') {
            throw new InvalidArgumentException('Final reconciliation requires transaction UUID, private evidence reference and second precision.');
        }

        return new self($transactionId, $confirmedAt->setTimezone(new DateTimeZone('UTC')), $evidenceReference);
    }
}
