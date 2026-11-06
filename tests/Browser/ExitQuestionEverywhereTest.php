<?php

namespace Tests\Browser;

use App\Models\Campaign;
use App\Models\Channel;
use App\Models\Media;
use App\Models\Organization;
use App\Models\PlaylistItem;
use App\Models\ScheduleRule;
use App\Models\Screen;
use Facebook\WebDriver\Chrome\ChromeDevToolsDriver;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Laravel\Dusk\Browser;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Browser\Concerns\PlaysEverything;
use Tests\DuskTestCase;

/**
 * The Samsung app's exit question in every orientation, over everything a screen can play and every state it can be in
 * (owner, 2026-11-06 — "make sure karlo sari orientation with ever possible way with putting everything too").
 *
 * The question stands inside the stage, so a turned screen turns it with what plays: on the glass Exit stands right of
 * Stay on a landscape screen, left of it turned over, below it on a portrait screen and above it turned the other way,
 * which is upright on the set as it hangs. Over each kind of item it is checked for that turn, for covering the whole
 * glass with nothing drawn over it, for its words and buttons inside its box and its box on the glass, and for answering
 * LEFT, RIGHT and ENTER, the item playing on under it. As a Samsung TV plays: its user agent, and no worker, which its
 * web runtime runs none of, so its Ad Builder pages play from the server in a frame of an origin of their own.
 * ExitQuestionTest holds the keys themselves.
 */
class ExitQuestionEverywhereTest extends DuskTestCase
{
    use DatabaseMigrations, PlaysEverything;

    private const RETURN = [10009, 'XF86Back'];

    private const RIGHT = [39, 'ArrowRight'];

    private const LEFT = [37, 'ArrowLeft'];

    private const ENTER = [13, 'Enter'];

    /** For each turn of the stage: where Exit stands from Stay on the glass, and whether the title reads across it. */
    private const TURNS = [
        'landscape' => ['right', true],
        'landscape_flipped' => ['left', true],
        'portrait' => ['down', false],
        'portrait_flipped' => ['up', false],
    ];

    private const EVERY_KIND = [
        'library-picture', 'library-video', 'page:Page with a picture', 'channel-picture', 'channel-video',
        'page:Channel page', 'page:Page over a video', 'ad campaigns/network-picture', 'ad campaigns/network-video',
    ];

    /** @var list<ChromeDevToolsDriver> browsers whose requests or size a test changed */
    private array $blocked = [];

    protected function tearDown(): void
    {
        foreach ($this->blocked as $tools) {
            try {
                $tools->execute('Network.setBlockedURLs', ['urls' => []]);
                $tools->execute('Emulation.clearDeviceMetricsOverride', []);
            } catch (\Throwable) {
                // The browser is gone already.
            }
        }
        $this->blocked = [];
        $this->forgetPageScripts();

        parent::tearDown();
    }

    /** @return array<string, array{0: string, 1: ?string}> */
    public static function turnsAndWays(): array
    {
        return [
            'landscape' => ['landscape', null],
            'landscape flipped' => ['landscape_flipped', null],
            'portrait' => ['portrait', null],
            'portrait flipped' => ['portrait_flipped', null],
            'portrait, on a set told to decode its videos' => ['portrait', '1'],
        ];
    }

