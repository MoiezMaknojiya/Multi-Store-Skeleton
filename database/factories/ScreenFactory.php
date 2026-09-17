<?php

namespace Database\Factories;

use App\Models\Screen;
use App\Models\Store;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Screen>
 */
class ScreenFactory extends Factory
{
    protected $model = Screen::class;

    /**
     * Define the model's default state. A factory screen is already paired —
     * an unpaired one is the exception, not the rule.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'store_id' => Store::factory(),
            'name' => fake()->randomElement(['Counter TV', 'Window Screen', 'Menu Board', 'Entrance Display']),
            'orientation' => 'landscape',
            // Set explicitly rather than left to the column default, so the model in
            // hand always agrees with the row without a refresh().
            'timezone' => Screen::DEFAULT_TIMEZONE,
            'token_hash' => hash('sha256', Str::random(64)),
            'device_uuid' => (string) Str::uuid(),
            'paired_at' => now()->subDays(2),
            'last_seen_at' => now()->subMinutes(1),
        ];
    }

    /** Pin the device token so a test can speak as this screen. */
    public function withToken(string $token): static
    {
        return $this->state(fn () => ['token_hash' => hash('sha256', $token)]);
    }

    /** Never checked in, or not for a long time — the Offline chip. */
    public function offline(): static
    {
        return $this->state(fn () => ['last_seen_at' => now()->subHours(5)]);
    }

    /** Created in the panel but no device has collected a token yet. */
    public function unpaired(): static
    {
        return $this->state(fn () => [
            'token_hash' => null,
            'device_uuid' => null,
            'paired_at' => null,
            'last_seen_at' => null,
        ]);
    }
}
