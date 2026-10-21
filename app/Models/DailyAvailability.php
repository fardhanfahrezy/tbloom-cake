<?php

namespace App\Models;

use Database\Factories\DailyAvailabilityFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['product_id', 'availability_date', 'quota_limit', 'updated_by'])]
class DailyAvailability extends Model
{
    /** @use HasFactory<DailyAvailabilityFactory> */
    use HasFactory;

    /**
     * @return array{availability_date: string, quota_limit: string}
     */
    protected function casts(): array
    {
        return [
            'availability_date' => 'immutable_date:Y-m-d',
            'quota_limit' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
