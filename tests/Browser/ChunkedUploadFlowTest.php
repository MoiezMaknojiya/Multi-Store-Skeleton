<?php

namespace Tests\Browser;

use App\Models\Campaign;
use App\Models\Media;
use App\Models\Store;
use App\Models\Upload;
use App\Models\User;
use Facebook\WebDriver\Chrome\ChromeDevToolsDriver;
use Facebook\WebDriver\Exception\TimeoutException;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Storage;
use Laravel\Dusk\Browser;
use Tests\DuskTestCase;

/**
 * The uploader every page that takes a file shares (docs/UPLOADS-SPEC.md, owner 2026-09-29), as a person meets it:
 * files dropped on the box go up together, each on a row of its own; a file larger than a chunk goes up in pieces and
 * arrives whole; a connection that drops is waited out — no reload, nothing chosen again — and an upload can be
 * paused, resumed and cancelled; and a form holds its Save until its file is in.
 *
 * A slow or a lost connection is Chrome's own network emulation, so the page runs its real code throughout. The files
 * are made in the page: pictures drawn on a canvas, and "noise" pictures that no compression can shrink, for sizes
 * past a chunk.
 */
class ChunkedUploadFlowTest extends DuskTestCase
{
    use DatabaseMigrations;

    /** Half a megabyte a second: slow enough to act on an upload on its way. */
    private const SLOW = 512 * 1024;

    public function test_files_dropped_on_the_box_go_up_together_and_join_the_library(): void
    {
        $store = Store::factory()->create(['name' => 'Alpha Mart']);
        $owner = $this->storeMember($store);

        $this->browse(function (Browser $browser) use ($owner, $store) {
            $this->openMediaUpload($browser, $owner, $store);

            // A drag over the box says what letting go does.
            $browser->script(<<<'JS'
                document.querySelector('[dusk="media-drop"]')
                    .dispatchEvent(new DragEvent('dragenter', { bubbles: true, cancelable: true, dataTransfer: new DataTransfer() }));
            JS);
            $browser->waitForTextIn('@media-drop', 'Let go to upload');

            $this->drop($browser, 'media-drop', [
                "window.__picture('Breakfast menu.png', 200, 40, 40)",
                "window.__picture('Lunch menu.png', 40, 200, 40)",
                "window.__picture('Dinner menu.png', 40, 40, 200)",
            ]);

            $browser->waitForTextIn('@media-upload-summary', '3 of 3 added', 30)
                ->assertDontSeeIn('@media-drop', 'Let go to upload');

            foreach (['Breakfast menu.png', 'Lunch menu.png', 'Dinner menu.png'] as $name) {
                $this->assertSame('Added', $this->statusOf($browser, 'media', $name));
            }

            // Each is in the library, titled by its name, and nothing is left on its way.
            $this->assertSame(['Breakfast menu', 'Dinner menu', 'Lunch menu'], Media::orderBy('title')->pluck('title')->all());
            $this->assertSame(0, Upload::count(), 'an upload was left behind');

            $this->jsClick($browser, '@media-upload-close');
            $this->waitForModalClosed($browser, '@media-upload-form');
            // The list refreshes 400 ms after a file joins it (onUploaded), so a refresh between two files can show the
            // first alone for a moment: each is waited for.
            $browser->waitForText('Breakfast menu')->waitForText('Lunch menu')->waitForText('Dinner menu');
        });
    }

