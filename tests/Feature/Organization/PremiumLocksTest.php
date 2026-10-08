<?php

use App\Models\BuilderAd;
use App\Models\Channel;
use App\Models\ChannelAd;
use App\Models\Media;
use App\Models\Organization;
use App\Models\PlaylistItem;
use App\Models\Screen;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| The two locks (owner, 2026-10-07: point 2, approved from the mockups)
|--------------------------------------------------------------------------
|
| Nothing is hidden, it is shown with a lock. Premium Templates locked: the gallery lists every template and its Preview, and Use
| This Template is refused. Platform Channels locked: the Channels tab lists the platform's channels marked locked, and no platform
| channel goes onto a screen that does not carry it yet — a line already there stays on its playlist, marked locked, and plays nothing
| until they are unlocked, then plays by itself (owner, 2026-10-08: "us line per remove aur unlock aye bs aur screen se bhi hat jaye").
| Everything is open for now ("abhi sub k liya premium khula rakho"); these hold the day the platform locks one (docs/BILLING-SPEC.md §6).
|
*/

beforeEach(function () {
    Storage::fake('public');

    $this->organization = Organization::factory()->create(['name' => 'Smart Stop']);
    $this->person = createOrganizationUser($this->organization, ['ad-view', 'ad-store', 'screen-view', 'screen-playlist'], 'Manager');
    $this->actingAs($this->person)->withSession(['current_organization_id' => $this->organization->id]);

    $this->template = BuilderAd::factory()->state(['organization_id' => null, 'name' => 'Burger menu'])->published()->create();
    $this->screen = Screen::factory()->withToken('locks-tok')->create(['organization_id' => $this->organization->id, 'name' => 'Tv1']);
    $this->gama = Channel::factory()->create(['name' => 'GAMA']);
    ChannelAd::factory()->lasting(10)->create(['channel_id' => $this->gama->id, 'media_id' => Media::factory()->platformOwned()->create()->id]);
    $this->own = Channel::factory()->create(['organization_id' => $this->organization->id, 'name' => 'Our deals']);
});

function lock(Organization $organization, string $feature): void
{
    $organization->forceFill(["{$feature}_unlocked" => false])->save();
}

/** What Tv1 is handed: the items it plays now, and every line its offline timeline names. */
function televisionOfTv1(TestCase $test): array
{
    $manifest = $test->withHeader('Authorization', 'Bearer locks-tok')->getJson('/device/playlist')->assertOk()->json();

    return [
        'blank' => $manifest['blank'],
        'now' => collect($manifest['items'])->pluck('type')->all(),
        'timeline' => collect($manifest['timeline']['lines'])->pluck('type')->unique()->sort()->values()->all(),
    ];
}

function savePlaylistOf(TestCase $test, Screen $screen, array $items): TestResponse
{
    $version = $test->getJson("/screens/{$screen->id}/playlist")->assertOk()->json('version');

    return $test->putJson("/screens/{$screen->id}/playlist", ['version' => $version, 'items' => $items]);
}

/* ── Premium Templates ───────────────────────────────────────────────────── */

test('open, as everything is for now, a template is used', function () {
    $this->getJson('/builder/templates?orientation=landscape')->assertOk()
        ->assertJsonPath('locked', false)
        ->assertJsonPath('organization_name', 'Smart Stop');

    $this->postJson("/builder/templates/{$this->template->id}")->assertOk();
});

test('locked, the gallery still lists every template and its Preview, and Use This Template is refused in words', function () {
    lock($this->organization, 'premium_templates');

    $this->getJson('/builder/templates?orientation=landscape')->assertOk()
        ->assertJsonPath('locked', true)
        ->assertJsonPath('templates.0.id', $this->template->id);
    $this->get("/builder/{$this->template->id}/preview")->assertOk();

    $this->postJson("/builder/templates/{$this->template->id}")->assertForbidden()
        ->assertJsonPath('message', 'Premium Templates are locked for Smart Stop. Contact us to unlock them.');

    expect(BuilderAd::where('organization_id', $this->organization->id)->count())->toBe(0);

    // The page says it with a lock and the one unlock dialog.
    $this->get('/builder')->assertOk()
        ->assertSee('dusk="templates-locked"', false)
        ->assertSee('Premium Templates are locked for Smart Stop.')
        ->assertSee('dusk="unlock-premium-templates"', false)
        ->assertSee('Unlock Premium Templates');
});

