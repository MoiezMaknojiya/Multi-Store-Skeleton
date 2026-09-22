<?php

namespace Database\Factories;

use App\Models\BuilderAsset;
use App\Models\Store;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BuilderAsset>
 */
class BuilderAssetFactory extends Factory
{
    protected $model = BuilderAsset::class;

    public function definition(): array
    {
        $name = fake()->unique()->lexify('????????');

        return [
            'store_id' => Store::factory(),
            'title' => fake()->words(2, true),
            'kind' => BuilderAsset::KIND_IMAGE,
            'mime_type' => 'image/jpeg',
            'disk' => 'public',
            'path' => "builder/1/assets/{$name}.jpg",
            'thumbnail_path' => "builder/1/assets/thumbs/{$name}.jpg",
            'size' => 240_000,
            'width' => 1920,
            'height' => 1080,
        ];
    }

    public function video(): static
    {
        return $this->state(fn () => [
            'kind' => BuilderAsset::KIND_VIDEO,
            'mime_type' => 'video/mp4',
            'path' => 'builder/1/assets/'.fake()->unique()->lexify('????????').'.mp4',
            'duration_seconds' => 12,
        ]);
    }
}
