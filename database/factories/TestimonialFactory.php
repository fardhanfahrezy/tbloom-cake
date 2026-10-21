<?php

namespace Database\Factories;

use App\Models\Testimonial;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Testimonial>
 */
class TestimonialFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array{display_name: string, content: string}
     */
    public function definition(): array
    {
        return [
            'display_name' => '[TEST] '.fake()->firstName(),
            'content' => 'Konten sintetis untuk pengujian, bukan ulasan customer.',
        ];
    }
}