    public function test_a_picture_larger_than_a_chunk_goes_up_in_pieces_and_arrives_whole(): void
    {
        $store = Store::factory()->create(['name' => 'Alpha Mart']);
        $owner = $this->storeMember($store);

        $this->browse(function (Browser $browser) use ($owner, $store) {
            $this->openMediaUpload($browser, $owner, $store);
            $this->countChunks($browser);

            $this->choose($browser, 'media-file', "window.__noise('Big poster.png', 1800)");
            $this->waitForStatus($browser, 'media', 'Big poster.png', '/^Added$/', 60);

            $media = Media::sole();
            $sent = $browser->script("return window.__digests['Big poster.png'];")[0];

            // Every byte, in the order sent: the same picture, byte for byte, as the one chosen.
            $this->assertSame($sent['size'], $media->size);
            $this->assertSame($sent['sha256'], hash('sha256', (string) Storage::disk('public')->get($media->path)));

            // In 5 MB pieces, one request each.
            $pieces = (int) ceil($media->size / (5 * 1024 * 1024));
            $this->assertGreaterThanOrEqual(2, $pieces, 'the picture fits one chunk: it proves nothing');
            $this->assertSame($pieces, $browser->script('return window.__chunks;')[0]);
        });
    }

    public function test_an_upload_waits_out_a_lost_connection_and_can_be_paused_resumed_and_cancelled(): void
    {
        $store = Store::factory()->create(['name' => 'Alpha Mart']);
        $owner = $this->storeMember($store);

        $this->browse(function (Browser $browser) use ($owner, $store) {
            $this->openMediaUpload($browser, $owner, $store);
            $browser->script('window.__samePage = true;');

            try {
                $this->network($browser, self::SLOW);
                $this->choose($browser, 'media-file', "window.__noise('Slow poster.png', 1300)");
                $this->waitForStatus($browser, 'media', 'Slow poster.png', '/^Uploading ([1-9]\d?)%/', 60);

                // Paused: it stays where it is.
                $this->clickInRow($browser, 'media', 'Slow poster.png', 'pause');
                $paused = $this->waitForStatus($browser, 'media', 'Slow poster.png', '/^Paused at \d+%$/', 10);
                $browser->pause(1500);
                $this->assertSame($paused, $this->statusOf($browser, 'media', 'Slow poster.png'));

                // Resumed: on it goes.
                $this->clickInRow($browser, 'media', 'Slow poster.png', 'pause');
                $this->waitForStatus($browser, 'media', 'Slow poster.png', '/^Uploading \d+%/', 10);

                // The connection drops: the row says so, and waits.
                $this->network($browser, self::SLOW, offline: true);
                $this->waitForStatus($browser, 'media', 'Slow poster.png', '/^Connection lost\. Waiting for the internet…$/', 10);
                $browser->pause(2000);
                $this->assertSame(0, Media::count());

                // Back, at full speed: it carries on from where the server kept it, on the same page.
                $this->network($browser, null);
                $this->waitForStatus($browser, 'media', 'Slow poster.png', '/^Added$/', 60);
                $this->assertSame(1, Media::count());
                $this->assertTrue($browser->script('return window.__samePage === true;')[0], 'the page was loaded again');
                $this->assertSame($browser->script("return window.__digests['Slow poster.png'].sha256;")[0],
                    hash('sha256', (string) Storage::disk('public')->get(Media::sole()->path)));

                // Cancelled on its way: the row goes, and so does the upload on the server.
                $this->network($browser, self::SLOW);
                $this->choose($browser, 'media-file', "window.__noise('Changed my mind.png', 1300)");
                $this->waitForStatus($browser, 'media', 'Changed my mind.png', '/^Uploading \d+%/', 60);
                $this->assertSame(1, Upload::count());

                $this->clickInRow($browser, 'media', 'Changed my mind.png', 'remove');
                $browser->waitUsing(10, 200, fn () => $this->statusOf($browser, 'media', 'Changed my mind.png') === null);
                $browser->waitUsing(20, 200, fn () => Upload::count() === 0, 'the cancelled upload stayed on the server');
                $this->assertSame(1, Media::count());
            } finally {
                $this->network($browser, null);
            }
        });
    }