test('a copy made before the lock stays the organization’s, and unlocked again the templates are used', function () {
    $copy = $this->postJson("/builder/templates/{$this->template->id}")->assertOk()->json('ad.id');
    lock($this->organization, 'premium_templates');

    $this->postJson("/builder/{$copy}/duplicate")->assertOk();
    expect(BuilderAd::where('organization_id', $this->organization->id)->count())->toBe(2);

    $this->organization->forceFill(['premium_templates_unlocked' => true])->save();
    $this->postJson("/builder/templates/{$this->template->id}")->assertOk();
});

/* ── Platform Channels ───────────────────────────────────────────────────── */

test('locked, the Channels tab marks the platform’s channels and leaves the organization’s own free', function () {
    lock($this->organization, 'platform_channels');

    $answer = $this->getJson("/screens/{$this->screen->id}/available-channels")->assertOk();
    $channels = collect($answer->json('channels'))->keyBy('id');

    expect($answer->json('platform_channels_locked'))->toBeTrue()
        ->and($channels[$this->gama->id]['locked'])->toBeTrue()
        ->and($channels[$this->own->id]['locked'])->toBeFalse();

    $this->get("/screens/{$this->screen->id}")->assertOk()
        ->assertSee('dusk="channels-locked"', false)
        ->assertSee('Platform channels are locked for Smart Stop.')
        ->assertSee('dusk="unlock-platform-channels"', false);
});

test('locked, no platform channel goes onto a screen that does not carry it, while the organization’s own goes on', function () {
    lock($this->organization, 'platform_channels');

    savePlaylistOf($this, $this->screen, [['channel_id' => $this->gama->id]])->assertStatus(422)
        ->assertJsonValidationErrors(['items' => 'GAMA is a platform channel, and Platform Channels are locked for Smart Stop. Contact us to unlock them.']);

    savePlaylistOf($this, $this->screen, [['channel_id' => $this->own->id]])->assertOk();
    expect(PlaylistItem::where('screen_id', $this->screen->id)->pluck('channel_id')->all())->toBe([$this->own->id]);
});

test('a platform channel a screen carries already stays on it, whatever else the save changes', function () {
    PlaylistItem::create(['screen_id' => $this->screen->id, 'channel_id' => $this->gama->id, 'position' => 0]);
    lock($this->organization, 'platform_channels');

    $picture = Media::factory()->create(['organization_id' => $this->organization->id]);
    savePlaylistOf($this, $this->screen, [['media_id' => $picture->id, 'duration_seconds' => 10], ['channel_id' => $this->gama->id]])->assertOk();

    expect(PlaylistItem::where('screen_id', $this->screen->id)->orderBy('position')->pluck('channel_id')->all())->toBe([null, $this->gama->id]);
});

test('locked, Copy to Other Screens does not carry a platform channel to a screen that lacks it, and does to one that has it', function () {
    PlaylistItem::create(['screen_id' => $this->screen->id, 'channel_id' => $this->gama->id, 'position' => 0]);
    $bare = Screen::factory()->create(['organization_id' => $this->organization->id, 'name' => 'Tv2']);
    $carrying = Screen::factory()->create(['organization_id' => $this->organization->id, 'name' => 'Tv3']);
    PlaylistItem::create(['screen_id' => $carrying->id, 'channel_id' => $this->gama->id, 'position' => 0]);
    lock($this->organization, 'platform_channels');

    $this->postJson("/screens/{$this->screen->id}/playlist/copy", ['target_screen_ids' => [$bare->id]])->assertStatus(422)
        ->assertJsonValidationErrors(['items' => 'GAMA is a platform channel, and Platform Channels are locked for Smart Stop. Contact us to unlock them.']);
    expect(PlaylistItem::where('screen_id', $bare->id)->exists())->toBeFalse();

    $this->postJson("/screens/{$this->screen->id}/playlist/copy", ['target_screen_ids' => [$carrying->id]])->assertOk();
});

