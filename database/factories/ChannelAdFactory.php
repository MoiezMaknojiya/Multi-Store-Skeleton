<?php

namespace Database\Factories;

use App\Models\Channel;
use App\Models\ChannelAd;
use App\Models\Media;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ChannelAd>
 */
class ChannelAdFactory extends Factory
{
    protected $model = ChannelAd::class;

    /**
     * A running image ad with no dates. Its file is a row of the channel's own library — the shop's for a shop's
     * channel, the platform's for the platform's — the way an upload inside the channel would have made it.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'channel_id' => Channel::factory(),
            'media_id' => fn (array $attributes) => Media::factory()->create([
                'store_id' => Channel::find($attributes['channel_id'])?->store_id,
            ])->id,
            'title' => fake()->words(3, true),
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
            'media_id' => fn (array $attributes) => Media::factory()->video()->create([
                'store_id' => Channel::find($attributes['channel_id'])?->store_id,
                'duration_seconds' => $seconds,
            ])->id,
            'duration_seconds' => null,
        ]);
    }

    /**
     * The file it shows, made in the channel's own library with these attributes — a real path for a
     * television to fetch, say, or no thumbnail for a page that must not ask for a missing one.
     *
     * @param  array<string, mixed>  $file
     */
    public function showing(array $file): static
    {
        return $this->state(fn () => [
            'media_id' => fn (array $attributes) => Media::factory()->create([
                'store_id' => Channel::find($attributes['channel_id'])?->store_id,
                ...$file,
            ])->id,
        ]);
    }

    /** A published Ad Builder page, shown for its seconds like an image. */
    public function adPage(): static
    {
        return $this->state(fn () => [
            'media_id' => fn (array $attributes) => Media::factory()->adPage()->create([
                'store_id' => Channel::find($attributes['channel_id'])?->store_id,
            ])->id,
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
