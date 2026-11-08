<?php

namespace App;

use App\Models\NotificationDelivery;
use App\Models\NotificationDeliveryStatus;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use LogicException;
use Throwable;

class NotificationOutbox
{
    public const MAX_ATTEMPTS = 5;

    public const LEASE_SECONDS = 120;

    public const BACKOFF_SECONDS = [30, 120, 600, 1800];

    public static function enqueue(NotificationDelivery $delivery): void
    {
        $id = $delivery->id;
        if (! is_int($id) || $id <= 0 || config('notifications.enabled') !== true) {
            return;
        }
        DB::afterCommit(static function () use ($id): void {
            if (config('notifications.enabled') !== true) {
                return;
            }
            try {
                ProcessNotificationDeliveryJob::dispatch($id);
            } catch (Throwable) {
                Log::warning('Notification enqueue failed; durable row retained.', ['delivery_id' => $id]);
            }
        });
    }

    public function deliver(int $id, NotificationTransport $transport): bool
    {
        if (config('notifications.enabled') !== true) {
            return false;
        }
        $claim = $this->claim($id);
        if (! $claim) {
            return false;
        }
        $delivery = $this->begin($id, $claim->claim_token);
        if (! $delivery) {
            return false;
        }
        try {
            $result = $transport->send($delivery);
        } catch (Throwable) {
            $result = new NotificationSendResult(NotificationDeliveryStatus::Unknown);
        }

        return $this->finish($id, $claim->claim_token, $result);
    }

    public function claim(int $id): ?NotificationDelivery
    {
        $this->guard();

        return DB::transaction(function () use ($id): ?NotificationDelivery {
            $row = NotificationDelivery::query()->whereKey($id)->lockForUpdate()->first();
            $now = CarbonImmutable::now('UTC')->startOfSecond();
            if (! $row || config('notifications.enabled') !== true
                || ! (($row->status === NotificationDeliveryStatus::Pending && ($row->next_attempt_at === null || $row->next_attempt_at <= $now))
                    || ($row->status === NotificationDeliveryStatus::Processing && $row->lease_until !== null && $row->lease_until <= $now))) {
                return null;
            }
            if ($row->attempts >= self::MAX_ATTEMPTS) {
                $row->forceFill(['status' => NotificationDeliveryStatus::Failed, 'claim_token' => null, 'lease_until' => null,
                    'next_attempt_at' => null, 'last_error' => 'retry_exhausted'])->save();

                return null;
            }
            $row->forceFill(['status' => NotificationDeliveryStatus::Processing, 'attempts' => $row->attempts + 1,
                'claim_token' => (string) Str::uuid(), 'lease_until' => $now->addSeconds(self::LEASE_SECONDS),
                'next_attempt_at' => null, 'last_error' => null])->save();

            return $row;
        }, 3);
    }

    public function begin(int $id, string $token): ?NotificationDelivery
    {
        $this->guard();

        return DB::transaction(function () use ($id, $token): ?NotificationDelivery {
            $row = NotificationDelivery::query()->whereKey($id)->lockForUpdate()->first();
            if (config('notifications.enabled') !== true || ! $this->owns($row, $token, NotificationDeliveryStatus::Processing)) {
                return null;
            }
            $row->forceFill(['status' => NotificationDeliveryStatus::Unknown, 'last_error' => 'send_started'])->save();

            return $row;
        }, 3);
    }

    public function finish(int $id, string $token, NotificationSendResult $result): bool
    {
        $this->guard();

        return DB::transaction(function () use ($id, $token, $result): bool {
            $row = NotificationDelivery::query()->whereKey($id)->lockForUpdate()->first();
            if (! $this->owns($row, $token, NotificationDeliveryStatus::Unknown)) {
                return false;
            }
            $now = CarbonImmutable::now('UTC')->startOfSecond();
            $status = $result->status;
            $exhausted = $status === NotificationDeliveryStatus::Pending && $row->attempts >= self::MAX_ATTEMPTS;
            if ($exhausted) {
                $status = NotificationDeliveryStatus::Failed;
            }
            $row->forceFill(['status' => $status, 'provider_message_id' => $result->providerMessageId,
                'sent_at' => $status === NotificationDeliveryStatus::Sent ? $now : null,
                'claim_token' => null, 'lease_until' => null,
                'next_attempt_at' => $status === NotificationDeliveryStatus::Pending ? $now->addSeconds(self::BACKOFF_SECONDS[$row->attempts - 1]) : null,
                'last_error' => $exhausted ? 'retry_exhausted' : match ($status) {
                    NotificationDeliveryStatus::Sent => null,
                    NotificationDeliveryStatus::Pending => 'provider_rejected',
                    NotificationDeliveryStatus::Unknown => 'send_outcome_unknown',
                    NotificationDeliveryStatus::Suppressed => 'notification_suppressed',
                    default => 'delivery_unavailable',
                }])->save();

            return true;
        }, 3);
    }

    public function recoverUnknown(int $limit = 100): void
    {
        $this->guard();
        $now = CarbonImmutable::now('UTC')->startOfSecond();
        $ids = NotificationDelivery::query()->where('status', NotificationDeliveryStatus::Unknown)->where('lease_until', '<=', $now)
            ->orderBy('id')->limit($limit)->pluck('id');
        foreach ($ids as $id) {
            DB::transaction(function () use ($id): void {
                $row = NotificationDelivery::query()->whereKey($id)->lockForUpdate()->first();
                if ($row?->status === NotificationDeliveryStatus::Unknown && $row->lease_until !== null && $row->lease_until <= CarbonImmutable::now('UTC')) {
                    $row->forceFill(['claim_token' => null, 'lease_until' => null, 'next_attempt_at' => null, 'last_error' => 'send_outcome_unknown'])->save();
                }
            }, 3);
        }
    }

    private function owns(?NotificationDelivery $row, string $token, NotificationDeliveryStatus $status): bool
    {
        return $row !== null && $row->status === $status && $row->claim_token !== null && hash_equals($row->claim_token, $token)
            && $row->lease_until !== null && $row->lease_until > CarbonImmutable::now('UTC')->startOfSecond();
    }

    private function guard(): void
    {
        $connection = DB::connection();
        if ($connection->getDriverName() !== 'mysql' || $connection->transactionLevel() !== 0) {
            throw new LogicException('Notification outbox requires an independent MySQL transaction.');
        }
        $session = $connection->selectOne('SELECT @@transaction_isolation AS isolation_level, @@session.time_zone AS time_zone');
        if ($session->isolation_level !== 'READ-COMMITTED' || $session->time_zone !== '+00:00') {
            throw new LogicException('Notification outbox requires READ COMMITTED and UTC.');
        }
        $retryAfter = config('queue.connections.database.retry_after');
        if (! is_int($retryAfter) || $retryAfter <= ProcessNotificationDeliveryJob::TIMEOUT_SECONDS || $retryAfter >= self::LEASE_SECONDS) {
            throw new LogicException('Notification worker timeout, queue retry_after, and outbox lease are misconfigured.');
        }
    }
}
