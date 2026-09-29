<?php

namespace Tests\Browser;

use App\Models\BuilderAd;
use App\Models\BuilderAsset;
use App\Models\Channel;
use App\Models\ChannelAd;
use App\Models\Media;
use App\Models\PlaylistItem;
use App\Models\Role;
use App\Models\Screen;
use App\Models\Store;
use App\Models\User;
use App\Services\AdPublisher;
use App\Services\MediaStorage;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Http\UploadedFile;
use Laravel\Dusk\Browser;
use Tests\DuskTestCase;

/**
 * Every kind of line on one television, each timed on the glass (owner, 2026-09-29: "brute force aur out of the box
 * testing … kuch break toh nahi ho raha"). A picture at the least it may (6 s); an Ad Builder ad of 6 s over a 20 s
 * background video, which is cut; a 3 s video, which no minimum holds; a shop's channel with a picture saved before
 * the six-second rule at 3 s, which plays 6, and an ad page of 7 s; and a picture line saved before the rule at 2 s,
 * which plays 6. What is measured is how long each one stays in front — not what any number says.
 */
class EveryLineKeepsItsTimeTest extends DuskTestCase
{
    use DatabaseMigrations;

    public function test_each_kind_of_line_stays_on_the_glass_for_its_own_time(): void
    {
        $this->seedSuperAdmin();
        $store = Store::factory()->create(['name' => 'Alpha Mart']);
        $owner = $this->storeMember($store, Role::OWNER, 'owner@example.com');
        $screen = Screen::factory()->withToken('times-token')->create(['store_id' => $store->id, 'name' => 'Counter TV']);

        $picture = fn (string $name, array $rgb): Media => Media::create([
            ...app(MediaStorage::class)->store(new UploadedFile($this->fixtureImage($name, ...$rgb), $name, 'image/png', null, true), $store->id, []),
            'title' => $name,
        ]);
        $first = $picture('first.png', [200, 40, 40]);
        $old = $picture('old.png', [40, 40, 200]);
        $inChannel = $picture('in-channel.png', [40, 160, 60]);

        $this->browse(function (Browser $panel, Browser $tv) use ($owner, $store, $screen, $first, $old, $inChannel) {
            /* ── Two real videos, made in the page and sent through the real doors ── */
            $this->freshSession($panel);
            $panel->loginAs($owner);
            $this->switchToStore($panel, $store);
            $panel->visit('/builder/assets');
            $this->waitForAlpine($panel);
            $this->defineMakeVideo($panel);
            $panel->script(<<<'JS'
                window.__sent = {};
                const send = async (url, seconds, name) => {
                    const body = new FormData();
                    body.append('file', await window.__makeVideo(seconds, name));
                    body.append('duration_seconds', String(seconds));
                    const response = await fetch(url, {
                        method: 'POST', body,
                        headers: { 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content, 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' },
                    });
                    window.__sent[name] = response.status;
                };
                (async () => { await send('/media', 3, 'three.webm'); await send('/builder/assets', 20, 'twenty.webm'); })();
            JS);
            $panel->waitUsing(90, 250, fn () => count((array) $panel->script('return window.__sent;')[0]) === 2);
            $this->assertEquals(['three.webm' => 200, 'twenty.webm' => 200], (array) $panel->script('return window.__sent;')[0]);

            $clip = Media::where('type', Media::TYPE_VIDEO)->sole();
            $this->assertSame(3, $clip->duration_seconds, 'the server measured the short video itself');

            /* ── Two ads: six seconds over the twenty-second video, and seven seconds plain ── */
            $six = $this->publishedAd($store, $owner, 'Six seconds', 6, BuilderAsset::sole());
            $seven = $this->publishedAd($store, $owner, 'Seven seconds', 7, null);

            /* ── The shop's channel: a picture saved before the rule at 3 s, and the seven-second page ── */
            $channel = Channel::factory()->create(['name' => 'Alpha Deals', 'store_id' => $store->id]);
            ChannelAd::factory()->create(['channel_id' => $channel->id, 'media_id' => $inChannel->id, 'title' => 'Old deal', 'duration_seconds' => 3, 'position' => 0]);
            ChannelAd::factory()->create(['channel_id' => $channel->id, 'media_id' => $seven->id, 'title' => 'Seven seconds', 'duration_seconds' => null, 'position' => 1]);

            /* ── The playlist, as a screen saved before the rule may hold it ── */
            foreach ([
                ['media_id' => $first->id, 'duration_seconds' => 6],
                ['media_id' => $six->id, 'duration_seconds' => 6],
                ['media_id' => $clip->id, 'duration_seconds' => 3],
                ['channel_id' => $channel->id, 'duration_seconds' => null],
                ['media_id' => $old->id, 'duration_seconds' => 2],
            ] as $position => $line) {
                PlaylistItem::create(['screen_id' => $screen->id, 'position' => $position, ...$line]);
            }

            /* ── The television: what comes on the glass, and when ── */
            $tv->visit('/login');
            $tv->script("localStorage.clear(); localStorage.setItem('signage.device.token', 'times-token');");
            $tv->visit('/player');
            $tv->script(<<<'JS'
                window.__glass = [];
                (function watch() {
                    const front = document.querySelector('#layer-a:not([hidden]) > *, #layer-b:not([hidden]) > *');
                    if (front && front !== window.__front) {
                        window.__front = front;
                        window.__glass.push([front.tagName, front.dataset.src || front.getAttribute('src') || '', performance.now()]);
                    }
                    setTimeout(watch, 50);
                })();
            JS);

            // Name each showing by what it is: the file's own name, or the ad's page.
            $names = [
                basename($first->path) => 'first', basename($old->path) => 'old', basename($inChannel->path) => 'in-channel',
                basename($clip->path) => 'clip', "/ads/{$six->builderAd->id}/" => 'six', "/ads/{$seven->builderAd->id}/" => 'seven',
            ];
            $nameOf = function (string $src) use ($names): string {
                foreach ($names as $needle => $name) {
                    if (str_contains($src, $needle)) {
                        return $name;
                    }
                }

                return 'unknown '.$src;
            };

            // One whole pass after the first picture: first, six, clip, in-channel, seven, old — then first again.
            $order = ['first', 'six', 'clip', 'in-channel', 'seven', 'old', 'first'];
            $pass = null;
            $tv->waitUsing(150, 500, function () use ($tv, $nameOf, $order, &$pass) {
                $glass = array_map(fn (array $row) => [$nameOf($row[1]), $row[2]], (array) $tv->script('return window.__glass;')[0]);

                for ($i = 0; $i + count($order) <= count($glass); $i++) {
                    if (array_column(array_slice($glass, $i, count($order)), 0) === $order) {
                        $pass = array_slice($glass, $i, count($order));

                        return true;
                    }
                }

                return false;
            }, 'the television never played the whole pass in order');

            $seconds = [];
            for ($i = 0; $i + 1 < count($pass); $i++) {
                $seconds[$pass[$i][0]] = round(($pass[$i + 1][1] - $pass[$i][1]) / 1000, 2);
            }

            $expected = ['first' => 6, 'six' => 6, 'clip' => 3, 'in-channel' => 6, 'seven' => 7, 'old' => 6];
            foreach ($expected as $name => $wanted) {
                $this->assertEqualsWithDelta($wanted, $seconds[$name], 1.5, "{$name} was on the glass for {$seconds[$name]} s, not {$wanted}: ".json_encode($seconds));
            }

            $tv->visit('/login');
            $tv->script('localStorage.clear();');
        });
    }

    /** An Ad Builder ad of $seconds, a text on it and — when given — a video as its background, published. */
    private function publishedAd(Store $store, User $owner, string $name, int $seconds, ?BuilderAsset $video): Media
    {
        $document = BuilderAd::blankDocument();
        $document['duration'] = $seconds;
        $document['elements'] = [[
            'id' => 'el_text', 'type' => 'text', 'name' => 'Headline', 'text' => $name,
            'x' => 160, 'y' => 400, 'w' => 1600, 'h' => 240, 'rotation' => 0, 'opacity' => 1, 'z' => 0, 'locked' => false, 'visible' => true,
            'style' => ['fontSize' => 120, 'fontWeight' => 700, 'color' => '#ffffff', 'align' => 'center'], 'animations' => [],
        ]];

        if ($video !== null) {
            $document['stage']['background']['layers'] = [[
                'id' => 'bg_video', 'type' => 'video', 'assetId' => $video->id, 'fit' => 'cover', 'visible' => true, 'opacity' => 1,
            ]];
        }

        $ad = BuilderAd::create([
            'store_id' => $store->id, 'name' => $name, 'orientation' => BuilderAd::LANDSCAPE,
            'document' => $document, 'in_playlists' => true, 'created_by' => $owner->id,
        ]);

        return app(AdPublisher::class)->publish($ad, $owner->id);
    }
}
