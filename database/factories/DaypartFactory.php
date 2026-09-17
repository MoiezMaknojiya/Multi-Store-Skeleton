<?php

namespace Database\Factories;

use App\Models\Daypart;
use App\Models\Store;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Daypart>
 */
class DaypartFactory extends Factory
{
    protected $model = Daypart::class;

    /**
     * An ordinary shop window that opens and closes on the same day.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'store_id' => Store::factory(),
            // Unique per store in the database, so the name only has to avoid
            // colliding with its own siblings inside one test.
            'name' => 'Hours '.fake()->unique()->numberBetween(1, 999999),
            'start_time' => '09:00',
            'end_time' => '17:00',
            'is_retired' => false,
        ];
    }

    /** A window that runs past midnight — the case that breaks naive comparisons. */
    public function overnight(): static
    {
        return $this->state(fn () => ['start_time' => '22:00', 'end_time' => '02:00']);
    }

    /** Still working where it is used, gone from every picker. */
    public function retired(): static
    {
        return $this->state(fn () => ['is_retired' => true]);
    }

    /** Pin the hours a test actually cares about. */
    public function between(string $start, string $end): static
    {
        return $this->state(fn () => ['start_time' => $start, 'end_time' => $end]);
    }
}
