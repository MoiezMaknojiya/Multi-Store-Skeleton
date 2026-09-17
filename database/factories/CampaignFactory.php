<?php

namespace Database\Factories;

use App\Models\Campaign;
use App\Models\Media;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Campaign>
 */
class CampaignFactory extends Factory
{
    protected $model = Campaign::class;

    /**
     * A live image advert with no limits on when it runs.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = (string) Str::ulid();

        return [
            'name' => 'Campaign '.fake()->unique()->numberBetween(1, 999999),
            'advertiser_name' => fake()->randomElement(['Coca-Cola', 'Nestlé', 'Lays', 'Pepsi']),
            'type' => Media::TYPE_IMAGE,
            'mime_type' => 'image/jpeg',
            'disk' => 'public',
            'path' => "campaigns/{$name}.jpg",
            'thumbnail_path' => "campaigns/thumbs/{$name}.jpg",
            'size' => 120_000,
            'width' => 1920,
            'height' => 1080,
            'media_duration_seconds' => null,
            'duration_seconds' => 15,
            'starts_on' => null,
            'ends_on' => null,
            'start_time' => null,
            'end_time' => null,
            'is_active' => true,
        ];
    }

    /** A video advert, which runs to its own length rather than a typed one. */
    public function video(int $seconds = 20): static
    {
        return $this->state(fn () => [
            'type' => Media::TYPE_VIDEO,
            'mime_type' => 'video/mp4',
            'path' => 'campaigns/'.Str::ulid().'.mp4',
            'media_duration_seconds' => $seconds,
        ]);
    }

    /** Only inside a window of the day, on each screen's own clock. */
    public function between(string $start, string $end): static
    {
        return $this->state(fn () => ['start_time' => $start, 'end_time' => $end]);
    }

    /** A contract with a beginning and an end. */
    public function running(string $from, string $to): static
    {
        return $this->state(fn () => ['starts_on' => $from, 'ends_on' => $to]);
    }

    /** Switched off, whatever its dates say. */
    public function paused(): static
    {
        return $this->state(fn () => ['is_active' => false]);
    }

    /** How long it occupies the break. */
    public function lasting(int $seconds): static
    {
        return $this->state(fn () => ['duration_seconds' => $seconds]);
    }
}