    #[DataProvider('turnsAndWays')]
    public function test_over_everything_a_screen_plays_the_question_stands_with_the_screen_and_answers(string $orientation, ?string $decode): void
    {
        $this->world($orientation);
        // Every ad of the channel in every pass, so one round of the playlist shows them all.
        Channel::query()->update(['ads_per_pass' => 3]);
        $names = $this->pageNames();

        $this->browse(function (Browser $tv) use ($orientation, $decode, $names) {
            $this->watch($tv);
            $this->startTheSet($tv, 'everything-token', $decode);

            $asked = [];
            $shot = false;
            $deadline = microtime(true) + 420;
            while (array_diff(self::EVERY_KIND, $asked) !== [] && microtime(true) < $deadline) {
                $now = $this->kindOf($this->glass($tv), $names);
                if ($now === null || in_array($now, $asked, true)) {
                    usleep(100_000);

                    continue;
                }

                $this->press($tv, self::RETURN);
                $look = $this->look($tv);
                $over = $this->kindOf((string) $look['glass'], $names) ?? $now;
                $this->assertStandsWithTheScreen($orientation, $look, "over {$over}");
                if (! $shot && str_starts_with($over, 'page:')) {
                    $tv->screenshot('exit-question-'.$orientation.($decode ? '-decoded' : ''));
                    $shot = true;
                }
                $this->answersAndPlaysOn($tv, "over {$over}");
                $asked[] = $over;
            }

            $this->assertSame([], array_values(array_diff(self::EVERY_KIND, $asked)),
                'never asked over: '.json_encode(array_values(array_diff(self::EVERY_KIND, $asked))).', seen: '.json_encode(array_values(array_unique($tv->script('return window.__all.seen;')[0]))));
            $this->assertSame($orientation, $tv->script("return document.getElementById('stage').dataset.orientation;")[0]);
            $this->assertFalse($tv->script('return !! (navigator.serviceWorker && navigator.serviceWorker.controller);')[0], 'a worker kept the set');
            $this->assertSame([], $tv->script('return window.__all.errors;')[0], 'the player threw');

            // After all of it, breaks that let pages go among it, Exit still leaves at once, for the page before.
            $this->leaves($tv);
        });
    }

    public function test_over_every_state_of_a_screen_in_every_orientation(): void
    {
        $organization = Organization::factory()->create(['name' => 'States Mart']);
        $holding = $this->picture($organization, 'holding-picture', [20, 120, 200]);

        $this->browse(function (Browser $tv) use ($organization, $holding) {
            foreach (array_keys(self::TURNS) as $orientation) {
                $screen = Screen::factory()->withToken("states-{$orientation}")->create([
                    'organization_id' => $organization->id, 'name' => "States {$orientation}", 'orientation' => $orientation,
                ]);

                // Paired, with nothing on its playlist: "No content".
                $this->startTheSet($tv, "states-{$orientation}");
                $tv->waitUntil("! document.getElementById('no-content').hidden", 15, 'No content never showed');
                $this->asksOver($tv, $orientation, "No content, {$orientation}");

                // A line whose days are over: the screen goes dark.
                $line = PlaylistItem::create(['screen_id' => $screen->id, 'media_id' => $holding->id, 'position' => 0, 'duration_seconds' => 10]);
                ScheduleRule::create(['playlist_item_id' => $line->id, 'starts_on' => '2020-01-01', 'ends_on' => '2020-01-02', 'position' => 0]);
                $tv->refresh();
                $tv->waitUntil("document.body.classList.contains('closed')", 15, 'the screen never went dark');
                $this->asksOver($tv, $orientation, "a dark screen, {$orientation}");

                // The same with a holding picture: it is on the glass.
                $screen->update(['default_media_id' => $holding->id]);
                $tv->refresh();
                $tv->waitUntil("!! document.querySelector('.media-layer:not([hidden]) img') && document.querySelector('.media-layer:not([hidden]) img').complete", 15, 'the holding picture never came on');
                $this->asksOver($tv, $orientation, "the holding picture, {$orientation}");

                // The screen deleted on the panel: the set shows a pairing code, the way it is turned.
                $screen->delete();
                $tv->script("window.dispatchEvent(new Event('online'));");
                $tv->waitUntil("! document.getElementById('view-pairing').hidden && /^[A-Z2-9]{6}$/.test(document.getElementById('pairing-code').textContent)", 20, 'the pairing code never showed');
                $this->assertSame($orientation, $tv->script("return document.getElementById('stage').dataset.orientation;")[0]);
                $this->asksOver($tv, $orientation, "the pairing code, {$orientation}");
            }

            // A set never paired cannot reach the server: it says so, and RETURN still asks. Its stage is as it starts.
            $tv->visit('/login');
            $tv->script('localStorage.clear();');
            $this->blockRequests($tv, ['*/device/register*']);
            $tv->visit('/player');
            $tv->waitUntil("! document.getElementById('view-error').hidden", 20, 'the set never said it has no connection');
            $tv->assertSeeIn('#error-message', 'No connection to the server');
            $this->asksOver($tv, 'landscape', 'no connection');
        });
    }

