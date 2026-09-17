<?php

namespace Database\Factories;

use App\Models\Channel;
use App\Models\ChannelAd;
use App\Models\Media;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<ChannelAd>
 */
class ChannelAdFactory extends Factory
{
    protected $model = ChannelAd::class;

    /**
     * A running image ad with no dates.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = (string) Str::ulid();

        return [
            'channel_id' => Channel::factory(),
            'title' => fake()->words(3, true),
            'type' => Media::TYPE_IMAGE,
            'mime_type' => 'image/jpeg',
            'disk' => 'public',
            'path' => "channels/1/{$name}.jpg",
            'thumbnail_path' => "channels/1/thumbs/{$name}.jpg",
            'size' => 120_000,
            'width' => 1920,
            'height' => 1080,
            'orientation' => 'landscape',
            'media_duration_seconds' => null,
            'duration_seconds' => 10,
            'position' => 0,
            'starts_on' => null,
            'ends_on' => null,
        ];
    }

    /** A video, which runs to its own length and has no seconds of its own. */
    public function video(int $seconds = 20): static
    {
        return $this->state(fn () => [
            'type' => Media::TYPE_VIDEO,
            'mime_type' => 'video/mp4',
            'path' => 'channels/1/'.Str::ulid().'.mp4',
            'media_duration_seconds' => $seconds,
            'duration_seconds' => null,
        ]);
    }

    /** How long an image shows. */
    public function lasting(int $seconds): static
    {
        return $this->state(fn () => ['duration_seconds' => $seconds]);
    }

    /** Only between these dates, both included. */
    public function running(?string $from, ?string $to): static
    {
        return $this->state(fn () => ['starts_on' => $from, 'ends_on' => $to]);
    }
}
