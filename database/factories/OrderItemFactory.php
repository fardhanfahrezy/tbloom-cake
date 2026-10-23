<?php

namespace Database\Factories;

use App\Models\DailyAvailability;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\QuotaReleaseReason;
use Closure;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OrderItem>
 */
class OrderItemFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array{product_variant_id: ProductVariantFactory, product_id: Closure, product_name_snapshot: Closure, size_snapshot: Closure, sales_unit_snapshot: Closure, flavor_code: string, flavor_label_snapshot: string, filling_code: string, filling_label_snapshot: string, cover_color: string, inscription: string, quantity: int, unit_price: Closure, line_total: Closure, order_id: Closure, daily_availability_id: Closure}
     */
    public function definition(): array
    {
        return [
            'product_variant_id' => ProductVariant::factory(),
            'product_id' => fn (array $attributes): int => ProductVariant::query()->findOrFail($attributes['product_variant_id'])->product_id,
            'product_name_snapshot' => fn (array $attributes): string => Product::query()->findOrFail($attributes['product_id'])->name,
            'size_snapshot' => fn (array $attributes): string => ProductVariant::query()->findOrFail($attributes['product_variant_id'])->size_label,
            'sales_unit_snapshot' => fn (array $attributes): string => Product::query()->findOrFail($attributes['product_id'])->sales_unit_label,
            'flavor_code' => 'test-flavor',
            'flavor_label_snapshot' => 'Rasa uji',
            'filling_code' => 'test-filling',
            'filling_label_snapshot' => 'Isian uji',
            'cover_color' => 'Warna uji',
            'inscription' => '',
            'quantity' => 1,
            'unit_price' => fn (array $attributes): int => ProductVariant::query()->findOrFail($attributes['product_variant_id'])->price,
            'line_total' => fn (array $attributes): int => $attributes['unit_price'] * $attributes['quantity'],
            'order_id' => fn (array $attributes): int => Order::factory()->create(['product_total' => $attributes['line_total']])->id,
            'daily_availability_id' => function (array $attributes): int {
                $pickupDate = Order::query()->findOrFail($attributes['order_id'])->pickup_date->toDateString();
                $existing = DailyAvailability::query()->where('product_id', $attributes['product_id'])
                    ->where('availability_date', $pickupDate)->first();

                return $existing?->id ?? DailyAvailability::factory()->create([
                    'product_id' => $attributes['product_id'],
                    'availability_date' => $pickupDate,
                ])->id;
            },
        ];
    }

    public function released(QuotaReleaseReason $reason): static
    {
        return $this->state(fn (): array => [
            'quota_released_at' => now()->startOfSecond(),
            'quota_release_reason' => $reason,
        ]);
    }
}
