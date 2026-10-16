<?php

namespace Tests\Browser;

use App\Models\BuilderAsset;
use App\Models\Channel;
use App\Models\ChannelAd;
use App\Models\Media;
use App\Models\Organization;
use App\Models\PlayerAppRelease;
use App\Models\Upload;
use Facebook\WebDriver\Chrome\ChromeDevToolsDriver;
use Facebook\WebDriver\Exception\TimeoutException;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\File;
use Laravel\Dusk\Browser;
use Tests\DuskTestCase;
use Tests\Support\ApkFiles;

/**
 * The uploader's fixes of the review of 2026-10-15, as a person meets them:
 *  - above the organizations a file joins the library (or the owner's shelf) it was dropped on, though the page's Library or
 *    Owner list is turned to another while its bytes go up — and the page keeps the storage of the one it shows; a file cut
 *    short on one library and dropped again on another (a reload, another tab) is an upload of its own;
 *  - a file refused for what it is (a program wearing a picture's name) says why on its row with no Try Again, and its upload
 *    is gone — on the Media page, which posts the file itself, and in a channel's Add Ad, whose form posts it and forgets it;
 *  - Developer → TV App holds its box while a publish is out, and never gives up the upload the version was made from.
 *
 * A slow line is Chrome's own (DuskTestCase::network), so the page runs its real code throughout.
 */
class ReviewFixesUploadsFlowTest extends DuskTestCase
{
    use DatabaseMigrations;

    /** 128 KB a second: a picture of a megabyte takes some eight seconds, time enough to turn the page's list. */
    private const SLOW = 128 * 1024;

    public function test_a_file_dropped_on_one_library_joins_it_whatever_the_library_list_shows_when_it_is_in(): void
    {
        $admin = $this->seedSuperAdmin();
        $alpha = Organization::factory()->create(['name' => 'Alpha Mart']);
        Media::factory()->create(['organization_id' => $alpha->id, 'title' => 'Alpha poster', 'thumbnail_path' => null]);
        $path = $this->noiseGif('Platform poster.gif', 1000);

        $this->browse(function (Browser $browser) use ($admin, $alpha, $path) {
            $this->freshSession($browser);
            $browser->loginAs($admin)->visit('/media');
            $this->waitForAlpine($browser);

            // The platform's own library, which has no wall: no meter.
            $browser->waitFor('@media-dropzone')
                ->assertSelected('@media-filter-library', 'platform')
                ->assertMissing('@storage-meter');

            try {
                $this->network($browser, upload: self::SLOW);
                $browser->attach('@media-file', $path);
                $this->waitForStatus($browser, 'media', 'Platform poster.gif', '/^Uploading \d+%/', 30);

                // While it goes up, the page is turned to Alpha Mart's library, and its meter.
                $browser->select('@media-filter-library', (string) $alpha->id)
                    ->waitForText('Alpha poster')
                    ->waitFor('@storage-meter');
            } finally {
                $this->network($browser);
            }

            $this->waitForStatus($browser, 'media', 'Platform poster.gif', '/^Added/', 60);

            // It joined the library it was dropped on — the platform's, whose storage the upload was asked against.
            $this->assertNull(Media::where('title', 'Platform poster')->sole()->organization_id, 'the file joined the library shown when it was in');

            // The page still shows Alpha Mart's library and Alpha Mart's storage (the answer's storage was the platform's: none),
            // and the list brought up to date does not list a file of another library.
            $browser->pause(1000)
                ->assertSelected('@media-filter-library', (string) $alpha->id)
                ->assertVisible('@storage-meter')
                ->assertSee('Alpha poster');
            $this->assertFalse($this->listed($browser, 'table tbody tr', 'Platform poster'), "the platform's file is listed in Alpha Mart's library");
        });
    }

    public function test_a_file_dropped_on_one_owners_assets_joins_them_whatever_the_owner_list_shows_when_it_is_in(): void
    {
        $admin = $this->seedSuperAdmin();
        $alpha = Organization::factory()->create(['name' => 'Alpha Mart']);
        $path = $this->noiseGif('Platform badge.gif', 1000);

        $this->browse(function (Browser $browser) use ($admin, $alpha, $path) {
            $this->freshSession($browser);
            $browser->loginAs($admin)->visit('/builder/assets');
            $this->waitForAlpine($browser);
            $browser->waitFor('@asset-dropzone')
                ->assertSelected('@assets-filter-organization', 'platform')
                ->assertMissing('@storage-meter');

            try {
                $this->network($browser, upload: self::SLOW);
                $browser->attach('@asset-file', $path);
                $this->waitForStatus($browser, 'asset', 'Platform badge.gif', '/^Uploading \d+%/', 30);

                $browser->select('@assets-filter-organization', (string) $alpha->id)->waitFor('@storage-meter');
            } finally {
                $this->network($browser);
            }

            $this->waitForStatus($browser, 'asset', 'Platform badge.gif', '/^Added/', 60);

            $this->assertNull(BuilderAsset::where('title', 'Platform badge')->sole()->organization_id, 'the file went onto the shelf shown when it was in');

            $browser->pause(1000)
                ->assertSelected('@assets-filter-organization', (string) $alpha->id)
                ->assertVisible('@storage-meter');
            $this->assertFalse($this->listed($browser, '[dusk="assets-grid"]', 'Platform badge'), "the platform's file is listed among Alpha Mart's");
        });
    }

