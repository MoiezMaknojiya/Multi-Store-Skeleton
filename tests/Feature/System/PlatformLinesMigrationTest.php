<?php

use App\Models\BuilderAd;
use App\Models\BuilderAsset;
use App\Models\Channel;
use App\Models\Media;
use App\Models\Organization;
use App\Models\PlaylistItem;
use App\Models\ScheduleRule;
use App\Models\Screen;
use App\Services\OrganizationStorage;
use Illuminate\Support\Facades\Storage;

/*
|--------------------------------------------------------------------------
| The platform's lines become the organizations' own (owner, 2026-10-08)
|--------------------------------------------------------------------------
|
| "Platfrom se jo template bane ha woo smart stop per copy kardo aur jis screen per jo ha woo laga do" — and Moiez Store the same.
| `2026_10_08_100200_give_every_organization_its_own_copy_of_the_platform_files_on_its_screens`: every playlist line naming a platform
| library row gets the organization's own copy — a template's page through Use This Template and Publish, a plain file copied — one
| per platform row per organization, the line keeping its place, seconds and schedule (docs/BILLING-SPEC.md §5). `down()` undoes it.
|
*/

function platformLinesMigration(): object
{
    return require database_path('migrations/2026_10_08_100200_give_every_organization_its_own_copy_of_the_platform_files_on_its_screens.php');
}

beforeEach(function () {
    Storage::fake('public');

    $this->smart = Organization::factory()->create(['name' => 'Smart Stop']);
    $this->moiez = Organization::factory()->create(['name' => 'Moiez Store']);

    // The platform's template, with a picture of the platform's in it, published into the platform's library.
    $this->photo = BuilderAsset::factory()->create(['organization_id' => null, 'title' => 'Burger photo', 'size' => 3000,
        'path' => 'builder/platform/assets/burger.webp', 'thumbnail_path' => 'builder/platform/assets/thumbs/burger.jpg']);
    Storage::disk('public')->put($this->photo->path, 'burger bytes');
    Storage::disk('public')->put($this->photo->thumbnail_path, 'burger preview');

    $document = BuilderAd::blankDocument();
    $document['elements'] = [['id' => 'pic', 'type' => 'image', 'x' => 0, 'y' => 0, 'w' => 400, 'h' => 300, 'z' => 0, 'assetId' => $this->photo->id]];
    $this->template = BuilderAd::factory()->state(['organization_id' => null, 'name' => 'SS6 Burger Menu', 'document' => $document])->published()->create();

    // A plain picture of the platform's library, with its preview.
    $this->poster = Media::factory()->platformOwned()->create(['title' => 'Platform poster', 'path' => 'media/platform/poster.jpg', 'thumbnail_path' => 'media/platform/thumbs/poster.jpg', 'size' => 2000]);
    Storage::disk('public')->put($this->poster->path, 'poster bytes');
    Storage::disk('public')->put($this->poster->thumbnail_path, 'poster preview');

    $this->tv1 = Screen::factory()->create(['organization_id' => $this->smart->id, 'name' => 'Tv1']);
    $this->tv2 = Screen::factory()->create(['organization_id' => $this->smart->id, 'name' => 'Tv2']);
    $this->counter = Screen::factory()->create(['organization_id' => $this->moiez->id, 'name' => 'Counter TV']);
    $this->ownPicture = Media::factory()->create(['organization_id' => $this->smart->id, 'title' => 'Texas Toast']);
    $this->channel = Channel::factory()->create(['name' => 'GAMA']);

    // Tv1: the template first for 30 seconds, then its own picture with a schedule, then a channel. Tv2: the template and the
    // poster. Counter TV: the template.
    $this->menuLine = PlaylistItem::create(['screen_id' => $this->tv1->id, 'media_id' => $this->template->media_id, 'position' => 0, 'duration_seconds' => 30]);
    $this->ownLine = PlaylistItem::create(['screen_id' => $this->tv1->id, 'media_id' => $this->ownPicture->id, 'position' => 1, 'duration_seconds' => 6]);
    ScheduleRule::create(['playlist_item_id' => $this->ownLine->id, 'start_time' => '09:00', 'end_time' => '17:00']);
    $this->channelLine = PlaylistItem::create(['screen_id' => $this->tv1->id, 'channel_id' => $this->channel->id, 'position' => 2]);
    $this->tv2Menu = PlaylistItem::create(['screen_id' => $this->tv2->id, 'media_id' => $this->template->media_id, 'position' => 0, 'duration_seconds' => 30]);
    $this->tv2Poster = PlaylistItem::create(['screen_id' => $this->tv2->id, 'media_id' => $this->poster->id, 'position' => 1, 'duration_seconds' => 8]);
    $this->counterMenu = PlaylistItem::create(['screen_id' => $this->counter->id, 'media_id' => $this->template->media_id, 'position' => 0, 'duration_seconds' => 30]);
});

