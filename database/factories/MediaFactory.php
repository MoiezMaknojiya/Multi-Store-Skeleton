<?php

namespace Database\Factories;

use App\Models\Media;
use App\Models\Store;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Media>
 */
class MediaFactory extends Factory
{
    protected $model = Media::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = (string) Str::ulid();

        return [
            'store_id' => Store::factory(),
            'title' => fake()->words(3, true),
            'description' => null,
            'type' => Media::TYPE_IMAGE,
            'mime_type' => 'image/jpeg',
            'disk' => 'public',
            'path' => "media/1/{$name}.jpg",
            'thumbnail_path' => "media/1/thumbs/{$name}.jpg",
            'size' => fake()->numberBetween(50_000, 5_000_000),
            'width' => 1920,
            'height' => 1080,
            'duration_seconds' => null,
            'orientation' => 'landscape',
            'starts_at' => null,
            'expires_at' => null,
        ];
    }

    /** A video row: portrait-agnostic, with a duration the player needs. */
    public function video(): static
    {
        return $this->state(fn () => [
            'type' => Media::TYPE_VIDEO,
            'mime_type' => 'video/mp4',
            'duration_seconds' => fake()->numberBetween(5, 120),
        ]);
    }

    /** Already past its expiry window. */
    public function expired(): static
    {
        return $this->state(fn () => [
            'starts_at' => now()->subMonth(),
            'expires_at' => now()->subDay(),
        ]);
    }
}
