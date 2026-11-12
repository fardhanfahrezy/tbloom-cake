<?php

namespace Database\Factories;

use App\Models\Product;
use App\Models\Category;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Product>
 */
class ProductFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array{name: string, slug: string, category_id: int, sales_unit_label: string}
     */
    public function definition(): array
    {
        return [
            'name' => fake()->words(3, true),
            'slug' => fake()->unique()->slug(3),
            'category_id' => Category::factory(),
            'sales_unit_label' => 'buah',
        ];
    }
}
