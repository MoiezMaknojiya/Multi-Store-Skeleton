<?php

namespace Tests\Browser;

use App\Models\BuilderAsset;
use App\Models\Media;
use App\Models\Organization;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Laravel\Dusk\Browser;
use Tests\DuskTestCase;

/**
 * An "Added" row goes by itself after eight seconds, as a notification does (owner, 2026-09-30) — on the Media page,
 * the Assets page and in the Ad Builder editor's picker alike — while a refused row stays for its reason, a row taken
 * off early is gone at once, and "3 of 5 added" keeps counting the rows that went until the list is empty.
 */
class UploadRowsComeAndGoTest extends DuskTestCase
{
    use DatabaseMigrations;

    public function test_added_rows_fade_after_eight_seconds_and_a_refused_one_stays(): void
    {
        $organization = Organization::factory()->create(['name' => 'Alpha Mart']);
        $owner = $this->organizationMember($organization);

        $this->browse(function (Browser $browser) use ($owner, $organization) {
            $this->freshSession($browser);
            $browser->loginAs($owner);
            $this->switchToOrganization($browser, $organization);
            $browser->visit('/media');
            $this->waitForAlpine($browser);
            $browser->waitFor('@media-dropzone');

            // Three pictures and a file that is no picture at all, dropped together.
            $this->dropFiles($browser, 'media-drop', [
                "picture('One.png', 200, 40, 40)",
                "picture('Two.png', 40, 200, 40)",
                "picture('Three.png', 40, 40, 200)",
                "new File(['not a picture'], 'Notes.txt', { type: 'text/plain' })",
            ]);

            // The refused one is not in the count; the three are.
            $browser->waitForTextIn('@media-upload-summary', '3 of 3 added', 30);
            $this->assertSame(4, $this->rowCount($browser, 'media'));
            $this->assertSame('Only images (JPG, PNG, GIF, WEBP) and videos (MP4, WEBM) can be uploaded.', $this->errorOf($browser, 'media', 'Notes.txt'));
            $addedAt = microtime(true);

            // Still there a few seconds on (the three arrive within a second or so of each other)…
            $browser->pause(3000);
            $this->assertSame(4, $this->rowCount($browser, 'media'), 'an Added row went before its eight seconds');

            // …then the three go by themselves; the refused one stays, and the count goes with the batch.
            $browser->waitUsing(12, 200, fn () => $this->rowCount($browser, 'media') === 1);
            $this->assertGreaterThanOrEqual(7.5, microtime(true) - $addedAt, 'the rows went too soon');
            $browser->assertSeeIn('@media-upload-name', 'Notes.txt')->assertMissing('@media-upload-summary');
            $this->assertSame(['One', 'Three', 'Two'], Media::orderBy('title')->pluck('title')->all());

            // Taken off by hand: the list is empty.
            $browser->click('@media-upload-remove');
            $browser->waitUsing(5, 200, fn () => $this->rowCount($browser, 'media') === 0);

            // A row taken off before its time is gone at once, and a new batch counts from nothing.
            $this->dropFiles($browser, 'media-drop', ["picture('Four.png', 90, 90, 90)", "picture('Five.png', 120, 60, 30)"]);
            $browser->waitForTextIn('@media-upload-summary', '2 of 2 added', 30);
            $browser->click('@media-upload-remove');
            $browser->waitUsing(5, 200, fn () => $this->rowCount($browser, 'media') === 1)
                ->assertSeeIn('@media-upload-summary', '2 of 2 added');
            $browser->waitUsing(12, 200, fn () => $this->rowCount($browser, 'media') === 0)
                ->assertMissing('@media-upload-summary');

            // Each file stayed in the library all the same.
            $browser->waitForText('Four')->waitForText('Five');
        });
    }

