<?php

namespace Tests\Browser;

use App\Models\Media;
use App\Models\PlaylistItem;
use App\Models\Role;
use App\Models\Screen;
use App\Models\Store;
use App\Models\User;
use App\Services\MediaStorage;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Laravel\Dusk\Browser;
use Tests\DuskTestCase;

/**
 * Zero to hero — the product exactly as a paying customer meets it.
 *
 * Most browser tests put their member into a store straight through the
 * database. That proves each feature, but never the road a real customer takes:
 * the public signup form, and the starter Owner role it hands out. Break that
 * assignment, or drop a permission from the Owner, and all of those tests still
 * pass while every new shop owner is stuck on day one.
 *
 * So this test hand-builds nothing and grants nothing. It signs up on the public
 * form and then does the whole job with whatever the Owner role gives: upload files,
 * adopt a TV, build a playlist, watch the picture appear, change it, empty it,
 * and finally revoke the screen.
 */
class ZeroToHeroTest extends DuskTestCase
{
    use DatabaseMigrations;

    /** Upload one file through the real modal and return its row. */
    private function upload(Browser $panel, string $path, string $title): Media
    {
        $this->clickAndAwait($panel, '@upload-media', fn (Browser $b) => $b->waitFor('@media-upload-form', 5));

        // The "select a store first" banner belongs to people who work above the stores. A
        // fresh owner has exactly one and is already inside it. Asked with the form OPEN:
        // the banner lives in this modal, so with the modal shut it would be missing anyway.
        $panel->assertDontSeeIn('@media-upload-form', 'Select a store first');

        $panel->attach('@media-file', $path);
        $this->jsType($panel, '@media-title', $title);
        $this->jsClick($panel, '@media-upload-save');

        $panel->waitForText($title, 20);
        $this->waitForModalClosed($panel, '@media-upload-form');

        return Media::where('title', $title)->firstOrFail();
    }

    /**
     * Take the test's uploads back off the disk and prove they are gone.
     *
     * The TV goes to a quiet page first: a <video> holds its file open and on
     * Windows an open file will not unlink, so deleting while the player is live
     * fails silently. Leaving the player also stops its timers, so nothing on that
     * page is still asking the server anything while the files go.
     */
    private function removeUploads(Browser $tv, Media ...$media): void
    {
        $tv->visit('/login');

        foreach ($media as $item) {
            app(MediaStorage::class)->delete($item);

            foreach (array_filter([$item->path, $item->thumbnail_path]) as $path) {
                $this->assertFalse(
                    Storage::disk('public')->exists($path),
                    "left {$path} on disk — a Dusk run must not litter storage/app/dusk-public"
                );
            }
        }
    }