test('every line naming a platform row plays the organization’s own copy, one copy per row per organization', function () {
    platformLinesMigration()->up();

    // Smart Stop: one ad made from the template for its two screens, published into its own library, its picture its own.
    $smartAd = BuilderAd::where('organization_id', $this->smart->id)->sole();
    $smartPage = $smartAd->media;
    $smartPhoto = BuilderAsset::where('organization_id', $this->smart->id)->sole();

    expect($smartAd->name)->toBe('SS6 Burger Menu')
        ->and($smartAd->isPublished())->toBeTrue()
        ->and($smartPage->organization_id)->toBe($this->smart->id)
        ->and($smartPage->copied_from_id)->toBe($this->template->media_id)
        ->and($smartPhoto->copied_from_id)->toBe($this->photo->id)
        ->and(Storage::disk('public')->get($smartPage->path))->toContain(basename($smartPhoto->path))->not->toContain('burger.webp');

    // The poster: a copy in Smart Stop's library, its bytes and preview with it, counted to its 512 MB.
    $smartPoster = Media::where('organization_id', $this->smart->id)->where('copied_from_id', $this->poster->id)->sole();
    expect(Storage::disk('public')->get($smartPoster->path))->toBe('poster bytes')
        ->and(Storage::disk('public')->get($smartPoster->thumbnail_path))->toBe('poster preview')
        ->and($smartPoster->path)->toStartWith("media/{$this->smart->id}/")
        ->and(app(OrganizationStorage::class)->used($this->smart->id))->toBeGreaterThan(0);

    // Every line where it was, as long as it was, its schedule kept; its own and the channel's untouched.
    expect($this->menuLine->fresh())->media_id->toBe($smartPage->id)->position->toBe(0)->duration_seconds->toBe(30)
        ->and($this->tv2Menu->fresh()->media_id)->toBe($smartPage->id)
        ->and($this->tv2Poster->fresh())->media_id->toBe($smartPoster->id)->duration_seconds->toBe(8)
        ->and($this->ownLine->fresh()->media_id)->toBe($this->ownPicture->id)
        ->and($this->ownLine->fresh()->scheduleRules()->count())->toBe(1)
        ->and($this->channelLine->fresh()->channel_id)->toBe($this->channel->id);

    // Moiez Store gets copies of its own.
    $moiezAd = BuilderAd::where('organization_id', $this->moiez->id)->sole();
    expect($this->counterMenu->fresh()->media_id)->toBe($moiezAd->media_id)
        ->and($moiezAd->media_id)->not->toBe($smartPage->id)
        ->and(BuilderAsset::where('organization_id', $this->moiez->id)->count())->toBe(1);

    // No line names a platform row any more, and the platform keeps its own.
    expect(PlaylistItem::whereIn('media_id', Media::platformOwned()->pluck('id'))->exists())->toBeFalse()
        ->and(BuilderAd::find($this->template->id))->not->toBeNull()
        ->and(Media::find($this->poster->id))->not->toBeNull();

    // Run again, it finds nothing left to do.
    $rows = [BuilderAd::count(), Media::count(), BuilderAsset::count()];
    platformLinesMigration()->up();
    expect([BuilderAd::count(), Media::count(), BuilderAsset::count()])->toBe($rows);
});

test('an organization the copy does not fit keeps the platform’s line, and nothing half made is left playing', function () {
    Media::factory()->create(['organization_id' => $this->moiez->id, 'size' => OrganizationStorage::LIMIT_BYTES, 'thumbnail_path' => null]);

    platformLinesMigration()->up();

    expect($this->counterMenu->fresh()->media_id)->toBe($this->template->media_id)
        ->and($this->menuLine->fresh()->media_id)->not->toBe($this->template->media_id);
});

test('down() points every line back at the platform’s row and takes the copies away', function () {
    platformLinesMigration()->up();
    $copies = Media::whereNotNull('copied_from_id')->get();
    expect($copies)->toHaveCount(3);

    platformLinesMigration()->down();

    expect($this->menuLine->fresh()->media_id)->toBe($this->template->media_id)
        ->and($this->tv2Menu->fresh()->media_id)->toBe($this->template->media_id)
        ->and($this->tv2Poster->fresh()->media_id)->toBe($this->poster->id)
        ->and($this->counterMenu->fresh()->media_id)->toBe($this->template->media_id)
        ->and(Media::whereNotNull('copied_from_id')->count())->toBe(0)
        ->and(BuilderAd::whereNotNull('organization_id')->count())->toBe(0);

    foreach ($copies as $copy) {
        Storage::disk('public')->assertMissing($copy->path);
    }
});
