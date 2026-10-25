<?php

namespace App;

use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Support\Str;
use InvalidArgumentException;

/** Normalizes caller-supplied Core GET evidence; does not authenticate webhook or browser input. */
final class BankTransferVerifier
{
    public function verify(
        ?int $httpStatus,
        mixed $payload,
        string $expectedReference,
        int $expectedAmount,
        DateTimeImmutable $deadline,
        ?string $expectedTransactionId = null,
        ?ReconciledPaymentTime $reconciledTime = null,
    ): BankTransferObservation {
        if (! preg_match('/\A[\x21-\x7e]{1,100}\z/', $expectedReference) || $expectedAmount < 1
            || $deadline->format('u') !== '000000'
            || ($expectedTransactionId !== null && ! Str::isUuid($expectedTransactionId))) {
            throw new InvalidArgumentException('Invalid expected payment context.');
        }
        if ($httpStatus !== 200) {
            return new BankTransferObservation(BankTransferOutcome::Unknown, 'provider_unavailable');
        }
        if (! is_array($payload) || ! in_array($payload['status_code'] ?? null, ['200', '201', '407'], true)) {
            return new BankTransferObservation(BankTransferOutcome::Unknown, 'unsupported_provider_response');
        }
        foreach (['order_id', 'transaction_id', 'gross_amount', 'currency', 'payment_type', 'transaction_status'] as $field) {
            if (! is_string($payload[$field] ?? null)) {
                return new BankTransferObservation(BankTransferOutcome::Unknown, 'malformed_identity');
            }
        }
        if (! Str::isUuid($payload['transaction_id']) || ! preg_match('/\A(?:0|[1-9][0-9]*)(?:\.00)?\z/', $payload['gross_amount'])) {
            return new BankTransferObservation(BankTransferOutcome::Unknown, 'malformed_identity');
        }
        if ($payload['order_id'] !== $expectedReference
            || ($expectedTransactionId !== null && $payload['transaction_id'] !== $expectedTransactionId)
            || ! in_array($payload['gross_amount'], [(string) $expectedAmount, $expectedAmount.'.00'], true)
            || $payload['currency'] !== 'IDR' || $payload['payment_type'] !== 'bank_transfer') {
            return new BankTransferObservation(BankTransferOutcome::Conflict, 'payment_context_mismatch');
        }
        $accounts = $payload['va_numbers'] ?? null;
        if (! is_array($accounts) || ! array_is_list($accounts) || count($accounts) !== 1
            || ! is_array($accounts[0]) || ($accounts[0]['bank'] ?? null) !== 'bca') {
            return new BankTransferObservation(BankTransferOutcome::Unknown, 'unsupported_bank');
        }

        $transactionTime = $this->timestamp($payload['transaction_time'] ?? null);
        $settlementTime = $this->timestamp($payload['settlement_time'] ?? null);
        $expiryTime = $this->timestamp($payload['expiry_time'] ?? null);
        if ($transactionTime === null
            || (isset($payload['settlement_time']) && $settlementTime === null)
            || (isset($payload['expiry_time']) && $expiryTime === null)) {
            return new BankTransferObservation(BankTransferOutcome::Unknown, 'malformed_timestamp');
        }
        $outcome = match (true) {
            $payload['status_code'] === '200' && $payload['transaction_status'] === 'settlement' => BankTransferOutcome::SuccessObserved,
            in_array($payload['status_code'], ['200', '201'], true) && $payload['transaction_status'] === 'pending' => BankTransferOutcome::Pending,
            $payload['status_code'] === '407' && $payload['transaction_status'] === 'expire' => BankTransferOutcome::ExpiredObserved,
            default => BankTransferOutcome::Unknown,
        };
        if ($outcome === BankTransferOutcome::Unknown) {
            return new BankTransferObservation($outcome, 'unsupported_status_combination');
        }
        if ($settlementTime !== null && ($outcome !== BankTransferOutcome::SuccessObserved || $settlementTime < $transactionTime)) {
            return new BankTransferObservation(BankTransferOutcome::Conflict, 'status_time_conflict');
        }
        $timing = PaymentTiming::Unknown;
        $reason = $outcome === BankTransferOutcome::SuccessObserved ? 'success_time_unverified' : 'provider_status_observed';
        if ($reconciledTime !== null) {
            if ($reconciledTime->transactionId !== $payload['transaction_id'] || $outcome !== BankTransferOutcome::SuccessObserved
                || $reconciledTime->paidAt < $transactionTime
                || ($settlementTime !== null && $reconciledTime->paidAt > $settlementTime)) {
                return new BankTransferObservation(BankTransferOutcome::Conflict, 'reconciliation_conflict');
            }
            $timing = match (true) {
                $reconciledTime->paidAt < $deadline => PaymentTiming::Timely,
                $reconciledTime->paidAt > $deadline => PaymentTiming::Late,
                default => PaymentTiming::Unknown,
            };
            $reason = $timing === PaymentTiming::Unknown ? 'boundary_requires_review' : 'reconciled_success';
        }

        return new BankTransferObservation($outcome, $reason, $payload['transaction_id'], $expectedAmount, 'bca',
            $transactionTime, $settlementTime, $expiryTime, $timing, $reconciledTime);
    }

    private function timestamp(mixed $value): ?DateTimeImmutable
    {
        if (! is_string($value) || ! preg_match('/\A[1-9][0-9]{3}-[0-9]{2}-[0-9]{2} [0-9]{2}:[0-9]{2}:[0-9]{2}\z/', $value)) {
            return null;
        }
        $date = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $value, new DateTimeZone('Asia/Jakarta'));
        if ($date === false || $date->format('Y-m-d H:i:s') !== $value) {
            return null;
        }

        return $date->setTimezone(new DateTimeZone('UTC'));
    }
}
