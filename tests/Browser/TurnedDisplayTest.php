<?php

namespace Tests\Browser;

use App\Models\Media;
use App\Models\Organization;
use App\Models\PlaylistItem;
use App\Models\Screen;
use Facebook\WebDriver\Chrome\ChromeDevToolsDriver;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Laravel\Dusk\Browser;
use Tests\DuskTestCase;

/**
 * A portrait screen is turned by the page only while the display lies flat.
 *
 * A Fire TV's WebView puts a video on a hardware plane that never turns with the stage: the plane's
 * box is the video element's un-turned rect, so a CSS-turned portrait stage showed the video sideways
 * and small (Roadstar 4, 2026-10-27). The Android player app therefore turns the DISPLAY the way the
 * set is mounted, and this page, seeing a viewport taller than wide, draws the screen as it draws a
 * landscape one: the stage is the viewport, nothing is turned, and the plane gets the whole screen.
 * Seen working on a Fire TV Stick 4K Max with the display turned by hand (adb) before this was written.
 *
 * A browser test because the turn is a media query the browser evaluates: the same page, the same
 * screen, two window shapes.
 */
class TurnedDisplayTest extends DuskTestCase
{
    use DatabaseMigrations;

    public function test_a_portrait_screen_is_turned_by_the_page_only_while_the_display_lies_flat(): void
    {
        $this->seedSuperAdmin();
        $organization = Organization::factory()->create(['name' => 'Alpha Mart']);
        $screen = Screen::factory()->withToken('turned-token')->create([
            'organization_id' => $organization->id,
            'name' => 'Menu TV',
            'orientation' => 'portrait',
        ]);
        $menu = Media::factory()->create([
            'organization_id' => $organization->id,
            'title' => 'menu',
            'type' => Media::TYPE_IMAGE,
            'mime_type' => 'image/png',
            'path' => $this->putImage("media/{$organization->id}/menu.png", 200, 40, 40),
            'thumbnail_path' => null,
        ]);
        PlaylistItem::create(['screen_id' => $screen->id, 'media_id' => $menu->id, 'position' => 0, 'duration_seconds' => 10]);

        $this->browse(function (Browser $tv) use ($screen) {
            $tv->visit('/login');
            $tv->script("localStorage.clear(); localStorage.setItem('signage.device.token', 'turned-token');");

            /* ── 1. A display lying flat (a set the app has not turned, a browser): the page turns the stage ── */
            $this->makePanel($tv, 1920, 1080);
            $tv->visit('/player');
            $this->waitForTheMenu($tv, 'portrait');

            $drawn = $this->drawn($tv);
            $this->assertSame(90, $drawn['turn'], 'on a flat display the page turns a portrait stage a quarter turn');
            $this->assertSame([1080, 1920], $drawn['stage'], 'the stage is the panel upright');
            $this->assertEqualsWithDelta(1920, $drawn['picture'][0], 2, 'turned, the picture spans the flat display');
            $this->assertEqualsWithDelta(1080, $drawn['picture'][1], 2);
            $tv->screenshot('turned-display-flat');

            /* ── 2. The display turned the way the set is mounted: the viewport is upright, and the page draws it flat ── */
            $this->makePanel($tv, 1080, 1920);

            $drawn = $this->drawn($tv);
            $this->assertSame(0, $drawn['turn'], 'on a turned display the page turns nothing');
            $this->assertSame([1080, 1920], $drawn['stage'], 'the stage is the viewport');
            $this->assertEqualsWithDelta(1080, $drawn['picture'][0], 2, 'the picture fills the upright display across');
            $this->assertEqualsWithDelta(1920, $drawn['picture'][1], 2, 'and down');
            $this->assertEqualsWithDelta(0, $drawn['at'][0], 2, 'from the left edge');
            $this->assertEqualsWithDelta(0, $drawn['at'][1], 2, 'and the top');
            $tv->screenshot('turned-display-upright');

            /* ── 3. A set mounted the other way up is the same page: the app turns the display the other way ── */
            $screen->update(['orientation' => 'portrait_flipped']);
            $tv->refresh();
            $this->waitForTheMenu($tv, 'portrait_flipped');

            $drawn = $this->drawn($tv);
            $this->assertSame(0, $drawn['turn'], 'on a turned display a flipped portrait stage is not turned either');
            $this->assertSame([1080, 1920], $drawn['stage']);

            $this->makePanel($tv, 1920, 1080);

            $drawn = $this->drawn($tv);
            $this->assertSame(-90, $drawn['turn'], 'on a flat display the page still turns it three quarters');
            $this->assertEqualsWithDelta(1920, $drawn['picture'][0], 2);
            $this->assertEqualsWithDelta(1080, $drawn['picture'][1], 2);

            /* ── 4. The Android player app as the shell: told which way the set is mounted, it turns the
                    display, and the page turns nothing — whichever of the four ways ── */
            $this->shellThatTurnsTheDisplay($tv, true);

            foreach (['landscape', 'landscape_flipped', 'portrait', 'portrait_flipped'] as $mounted) {
                $screen->update(['orientation' => $mounted]);
                $tv->refresh();
                $this->waitForTheMenu($tv, $mounted);

                $this->assertSame($mounted, $tv->script('return window.toldTheApp;')[0], "the page told the app the set is mounted {$mounted}");
                $drawn = $this->drawn($tv);
                $this->assertSame(0, $drawn['turn'], "with the app turning the display, the page turns nothing for {$mounted}");
                $this->assertSame([1920, 1080], $drawn['stage'], 'the stage is the viewport');
            }
            $tv->screenshot('turned-display-by-the-app');

            /* ── 5. A shell that cannot turn the display (an older app) leaves the page turning the stage itself ── */
            $this->shellThatTurnsTheDisplay($tv, false);
            $screen->update(['orientation' => 'portrait']);
            $tv->refresh();
            $this->waitForTheMenu($tv, 'portrait');

            $this->assertSame('portrait', $tv->script('return window.toldTheApp;')[0]);
            $this->assertSame(90, $this->drawn($tv)['turn'], 'an app that answers no leaves the page to turn the stage');
        });
    }

