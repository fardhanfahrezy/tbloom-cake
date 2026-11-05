<?php

namespace App;

use App\Models\PaymentEvent;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use LogicException;

class DispatchPaymentEvents
{
    public function dispatch(int $limit = 100): int
    {
        if ($limit < 1 || $limit > 100) {
            throw new InvalidArgumentException('Payment dispatch limit must be between 1 and 100.');
        }
        if (DB::connection()->getDriverName() !== 'mysql' || DB::transactionLevel() !== 0) {
            throw new LogicException('Payment dispatch requires MySQL and no caller transaction.');
        }
        $now = CarbonImmutable::now('UTC')->startOfSecond();
        $ids = PaymentEvent::query()->where('provider', 'midtrans')
            ->where(fn ($query) => $query->where(fn ($pending) => $pending->where('processing_status', 'pending')
                ->where(fn ($due) => $due->whereNull('next_attempt_at')->orWhere('next_attempt_at', '<=', $now)))
                ->orWhere(fn ($processing) => $processing->where('processing_status', 'processing')->where('lease_until', '<=', $now)))
            ->orderBy('id')->limit($limit)->pluck('id');
        foreach ($ids as $id) {
            ProcessPaymentEventJob::dispatch($id);
        }

        return $ids->count();
    }
}
