<?php
namespace Database\Factories;
use App\Models\Category;
use Illuminate\Database\Eloquent\Factories\Factory;
/** @extends Factory<Category> */
class CategoryFactory extends Factory
{
    protected $model = Category::class;
    public function definition(): array
    {
        $name = fake()->unique()->words(2, true);
        return ['name' => ucwords($name), 'slug' => str($name)->slug()->toString(), 'description' => fake()->sentence(), 'sort_order' => 0, 'is_active' => true];
    }
}