    public function test_the_question_fits_every_screen_size_in_every_orientation(): void
    {
        $organization = Organization::factory()->create(['name' => 'Sizes Mart']);
        $screen = Screen::factory()->withToken('sizes-token')->create(['organization_id' => $organization->id, 'name' => 'Sizes TV']);
        PlaylistItem::create(['screen_id' => $screen->id, 'media_id' => $this->picture($organization, 'sizes-picture', [200, 40, 40])->id, 'position' => 0, 'duration_seconds' => 300]);

        $this->browse(function (Browser $tv) use ($screen) {
            foreach (array_keys(self::TURNS) as $orientation) {
                $screen->update(['orientation' => $orientation]);
                $this->startTheSet($tv, 'sizes-token');
                $tv->waitUntil("document.getElementById('stage').dataset.orientation === '{$orientation}'", 15, 'the stage never turned');

                // A TV's page is 1920 x 1080 whatever its panel; the smaller ones are the boxes whose WebView is smaller.
                foreach ([[1920, 1080], [1366, 768], [1280, 720], [960, 540]] as [$width, $height]) {
                    $this->viewport($tv, $width, $height);
                    $this->assertSame([$width, $height], $tv->script('return [innerWidth, innerHeight];')[0]);
                    $this->asksOver($tv, $orientation, "{$width} x {$height}, {$orientation}");
                }
                $this->viewport($tv, 1920, 1080);
            }
        });
    }

    public function test_return_still_asks_after_a_pointer_pressed_on_an_ad_page(): void
    {
        $organization = Organization::factory()->create(['name' => 'Pointer Mart']);
        $screen = Screen::factory()->withToken('pointer-token')->create(['organization_id' => $organization->id, 'name' => 'Pointer TV']);
        PlaylistItem::create(['screen_id' => $screen->id, 'media_id' => $this->page($organization, 'Pressed page', 'landscape', picture: null, video: null)->id, 'position' => 0]);

        $this->browse(function (Browser $tv) {
            $this->startTheSet($tv, 'pointer-token');
            $tv->waitUntil("!! document.querySelector('.media-layer:not([hidden]) iframe[src]')", 20, 'the page never came on');
            $tv->pause(1500);

            // A Smart Remote's pointer or a mouse in the set's USB port, pressed on the page.
            $tools = new ChromeDevToolsDriver($tv->driver);
            foreach (['mousePressed', 'mouseReleased'] as $type) {
                $tools->execute('Input.dispatchMouseEvent', ['type' => $type, 'x' => 960, 'y' => 540, 'button' => 'left', 'clickCount' => 1]);
            }
            $tv->pause(300);
            $this->asksOver($tv, 'landscape', 'a page, pressed');

            // However the keyboard gets into the frame, it is taken back.
            $tv->script("document.querySelector('.media-layer:not([hidden]) iframe').focus();");
            $tv->pause(300);
            $this->asksOver($tv, 'landscape', 'a page, focused');
        });
    }

    public function test_exit_leaves_at_once_after_breaks_have_let_ad_pages_go(): void
    {
        $organization = Organization::factory()->create(['name' => 'Break Mart', 'accepts_network_ads' => true]);
        $screen = Screen::factory()->withToken('break-token')->create([
            'organization_id' => $organization->id, 'name' => 'Break TV', 'accepts_network_ads' => true,
        ]);
        // Ten seconds, past the dusk environment's six between breaks: a break comes after it every time round.
        $page = $this->page($organization, 'Page before a break', 'landscape', picture: null, video: null);
        PlaylistItem::create(['screen_id' => $screen->id, 'media_id' => $page->id, 'position' => 0]);
        Campaign::factory()->lasting(10)->create([
            'name' => 'break-advert', 'path' => $this->putImage('campaigns/break-advert.png', 220, 30, 40), 'thumbnail_path' => null, 'mime_type' => 'image/png',
        ])->screens()->attach($screen);

        $this->browse(function (Browser $tv) {
            $this->watch($tv);
            $this->startTheSet($tv, 'break-token');
            $before = $tv->script('return history.length;')[0];

            // Two breaks, each letting the page go and bringing it back from its start.
            $tv->waitUntil("window.__all.seen.filter((s) => s.startsWith('ad ')).length >= 2 && window.__all.glass().startsWith('page:')", 90, 'two breaks never came after the page: '.json_encode($tv->script('return window.__all.seen;')[0]));
            $this->assertSame($before, $tv->script('return history.length;')[0], 'the breaks wrote history the Exit button would have to go back through');

            // And should a frame ever write some, Exit goes back past it to the page the player was opened from.
            $tv->script("const frame = document.querySelector('.media-layer:not([hidden]) iframe'); frame.src = frame.src.split('#')[0] + '#written';");
            $tv->waitUntil('history.length === '.($before + 1), 5, 'the frame wrote no history');
            $this->leaves($tv);
        });
    }

