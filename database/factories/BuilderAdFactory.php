<?php

namespace Database\Factories;

use App\Models\BuilderAd;
use App\Models\Media;
use App\Models\Store;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BuilderAd>
 */
class BuilderAdFactory extends Factory
{
    protected $model = BuilderAd::class;

    public function definition(): array
    {
        return [
            'store_id' => Store::factory(),
            'name' => fake()->words(2, true).' ad',
            'document' => BuilderAd::blankDocument(),
        ];
    }

    /**
     * Published and unchanged since, the way AdPublisher leaves it: its page is an html row of its store's
     * library, and the version on the screens is kept — this very design and name.
     */
    public function published(): static
    {
        $now = now();

        return $this->state(fn () => [
            'media_id' => fn (array $attributes) => Media::factory()->adPage()->create([
                'store_id' => $attributes['store_id'],
                'title' => $attributes['name'],
            ])->id,
            'published_at' => $now,
            'published_document' => fn (array $attributes) => $attributes['document'],
            'published_name' => fn (array $attributes) => $attributes['name'],
            'updated_at' => $now,
            // Published AND open to the shop's own playlists — an ad already in use, which is what the
            // migration made of every ad published before the tick existed. An ad kept for channels is
            // `create(['in_playlists' => false])`, and publishing through the endpoint leaves it that way.
            'in_playlists' => true,
        ]);
    }

    /**
     * For a screen mounted upright: a 1080 × 1920 stage (docs/AD-BUILDER-SPEC.md §12). The design made so
     * far keeps its elements and background; only the frame turns.
     */
    public function portrait(): static
    {
        return $this->state(function (array $attributes) {
            $document = $attributes['document'] ?? BuilderAd::blankDocument();
            [$document['stage']['width'], $document['stage']['height']] = BuilderAd::stageSize(BuilderAd::PORTRAIT);

            return ['orientation' => BuilderAd::PORTRAIT, 'document' => $document];
        });
    }

    /** An ad with one line of text on it, the smallest design worth testing against. */
    public function withText(string $text = 'Winter sale'): static
    {
        return $this->state(fn (array $attributes) => [
            'document' => [
                ...BuilderAd::blankDocument($attributes['orientation'] ?? BuilderAd::LANDSCAPE),
                'elements' => [[
                    'id' => 'el_text', 'type' => 'text', 'name' => 'Headline',
                    'x' => 160, 'y' => 240, 'w' => 1200, 'h' => 200,
                    'rotation' => 0, 'opacity' => 1, 'z' => 0, 'locked' => false, 'visible' => true,
                    'text' => $text,
                    'style' => ['fontSize' => 96, 'fontWeight' => 700, 'color' => '#ffffff', 'align' => 'left'],
                    'animations' => [],
                ]],
            ],
        ]);
    }
}
