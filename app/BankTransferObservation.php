<?php

namespace App;

use DateTimeImmutable;

/** Provider observations are not authorization to fulfill, refund, or release an order. */
final readonly class BankTransferObservation
{
    public function __construct(
        public BankTransferOutcome $outcome,
        public string $reason,
        public ?string $transactionId = null,
        public ?int $amount = null,
        public ?string $bank = null,
        public ?DateTimeImmutable $transactionTime = null,
        public ?DateTimeImmutable $settlementTime = null,
        public ?DateTimeImmutable $expiryTime = null,
        public PaymentTiming $timing = PaymentTiming::Unknown,
        public ?ReconciledPaymentTime $actionTime = null,
    ) {}
}
