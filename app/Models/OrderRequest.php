<?php

namespace App\Models;

use Database\Factories\OrderRequestFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrderRequest extends Model
{
    /** @use HasFactory<OrderRequestFactory> */
    use HasFactory;

    /** @return array{type: class-string<OrderRequestType>, decision_mode: class-string<OrderRequestDecisionMode>, status: class-string<OrderRequestStatus>, requested_changes: string, submitted_at: string, reviewed_at: string, applied_at: string} */
    protected function casts(): array
    {
        return [
            'type' => OrderRequestType::class,
            'decision_mode' => OrderRequestDecisionMode::class,
            'status' => OrderRequestStatus::class,
            'requested_changes' => 'array',
            'submitted_at' => 'immutable_datetime',
            'reviewed_at' => 'immutable_datetime',
            'applied_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<Order, $this> */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<User, $this> */
    public function reviewedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }
}
