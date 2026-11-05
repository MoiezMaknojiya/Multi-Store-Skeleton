<?php

namespace Tests\Browser;

use App\Models\Media;
use App\Models\Organization;
use App\Models\Upload;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Storage;
use Laravel\Dusk\Browser;
use Tests\DuskTestCase;

/**
 * A video bigger than 1080p is made 1080p, with no sound, in the browser before it is uploaded (owner, 2026-11-05 —
 * "agar 4K ki video aye toh usko optimize kar k 1080p kardeve", "awaaz chiya hi nahi"): resources/js/core/video-to-1080p.js,
 * from the shared drop box (upload-dropzone.js, shrink). Most televisions decode one 4K video at a time (player.js, "One
 * decoder at a time"). The fixtures are real H.264 with an AAC sound track, 3840 × 2160: one landscape, one written as
 * a phone held upright writes it — coded on its side, its track saying "shown turned 90°".
 */
class VideoMadeFullHdTest extends DuskTestCase
{
    use DatabaseMigrations;

    private const FIXTURES = __DIR__.'/fixtures/video/';

    public function test_a_4k_video_goes_up_as_1080p_with_no_sound(): void
    {
        [$owner, $organization] = $this->ownerOfAlphaMart();
        $path = $this->copyOf('landscape-4k-with-sound.mp4', 'Shop Promo 4K.mp4');

        $this->browse(function (Browser $browser) use ($owner, $organization, $path) {
            $this->openTheLibrary($browser, $owner, $organization);
            $this->uploadThrough($browser, 'media', $path);

            $browser->assertSeeIn('@media-upload-notice', 'Made 1080p with no sound:');

            $media = Media::where('title', 'Shop Promo 4K')->firstOrFail();
            $this->assertSame([1920, 1080, 'landscape', 'video/mp4', 2], [$media->width, $media->height, $media->orientation, $media->mime_type, $media->duration_seconds]);

            $tracks = $this->tracksOf(Storage::disk('public')->path($media->path));
            $this->assertSame(['vide'], $tracks['handlers'], 'the stored file kept a sound track');
            $this->assertSame([1920, 1080], $tracks['size']);

            // It plays in the browser, the red half on top as it was.
            $this->assertSame(['top' => 'red', 'bottom' => 'blue'], $this->halvesOf($browser, $media));
        });
    }

    public function test_a_phone_video_held_upright_goes_up_upright_as_1080_by_1920(): void
    {
        [$owner, $organization] = $this->ownerOfAlphaMart();
        $path = $this->copyOf('phone-4k-held-upright.mp4', 'Upright Menu.mp4');

        $this->browse(function (Browser $browser) use ($owner, $organization, $path) {
            $this->openTheLibrary($browser, $owner, $organization);
            $this->uploadThrough($browser, 'media', $path);

            $media = Media::where('title', 'Upright Menu')->firstOrFail();
            $this->assertSame([1080, 1920, 'portrait'], [$media->width, $media->height, $media->orientation]);

            // Turned upright in the pixels themselves: no turn left in the file for a television to get wrong.
            $tracks = $this->tracksOf(Storage::disk('public')->path($media->path));
            $this->assertSame([1080, 1920], $tracks['size']);
            $this->assertSame(0, $tracks['turn']);

            // Coded red on top and turned 90° clockwise to be shown: red on the right.
            $this->assertSame(['left' => 'blue', 'right' => 'red'], $this->sidesOf($browser, $media));
        });
    }

    public function test_a_browser_that_cannot_make_it_1080p_uploads_it_as_it_came(): void
    {
        [$owner, $organization] = $this->ownerOfAlphaMart();
        $path = $this->copyOf('landscape-4k-with-sound.mp4', 'Big Promo.mp4');

        $this->browse(function (Browser $browser) use ($owner, $organization, $path) {
            $this->openTheLibrary($browser, $owner, $organization);
            // An older browser: no WebCodecs encoder.
            $browser->script('window.VideoEncoder = undefined;');
            $this->uploadThrough($browser, 'media', $path);

            $browser->assertSeeIn('@media-upload-notice', 'Uploaded as it came: this browser cannot make it 1080p.');
            $media = Media::where('title', 'Big Promo')->firstOrFail();
            $this->assertSame([3840, 2160], [$media->width, $media->height]);
            $this->assertSame(filesize($path), Storage::disk('public')->size($media->path));
        });
    }

