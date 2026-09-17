<?php

namespace Database\Factories;

use App\Models\Channel;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Channel>
 */
class ChannelFactory extends Factory
{
    protected $model = Channel::class;

    /**
     * A live channel that plays every one of its ads each time.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => 'Channel '.fake()->unique()->numberBetween(1, 999999),
            'ads_per_pass' => null,
            'is_active' => true,
        ];
    }

    /** Off the air, whatever its ads' dates say. */
    public function paused(): static
    {
        return $this->state(fn () => ['is_active' => false]);
    }

    /** Only this many ads each time the loop reaches it, rotating through the rest. */
    public function perPass(int $count): static
    {
        return $this->state(fn () => ['ads_per_pass' => $count]);
    }
}
