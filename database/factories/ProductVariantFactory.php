<?php

namespace Database\Factories;

use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProductVariant>
 */
class ProductVariantFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array{product_id: ProductFactory, size_label: string, price: int}
     */
    public function definition(): array
    {
        return [
            'product_id' => Product::factory(),
            'size_label' => fake()->unique()->bothify('size-???###'),
            'price' => fake()->numberBetween(10000, 500000),
        ];
    }
}
