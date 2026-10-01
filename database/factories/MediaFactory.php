<?php

namespace Database\Factories;

use App\Models\Media;
use App\Models\Organization;
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
            'organization_id' => Organization::factory(),
            'title' => fake()->words(3, true),
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

    /** A file of the platform's own library, which belongs to no organization. */
    public function platformOwned(): static
    {
        return $this->state(fn () => ['organization_id' => null]);
    }

    /** A published Ad Builder page, as AdPublisher writes one. */
    public function adPage(): static
    {
        return $this->state(fn () => [
            'type' => Media::TYPE_HTML,
            'mime_type' => 'text/html',
            'path' => 'builder/1/ads/'.fake()->unique()->numberBetween(1, 1_000_000).'/index.html',
            'size' => 4_000,
        ]);
    }
}