    public function test_cancel_while_it_is_made_1080p_sends_nothing(): void
    {
        [$owner, $organization] = $this->ownerOfAlphaMart();
        $path = $this->copyOf('landscape-4k-with-sound.mp4', 'Slow Promo.mp4');

        $this->browse(function (Browser $browser) use ($owner, $organization, $path) {
            $this->openTheLibrary($browser, $owner, $organization);
            // A slow computer: every frame takes a fifth of a second to decode, so the work lasts some twelve seconds.
            $browser->script(<<<'JS'
                const decode = VideoDecoder.prototype.decode;
                const flush = VideoDecoder.prototype.flush;
                let queue = Promise.resolve();
                VideoDecoder.prototype.decode = function (chunk) {
                    queue = queue.then(() => new Promise((done) => setTimeout(() => { if (this.state === 'configured') { window.__decodes = (window.__decodes || 0) + 1; decode.call(this, chunk); } done(); }, 200)));
                };
                VideoDecoder.prototype.flush = function () { return queue.then(() => (this.state === 'configured' ? flush.call(this) : undefined)); };
            JS);
            $browser->attach('@media-file', $path);

            $browser->waitUntil("document.querySelector('[dusk=\"media-upload-status\"]')?.textContent.includes('Making it 1080p') && (window.__decodes || 0) >= 2", 10);
            $label = $browser->script("return document.querySelector('[dusk=\"media-upload-row\"] [role=\"progressbar\"]').getAttribute('aria-label');")[0];
            $this->assertSame('Making Slow Promo.mp4 1080p', $label);

            // × asks first, as for a file going up; a double press asks once.
            $browser->doubleClick('@media-upload-remove')->waitFor('@media-upload-stop-ask')
                ->assertSeeIn('@media-upload-stop-ask', 'Stop uploading Slow Promo.mp4?')
                ->click('@media-upload-stop')
                ->waitUntilMissing('@media-upload-row', 5);

            // The computer's work ends there too: no frame decoded once it was stopped.
            $stoppedAt = $browser->script('return window.__decodes || 0;')[0];
            $this->assertGreaterThan(0, $stoppedAt);
            $browser->pause(3000);
            $this->assertLessThanOrEqual($stoppedAt + 1, $browser->script('return window.__decodes || 0;')[0], 'the video went on being decoded after Stop');

            // Long after it would have been done: nothing opened, nothing added.
            $browser->pause(11000);
            $this->assertSame(0, Upload::count(), 'an upload was opened for a file given up');
            $this->assertSame(0, Media::count());
            $browser->assertMissing('@media-upload-row');
        });
    }

    /** @return array{0: User, 1: Organization} */
    private function ownerOfAlphaMart(): array
    {
        $this->seedSuperAdmin();
        $organization = Organization::factory()->create(['name' => 'Alpha Mart']);

        return [$this->organizationMember($organization, ['media-view', 'media-store', 'media-update', 'media-destroy']), $organization];
    }

    private function openTheLibrary(Browser $browser, $owner, Organization $organization): void
    {
        $this->freshSession($browser);
        $browser->loginAs($owner);
        $this->switchToOrganization($browser, $organization);
        $browser->visit('/media');
        $this->waitForAlpine($browser);
        $browser->waitForText('No files yet.');
    }

    /** The fixture under a name of the test's own, as a person's file would be called. */
    private function copyOf(string $fixture, string $name): string
    {
        $folder = sys_get_temp_dir().'/video-made-full-hd-'.getmypid();
        @mkdir($folder);
        copy(self::FIXTURES.$fixture, $folder.'/'.$name);

        return $folder.'/'.$name;
    }

