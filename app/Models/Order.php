<?php

namespace App\Models;

use Database\Factories\OrderFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Order extends Model
{
    /** @use HasFactory<OrderFactory> */
    use HasFactory;

    /**
     * @return array{status: class-string<OrderStatus>, pickup_method: class-string<PickupMethod>, product_total: string, pickup_date: string, payment_expires_at: string, paid_at: string, expired_at: string, cancelled_at: string, completed_at: string, courier_terms_accepted_at: string}
     */
    protected function casts(): array
    {
        return [
            'status' => OrderStatus::class,
            'pickup_method' => PickupMethod::class,
            'product_total' => 'integer',
            'pickup_date' => 'immutable_date:Y-m-d',
            'payment_expires_at' => 'immutable_datetime',
            'paid_at' => 'immutable_datetime',
            'expired_at' => 'immutable_datetime',
            'cancelled_at' => 'immutable_datetime',
            'completed_at' => 'immutable_datetime',
            'courier_terms_accepted_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<User, $this> */
    public function handedOverBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'handed_over_by');
    }

    /** @return HasOne<OrderItem, $this> */
    public function item(): HasOne
    {
        return $this->hasOne(OrderItem::class);
    }
}