    public function test_a_file_refused_for_what_it_is_says_why_with_no_try_again_and_its_upload_is_gone(): void
    {
        $this->seedSuperAdmin();
        $organization = Organization::factory()->create(['name' => 'Alpha Mart']);
        $owner = $this->organizationMember($organization);
        $path = $this->programNamed('Menu board.jpg');

        $this->browse(function (Browser $browser) use ($owner, $organization, $path) {
            $this->freshSession($browser);
            $browser->loginAs($owner);
            $this->switchToOrganization($browser, $organization);
            $browser->visit('/media');
            $this->waitForAlpine($browser);
            $browser->waitFor('@media-dropzone')->attach('@media-file', $path);

            $said = $this->waitForStatus($browser, 'media', 'Menu board.jpg', '/^error: /', 30);
            $this->assertSame('error: Only images (JPG, PNG, GIF, WEBP) and videos (MP4, WEBM) can be uploaded.', $said);

            // Posting it again could only be refused again: no Try Again, and nothing waits on the server.
            $this->assertFalse($this->rowButtonShown($browser, 'media', 'Menu board.jpg', 'retry'), 'a Try Again that could only fail is offered');
            $this->assertSame(0, Upload::count(), 'the refused upload was kept');
            $this->assertSame(0, Media::count());
        });
    }

    public function test_in_a_channels_add_ad_a_file_refused_for_what_it_is_says_why_on_its_row_with_nothing_to_retry(): void
    {
        $admin = $this->seedSuperAdmin();
        $channel = Channel::factory()->create(['organization_id' => null, 'name' => 'GAMA Wholesale']);
        $path = $this->programNamed('Deal poster.jpg');

        $this->browse(function (Browser $browser) use ($admin, $channel, $path) {
            $this->freshSession($browser);
            $browser->loginAs($admin)->visit('/channels/'.$channel->id);
            $this->waitForAlpine($browser);
            $browser->waitFor('@channel-ads-empty');

            $this->jsClick($browser, '@add-channel-ad');
            $browser->waitFor('@channel-ad-form');
            $this->jsClick($browser, '@channel-ad-source-upload');
            $browser->waitFor('@channel-ad-drop')->attach('@channel-ad-file', $path);

            // Its bytes are in: the row waits for Save.
            $this->waitForStatus($browser, 'channel-ad', 'Deal poster.jpg', '/^Uploaded\. It is added when you save\.$/', 30);
            $this->jsType($browser, '@channel-ad-seconds', '12');
            $this->jsClick($browser, '@channel-ad-save');

            // The form's answer refuses the file for what it is: its row says so, no longer "Uploaded", and offers nothing
            // to retry; the upload is gone, and no ad was made.
            $said = $this->waitForStatus($browser, 'channel-ad', 'Deal poster.jpg', '/^error: /', 30);
            $this->assertSame('error: Only images (JPG, PNG, GIF, WEBP) and videos (MP4, WEBM) can be uploaded.', $said);
            $this->assertFalse($this->rowButtonShown($browser, 'channel-ad', 'Deal poster.jpg', 'retry'));
            $this->assertSame(0, Upload::count(), 'the refused upload was kept');
            $this->assertSame(0, ChannelAd::count());
            $browser->assertVisible('@channel-ad-form');

            // The form forgot the upload too: Save again asks for a file, and sends nothing. Before, it sent the save, and the
            // answer said only that the upload was gone, in place of the reason.
            $this->countRequests($browser, 'POST', '/channels/'.$channel->id.'/ads');
            $this->jsClick($browser, '@channel-ad-save');
            $browser->waitForText('Choose the file to upload.')
                ->assertDontSee('That upload is not finished, or it has expired.');
            $this->assertSame(0, $this->requestsCounted($browser), 'the save was sent with an upload the server had given up');
        });
    }

