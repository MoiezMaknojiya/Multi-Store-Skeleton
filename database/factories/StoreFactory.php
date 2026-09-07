<?php

namespace Database\Factories;

use App\Models\Store;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Store>
 */
class StoreFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->company(),
            'street' => fake()->streetAddress(),
            'suite' => null,
            'city' => fake()->city(),
            'state' => fake()->randomElement(array_keys(Store::US_STATES)),
            'zip_code' => fake()->numerify('#####'),
            'country' => 'USA',
            'is_active' => true,
        ];
    }
}
