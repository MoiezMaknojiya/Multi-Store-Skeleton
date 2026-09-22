<?php

namespace Tests\Browser;

use App\Models\Channel;
use App\Models\ChannelAd;
use App\Models\Media;
use App\Models\Role;
use App\Models\Store;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Storage;
use Laravel\Dusk\Browser;
use Tests\DuskTestCase;

class MediaLibraryTest extends DuskTestCase
{
    use DatabaseMigrations;

    /**
     * A shop owner uploads a file, renames it, and deletes it — the whole library
     * loop through the real UI, inside one store.
     */
    public function test_a_store_user_uploads_renames_and_deletes_a_file(): void
    {
        $this->seedSuperAdmin();
        $store = Store::factory()->create(['name' => 'Alpha Mart']);

        $owner = $this->storeMember($store, ['media-view', 'media-store', 'media-update', 'media-destroy']);

        $path = $this->fixtureImage('dusk-menu.png', 30, 120, 200);

        $this->browse(function (Browser $browser) use ($owner, $store, $path) {
            $this->freshSession($browser);
            $browser->loginAs($owner);
            $this->switchToStore($browser, $store);

            // -- Empty library -------------------------------------------------
            $browser->visit('/media');
            $this->waitForAlpine($browser);
            $browser->waitForText('No media found.');

            // -- Cancel closes the upload modal (it is not the shared form modal,
            //    so it needs its own close action) -----------------------------
            $this->clickAndAwait($browser, '@upload-media', fn (Browser $b) => $b->waitFor('@media-upload-form', 3));
            $this->jsClick($browser, '@media-upload-cancel');
            $this->waitForModalClosed($browser, '@media-upload-form');

            // -- Upload --------------------------------------------------------
            $this->clickAndAwait($browser, '@upload-media', fn (Browser $b) => $b->waitFor('@media-upload-form', 3));
            $browser->attach('@media-file', $path);
            $this->jsType($browser, '@media-title', 'Breakfast Board');
            $this->jsClick($browser, '@media-upload-save');

            $browser->waitForText('Breakfast Board', 15);
            $this->waitForModalClosed($browser, '@media-upload-form');

            $media = Media::where('title', 'Breakfast Board')->firstOrFail();
            $this->assertSame($store->id, $media->store_id);
            $this->assertSame($owner->id, $media->created_by);
            $this->assertSame('landscape', $media->orientation);
            $this->assertNotNull($media->thumbnail_path);
            $this->assertTrue(Storage::disk('public')->exists($media->thumbnail_path), 'the upload made no thumbnail on disk');

            // -- Rename --------------------------------------------------------
            $this->clickAndAwait($browser, '@edit-media-'.$media->id, fn (Browser $b) => $b->waitFor('@media-form', 3));
            $this->jsType($browser, '@media-edit-title', 'Lunch Board');
            $this->jsClick($browser, '@media-save');
            $browser->waitForText('Lunch Board');
            $this->waitForModalClosed($browser, '@media-form');
            $this->assertSame('Lunch Board', $media->fresh()->title);

            // -- Delete --------------------------------------------------------
            $this->clickAndAwait($browser, '@delete-media-'.$media->id,
                fn (Browser $b) => $b->waitForText('Are you sure you want to delete', 3));
            $this->jsClick($browser, '@confirm-media-deletion-confirm');
            $browser->waitForText('No media found.');

            // Deleting through the UI removes the row and both of its files — the upload and
            // its thumbnail — so the Dusk disk (storage/app/dusk-public) is left as it was found.
            $this->assertDatabaseMissing('media', ['id' => $media->id]);
            $this->assertFalse(Storage::disk('public')->exists($media->path), 'the file outlived its row');
            $this->assertFalse(Storage::disk('public')->exists($media->thumbnail_path), 'the thumbnail outlived its row');
        });
    }

    /** The library is walled per store: a file uploaded in one store is not listed
     *  in another store the same person also works in. */
    public function test_the_library_is_scoped_to_the_store_being_worked_in(): void
    {
        $this->seedSuperAdmin();
        $alpha = Store::factory()->create(['name' => 'Alpha Mart']);
        $beta = Store::factory()->create(['name' => 'Beta Store']);

        $owner = $this->storeMember($alpha, Role::OWNER);
        $owner->stores()->attach($beta->id, ['role_id' => Role::starter(Role::OWNER)->id]);

        Media::factory()->create(['store_id' => $alpha->id, 'title' => 'Alpha Only Poster']);

        $this->browse(function (Browser $browser) use ($owner, $alpha, $beta) {
            $this->freshSession($browser);
            $browser->loginAs($owner);

            // -- Inside Alpha the file is listed -------------------------------
            $this->switchToStore($browser, $alpha);
            $browser->visit('/media');
            $this->waitForAlpine($browser);
            $browser->waitForText('Alpha Only Poster');

            // -- Inside Beta the same person sees an empty library -------------
            $this->switchToStore($browser, $beta);
            $browser->visit('/media');
            $this->waitForAlpine($browser);
            $browser->waitForText('No media found.')
                ->assertDontSee('Alpha Only Poster');
        });
    }

    /**
     * Above the stores the page reads one library at a time — the platform's own first, or a shop's — and an
     * upload joins the one chosen (docs/CHANNEL-CONTENT-SPEC.md). A file a channel shows is refused at once,
     * before any confirmation.
     */
    public function test_the_platform_reads_one_library_at_a_time_and_uploads_into_the_one_chosen(): void
    {
        $admin = $this->seedSuperAdmin();
        $alpha = Store::factory()->create(['name' => 'Alpha Mart']);
        Media::factory()->create(['store_id' => $alpha->id, 'title' => 'Alpha poster', 'thumbnail_path' => null]);
        $promo = Media::factory()->platformOwned()->create(['title' => 'Platform promo', 'thumbnail_path' => null]);
        ChannelAd::factory()->create(['channel_id' => Channel::factory()->create(['name' => 'GAMA'])->id, 'media_id' => $promo->id]);

        $path = $this->fixtureImage('dusk-alpha-menu.png', 200, 120, 30);

        $this->browse(function (Browser $browser) use ($admin, $alpha, $promo, $path) {
            $this->freshSession($browser);
            $browser->loginAs($admin)->visit('/media');
            $this->waitForAlpine($browser);

            // -- The platform's own library first --------------------------------
            $browser->waitForText('Platform promo')->assertDontSee('Alpha poster');

            // -- A file a channel shows: said at once, and no confirmation opens ---
            $this->jsClick($browser, '@delete-media-'.$promo->id);
            $browser->waitForText('Still used by the channel GAMA. Take it out of that channel first.')
                ->assertMissing('@confirm-media-deletion-confirm');
            $this->assertNotNull(Media::find($promo->id));

            // -- A shop's library, and an upload that joins it ---------------------
            $browser->select('@media-filter-library', (string) $alpha->id)
                ->waitForText('Alpha poster')
                ->assertDontSee('Platform promo');

            $this->clickAndAwait($browser, '@upload-media', fn (Browser $b) => $b->waitFor('@media-upload-form', 3));
            $browser->assertSeeIn('@media-upload-library', "It joins Alpha Mart's library.")
                ->attach('@media-file', $path);
            $this->jsType($browser, '@media-title', 'Alpha menu');
            $this->jsClick($browser, '@media-upload-save');

            $browser->waitForText('Alpha menu', 15);
            $this->waitForModalClosed($browser, '@media-upload-form');
            $this->assertSame($alpha->id, Media::where('title', 'Alpha menu')->firstOrFail()->store_id);
        });
    }
}
