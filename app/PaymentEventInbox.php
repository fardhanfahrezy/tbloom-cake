<?php

namespace App;

use App\Models\PaymentEvent;
use App\Models\PaymentEventStatus;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;
use InvalidArgumentException;
use LogicException;

class PaymentEventInbox
{
    public const MAX_ATTEMPTS = 5;

    public const LEASE_SECONDS = 120;

    private const BACKOFF_SECONDS = [30, 120, 600, 1800];

    private const RETRY_REASONS = ['provider_unavailable', 'malformed_provider_response', 'processing_failed'];

    public function claim(int $eventId): ?PaymentEvent
    {
        return $this->transaction(function () use ($eventId): ?PaymentEvent {
            $event = PaymentEvent::query()->whereKey($eventId)->lockForUpdate()->first();
            $now = CarbonImmutable::now('UTC');
            if ($event === null || ! in_array($event->processing_status, [PaymentEventStatus::Pending, PaymentEventStatus::Processing], true)
                || ($event->processing_status === PaymentEventStatus::Pending && $event->next_attempt_at?->isAfter($now))
                || ($event->processing_status === PaymentEventStatus::Processing && $event->lease_until?->isAfter($now))) {
                return null;
            }
            if ($event->payment_id === null || $event->verified_at === null || $event->provider !== 'midtrans') {
                $this->markReview($event, 'unverified_event');

                return null;
            }
            if ($event->attempts >= self::MAX_ATTEMPTS) {
                $this->markReview($event, 'retry_exhausted');

                return null;
            }
            $event->forceFill([
                'processing_status' => PaymentEventStatus::Processing, 'attempts' => $event->attempts + 1,
                'claim_token' => (string) Str::uuid(), 'lease_until' => $now->startOfSecond()->addSeconds(self::LEASE_SECONDS),
                'next_attempt_at' => null, 'error' => null,
            ])->save();

            return $event;
        });
    }

    /**
     * The callback performs DB effects only, locking domain parents in global order.
     * Inbox is locked last; a lost token or expired lease rolls back every effect.
     * Provider HTTP must finish before this method and must never run in the callback.
     *
     * @param  Closure(PaymentEvent): (PaymentReviewReason|null|void)  $effects
     */
    public function complete(PaymentEvent $claim, Closure $effects): bool
    {
        try {
            return $this->transaction(function () use ($claim, $effects): bool {
                $current = PaymentEvent::query()->find($claim->id);
                if (! $this->owns($claim, $current)) {
                    return false;
                }
                $review = $effects($claim);
                if ($review !== null && ! $review instanceof PaymentReviewReason) {
                    throw new LogicException('Inbox completion requires a typed review outcome.');
                }
                $current = PaymentEvent::query()->whereKey($claim->id)->lockForUpdate()->first();
                if (! $this->owns($claim, $current)) {
                    throw new LostPaymentEventClaim;
                }
                $current->forceFill([
                    'processing_status' => $review === null ? PaymentEventStatus::Processed : PaymentEventStatus::Review,
                    'processed_at' => $review === null ? CarbonImmutable::now('UTC')->startOfSecond() : null,
                    'claim_token' => null, 'lease_until' => null, 'next_attempt_at' => null, 'error' => $review?->value,
                ])->save();

                return true;
            });
        } catch (LostPaymentEventClaim) {
            return false;
        }
    }

    public function retry(PaymentEvent $claim, string $reason): bool
    {
        if (! in_array($reason, self::RETRY_REASONS, true)) {
            throw new InvalidArgumentException('Unsupported inbox retry reason.');
        }

        return $this->transaction(function () use ($claim, $reason): bool {
            $current = PaymentEvent::query()->whereKey($claim->id)->lockForUpdate()->first();
            if (! $this->owns($claim, $current)) {
                return false;
            }
            if ($current->attempts >= self::MAX_ATTEMPTS) {
                $this->markReview($current, 'retry_exhausted');
            } else {
                $current->forceFill([
                    'processing_status' => PaymentEventStatus::Pending,
                    'next_attempt_at' => CarbonImmutable::now('UTC')->startOfSecond()->addSeconds(self::BACKOFF_SECONDS[$current->attempts - 1]),
                    'claim_token' => null, 'lease_until' => null, 'error' => $reason,
                ])->save();
            }

            return true;
        });
    }

    private function owns(PaymentEvent $claim, ?PaymentEvent $current): bool
    {
        return $current !== null && $current->processing_status === PaymentEventStatus::Processing
            && is_string($claim->claim_token) && $current->claim_token === $claim->claim_token
            && $current->lease_until !== null && $current->lease_until->isAfter(CarbonImmutable::now('UTC'))
            && $current->payment_id !== null && $current->verified_at !== null
            && $current->payment_id === $claim->payment_id && $current->provider === $claim->provider
            && $current->provider_reference === $claim->provider_reference && $current->event_fingerprint === $claim->event_fingerprint;
    }

    private function markReview(PaymentEvent $event, string $reason): void
    {
        $event->forceFill(['processing_status' => PaymentEventStatus::Review, 'error' => $reason,
            'claim_token' => null, 'lease_until' => null, 'next_attempt_at' => null])->save();
    }

    /**
     * @template T
     *
     * @param  Closure(): T  $operation
     * @return T
     */
    private function transaction(Closure $operation): mixed
    {
        $connection = DB::connection();
        if ($connection->getDriverName() !== 'mysql' || $connection->transactionLevel() !== 0) {
            throw new LogicException('Inbox processing requires MySQL and no caller transaction.');
        }
        $settings = $connection->selectOne('SELECT @@transaction_isolation AS isolation_level, @@session.time_zone AS time_zone');
        if ($settings->isolation_level !== 'READ-COMMITTED' || $settings->time_zone !== '+00:00') {
            throw new LogicException('Inbox processing requires READ COMMITTED and UTC.');
        }
        for ($attempt = 1; ; $attempt++) {
            try {
                return $connection->transaction($operation);
            } catch (QueryException $exception) {
                if ($attempt >= 3 || ! in_array((int) ($exception->errorInfo[1] ?? 0), [1205, 1213], true)) {
                    throw $exception;
                }
                Sleep::for(random_int(20, 60) * $attempt)->milliseconds();
            }
        }
    }
}
