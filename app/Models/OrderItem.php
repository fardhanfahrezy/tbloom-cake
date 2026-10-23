<?php

namespace App\Models;

use Database\Factories\OrderItemFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrderItem extends Model
{
    public const UPDATED_AT = null;

    /** @use HasFactory<OrderItemFactory> */
    use HasFactory;

    /** @return array{quantity: string, unit_price: string, line_total: string, quota_released_at: string, quota_release_reason: class-string<QuotaReleaseReason>} */
    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'unit_price' => 'integer',
            'line_total' => 'integer',
            'quota_released_at' => 'immutable_datetime',
            'quota_release_reason' => QuotaReleaseReason::class,
        ];
    }

    /** @return BelongsTo<Order, $this> */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /** @return BelongsTo<Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /** @return BelongsTo<ProductVariant, $this> */
    public function productVariant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class);
    }

    /** @return BelongsTo<DailyAvailability, $this> */
    public function dailyAvailability(): BelongsTo
    {
        return $this->belongsTo(DailyAvailability::class);
    }
}
