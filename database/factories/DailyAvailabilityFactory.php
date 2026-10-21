<?php

namespace Database\Factories;

use App\Models\DailyAvailability;
use App\Models\Product;
use Closure;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DailyAvailability>
 */
class DailyAvailabilityFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array{product_id: ProductFactory, availability_date: string, quota_limit: Closure(array{product_id: int}): int, updated_by: null}
     */
    public function definition(): array
    {
        return [
            'product_id' => Product::factory(),
            'availability_date' => now('Asia/Jakarta')->addDay()->toDateString(),
            'quota_limit' => fn (array $attributes): int => Product::query()->findOrFail($attributes['product_id'])->default_daily_quota,
            'updated_by' => null,
        ];
    }
}
