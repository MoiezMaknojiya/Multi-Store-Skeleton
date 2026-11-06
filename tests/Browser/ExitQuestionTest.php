<?php

namespace Tests\Browser;

use App\Models\Media;
use App\Models\Organization;
use App\Models\PlaylistItem;
use App\Models\Screen;
use Facebook\WebDriver\Chrome\ChromeDevToolsDriver;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Storage;
use Laravel\Dusk\Browser;
use Tests\DuskTestCase;

/**
 * The Samsung TV app's exit question (resources/js/player/exit-question.js; owner, 2026-11-06).
 *
 * Samsung requires RETURN to do something on every screen, and a shop's TV must not close at a stray press: RETURN
 * asks "Exit …?" with Stay chosen, LEFT and RIGHT move between Stay and Exit, ENTER presses the chosen one, and RETURN
 * again or 10 seconds with no key close it. Exit goes back to the page before, which on the TV is the app's own page
 * and closes the app (seen on the TV 10.0 emulator with its remote). On a Samsung TV it is on by itself (Tizen in the
 * user agent); here the set's own switch turns it on, as it can on any set. Keys are pressed as a remote sends them,
 * through Chrome itself (Input.dispatchKeyEvent): RETURN is keyCode 10009, "XF86Back".
 */
class ExitQuestionTest extends DuskTestCase
{
    use DatabaseMigrations;

    private const RETURN = [10009, 'XF86Back'];

    private const RIGHT = [39, 'ArrowRight'];

    private const LEFT = [37, 'ArrowLeft'];

    private const ENTER = [13, 'Enter'];

    private const UP = [38, 'ArrowUp'];