    /**
     * The Android player app's half of the contract, played by a script that runs before every page
     * from now on: window.SignagePlayer.turnDisplay(orientation) is told which way the set is mounted
     * and answers true once it has turned the display, false when it cannot.
     */
    private function shellThatTurnsTheDisplay(Browser $tv, bool $turns): void
    {
        $answer = $turns ? 'true' : 'false';

        $tools = new ChromeDevToolsDriver($tv->driver);
        $tools->execute('Page.enable');
        $tools->execute('Page.addScriptToEvaluateOnNewDocument', [
            'source' => "window.SignagePlayer = { turnDisplay: function (orientation) { window.toldTheApp = orientation; return {$answer}; } };",
        ]);
    }

    /**
     * A page of exactly this size: a window of that size shows a smaller page inside its own frame
     * (headless Chrome keeps room for a toolbar it never draws), so the window is widened by the
     * difference until the page itself is the panel. The media query follows the page, not the window.
     */
    private function makePanel(Browser $tv, int $width, int $height): void
    {
        $tv->resize($width, $height);

        [$innerWidth, $innerHeight] = $tv->script('return [window.innerWidth, window.innerHeight];')[0];
        $tv->resize($width + ($width - $innerWidth), $height + ($height - $innerHeight));

        $tv->waitUntil("window.innerWidth === {$width} && window.innerHeight === {$height}", 5, "the page did not become {$width} x {$height}");
    }

    /**
     * The menu is on the screen and the stage says which way the screen is mounted. The player builds a
     * picture in a hidden layer and shows the layer once the picture has loaded, so "on" is a box drawn
     * on the glass, not an element in the page.
     */
    private function waitForTheMenu(Browser $tv, string $orientation): void
    {
        $tv->waitUntil("document.getElementById('stage').dataset.orientation === '{$orientation}'", 15, "the stage never said {$orientation}");
        $tv->waitUntil(
            '(function () { const menu = document.querySelector(\'.media-layer img[src*="menu.png"]\'); return !!menu && menu.getBoundingClientRect().width > 0; })()',
            15,
            'the menu never came on'
        );
    }

    /**
     * How the stage is drawn: its turn in degrees (0 for none; a quarter turn is 90, three quarters
     * -90), its layout size, and the box the picture takes on the glass.
     *
     * @return array{turn: int, stage: array{int, int}, picture: array{float, float}, at: array{float, float}}
     */
    private function drawn(Browser $tv): array
    {
        return $tv->script(<<<'JS'
            const stage = document.getElementById('stage');
            const transform = getComputedStyle(stage).transform;
            let turn = 0;
            if (transform !== 'none') {
                const [a, b] = transform.replace(/^matrix\(|\)$/g, '').split(',').map(Number);
                turn = Math.round(Math.atan2(b, a) * 180 / Math.PI);
            }
            const picture = document.querySelector('.media-layer img[src*="menu.png"]').getBoundingClientRect();
            return {
                turn: turn,
                stage: [stage.offsetWidth, stage.offsetHeight],
                picture: [picture.width, picture.height],
                at: [picture.left, picture.top],
            };
        JS)[0];
    }
}