    /** RETURN over what is on the glass now: the question stands with the screen and answers, and the screen plays on. */
    private function asksOver(Browser $tv, string $orientation, string $where): void
    {
        $this->press($tv, self::RETURN);
        $this->assertStandsWithTheScreen($orientation, $this->look($tv), $where);
        $this->answersAndPlaysOn($tv, $where);
    }

    private function assertStandsWithTheScreen(string $orientation, array $look, string $where): void
    {
        [$way, $across] = self::TURNS[$orientation];

        $this->assertTrue($look['open'], "{$where}: RETURN did not ask (the keyboard was on ".$look['focus'].')');
        $this->assertSame('Stay', $look['chosen'], "{$where}: Stay is not chosen");
        $this->assertSame('exit-question-stay', $look['focus'], "{$where}: the keyboard is not on Stay");
        $this->assertSame($orientation, $look['stage'], "{$where}: the stage is not turned {$orientation}");
        $this->assertSame($way, $look['way'], "{$where}: the question does not turn with the screen");
        $this->assertSame($across, $look['across'], "{$where}: the title does not read the way the screen does");
        $this->assertTrue($look['covers'], "{$where}: the question does not cover the glass");
        $this->assertTrue($look['onScreen'], "{$where}: the box runs off the glass: ".json_encode($look['box']));
        $this->assertTrue($look['inBox'], "{$where}: words or buttons run out of the box: ".json_encode($look['box']));
        $this->assertTrue($look['words'], "{$where}: words are cut inside their button or line");
        $this->assertSame([], $look['hits'], "{$where}: something is drawn over the question");
    }

    /** RIGHT and LEFT choose, ENTER on Stay closes it, and the screen is playing under it all the while. */
    private function answersAndPlaysOn(Browser $tv, string $where): void
    {
        $this->press($tv, self::RIGHT);
        $this->assertSame('Exit', $this->look($tv)['chosen'], "{$where}: RIGHT did not choose Exit");
        $this->press($tv, self::LEFT);
        $this->assertSame('Stay', $this->look($tv)['chosen'], "{$where}: LEFT did not choose Stay");
        $this->press($tv, self::ENTER);
        $look = $this->look($tv);
        $this->assertFalse($look['open'], "{$where}: Stay did not close the question");
        if ($look['glass'] !== null && ! $tv->script("return document.getElementById('view-content').hidden || document.body.classList.contains('closed') || ! document.getElementById('no-content').hidden;")[0]) {
            $this->assertNotSame('NOTHING', $look['glass'], "{$where}: the glass is empty after Stay");
        }
        $tv->assertPathIs('/player');
    }

    /** Exit from the question: the page before, at once and once. */
    private function leaves(Browser $tv): void
    {
        $this->press($tv, self::RETURN);
        $this->press($tv, self::RIGHT);
        $this->press($tv, self::ENTER);
        $tv->waitForLocation('/login', 10)->assertPathIs('/login');
        $tv->pause(800);
        $tv->assertPathIs('/login');
    }

