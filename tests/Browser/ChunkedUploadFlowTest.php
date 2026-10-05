<?php

namespace Tests\Browser;

use App\Models\Campaign;
use App\Models\Media;
use App\Models\Organization;
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
 * are made in the page: pictures drawn on a canvas, and "noise" GIFs that no compression can shrink, for sizes past a
 * chunk — a GIF because the server stores every GIF exactly as it came (PictureOptimizer makes the other pictures
 * lighter), so what arrives can be compared with what was chosen, byte for byte.
 */
class ChunkedUploadFlowTest extends DuskTestCase
{
    use DatabaseMigrations;

    /** Half a megabyte a second: slow enough to act on an upload on its way. */
    private const SLOW = 512 * 1024;

    public function test_files_dropped_on_the_box_go_up_together_and_join_the_library(): void
    {
        $organization = Organization::factory()->create(['name' => 'Alpha Mart']);
        $owner = $this->organizationMember($organization);

        $this->browse(function (Browser $browser) use ($owner, $organization) {
            $this->openMediaUpload($browser, $owner, $organization);

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

            // (A picture the server made lighter says by how much after it: PicturesMadeLighterTest.)
            foreach (['Breakfast menu.png', 'Lunch menu.png', 'Dinner menu.png'] as $name) {
                $this->assertStringStartsWith('Added', (string) $this->statusOf($browser, 'media', $name));
            }

            // Each is in the library, titled by its name, and nothing is left on its way.
            $this->assertSame(['Breakfast menu', 'Dinner menu', 'Lunch menu'], Media::orderBy('title')->pluck('title')->all());
            $this->assertSame(0, Upload::count(), 'an upload was left behind');

            // The list refreshes 400 ms after a file joins it (onUploaded), so a refresh between two files can show the
            // first alone for a moment: each is waited for.
            $browser->waitForText('Breakfast menu')->waitForText('Lunch menu')->waitForText('Dinner menu');

            // An "Added" row goes by itself after eight seconds, as a notification does (owner, 2026-09-30), and the
            // count of the batch with it; the files stay in the library.
            $browser->waitUntilMissing('@media-upload-row', 15)
                ->assertMissing('@media-upload-summary')
                ->assertSee('Breakfast menu');
        });
    }

