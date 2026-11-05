<?php

namespace App\Models;

use Database\Factories\PaymentFactory;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Hidden(['snap_token'])]
class Payment extends Model
{
    /** @use HasFactory<PaymentFactory> */
    use HasFactory;

    /** @return array{status: class-string<PaymentStatus>, closure_result: class-string<PaymentClosureResult>, snap_token: string, snap_requested_at: string, expected_amount: string, is_late: string, reconciliation_attempts: string, provider_paid_at: string, verified_at: string, next_reconcile_at: string, closure_verified_at: string} */
    protected function casts(): array
    {
        return [
            'status' => PaymentStatus::class,
            'closure_result' => PaymentClosureResult::class,
            'snap_token' => 'encrypted',
            'snap_requested_at' => 'immutable_datetime',
            'expected_amount' => 'integer',
            'is_late' => 'boolean',
            'reconciliation_attempts' => 'integer',
            'provider_paid_at' => 'immutable_datetime',
            'verified_at' => 'immutable_datetime',
            'next_reconcile_at' => 'immutable_datetime',
            'closure_verified_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<Order, $this> */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /** @return HasMany<PaymentEvent, $this> */
    public function events(): HasMany
    {
        return $this->hasMany(PaymentEvent::class);
    }

    /** @return BelongsTo<PaymentEvent, $this> */
    public function closureEvent(): BelongsTo
    {
        return $this->belongsTo(PaymentEvent::class, 'closure_event_id');
    }

    /** @return HasOne<RefundLog, $this> */
    public function refundLog(): HasOne
    {
        return $this->hasOne(RefundLog::class);
    }

    /** @return HasMany<RefundTransfer, $this> */
    public function refundTransfers(): HasMany
    {
        return $this->hasMany(RefundTransfer::class);
    }

    /** @return HasMany<PaymentMovement, $this> */
    public function movements(): HasMany
    {
        return $this->hasMany(PaymentMovement::class);
    }
}