    public function test_a_file_cut_short_on_one_library_and_dropped_again_on_another_is_an_upload_of_its_own(): void
    {
        $admin = $this->seedSuperAdmin();
        $alpha = Organization::factory()->create(['name' => 'Alpha Mart']);
        $path = $this->noiseGif('Deal poster.gif', 1000);

        $this->browse(function (Browser $browser) use ($admin, $alpha, $path) {
            $this->freshSession($browser);
            $browser->loginAs($admin)->visit('/media');
            $this->waitForAlpine($browser);
            $browser->script('localStorage.clear();');
            $first = $browser->driver->getWindowHandle();

            // Cut short on the platform's library: its upload is opened, and the browser keeps where it got to.
            try {
                $this->network($browser, upload: self::SLOW);
                $browser->waitFor('@media-dropzone')->attach('@media-file', $path);
                $this->waitForStatus($browser, 'media', 'Deal poster.gif', '/^Uploading \d+%/', 30);
                $this->jsClick($browser, '@media-upload-pause');
                $this->waitForStatus($browser, 'media', 'Deal poster.gif', '/^Paused/', 10);
            } finally {
                $this->network($browser);
            }
            $this->assertSame(1, Upload::count());

            // The same file in another tab — as after a reload — dropped on Alpha Mart's library.
            $browser->driver->switchTo()->newWindow();

            try {
                $browser->visit('/media');
                $this->waitForAlpine($browser);
                $browser->waitFor('@media-dropzone')
                    ->select('@media-filter-library', (string) $alpha->id)
                    ->waitFor('@storage-meter')
                    ->attach('@media-file', $path);

                // Before: it carried on the platform's upload, and Alpha Mart's library refused it as another library's.
                $said = $this->waitForStatus($browser, 'media', 'Deal poster.gif', '/^(Added|error: )/', 60);
                $this->assertStringStartsWith('Added', $said);
                $this->assertSame($alpha->id, Media::where('title', 'Deal poster')->sole()->organization_id);
                $this->assertSame(1, Upload::count(), "the first tab's upload was carried on");
            } finally {
                $browser->driver->close();
                $browser->driver->switchTo()->window($first);
            }

            // The first tab gives its own up, so nothing is left waiting.
            $this->jsClick($browser, '@media-upload-remove');
            $browser->waitFor('@media-upload-stop');
            $this->jsClick($browser, '@media-upload-stop');
            $browser->waitUsing(10, 200, fn () => Upload::count() === 0);
        });
    }

    public function test_while_a_tv_app_version_is_published_its_box_is_held_and_its_upload_is_never_given_up(): void
    {
        $admin = $this->seedSuperAdmin();
        $folder = sys_get_temp_dir().DIRECTORY_SEPARATOR.'dusk-apk-'.bin2hex(random_bytes(4));
        mkdir($folder);
        $apk = $folder.DIRECTORY_SEPARATOR.'TheDisplaySolution-1.0.apk';
        file_put_contents($apk, ApkFiles::apk(ApkFiles::manifest(versionCode: 1, versionName: '1.0')));

        try {
            $this->browse(function (Browser $browser) use ($admin, $apk) {
                $this->freshSession($browser);
                $browser->loginAs($admin)->visit('/developer');
                $this->waitForAlpine($browser);

                $this->uploadThrough($browser, 'tv-app', $apk);
                $browser->waitFor('@tv-app-version-read')->assertAttribute('@tv-app-box-hold', 'data-held', 'false');
                $upload = Upload::where('filename', 'TheDisplaySolution-1.0.apk')->value('id');
                $this->assertNotNull($upload);
                $this->countRequests($browser, 'DELETE', '/uploads/'.$upload);

                try {
                    // A slow line: the publish is out for a few seconds, and the box is held meanwhile.
                    $this->network($browser, latency: 3000);
                    $this->jsClick($browser, '@tv-app-publish');
                    $browser->waitUsing(5, 50, fn () => $browser->attribute('@tv-app-box-hold', 'data-held') === 'true');
                    $this->assertTrue((bool) $browser->script('return document.querySelector(\'[dusk="tv-app-box-hold"]\').inert;')[0]);

                    // A real press on its × meanwhile, twice as a mouse that sends two, gives nothing up.
                    $this->presses($browser, $this->centreOf($browser, '[dusk="tv-app-upload-remove"]'), 2);
                    $browser->pause(300)->assertPresent('@tv-app-upload-row');
                } finally {
                    $this->network($browser);
                }

                $browser->waitUsing(20, 200, fn () => PlayerAppRelease::query()->exists());
                $browser->waitFor('@tv-app-release-'.PlayerAppRelease::query()->value('id'), 15)
                    ->waitUntilMissing('@tv-app-upload-row')
                    ->assertAttribute('@tv-app-box-hold', 'data-held', 'false');

                // Before: × gave the upload up under the publish (and the box's clear() gave it up again once published).
                $this->assertSame(0, $this->requestsCounted($browser), 'the upload the version was made from was given up');
            });
        } finally {
            @unlink($apk);
            @rmdir($folder);
        }
    }