    public function test_a_picture_larger_than_a_chunk_goes_up_in_pieces_and_arrives_whole(): void
    {
        $organization = Organization::factory()->create(['name' => 'Alpha Mart']);
        $owner = $this->organizationMember($organization);

        $this->browse(function (Browser $browser) use ($owner, $organization) {
            $this->openMediaUpload($browser, $owner, $organization);
            $this->countChunks($browser);

            $this->choose($browser, 'media-file', "window.__noise('Big poster.gif', 3400)");
            $this->waitForStatus($browser, 'media', 'Big poster.gif', '/^Added$/', 60);

            $media = Media::sole();
            $sent = $browser->script("return window.__digests['Big poster.gif'];")[0];

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
        $organization = Organization::factory()->create(['name' => 'Alpha Mart']);
        $owner = $this->organizationMember($organization);

        $this->browse(function (Browser $browser) use ($owner, $organization) {
            $this->openMediaUpload($browser, $owner, $organization);
            $browser->script('window.__samePage = true;');

            try {
                $this->network($browser, self::SLOW);
                $this->choose($browser, 'media-file', "window.__noise('Slow poster.gif', 2450)");
                $this->waitForStatus($browser, 'media', 'Slow poster.gif', '/^Uploading ([1-9]\d?)%/', 60);

                // Paused: it stays where it is.
                $this->clickInRow($browser, 'media', 'Slow poster.gif', 'pause');
                $paused = $this->waitForStatus($browser, 'media', 'Slow poster.gif', '/^Paused at \d+%$/', 10);
                $browser->pause(1500);
                $this->assertSame($paused, $this->statusOf($browser, 'media', 'Slow poster.gif'));

                // Resumed: on it goes.
                $this->clickInRow($browser, 'media', 'Slow poster.gif', 'pause');
                $this->waitForStatus($browser, 'media', 'Slow poster.gif', '/^Uploading \d+%/', 10);

                // The connection drops: the row says so, and waits.
                $this->network($browser, self::SLOW, offline: true);
                $this->waitForStatus($browser, 'media', 'Slow poster.gif', '/^Connection lost\. Waiting for the internet…$/', 10);
                $browser->pause(2000);
                $this->assertSame(0, Media::count());

                // Back, at full speed: it carries on from where the server kept it, on the same page.
                $this->network($browser, null);
                $this->waitForStatus($browser, 'media', 'Slow poster.gif', '/^Added$/', 60);
                $this->assertSame(1, Media::count());
                $this->assertTrue($browser->script('return window.__samePage === true;')[0], 'the page was loaded again');
                $this->assertSame($browser->script("return window.__digests['Slow poster.gif'].sha256;")[0],
                    hash('sha256', (string) Storage::disk('public')->get(Media::sole()->path)));

                // Cancelled on its way: the row goes, and so does the upload on the server.
                $this->network($browser, self::SLOW);
                $this->choose($browser, 'media-file', "window.__noise('Changed my mind.gif', 2450)");
                $this->waitForStatus($browser, 'media', 'Changed my mind.gif', '/^Uploading \d+%/', 60);
                $this->assertSame(1, Upload::count());

                $this->clickInRow($browser, 'media', 'Changed my mind.gif', 'remove');
                $browser->waitUsing(10, 200, fn () => $this->statusOf($browser, 'media', 'Changed my mind.gif') === null);
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
                $this->choose($browser, 'campaign-file', "window.__noise('Summer advert.gif', 2060)");
                $this->waitForStatus($browser, 'campaign', 'Summer advert.gif', '/^Uploading \d+%/', 60);

                // The picture's seconds can be set while it goes up; Save waits.
                $browser->waitFor('@campaign-seconds');
                $this->jsType($browser, '@campaign-seconds', '10');
                $this->assertTrue($browser->script('return document.querySelector(\'[dusk="campaign-save"]\').disabled;')[0]);
                $this->jsClick($browser, '@campaign-save');
                $browser->pause(500);
                $this->assertSame(0, Campaign::count());

                $this->network($browser, null);
                $this->waitForStatus($browser, 'campaign', 'Summer advert.gif', '/^Uploaded\. It is added when you save\.$/', 60);
            } finally {
                $this->network($browser, null);
            }

            $this->assertFalse($browser->script('return document.querySelector(\'[dusk="campaign-save"]\').disabled;')[0]);
            $this->jsClick($browser, '@campaign-save');
            $browser->waitUsing(20, 200, fn () => Campaign::where('name', 'Summer Cola')->exists());
            $browser->waitUsing(10, 200, fn () => Upload::count() === 0, 'the upload was kept after the campaign took it');
        });
    }

    private function openMediaUpload(Browser $browser, User $owner, Organization $organization): void
    {
        $this->freshSession($browser);
        $browser->loginAs($owner);
        $this->switchToOrganization($browser, $organization);
        $browser->visit('/media');
        $this->waitForAlpine($browser);
        // The drop box is on the page itself (owner, 2026-09-30), not in a dialog.
        $browser->waitFor('@media-dropzone');
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

    /** Files made in the page: a plain picture (a PNG), and a GIF of noise $side pixels square (about 1.13 bytes a pixel). */
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
                    const pixels = new Uint8Array(side * side);

                    // At most 64 KB of randomness per call.
                    for (let at = 0; at < pixels.length; at += 65536) {
                        crypto.getRandomValues(pixels.subarray(at, Math.min(at + 65536, pixels.length)));
                    }

                    // Every pixel a code of its own, 9 bits wide: a clear code every 250 keeps the decoder's table, and so
                    // the width, from growing — the "uncompressed GIF" every decoder reads.
                    const codes = new Uint8Array(Math.ceil((pixels.length * 251 / 250 + 2) * 9 / 8) + 1);
                    let length = 0;
                    let buffer = 0;
                    let bits = 0;
                    const put = (code) => {
                        buffer |= code << bits;
                        bits += 9;

                        while (bits >= 8) {
                            codes[length++] = buffer & 0xFF;
                            buffer >>>= 8;
                            bits -= 8;
                        }
                    };

                    for (let at = 0; at < pixels.length; at++) {
                        if (at % 250 === 0) put(256);
                        put(pixels[at]);
                    }

                    put(257);
                    if (bits > 0) codes[length++] = buffer & 0xFF;

                    // The codes in blocks of at most 255 bytes, each led by its length, and an empty block to end them.
                    const blocks = new Uint8Array(length + Math.ceil(length / 255) + 1);
                    let out = 0;

                    for (let at = 0; at < length; at += 255) {
                        const size = Math.min(255, length - at);
                        blocks[out++] = size;
                        blocks.set(codes.subarray(at, at + size), out);
                        out += size;
                    }

                    blocks[out++] = 0;

                    // GIF89a, a screen $side square with a table of 256 random colours, one image of it, the end.
                    const low = side & 0xFF;
                    const high = side >> 8;
                    const colours = new Uint8Array(768);
                    crypto.getRandomValues(colours);

                    return remember(new File([
                        new Uint8Array([0x47, 0x49, 0x46, 0x38, 0x39, 0x61, low, high, low, high, 0xF7, 0, 0]),
                        colours,
                        new Uint8Array([0x2C, 0, 0, 0, 0, low, high, low, high, 0, 8]),
                        blocks.subarray(0, out),
                        new Uint8Array([0x3B]),
                    ], name, { type: 'image/gif' }));
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
