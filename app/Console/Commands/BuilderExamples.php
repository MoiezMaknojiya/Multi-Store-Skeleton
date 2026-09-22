<?php

namespace App\Console\Commands;

use App\Http\Requests\Builder\BuilderAdRequest;
use App\Models\ActivityLog;
use App\Models\BuilderAd;
use App\Models\BuilderAsset;
use App\Models\Store;
use App\Services\AdPublisher;
use App\Services\ExampleAds;
use App\Services\ExampleArtwork;
use App\Services\GoogleFontInstaller;
use App\Services\MediaStorage;
use Illuminate\Console\Command;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Four finished example ads in one store's Ad Builder (docs/AD-BUILDER-SPEC.md §10) — to learn the
 * editor from, and to put on a screen straight away.
 *
 * Safe to run again: the pictures and the ads are found by their names in that store and brought back
 * to the example, never duplicated. Each design goes through the same rules a save from the editor
 * does (BuilderAdRequest), so what is stored is exactly what the editor could have saved — and opens in
 * it like any other ad.
 */
class BuilderExamples extends Command
{
    protected $signature = 'builder:examples
        {store : The id of the store the ads are for}
        {--no-publish : Leave the ads as drafts instead of putting them in the media library}
        {--no-fonts : Do not download their Google fonts (the ads fall back to a system face until installed)}';

    protected $description = 'Create the example ads (Winter Sale, Fresh Coffee, Grand Opening, Burger Deal) in a store';

    public function handle(
        ExampleArtwork $artwork,
        ExampleAds $examples,
        MediaStorage $storage,
        AdPublisher $publisher,
        GoogleFontInstaller $fonts,
    ): int {
        $store = Store::find((int) $this->argument('store'));

        if ($store === null) {
            $this->error('There is no store with that id.');

            return self::FAILURE;
        }

        $this->info("Example ads for {$store->name}");

        if (! $this->option('no-fonts')) {
            $this->installFonts($fonts, $examples->families());
        }

        $assets = $this->putArtworkOnTheShelf($store, $artwork, $storage);
        $request = new BuilderAdRequest;
        $rows = [];

        foreach ($examples->designs($assets, $store->name) as $name => $document) {
            // The editor's own rules, so the stored document is one the editor could have saved.
            $validated = Validator::make(
                ['name' => $name, 'document' => $document],
                $request->rules(),
                $request->messages(),
                $request->attributes(),
            )->validate();

            $ad = BuilderAd::firstOrNew(['store_id' => $store->id, 'name' => $name]);
            $created = ! $ad->exists;

            $ad->fill(['document' => $validated['document']])->save();

            ActivityLog::record($created ? 'ad.created' : 'ad.updated', $ad, ($created ? 'Created' : 'Restored')." example ad {$ad->name}");

            $state = 'draft';

            if (! $this->option('no-publish')) {
                $media = $publisher->publish($ad);
                $state = "published (media #{$media->id})";

                // An example exists to be put on a screen, so it is one of the ads a playlist may pick
                // (owner's rule, 2026-09-22 — an ad made in the editor starts the other way round).
                BuilderAd::withoutTimestamps(fn () => $ad->update(['in_playlists' => true]));

                ActivityLog::record('ad.published', $ad, "Published example ad {$ad->name}");
            }

            $rows[] = [$ad->id, $ad->name, $created ? 'created' : 'restored', $state, route('builder.edit', $ad)];
        }

        $this->table(['Id', 'Ad', 'Row', 'State', 'Open in the editor'], $rows);

        return self::SUCCESS;
    }

    /** Each family once, for the whole installation — a failure is reported, not fatal. */
    private function installFonts(GoogleFontInstaller $fonts, array $families): void
    {
        foreach ($families as $family) {
            try {
                $fonts->install($family);
                $this->line("  font    {$family}: ready");
            } catch (Throwable $error) {
                $reason = $error instanceof ValidationException
                    ? (string) collect($error->errors())->flatten()->first()
                    : $error->getMessage();

                $this->warn("  font    {$family}: not installed ({$reason}) — the ads use a system face until it is picked in the editor.");
            }
        }
    }

    /**
     * The example pictures on this store's shelf, as {piece: asset id}. A piece already there (by its
     * title) is reused; a missing one is drawn and stored the way an upload is.
     *
     * @return array<string, int>
     */
    private function putArtworkOnTheShelf(Store $store, ExampleArtwork $artwork, MediaStorage $storage): array
    {
        $ids = [];

        foreach (ExampleArtwork::PIECES as $piece => [$title]) {
            $title = "Example · {$title}";
            $existing = BuilderAsset::where('store_id', $store->id)->where('title', $title)->first();

            if ($existing !== null) {
                $ids[$piece] = $existing->id;

                continue;
            }

            $path = (string) tempnam(sys_get_temp_dir(), 'ad-art');

            try {
                file_put_contents($path, $artwork->png($piece));

                $stored = $storage->storeBuilderAsset(new UploadedFile($path, "{$piece}.png", 'image/png', null, true), $store->id);
            } finally {
                @unlink($path);
            }

            $asset = BuilderAsset::fromStoredFile($store->id, $title, $stored, null);

            ActivityLog::record('ad_asset.uploaded', $asset, "Added {$asset->title} to the ad builder", storeId: $store->id);

            $this->line("  picture {$asset->title}");
            $ids[$piece] = $asset->id;
        }

        return $ids;
    }
}