test('locked, a platform channel line stays on the playlist marked locked and plays nothing; unlocked, it plays again by itself', function () {
    $picture = Media::factory()->create(['organization_id' => $this->organization->id]);
    PlaylistItem::create(['screen_id' => $this->screen->id, 'media_id' => $picture->id, 'duration_seconds' => 10, 'position' => 0]);
    PlaylistItem::create(['screen_id' => $this->screen->id, 'channel_id' => $this->gama->id, 'position' => 1]);
    PlaylistItem::create(['screen_id' => $this->screen->id, 'channel_id' => $this->own->id, 'position' => 2]);
    ChannelAd::factory()->lasting(8)->create(['channel_id' => $this->own->id, 'media_id' => Media::factory()->create(['organization_id' => $this->organization->id])->id]);

    expect(televisionOfTv1($this)['now'])->toBe(['image', 'channel', 'channel']);

    lock($this->organization, 'platform_channels');

    // The page keeps every line and says which one is locked; the organization's own channel is free.
    $lines = collect($this->getJson("/screens/{$this->screen->id}/playlist")->assertOk()->json('items'));
    expect($lines->pluck('type')->all())->toBe(['image', 'channel', 'channel'])
        ->and($lines[1]['locked'])->toBeTrue()
        ->and($lines[2]['locked'])->toBeFalse()
        ->and($lines[0])->not->toHaveKey('locked');

    // The television plays the picture and the organization's own channel, now and in its offline timeline.
    $television = televisionOfTv1($this);
    expect($television['now'])->toBe(['image', 'channel'])
        ->and(collect($this->withHeader('Authorization', 'Bearer locks-tok')->getJson('/device/playlist')->json('items'))->pluck('id')->all())
        ->not->toContain('p'.PlaylistItem::where('channel_id', $this->gama->id)->value('id'));

    // A save of the page as it stands keeps the locked line where it was.
    savePlaylistOf($this, $this->screen, [
        ['media_id' => $picture->id, 'duration_seconds' => 10], ['channel_id' => $this->gama->id], ['channel_id' => $this->own->id],
    ])->assertOk();
    expect(PlaylistItem::where('screen_id', $this->screen->id)->orderBy('position')->pluck('channel_id')->all())
        ->toBe([null, $this->gama->id, $this->own->id]);

    // Unlocked, nobody touches the playlist: the line plays again on the next answer.
    $this->organization->forceFill(['platform_channels_unlocked' => true])->save();
    expect(televisionOfTv1($this)['now'])->toBe(['image', 'channel', 'channel'])
        ->and(collect($this->getJson("/screens/{$this->screen->id}/playlist")->json('items'))->pluck('locked')->filter()->all())->toBe([]);
});

test('locked, the timeline names no ad of a locked platform channel at any moment', function () {
    $picture = Media::factory()->create(['organization_id' => $this->organization->id]);
    PlaylistItem::create(['screen_id' => $this->screen->id, 'media_id' => $picture->id, 'duration_seconds' => 10, 'position' => 0]);
    PlaylistItem::create(['screen_id' => $this->screen->id, 'channel_id' => $this->gama->id, 'position' => 1]);

    expect(televisionOfTv1($this)['timeline'])->toBe(['channel', 'image']);

    lock($this->organization, 'platform_channels');

    expect(televisionOfTv1($this)['timeline'])->toBe(['image']);
});

test('locked, a screen whose only line is a platform channel shows its holding picture, or black, never "add content"', function () {
    PlaylistItem::create(['screen_id' => $this->screen->id, 'channel_id' => $this->gama->id, 'position' => 0]);
    lock($this->organization, 'platform_channels');

    // Black: there is a playlist, and nothing of it may play.
    expect(televisionOfTv1($this))->toMatchArray(['blank' => true, 'now' => []]);

    $holding = Media::factory()->create(['organization_id' => $this->organization->id]);
    $this->screen->forceFill(['default_media_id' => $holding->id])->save();

    expect(televisionOfTv1($this))->toMatchArray(['blank' => false, 'now' => ['image']]);
});

test('open, as everything is for now, a platform channel goes on', function () {
    $this->getJson("/screens/{$this->screen->id}/available-channels")->assertOk()->assertJsonPath('platform_channels_locked', false);

    savePlaylistOf($this, $this->screen, [['channel_id' => $this->gama->id]])->assertOk();
});