    public function test_return_asks_over_what_plays_and_stay_keeps_it_playing(): void
    {
        $this->screenPlayingAPicture();

        $this->browse(function (Browser $tv) {
            $this->startTheSet($tv, token: 'exit-token');
            $tv->waitUntil("document.querySelector('.media-layer:not([hidden]) img') !== null", 15, 'the picture never came on');

            $this->press($tv, self::RETURN);
            $this->assertEquals(['open' => true, 'chosen' => 'Stay', 'focus' => 'exit-question-stay'], $this->question($tv));
            $tv->assertSeeIn('@exit-question', 'Exit '.config('app.name').'?')
                ->assertSeeIn('@exit-question', 'This screen stops playing until the app is opened again.');

            // Over the stage: the picture stays on, under it, and the stage's turn would carry the question too.
            $this->assertTrue($tv->script("return document.getElementById('exit-question').parentElement.id === 'stage'
                && ! document.getElementById('view-content').hidden
                && document.querySelector('.media-layer:not([hidden]) img') !== null;")[0]);

            $this->press($tv, self::ENTER);
            $this->assertFalse($this->question($tv)['open'], 'ENTER on Stay closes the question');
            $tv->assertPathIs('/player');
            $this->assertFalse($tv->script("return document.getElementById('view-content').hidden;")[0], 'the screen goes on playing');
        });
    }

    public function test_left_and_right_choose_and_return_again_closes(): void
    {
        $this->browse(function (Browser $tv) {
            $this->startTheSet($tv);

            // Closed, the arrows and ENTER do nothing.
            $this->press($tv, self::RIGHT);
            $this->press($tv, self::ENTER);
            $this->assertFalse($this->question($tv)['open']);

            $this->press($tv, self::RETURN);
            $this->press($tv, self::RIGHT);
            $this->assertEquals(['open' => true, 'chosen' => 'Exit', 'focus' => 'exit-question-exit'], $this->question($tv));
            $this->press($tv, self::RIGHT);
            $this->assertSame('Exit', $this->question($tv)['chosen'], 'RIGHT past Exit stays on Exit');
            $this->press($tv, self::LEFT);
            $this->assertSame('Stay', $this->question($tv)['chosen']);

            $this->press($tv, self::RETURN);
            $this->assertFalse($this->question($tv)['open'], 'RETURN again keeps the TV playing');
            $tv->assertPathIs('/player');

            // Opened again it starts on Stay, whatever was chosen before.
            $this->press($tv, self::RETURN);
            $this->press($tv, self::RIGHT);
            $this->press($tv, self::RETURN);
            $this->press($tv, self::RETURN);
            $this->assertSame('Stay', $this->question($tv)['chosen']);
        });
    }

    public function test_a_held_return_opens_it_once_and_a_held_enter_on_stay_never_leaves(): void
    {
        $this->browse(function (Browser $tv) {
            $this->startTheSet($tv);

            // A held key repeats: the first press opens the question and the repeats change nothing.
            $this->press($tv, self::RETURN);
            foreach (range(1, 5) as $repeat) {
                $this->press($tv, self::RETURN, repeat: true);
            }
            $this->assertTrue($this->question($tv)['open']);

            $this->press($tv, self::ENTER);
            foreach (range(1, 5) as $repeat) {
                $this->press($tv, self::ENTER, repeat: true);
            }
            $this->assertFalse($this->question($tv)['open']);
            $tv->assertPathIs('/player');

            // A repeat alone, nothing open, opens nothing.
            $this->press($tv, self::RETURN, repeat: true);
            $this->assertFalse($this->question($tv)['open']);
        });
    }

    public function test_it_closes_by_itself_after_ten_seconds_with_no_key(): void
    {
        $this->browse(function (Browser $tv) {
            $this->startTheSet($tv);

            $this->press($tv, self::RETURN);
            $tv->pause(6000);
            // A key keeps it open another ten seconds.
            $this->press($tv, self::UP);
            $tv->pause(6000);
            $this->assertTrue($this->question($tv)['open'], 'a key 6 s in keeps the question open past 10 s');
            $tv->pause(4500);
            $this->assertFalse($this->question($tv)['open'], 'ten seconds after the last key it closes');
            $tv->assertPathIs('/player');
        });
    }

    public function test_exit_goes_back_once_to_the_page_before_even_at_a_double_press(): void
    {
        $this->browse(function (Browser $tv) {
            $this->startTheSet($tv);

            $this->press($tv, self::RETURN);
            $this->press($tv, self::RIGHT);
            // Pressed twice at once, as a remote's OK sometimes is: one step back, never two.
            $this->press($tv, self::ENTER);
            $this->press($tv, self::ENTER);

            $tv->waitForLocation('/login', 10)->assertPathIs('/login');
            $tv->pause(800);
            $tv->assertPathIs('/login');
        });
    }

    public function test_a_pointer_can_answer_it_too(): void
    {
        $this->browse(function (Browser $tv) {
            $this->startTheSet($tv);

            $this->press($tv, self::RETURN);
            $tv->click('@exit-question-stay');
            $this->assertFalse($this->question($tv)['open']);

            $this->press($tv, self::RETURN);
            $tv->click('@exit-question-exit')->waitForLocation('/login', 10)->assertPathIs('/login');
        });
    }

    public function test_other_televisions_never_ask(): void
    {
        $this->browse(function (Browser $tv) {
            // Chrome's own user agent, no switch: not a Samsung TV.
            $this->startTheSet($tv, switch: null);

            $this->press($tv, self::RETURN);
            $this->assertFalse($this->question($tv)['open']);

            // And a set told no keeps it off even on Samsung.
            $this->startTheSet($tv, switch: '0');
            $this->press($tv, self::RETURN);
            $this->assertFalse($this->question($tv)['open']);
            $tv->assertPathIs('/player');
        });
    }

    private function screenPlayingAPicture(): void
    {
        $organization = Organization::factory()->create(['name' => 'Alpha Mart']);
        $screen = Screen::factory()->withToken('exit-token')->create([
            'organization_id' => $organization->id, 'name' => 'Counter TV', 'orientation' => 'landscape',
        ]);

        $picture = imagecreatetruecolor(320, 180);
        imagefill($picture, 0, 0, imagecolorallocate($picture, 30, 120, 200));
        ob_start();
        imagepng($picture);
        $bytes = (string) ob_get_clean();
        $path = "media/{$organization->id}/exit-question-picture.png";
        Storage::disk('public')->put($path, $bytes);

        $media = Media::factory()->create([
            'organization_id' => $organization->id, 'mime_type' => 'image/png', 'path' => $path, 'thumbnail_path' => null,
            'size' => strlen($bytes), 'width' => 320, 'height' => 180,
        ]);
        PlaylistItem::create(['screen_id' => $screen->id, 'media_id' => $media->id, 'position' => 0, 'duration_seconds' => 60]);
    }

    /** The player opened after /login, as the Samsung app opens it after its own page; the switch on unless said. */
    private function startTheSet(Browser $tv, ?string $token = null, ?string $switch = '1'): void
    {
        $tv->visit('/login');
        $tv->script('localStorage.clear();');
        if ($token !== null) {
            $tv->script("localStorage.setItem('signage.device.token', '{$token}');");
        }
        if ($switch !== null) {
            $tv->script("localStorage.setItem('signage.exit_question', '{$switch}');");
        }
        $tv->visit('/player');
        $tv->waitUntil('window.signagePlayerStarted === true', 15, 'the player never started');
    }

    /** A key as the remote sends it, through Chrome itself. */
    private function press(Browser $tv, array $key, bool $repeat = false): void
    {
        [$code, $name] = $key;
        $tools = new ChromeDevToolsDriver($tv->driver);
        $tools->execute('Input.dispatchKeyEvent', [
            'type' => 'rawKeyDown', 'windowsVirtualKeyCode' => $code, 'nativeVirtualKeyCode' => $code,
            'key' => $name, 'code' => $name, 'autoRepeat' => $repeat,
        ]);
        $tools->execute('Input.dispatchKeyEvent', [
            'type' => 'keyUp', 'windowsVirtualKeyCode' => $code, 'nativeVirtualKeyCode' => $code, 'key' => $name, 'code' => $name,
        ]);
    }

    /** @return array{open: bool, chosen: string, focus: string} */
    private function question(Browser $tv): array
    {
        return $tv->script("return {
            open: ! document.getElementById('exit-question').hidden,
            chosen: [...document.querySelectorAll('.exit-question-buttons button.chosen')].map((b) => b.textContent).join(),
            focus: document.activeElement ? document.activeElement.id : '',
        };")[0];
    }
}