    /** A GIF of noise $side pixels square (some 1.1 bytes a pixel, nothing compresses it): stored as it came, byte for byte. */
    private function noiseGif(string $name, int $side): string
    {
        $directory = storage_path('framework/testing');
        File::ensureDirectoryExists($directory);
        $path = $directory.DIRECTORY_SEPARATOR.$name;

        $image = imagecreate($side, $side);
        $colours = array_map(fn () => imagecolorallocate($image, random_int(0, 255), random_int(0, 255), random_int(0, 255)), range(0, 255));

        for ($y = 0; $y < $side; $y++) {
            for ($x = 0; $x < $side; $x++) {
                imagesetpixel($image, $x, $y, $colours[random_int(0, 255)]);
            }
        }

        imagegif($image, $path);

        return $path;
    }

    /** A program wearing a picture's name: the browser takes it for a JPEG, the server reads its bytes. */
    private function programNamed(string $name): string
    {
        $directory = storage_path('framework/testing');
        File::ensureDirectoryExists($directory);
        $path = $directory.DIRECTORY_SEPARATOR.$name;
        file_put_contents($path, "<?php echo 'pwned'; ?>\n".str_repeat('x', 2048));

        return $path;
    }

    /** What the row of $name says: its error (prefixed "error: ") when one shows, else its status; null with no such row. */
    private function statusOf(Browser $browser, string $box, string $name): ?string
    {
        $name = json_encode($name);

        return $browser->script(<<<JS
            const row = [...document.querySelectorAll('[dusk="{$box}-upload-row"]')]
                .find((each) => each.querySelector('[dusk="{$box}-upload-name"]')?.textContent.trim() === {$name});

            if (! row) return null;

            const error = row.querySelector('[dusk="{$box}-upload-error"]');
            if (error && error.offsetParent !== null && error.textContent.trim() !== '') return 'error: ' + error.textContent.trim();

            return row.querySelector('[dusk="{$box}-upload-status"]')?.textContent.trim() ?? '';
        JS)[0];
    }

    /** Wait until the row of $name says what $pattern matches; what it said. */
    private function waitForStatus(Browser $browser, string $box, string $name, string $pattern, int $seconds): string
    {
        $said = '';

        try {
            $browser->waitUsing($seconds, 100, function () use ($browser, $box, $name, $pattern, &$said) {
                $said = (string) $this->statusOf($browser, $box, $name);

                return preg_match($pattern, $said) === 1;
            });
        } catch (TimeoutException) {
            $this->fail("The row of {$name} never matched {$pattern} in {$seconds} s. It said: \"{$said}\".");
        }

        return $said;
    }

    /** Whether the row of $name shows its $button (pause, retry, remove). */
    private function rowButtonShown(Browser $browser, string $box, string $name, string $button): bool
    {
        $name = json_encode($name);

        return (bool) $browser->script(<<<JS
            const row = [...document.querySelectorAll('[dusk="{$box}-upload-row"]')]
                .find((each) => each.querySelector('[dusk="{$box}-upload-name"]')?.textContent.trim() === {$name});
            const button = row?.querySelector('[dusk="{$box}-upload-{$button}"]');

            return !! button && button.offsetParent !== null;
        JS)[0];
    }

    /** Presses sent to Chrome itself, each carrying the click count a person's double or triple click carries. */
    private function presses(Browser $browser, array $at, int $times, int $gap = 120): void
    {
        $tools = new ChromeDevToolsDriver($browser->driver);
        $tools->execute('Input.dispatchMouseEvent', ['type' => 'mouseMoved', 'x' => $at[0], 'y' => $at[1]]);

        for ($press = 1; $press <= $times; $press++) {
            foreach (['mousePressed', 'mouseReleased'] as $type) {
                $tools->execute('Input.dispatchMouseEvent', [
                    'type' => $type, 'x' => $at[0], 'y' => $at[1], 'button' => 'left',
                    'buttons' => $type === 'mousePressed' ? 1 : 0, 'clickCount' => min($press, 3),
                ]);
            }

            if ($press < $times) {
                usleep($gap * 1000);
            }
        }
    }

    /** The centre of $css in the window, brought into sight first. */
    private function centreOf(Browser $browser, string $css): array
    {
        return $browser->script('const el = document.querySelector('.json_encode($css).'); el.scrollIntoView({ block: "center" }); '
            .'const r = el.getBoundingClientRect(); return [Math.round(r.left + r.width / 2), Math.round(r.top + r.height / 2)];')[0];
    }

    /** Whether any element $selector finds holds $words. */
    private function listed(Browser $browser, string $selector, string $words): bool
    {
        $selector = json_encode($selector);
        $words = json_encode($words);

        return (bool) $browser->script("return [...document.querySelectorAll({$selector})].some((each) => each.textContent.includes({$words}));")[0];
    }
}
