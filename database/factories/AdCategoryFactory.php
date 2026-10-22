<?php

namespace Database\Factories;

use App\Models\AdCategory;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AdCategory>
 */
class AdCategoryFactory extends Factory
{
    protected $model = AdCategory::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->randomElement(['Menu', 'Beer', 'Education', 'Hospital', 'Breakfast', 'Lunch', 'Coffee', 'Sale', 'Holiday', 'Drinks']),
        ];
    }
}
