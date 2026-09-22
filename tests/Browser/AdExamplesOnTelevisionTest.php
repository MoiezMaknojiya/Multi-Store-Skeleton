<?php

namespace Tests\Browser;

use App\Models\BuilderAd;
use App\Models\PlaylistItem;
use App\Models\Role;
use App\Models\Screen;
use App\Models\Store;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Laravel\Dusk\Browser;
use Tests\DuskTestCase;

/**
 * The four example ads (`builder:examples`, docs/AD-BUILDER-SPEC.md §10) on a real television: paired
 * from the panel, put on its playlist with seconds of their own, and played one after another — each
 * checked from INSIDE the television's sandboxed frame, where the page's own runtime has brought its
 * headline in. A screenshot of each is kept in tests/Browser/screenshots.
 */
class AdExamplesOnTelevisionTest extends DuskTestCase
{
    use DatabaseMigrations;

    /** Each example's headline: the element that carries it, and the words it must show. */
    private const HEADLINES = [
        'Example · Winter Sale' => ['ws_title', 'WINTER SALE'],
        'Example · Fresh Coffee' => ['fc_title', 'Coffee'],
        'Example · Grand Opening' => ['go_grand', 'GRAND'],
        'Example · Burger Deal (Urdu)' => ['bd_title', 'زبردست ڈیل'],
    ];

    public function test_the_four_example_ads_play_on_a_television_one_after_another(): void
    {
        $this->seedSuperAdmin();
        $store = Store::factory()->create(['name' => 'Alpha Mart']);
        $owner = $this->storeMember($store, Role::OWNER, 'owner@example.com');

        $this->artisan('builder:examples', ['store' => $store->id, '--no-fonts' => true])->assertSuccessful();

        $ads = BuilderAd::where('store_id', $store->id)->orderBy('id')->get();
        $this->assertSame(array_keys(self::HEADLINES), $ads->pluck('name')->all());

        $this->browse(function (Browser $tv, Browser $panel) use ($owner, $store, $ads) {
            /* ── 1. A television asks to be adopted ─────────────────────── */
            $tv->visit('/player');
            $tv->waitFor('@pairing-code', 15);
            $tv->waitUntil('document.querySelector(\'[dusk="pairing-code"]\').textContent.trim().length === 6', 15);
            $code = trim($tv->text('@pairing-code'));

            /* ── 2. The owner pairs it and gives it the four, eight seconds each ── */
            $this->freshSession($panel);
            $panel->loginAs($owner);
            $this->switchToStore($panel, $store);

            $panel->visit('/screens');
            $this->waitForAlpine($panel);
            $this->clickAndAwait($panel, '@add-screen', fn (Browser $b) => $b->waitFor('@screen-pair-form', 3));
            $this->jsType($panel, '@screen-code', $code);
            $this->jsType($panel, '@screen-name', 'Counter TV');
            $this->jsClick($panel, '@screen-pair-save');

            $panel->waitUsing(20, 250, fn () => Screen::where('store_id', $store->id)->exists());
            $screen = Screen::where('store_id', $store->id)->sole();

            $panel->visit('/screens/'.$screen->id);
            $this->waitForAlpine($panel);

            foreach ($ads->values() as $index => $ad) {
                $panel->waitFor('@playlist-add-'.$ad->media_id);
                $this->jsClick($panel, '@playlist-add-'.$ad->media_id);
                $panel->waitFor('@playlist-duration-'.$index);
                $this->jsType($panel, '@playlist-duration-'.$index, '8');
            }

            $this->jsClick($panel, '@playlist-save');
            $panel->waitUsing(15, 250, fn () => PlaylistItem::where('screen_id', $screen->id)->count() === 4);
            $this->assertSame([8, 8, 8, 8], PlaylistItem::where('screen_id', $screen->id)->orderBy('position')->pluck('duration_seconds')->all());

            /* ── 3. Reopened, the television asks at once rather than at its next poll ── */
            $tv->refresh();

            foreach ($ads as $ad) {
                [$headline, $words] = self::HEADLINES[$ad->name];

                // Its page in the frame in front…
                $layer = null;
                $tv->waitUsing(60, 250, function () use ($tv, $ad, &$layer) {
                    $layer = $tv->script(
                        'const frame = [...document.querySelectorAll("#layer-a iframe, #layer-b iframe")]'
                        .'.find((f) => !f.closest("[id^=layer]").hidden && f.getAttribute("src").includes("/ads/'.$ad->id.'/"));'
                        .'return frame ? frame.closest("[id^=layer]").id : null;'
                    )[0];

                    return $layer !== null;
                });

                // …and inside it, sandboxed, the headline its own animation has brought in.
                $tv->withinFrame("#{$layer} iframe", function (Browser $page) use ($headline, $words) {
                    $anim = 'document.querySelector(\'[data-anim-id="'.$headline.'"] > .ad-anim\')';

                    $page->waitUntil("!!window.anime && !!{$anim} && getComputedStyle({$anim}).opacity === '1'", 10);
                    $this->assertStringContainsString($words, $page->script('return document.querySelector(\'[data-anim-id="'.$headline.'"]\').textContent;')[0]);
                });

                $tv->screenshot('example-ad-'.$ad->id);
            }
        });
    }
}