    public function test_a_form_holds_its_save_until_its_file_is_in(): void
    {
        $admin = $this->seedSuperAdmin();

        $this->browse(function (Browser $browser) use ($admin) {
            $this->freshSession($browser);
            $browser->loginAs($admin)->visit('/campaigns');
            $this->waitForAlpine($browser);
            $this->clickAndAwait($browser, '@add-campaign', fn (Browser $b) => $b->waitFor('@campaign-form', 3));
            $this->jsType($browser, '@campaign-name', 'Summer Cola');

            try {
                $this->network($browser, self::SLOW);
                $this->choose($browser, 'campaign-file', "window.__noise('Summer advert.png', 1100)");
                $this->waitForStatus($browser, 'campaign', 'Summer advert.png', '/^Uploading \d+%/', 60);

                // The picture's seconds can be set while it goes up; Save waits.
                $browser->waitFor('@campaign-seconds');
                $this->jsType($browser, '@campaign-seconds', '10');
                $this->assertTrue($browser->script('return document.querySelector(\'[dusk="campaign-save"]\').disabled;')[0]);
                $this->jsClick($browser, '@campaign-save');
                $browser->pause(500);
                $this->assertSame(0, Campaign::count());

                $this->network($browser, null);
                $this->waitForStatus($browser, 'campaign', 'Summer advert.png', '/^Uploaded\. It is added when you save\.$/', 60);
            } finally {
                $this->network($browser, null);
            }

            $this->assertFalse($browser->script('return document.querySelector(\'[dusk="campaign-save"]\').disabled;')[0]);
            $this->jsClick($browser, '@campaign-save');
            $browser->waitUsing(20, 200, fn () => Campaign::where('name', 'Summer Cola')->exists());
            $browser->waitUsing(10, 200, fn () => Upload::count() === 0, 'the upload was kept after the campaign took it');
        });
    }

    private function openMediaUpload(Browser $browser, User $owner, Store $store): void
    {
        $this->freshSession($browser);
        $browser->loginAs($owner);
        $this->switchToStore($browser, $store);
        $browser->visit('/media');
        $this->waitForAlpine($browser);
        $this->clickAndAwait($browser, '@upload-media', fn (Browser $b) => $b->waitFor('@media-upload-form', 3));
    }

    /**
     * Chrome's network: uploads at most $uploadBytesPerSecond (null for no limit), or no network at all. The page hears
     * it as a real one: `navigator.onLine`, and the `offline` and `online` events.
     */
    private function network(Browser $browser, ?int $uploadBytesPerSecond, bool $offline = false): void
    {
        $tools = new ChromeDevToolsDriver($browser->driver);
        $tools->execute('Network.enable');
        $tools->execute('Network.emulateNetworkConditions', [
            'offline' => $offline,
            'latency' => 0,
            'downloadThroughput' => -1,
            'uploadThroughput' => $uploadBytesPerSecond ?? -1,
        ]);
    }

    /** Files made in the page: a plain picture, and a picture of noise $side pixels square (about 4 bytes a pixel). */
    private function defineFiles(Browser $browser): void
    {
        $browser->script(<<<'JS'
            if (! window.__picture) {
                window.__digests = {};

                const remember = async (file) => {
                    const digest = await crypto.subtle.digest('SHA-256', await file.arrayBuffer());
                    const sha256 = [...new Uint8Array(digest)].map((byte) => byte.toString(16).padStart(2, '0')).join('');
                    window.__digests[file.name] = { size: file.size, sha256 };

                    return file;
                };

                const asPng = (canvas, name) => new Promise((resolve) => canvas.toBlob(
                    (blob) => resolve(remember(new File([blob], name, { type: 'image/png' }))), 'image/png'));

                window.__picture = (name, r, g, b) => {
                    const canvas = document.createElement('canvas');
                    canvas.width = 320;
                    canvas.height = 180;
                    const context = canvas.getContext('2d');
                    context.fillStyle = `rgb(${r}, ${g}, ${b})`;
                    context.fillRect(0, 0, 320, 180);

                    return asPng(canvas, name);
                };

                window.__noise = (name, side) => {
                    const canvas = document.createElement('canvas');
                    canvas.width = side;
                    canvas.height = side;
                    const context = canvas.getContext('2d');
                    const image = context.createImageData(side, side);
                    const words = new Uint32Array(image.data.buffer);

                    // At most 64 KB of randomness per call.
                    for (let at = 0; at < words.length; at += 16384) {
                        crypto.getRandomValues(words.subarray(at, Math.min(at + 16384, words.length)));
                    }

                    // Opaque, so the canvas keeps every colour exactly as drawn.
                    for (let at = 3; at < image.data.length; at += 4) image.data[at] = 255;

                    context.putImageData(image, 0, 0);

                    return asPng(canvas, name);
                };
            }
        JS);
    }