    /** What the question looks like on the glass now. */
    private function look(Browser $tv): array
    {
        return $tv->script(<<<'JS'
            const q = document.getElementById('exit-question');
            const stay = document.getElementById('exit-question-stay');
            const exit = document.getElementById('exit-question-exit');
            const title = document.getElementById('exit-question-title');
            const note = q.querySelector('.exit-question-note');
            const box = q.querySelector('.exit-question-box');
            const rect = (n) => n.getBoundingClientRect();
            const mid = (r) => ({ x: (r.left + r.right) / 2, y: (r.top + r.bottom) / 2 });
            const inside = (a, b) => a.left >= b.left - 1 && a.top >= b.top - 1 && a.right <= b.right + 1 && a.bottom <= b.bottom + 1;
            const glassRect = { left: 0, top: 0, right: innerWidth, bottom: innerHeight };
            const s = mid(rect(stay)), e = mid(rect(exit)), dx = e.x - s.x, dy = e.y - s.y;
            const parts = [stay, exit, title, note];
            const hits = parts.map((n) => {
                const c = mid(rect(n));
                const hit = document.elementFromPoint(c.x, c.y);
                return hit && (hit === n || n.contains(hit)) ? '' : (hit ? hit.tagName + '#' + hit.id + '.' + hit.className : 'nothing');
            }).filter(Boolean);
            const r = rect(box);
            return {
                open: ! q.hidden,
                chosen: [...q.querySelectorAll('button.chosen')].map((b) => b.textContent).join(),
                focus: document.activeElement ? (document.activeElement.id || document.activeElement.tagName) : '',
                stage: document.getElementById('stage').dataset.orientation,
                way: Math.abs(dx) > Math.abs(dy) ? (dx > 0 ? 'right' : 'left') : (dy > 0 ? 'down' : 'up'),
                across: rect(title).width > rect(title).height,
                covers: inside(glassRect, rect(q)),
                onScreen: inside(r, glassRect),
                inBox: parts.every((n) => inside(rect(n), r)),
                words: parts.every((n) => n.scrollWidth <= n.clientWidth + 1),
                hits,
                box: { left: Math.round(r.left), top: Math.round(r.top), right: Math.round(r.right), bottom: Math.round(r.bottom) },
                glass: window.__all ? window.__all.glass() : null,
            };
        JS)[0];
    }

    private function glass(Browser $tv): string
    {
        return (string) $tv->script('return window.__all ? window.__all.glass() : "";')[0];
    }

    /**
     * Which of EVERY_KIND is on the glass. A page from the server is a frame no test can look into, so it is known by its
     * file, which `$names` turns into the page's words.
     *
     * @param  array<string, string>  $names
     */
    private function kindOf(string $glass, array $names): ?string
    {
        $glass = $names[$glass] ?? $glass;
        foreach (self::EVERY_KIND as $kind) {
            if (str_contains($glass, $kind)) {
                return $kind;
            }
        }

        return null;
    }

    /** @return array<string, string> each Ad Builder page as the watch names it by its file, and by its words */
    private function pageNames(): array
    {
        return Media::where('type', Media::TYPE_HTML)->get()
            ->mapWithKeys(fn (Media $page) => ['page:'.implode('/', array_slice(explode('/', $page->path), -2)) => 'page:'.$page->title])
            ->all();
    }

    /** The player opened after /login, as the Samsung app opens it after its own page, on a Samsung TV (playAsASamsungTv). */
    private function startTheSet(Browser $tv, string $token, ?string $decode = null): void
    {
        $this->playAsASamsungTv($tv);
        $tv->resize(1920, 1080);
        $tv->visit('/login');
        $tv->script('localStorage.clear();');
        $tv->script("localStorage.setItem('signage.device.token', '{$token}');");
        if ($decode !== null) {
            $tv->script("localStorage.setItem('signage.decode_turned_video', '{$decode}');");
        }
        $tv->visit('/player');
        $tv->waitUntil('window.signagePlayerStarted === true', 15, 'the player never started');
    }

    /** @param  list<string>  $urls */
    private function blockRequests(Browser $tv, array $urls): void
    {
        $tools = new ChromeDevToolsDriver($tv->driver);
        $tools->execute('Network.enable', []);
        $tools->execute('Network.setBlockedURLs', ['urls' => $urls]);
        $this->blocked[] = $tools;
    }

    private function viewport(Browser $tv, int $width, int $height): void
    {
        $tools = new ChromeDevToolsDriver($tv->driver);
        $tools->execute('Emulation.setDeviceMetricsOverride', ['width' => $width, 'height' => $height, 'deviceScaleFactor' => 1, 'mobile' => false]);
        $this->blocked[] = $tools;
    }

    /** A key as the remote sends it, through Chrome itself. */
    private function press(Browser $tv, array $key): void
    {
        [$code, $name] = $key;
        $tools = new ChromeDevToolsDriver($tv->driver);
        $tools->execute('Input.dispatchKeyEvent', [
            'type' => 'rawKeyDown', 'windowsVirtualKeyCode' => $code, 'nativeVirtualKeyCode' => $code, 'key' => $name, 'code' => $name,
        ]);
        $tools->execute('Input.dispatchKeyEvent', [
            'type' => 'keyUp', 'windowsVirtualKeyCode' => $code, 'nativeVirtualKeyCode' => $code, 'key' => $name, 'code' => $name,
        ]);
    }
}
