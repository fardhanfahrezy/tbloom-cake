<?php

namespace App;

use App\Models\NotificationDelivery;
use App\Models\NotificationDeliveryStatus;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use LogicException;
use Throwable;

class DispatchNotificationDeliveries
{
    public function __construct(private NotificationOutbox $outbox) {}

    public function dispatch(int $limit = 100): int
    {
        if ($limit < 1 || $limit > 100) {
            throw new InvalidArgumentException('Notification batch size must be between 1 and 100.');
        }
        if (config('notifications.enabled') !== true) {
            return 0;
        }
        if (DB::connection()->getDriverName() !== 'mysql' || DB::transactionLevel() !== 0) {
            throw new LogicException('Notification scanner requires MySQL without a caller transaction.');
        }
        $this->outbox->recoverUnknown($limit);
        $now = CarbonImmutable::now('UTC')->startOfSecond();
        $ids = NotificationDelivery::query()->where(fn ($query) => $query->where(fn ($pending) => $pending->where('status', NotificationDeliveryStatus::Pending)
            ->where(fn ($due) => $due->whereNull('next_attempt_at')->orWhere('next_attempt_at', '<=', $now)))
            ->orWhere(fn ($stale) => $stale->where('status', NotificationDeliveryStatus::Processing)->where('lease_until', '<=', $now)))
            ->orderBy('id')->limit($limit)->pluck('id');
        $queued = 0;
        foreach ($ids as $id) {
            try {
                ProcessNotificationDeliveryJob::dispatch($id);
                $queued++;
            } catch (Throwable) {
                Log::warning('Notification enqueue failed; durable row retained.', ['delivery_id' => $id]);
            }
        }

        return $queued;
    }
}