    /** Put a file made in the page into a file input, as choosing one does. */
    private function choose(Browser $browser, string $input, string $makeFile): void
    {
        $this->defineFiles($browser);
        $browser->script(<<<JS
            window.__chosen = false;
            Promise.resolve({$makeFile}).then((file) => {
                const input = document.querySelector('[dusk="{$input}"]');
                const list = new DataTransfer();
                list.items.add(file);
                input.files = list.files;
                input.dispatchEvent(new Event('change', { bubbles: true }));
                window.__chosen = true;
            });
        JS);
        $browser->waitUsing(60, 200, fn () => $browser->script('return window.__chosen;')[0] === true);
    }

    /**
     * Drop files made in the page on the box, as letting go of a drag does.
     *
     * @param  list<string>  $makeFiles
     */
    private function drop(Browser $browser, string $box, array $makeFiles): void
    {
        $this->defineFiles($browser);
        $files = implode(', ', $makeFiles);
        $browser->script(<<<JS
            window.__dropped = false;
            Promise.all([{$files}]).then((files) => {
                const list = new DataTransfer();
                files.forEach((file) => list.items.add(file));
                document.querySelector('[dusk="{$box}"]')
                    .dispatchEvent(new DragEvent('drop', { bubbles: true, cancelable: true, dataTransfer: list }));
                window.__dropped = true;
            });
        JS);
        $browser->waitUsing(60, 200, fn () => $browser->script('return window.__dropped;')[0] === true);
    }

    /** Count the chunks the page sends: one PATCH to /uploads/{id} each. */
    private function countChunks(Browser $browser): void
    {
        $browser->script(<<<'JS'
            window.__chunks = 0;
            const open = XMLHttpRequest.prototype.open;
            XMLHttpRequest.prototype.open = function (method, url, ...rest) {
                if (String(method).toUpperCase() === 'PATCH' && /\/uploads\/[0-9a-f-]{36}$/.test(String(url))) window.__chunks++;

                return open.call(this, method, url, ...rest);
            };
        JS);
    }

    /** What the row of the file named $name says, or null when there is no such row. */
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

    /** Wait until the row of $name says what $pattern matches; what it said. A row that is refused fails at once. */
    private function waitForStatus(Browser $browser, string $box, string $name, string $pattern, int $seconds): string
    {
        $said = '';

        try {
            $browser->waitUsing($seconds, 100, function () use ($browser, $box, $name, $pattern, &$said) {
                $said = (string) $this->statusOf($browser, $box, $name);
                $this->assertStringStartsNotWith('error: ', $said, "{$name} was refused");

                return preg_match($pattern, $said) === 1;
            });
        } catch (TimeoutException) {
            $this->fail("The row of {$name} never matched {$pattern} in {$seconds} s. It said: \"{$said}\".");
        }

        return $said;
    }

    /** Press one of the buttons on the row of $name: pause (Pause and Resume), retry or remove. */
    private function clickInRow(Browser $browser, string $box, string $name, string $button): void
    {
        $name = json_encode($name);
        $browser->script(<<<JS
            [...document.querySelectorAll('[dusk="{$box}-upload-row"]')]
                .find((each) => each.querySelector('[dusk="{$box}-upload-name"]')?.textContent.trim() === {$name})
                .querySelector('[dusk="{$box}-upload-{$button}"]').click();
        JS);
    }
}
