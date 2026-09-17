<?php

namespace Tests\Browser;

use App\Models\Media;
use App\Models\Role;
use App\Models\Store;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Laravel\Dusk\Browser;
use Tests\DuskTestCase;

class MediaLibraryTest extends DuskTestCase
{
    use DatabaseMigrations;

    /** A real PNG on disk for the file input to attach. */
    private function fixtureImage(): string
    {
        $directory = storage_path('framework/testing');
        File::ensureDirectoryExists($directory);
        $path = $directory.DIRECTORY_SEPARATOR.'dusk-menu.png';

        $image = imagecreatetruecolor(640, 360);
        imagefilledrectangle($image, 0, 0, 640, 360, imagecolorallocate($image, 30, 120, 200));
        imagepng($image, $path);
        imagedestroy($image);

        return $path;
    }

    /**
     * A shop owner uploads a file, renames it, and deletes it — the whole library
     * loop through the real UI, inside one store.
     */
    public function test_a_store_user_uploads_renames_and_deletes_a_file(): void
    {
        $admin = $this->seedSuperAdmin();
        $store = Store::factory()->create(['name' => 'Alpha Mart']);

        $owner = $this->storeMember($store, ['media-view', 'media-store', 'media-update', 'media-destroy']);

        $path = $this->fixtureImage();

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

            // Deleting through the UI already removed both files from disk, so a
            // Dusk run leaves storage/app/public exactly as it found it.
            $this->assertDatabaseMissing('media', ['id' => $media->id]);
            $this->assertFalse(Storage::disk('public')->exists($media->path));
        });
    }

    /** The library is walled per store: a file uploaded in one store is not listed
     *  in another store the same person also works in. */
    public function test_the_library_is_scoped_to_the_store_being_worked_in(): void
    {
        $admin = $this->seedSuperAdmin();
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
}