    public function test_a_new_customer_goes_from_signing_up_to_a_picture_on_their_tv(): void
    {
        // Seeds the super admin AND the Owner role signup relies on.
        // Nothing else is created by hand anywhere in this test.
        $this->seedSuperAdmin();

        $posterPath = $this->fixtureImage('hero-poster.png', 205, 55, 85);
        $secondPath = $this->fixtureImage('hero-second.png', 35, 135, 205);

        $this->browse(function (Browser $panel, Browser $tv) use ($posterPath, $secondPath) {

            /* ── 1. A stranger signs themselves up ───────────────────────── */
            $this->freshSession($panel);
            $panel->visit('/register')->waitFor('@register-form');
            $this->waitForAlpine($panel);

            $this->jsType($panel, '#first_name', 'Bilal');
            $this->jsType($panel, '#last_name', 'Ahmed');
            $this->jsType($panel, '#phone', '3001234567');
            $this->jsType($panel, '#email', 'bilal@example.com');
            $this->jsType($panel, '#password', 'password123');
            $this->jsType($panel, '#password_confirmation', 'password123');
            $this->jsType($panel, '#store_name', 'Bilal Mart');
            $this->jsType($panel, '#street', '12 Tariq Road');
            $this->jsType($panel, '#city', 'Austin');
            $panel->select('#state', 'TX');
            $this->jsType($panel, '#zip_code', '73301');

            $panel->press('Create Account')
                ->waitForLocation('/dashboard')
                ->waitForText('Bilal Mart');

            $owner = User::where('email', 'bilal@example.com')->firstOrFail();
            $store = Store::where('name', 'Bilal Mart')->firstOrFail();

            /* ── 2. The navigation an Owner earns, and what it does not ─── */
            $this->assertSame(Role::starter(Role::OWNER)->id, (int) DB::table('store_user')
                ->where(['user_id' => $owner->id, 'store_id' => $store->id])->value('role_id'));

            $panel->assertPresent('#main-sidebar a[href$="/screens"]')
                ->assertPresent('#main-sidebar a[href$="/media"]')
                ->assertPresent('#main-sidebar a[href$="/members"]')
                // The store's own settings are a tab of Settings — the name at the foot of the sidebar.
                ->assertPresent('@sidebar-settings')
                // The platform's pages: an Owner runs a store, never the platform.
                ->assertMissing('#main-sidebar a[href$="/stores"]')
                ->assertMissing('#main-sidebar a[href$="/permissions"]')
                ->assertMissing('#main-sidebar a[href$="/activity"]');

            /* ── 3. Straight to work: one store means no store to pick ──── */
            $panel->visit('/media');
            $this->waitForAlpine($panel);
            $panel->waitForText('No media found.');

            $poster = $this->upload($panel, $posterPath, 'Opening Poster');
            $second = $this->upload($panel, $secondPath, 'Second Board');

            $this->assertSame($store->id, $poster->store_id);
            $this->assertSame($owner->id, $poster->created_by);
            $this->assertNotNull($poster->thumbnail_path);

            /* ── 4. The TV asks to be adopted ────────────────────────────── */
            $tv->visit('/player');
            $tv->waitFor('@pairing-code', 20);
            $tv->waitUntil('document.querySelector(\'[dusk="pairing-code"]\').textContent.trim().length === 6', 20);
            $code = trim($tv->text('@pairing-code'));

            /* ── 5. The owner adopts it, from their own panel ────────────── */
            $panel->visit('/screens');
            $this->waitForAlpine($panel);
            $panel->waitForText('No screens found.');

            $this->clickAndAwait($panel, '@add-screen', fn (Browser $b) => $b->waitFor('@screen-pair-form', 5));
            $this->jsType($panel, '@screen-code', $code);
            $this->jsType($panel, '@screen-name', 'Counter TV');
            $this->jsClick($panel, '@screen-pair-save');
            $panel->waitForText('Counter TV', 15);

            $screen = Screen::where('name', 'Counter TV')->firstOrFail();
            $this->assertSame($store->id, $screen->store_id);
            $this->assertNotNull($screen->paired_at);

            /* ── 6. Adopted, but nothing to show yet ─────────────────────── */
            $tv->waitForText('No content', 60);
            // The screen names itself here — this is the only place it appears,
            // since the message is replaced the moment content starts playing.
            $tv->assertSee('Counter TV');

            /* ── 7. Fill the playlist ────────────────────────────────────── */
            $panel->visit("/screens/{$screen->id}");
            $this->waitForAlpine($panel);
            $panel->waitForText('Nothing here yet');

            // The library on the right loads separately from the playlist on the
            // left, so wait for the row itself rather than for the page.
            $panel->waitFor('@playlist-add-'.$poster->id, 15);
            $this->jsClick($panel, '@playlist-add-'.$poster->id);
            $panel->waitForText('1 item');
            $this->jsClick($panel, '@playlist-add-'.$second->id);
            $panel->waitForText('2 items');
            $this->jsClick($panel, '@playlist-save');
            $panel->waitUsing(15, 200, fn () => PlaylistItem::where('screen_id', $screen->id)->count() === 2);

            $saved = PlaylistItem::where('screen_id', $screen->id)->orderBy('position')->get();
            $this->assertSame([$poster->id, $second->id], $saved->pluck('media_id')->all());
            $this->assertSame([10, 10], $saved->pluck('duration_seconds')->all()); // the image default

            /* ── 8. The picture actually reaches the wall ────────────────── */
            $visible = '#layer-a:not([hidden]) img, #layer-b:not([hidden]) img';
            $srcOf = fn () => $tv->script("return (document.querySelector('{$visible}') || {}).src || '';")[0];

            $tv->waitUsing(45, 250, fn () => str_contains($srcOf(), basename($poster->path)));
            $tv->waitUntilMissingText('No content');

            // Present in the DOM is not the same as shown. naturalWidth is only
            // non-zero once the browser has actually fetched and decoded the file,
            // so this is what proves the media disk's URL really serves — Dusk now
            // serves from its own prefix, and a broken one would still leave an
            // <img> element sitting there looking fine to a src check.
            $this->assertGreaterThan(0, $tv->script("return (document.querySelector('{$visible}') || {}).naturalWidth || 0;")[0],
                'the image is on screen but never loaded');

            // …and it moves on to the second file by itself.
            $tv->waitUsing(30, 250, fn () => str_contains($srcOf(), basename($second->path)));

            /* ── 9. The owner changes their mind: retime and reorder ─────── */
            $this->jsType($panel, '@playlist-duration-0', '4');
            $this->jsClick($panel, '@playlist-down-0');       // poster moves to second
            $this->jsClick($panel, '@playlist-save');
            $panel->waitUsing(15, 200, function () use ($screen, $second) {
                $first = PlaylistItem::where('screen_id', $screen->id)->orderBy('position')->first();

                return $first && $first->media_id === $second->id;
            });

            $reordered = PlaylistItem::where('screen_id', $screen->id)->orderBy('position')->get();
            $this->assertSame([$second->id, $poster->id], $reordered->pluck('media_id')->all());
            $this->assertSame(4, $reordered->last()->duration_seconds, 'the retimed row did not follow its file');

            // The rows are in the database before the answer reaches the page, and the page then redraws
            // its list from that answer — a click made in between is redrawn away. Wait for the answer:
            // the Save button goes quiet only once it has landed.
            $panel->waitUntil('document.querySelector(\'[dusk="playlist-save"]\').disabled', 10);

            /* ── 10. Emptying the playlist puts the wall back to "No content" */
            $this->jsClick($panel, '@playlist-remove-0');
            $panel->waitForText('1 item');
            $this->jsClick($panel, '@playlist-remove-0');
            $panel->waitForText('0 items');
            $this->jsClick($panel, '@playlist-save');
            $panel->waitUsing(15, 200, fn () => PlaylistItem::where('screen_id', $screen->id)->count() === 0);

            $tv->waitForText('No content', 45);

            /* ── 11. Deleting the screen is how a device is revoked ──────── */
            $panel->visit('/screens');
            $this->waitForAlpine($panel);
            $panel->waitForText('Counter TV');

            $this->clickAndAwait($panel, '@delete-screen-'.$screen->id,
                fn (Browser $b) => $b->waitForText('Are you sure you want to delete', 5));
            $this->jsClick($panel, '@confirm-screen-deletion-confirm');
            $panel->waitForText('No screens found.', 15);

            // The TV loses its token on its next call and asks to be adopted again,
            // with a NEW code — it does not sit on a dead screen.
            $tv->waitFor('@pairing-code', 60);
            $tv->waitUntil('document.querySelector(\'[dusk="pairing-code"]\').textContent.trim().length === 6', 20);
            $this->assertNotSame($code, trim($tv->text('@pairing-code')), 'the revoked screen was offered its old code');

            $this->assertDatabaseMissing('screens', ['id' => $screen->id]);

            /* ── Leave the disk exactly as we found it ───────────────────── */
            $this->removeUploads($tv, $poster, $second);
        });
    }
}