    public function test_the_shelf_and_the_editors_picker_let_their_added_rows_go_too(): void
    {
        $this->seedSuperAdmin();
        $organization = Organization::factory()->create(['name' => 'Alpha Mart']);
        $designer = $this->organizationMember($organization, ['ad-view', 'ad-store', 'ad-update'], 'designer@example.com', 'Designer');

        $this->browse(function (Browser $browser) use ($designer, $organization) {
            $this->freshSession($browser);
            $browser->loginAs($designer);
            $this->switchToOrganization($browser, $organization);

            // The shelf.
            $browser->visit('/builder/assets');
            $this->waitForAlpine($browser);
            $browser->waitFor('@asset-dropzone');
            $this->dropFiles($browser, 'asset-drop', ["picture('Shelf logo.png', 10, 120, 200)"]);
            $browser->waitUsing(20, 200, fn () => BuilderAsset::where('title', 'Shelf logo')->exists());
            $browser->waitUsing(5, 200, fn () => $this->rowCount($browser, 'asset') === 1);
            $browser->waitUsing(12, 200, fn () => $this->rowCount($browser, 'asset') === 0);
            $browser->assertPresent('@asset-card-'.BuilderAsset::firstWhere('title', 'Shelf logo')->id);

            // The editor's picker: the row goes, the file stays on its grid.
            $browser->visit('/builder/create?orientation=landscape');
            $this->waitForAlpine($browser);
            $browser->waitFor('@ad-stage');
            $this->clickAndAwait($browser, '@add-image', fn (Browser $b) => $b->waitFor('@asset-picker', 3));
            $this->dropFiles($browser, 'picker-drop', ["picture('Picker badge.png', 220, 180, 20)"]);
            $browser->waitUsing(20, 200, fn () => BuilderAsset::where('title', 'Picker badge')->exists());
            $badge = BuilderAsset::firstWhere('title', 'Picker badge');
            $browser->waitFor('@pick-asset-'.$badge->id)
                ->waitUsing(12, 200, fn () => $this->rowCount($browser, 'picker') === 0)
                ->assertPresent('@pick-asset-'.$badge->id);
        });
    }

    /** What the row of the file named $name says went wrong, or null when there is no such row. */
    private function errorOf(Browser $browser, string $box, string $name): ?string
    {
        $file = json_encode($name);

        return $browser->script(<<<JS
            const row = [...document.querySelectorAll('[dusk="{$box}-upload-row"]')]
                .find((each) => each.querySelector('[dusk="{$box}-upload-name"]')?.textContent.trim() === {$file});
            return row ? row.querySelector('[dusk="{$box}-upload-error"]').textContent.trim() : null;
        JS)[0];
    }

    /** How many rows the box named $box lists. */
    private function rowCount(Browser $browser, string $box): int
    {
        return (int) $browser->script("return document.querySelectorAll('[dusk=\"{$box}-upload-row\"]').length;")[0];
    }

    /**
     * Drop files made in the page on a box, as letting go of a drag does. `picture(name, r, g, b)` makes a PNG.
     *
     * @param  list<string>  $makeFiles
     */
    private function dropFiles(Browser $browser, string $box, array $makeFiles): void
    {
        $files = implode(', ', $makeFiles);
        $browser->script(<<<JS
            window.__dropped = false;
            const picture = (name, r, g, b) => new Promise((resolve) => {
                const canvas = document.createElement('canvas');
                canvas.width = 320;
                canvas.height = 180;
                const context = canvas.getContext('2d');
                context.fillStyle = `rgb(\${r}, \${g}, \${b})`;
                context.fillRect(0, 0, 320, 180);
                canvas.toBlob((blob) => resolve(new File([blob], name, { type: 'image/png' })), 'image/png');
            });
            Promise.all([{$files}]).then((files) => {
                const list = new DataTransfer();
                files.forEach((file) => list.items.add(file));
                document.querySelector('[dusk="{$box}"]')
                    .dispatchEvent(new DragEvent('drop', { bubbles: true, cancelable: true, dataTransfer: list }));
                window.__dropped = true;
            });
        JS);
        $browser->waitUsing(30, 200, fn () => $browser->script('return window.__dropped;')[0] === true);
    }
}