    /**
     * What the stored MP4 says: each track's handler, the video track's size and its turn.
     *
     * @return array{handlers: list<string>, size: array{0: int, 1: int}, turn: int}
     */
    private function tracksOf(string $file): array
    {
        $bytes = file_get_contents($file);
        $moov = $this->child($bytes, 0, strlen($bytes), 'moov');
        $this->assertNotNull($moov, 'the stored file has no moov');

        $handlers = [];
        $size = [0, 0];
        $turn = 0;
        foreach ($this->children($bytes, $moov[0], $moov[1], 'trak') as [$start, $end]) {
            $mdia = $this->child($bytes, $start, $end, 'mdia');
            $hdlr = $this->child($bytes, $mdia[0], $mdia[1], 'hdlr');
            $handler = substr($bytes, $hdlr[0] + 8, 4);
            $handlers[] = $handler;
            if ($handler === 'vide') {
                $tkhd = $this->child($bytes, $start, $end, 'tkhd');
                $version = ord($bytes[$tkhd[0]]);
                $matrixAt = $tkhd[0] + 4 + ($version === 1 ? 32 : 20) + 16;
                $a = unpack('N', substr($bytes, $matrixAt, 4))[1];
                $b = unpack('N', substr($bytes, $matrixAt + 4, 4))[1];
                $turn = $a === 0x10000 && $b === 0 ? 0 : 1;
                $size = [unpack('N', substr($bytes, $matrixAt + 36, 4))[1] >> 16, unpack('N', substr($bytes, $matrixAt + 40, 4))[1] >> 16];
            }
        }

        return ['handlers' => $handlers, 'size' => $size, 'turn' => $turn];
    }

    /** @return list<array{0: int, 1: int}> each box of that type between the offsets, as [body start, end]. */
    private function children(string $bytes, int $from, int $to, string $type): array
    {
        $found = [];
        for ($at = $from; $at + 8 <= $to;) {
            $length = unpack('N', substr($bytes, $at, 4))[1];
            if ($length < 8) {
                break;
            }
            if (substr($bytes, $at + 4, 4) === $type) {
                $found[] = [$at + 8, $at + $length];
            }
            $at += $length;
        }

        return $found;
    }

    /** @return array{0: int, 1: int}|null */
    private function child(string $bytes, int $from, int $to, string $type): ?array
    {
        return $this->children($bytes, $from, $to, $type)[0] ?? null;
    }

    /** @return array{top: string, bottom: string} */
    private function halvesOf(Browser $browser, Media $media): array
    {
        $colours = $this->coloursAt($browser, $media, [[0.9, 0.1], [0.9, 0.9]]);

        return ['top' => $colours[0], 'bottom' => $colours[1]];
    }

    /** @return array{left: string, right: string} */
    private function sidesOf(Browser $browser, Media $media): array
    {
        $colours = $this->coloursAt($browser, $media, [[0.1, 0.9], [0.9, 0.9]]);

        return ['left' => $colours[0], 'right' => $colours[1]];
    }

    /**
     * The stored file played in the page, its first frame drawn, and the colour at each point (shares of its width
     * and height): red, blue or what it is.
     *
     * @param  list<array{0: float, 1: float}>  $points
     * @return list<string>
     */
    private function coloursAt(Browser $browser, Media $media, array $points): array
    {
        $url = json_encode('/'.ltrim(parse_url(Storage::disk('public')->url($media->path), PHP_URL_PATH), '/'));
        $at = json_encode($points);
        $browser->script(<<<JS
            window.__colours = null;
            const video = document.createElement('video');
            video.muted = true; video.src = {$url};
            video.addEventListener('loadeddata', () => {
                const canvas = document.createElement('canvas');
                canvas.width = video.videoWidth; canvas.height = video.videoHeight;
                const context = canvas.getContext('2d');
                context.drawImage(video, 0, 0);
                window.__colours = {$at}.map(([x, y]) => {
                    const [r, g, b] = context.getImageData(Math.floor(x * canvas.width), Math.floor(y * canvas.height), 1, 1).data;
                    return r > 150 && g < 80 && b < 80 ? 'red' : (b > 120 && r < 80 ? 'blue' : r + ',' + g + ',' + b);
                });
            }, { once: true });
            video.addEventListener('error', () => { window.__colours = ['error ' + (video.error && video.error.message)]; }, { once: true });
            video.load();
        JS);
        $browser->waitUntil('window.__colours !== null', 10);

        return $browser->script('return window.__colours;')[0];
    }
}
