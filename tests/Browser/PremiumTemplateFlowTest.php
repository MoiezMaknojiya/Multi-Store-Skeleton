<?php

namespace Tests\Browser;

use App\Models\BuilderAd;
use App\Models\BuilderAsset;
use App\Models\Media;
use App\Models\Organization;
use App\Models\User;
use App\Services\OrganizationStorage;
use Facebook\WebDriver\Chrome\ChromeDevToolsDriver;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Str;
use Laravel\Dusk\Browser;
use Tests\DuskTestCase;

/**
 * Create Ad inside an organization (owner, 2026-10-07): which way the screen is, then Create Your Own or Premium Template — the
 * platform's designs of that shape — and Use This Template, which makes one the organization's own ad, its pictures copied onto the
 * organization's own shelf.
 *
 * Pressed as a person presses — presses sent to Chrome itself (Input.dispatchMouseEvent) with the click count a double or triple
 * click carries, a slow line, an organization out of room, a phone, a keyboard — and watched for what a person would see go wrong:
 * a choice made by the rest of a double click, two copies, two tabs, an error thrown.
 */
class PremiumTemplateFlowTest extends DuskTestCase
{
    use DatabaseMigrations;

    public function test_an_organization_makes_its_own_ad_from_a_premium_template(): void
    {
        [$designer, $alpha, $burger, $coffee, $upright, $photo] = $this->templates();

        $this->browse(function (Browser $browser) use ($designer, $alpha, $burger, $coffee, $upright, $photo) {
            $this->openAds($browser, $designer, $alpha);

            $this->presses($browser, $this->centreOf($browser, '[dusk="new-ad"]'), 1);
            $browser->waitFor('@new-ad-landscape')->assertSee('which way is the screen');
            $this->presses($browser, $this->centreOf($browser, '[dusk="new-ad-landscape"]'), 1);

            // The second question, the keyboard already on its first answer.
            $browser->waitFor('@new-ad-own')->assertSee('how do you want to start')
                ->assertSeeIn('@new-ad-shape', 'Landscape · 1920 × 1080')
                ->assertVisible('@new-ad-premium')
                ->assertMissing('@new-ad-landscape');
            $this->assertSame('new-ad-own', $browser->script('return document.activeElement?.id;')[0]);
            $browser->screenshot('premium-new-ad-start');

            $this->presses($browser, $this->centreOf($browser, '[dusk="new-ad-premium"]'), 1);
            $browser->waitFor('@template-'.$burger->id)
                ->assertVisible('@template-'.$coffee->id)
                ->assertMissing('@template-'.$upright->id)
                ->assertMissing('@new-ad-own')
                ->assertAttribute('@template-preview-'.$burger->id, 'href', route('builder.preview', $burger))
                ->assertAttribute('@template-preview-'.$burger->id, 'target', '_blank')
                ->screenshot('premium-templates-gallery');

            // A search narrows the gallery; cleared, both come back.
            $this->jsType($browser, '@templates-search', 'coffee');
            $browser->waitUntilMissing('@template-'.$burger->id)->assertVisible('@template-'.$coffee->id);
            $this->jsType($browser, '@templates-search', '');
            $browser->waitFor('@template-'.$burger->id);
            $this->assertCalm($browser);

            $this->presses($browser, $this->centreOf($browser, '[dusk="use-template-'.$burger->id.'"]'), 1);
            $browser->waitFor('@ad-stage', 15);
            $this->waitForAlpine($browser);

            $copy = BuilderAd::where('organization_id', $alpha->id)->sole();
            $own = BuilderAsset::where('organization_id', $alpha->id)->sole();
            $this->assertSame('Burger menu', $copy->name);
            $this->assertSame($photo->id, $own->copied_from_id);
            $this->assertStringEndsWith('/builder/'.$copy->id, $browser->driver->getCurrentURL());

            // On the stage, its own copy of the picture — and the picker offers that copy alone.
            $browser->waitFor('[dusk^="element-"]');
            $this->clickAndAwait($browser, '@add-image', fn (Browser $b) => $b->waitFor('@asset-picker', 3));
            $browser->waitFor('@pick-asset-'.$own->id)->assertMissing('@pick-asset-'.$photo->id)->screenshot('premium-copy-in-editor');

            // Back on the Ads page: the copy is theirs; the template is no card of theirs.
            $browser->visit('/builder');
            $this->waitForAlpine($browser);
            $browser->waitFor('@ad-card-'.$copy->id)->assertMissing('@ad-card-'.$burger->id)->assertVisible('@edit-ad-'.$copy->id);
        });
    }

    public function test_double_and_triple_presses_choose_once_open_once_and_copy_once(): void
    {
        [$designer, $alpha, $burger] = $this->templates();

        $this->browse(function (Browser $browser) use ($designer, $alpha, $burger) {
            $this->openAds($browser, $designer, $alpha);

            $this->presses($browser, $this->centreOf($browser, '[dusk="new-ad"]'), 2);
            $browser->waitFor('@new-ad-landscape')->pause(300)->assertVisible('@new-ad-landscape');

            // The rest of a double click on the shape lands where Create Your Own now is: it must not choose it.
            $this->presses($browser, $this->centreOf($browser, '[dusk="new-ad-landscape"]'), 2);
            $browser->pause(900)->assertVisible('@new-ad-own');
            $this->assertOnTheAdsPage($browser);

            // Back, and a triple click on the other shape: still the second question, nothing chosen.
            $this->presses($browser, $this->centreOf($browser, '[dusk="new-ad-back"]'), 1);
            $browser->waitFor('@new-ad-portrait');
            $this->presses($browser, $this->centreOf($browser, '[dusk="new-ad-portrait"]'), 3);
            $browser->pause(900)->assertSeeIn('@new-ad-shape', 'Portrait');
            $this->assertOnTheAdsPage($browser);

            $this->presses($browser, $this->centreOf($browser, '[dusk="new-ad-back"]'), 1);
            $browser->waitFor('@new-ad-landscape');
            $this->presses($browser, $this->centreOf($browser, '[dusk="new-ad-landscape"]'), 1);
            $browser->waitFor('@new-ad-premium');

            // A triple click on Premium Template: one gallery, and the rest of the click chooses nothing in it.
            $this->presses($browser, $this->centreOf($browser, '[dusk="new-ad-premium"]'), 3);
            $browser->waitFor('@template-'.$burger->id)->pause(600)->assertMissing('@new-ad-own');
            $this->assertOnTheAdsPage($browser);
            $this->assertSame(0, BuilderAd::where('organization_id', $alpha->id)->count());

            // A double click on Preview opens one tab.
            $page = $browser->driver->getWindowHandle();
            $before = $browser->driver->getWindowHandles();
            $this->presses($browser, $this->centreOf($browser, '[dusk="template-preview-'.$burger->id.'"]'), 2);
            $browser->pause(1200);
            $opened = array_values(array_diff($browser->driver->getWindowHandles(), $before));
            $this->assertCount(1, $opened, 'A double click on Preview did not open exactly one tab');
            $browser->driver->switchTo()->window($opened[0]);
            $browser->driver->close();
            $browser->driver->switchTo()->window($page);

            // A triple click on Use This Template: one copy.
            $this->presses($browser, $this->centreOf($browser, '[dusk="use-template-'.$burger->id.'"]'), 3);
            $browser->waitFor('@ad-stage', 15);
            $browser->pause(800);
            $this->assertSame(1, BuilderAd::where('organization_id', $alpha->id)->count(), 'A triple click made more than one copy');
            $this->assertSame(1, BuilderAsset::where('organization_id', $alpha->id)->count());
        });
    }

    public function test_on_a_slow_line_the_gallery_says_the_newest_search_and_the_copy_is_made_once(): void
    {
        [$designer, $alpha, $burger, $coffee] = $this->templates();

        $this->browse(function (Browser $browser) use ($designer, $alpha, $burger, $coffee) {
            $this->openAds($browser, $designer, $alpha);
            $this->network($browser, 1200);

            $this->jsClick($browser, '@new-ad');
            $browser->waitFor('@new-ad-landscape');
            $this->jsClick($browser, '@new-ad-landscape');
            $this->jsClick($browser, '@new-ad-premium');
            $browser->waitFor('@premium-templates')->waitForTextIn('@premium-templates', 'Loading...');

            // Two searches on their way at once: the older answer, arriving late, must not win.
            $this->jsType($browser, '@templates-search', 'bur');
            $browser->pause(400);
            $this->jsType($browser, '@templates-search', 'coffee');
            $browser->waitFor('@template-'.$coffee->id, 15)->pause(2500)
                ->assertVisible('@template-'.$coffee->id)->assertMissing('@template-'.$burger->id);

            // Copying on the slow line: said on the button, every other Use This Template and Back waiting for it.
            $this->jsType($browser, '@templates-search', '');
            $browser->waitFor('@template-'.$burger->id, 15);
            $this->presses($browser, $this->centreOf($browser, '[dusk="use-template-'.$coffee->id.'"]'), 1);
            $browser->waitForTextIn('@use-template-'.$coffee->id, 'Copying...')
                ->assertDisabled('@use-template-'.$burger->id)
                ->assertDisabled('@templates-back');
            $this->presses($browser, $this->centreOf($browser, '[dusk="use-template-'.$burger->id.'"]'), 1);

            $browser->waitFor('@ad-stage', 20);
            $this->network($browser, 0);
            $this->assertSame(['Coffee menu'], BuilderAd::where('organization_id', $alpha->id)->pluck('name')->all());
        });
    }

    public function test_an_organization_out_of_room_is_told_how_much_is_needed_and_can_choose_again(): void
    {
        [$designer, $alpha, $burger] = $this->templates();
        Media::factory()->create(['organization_id' => $alpha->id, 'size' => OrganizationStorage::LIMIT_BYTES - 1000, 'thumbnail_path' => null]);

        $this->browse(function (Browser $browser) use ($designer, $alpha, $burger) {
            $this->openAds($browser, $designer, $alpha);
            $this->jsClick($browser, '@new-ad');
            $browser->waitFor('@new-ad-landscape');
            $this->jsClick($browser, '@new-ad-landscape');
            $this->jsClick($browser, '@new-ad-premium');
            $browser->waitFor('@use-template-'.$burger->id);

            $this->presses($browser, $this->centreOf($browser, '[dusk="use-template-'.$burger->id.'"]'), 1);
            $browser->waitForText('Not enough storage', 10)
                ->assertSee('Alpha Mart has 1 KB left of its 512 MB')
                ->assertVisible('@premium-templates')
                ->assertEnabled('@use-template-'.$burger->id)
                ->assertSeeIn('@use-template-'.$burger->id, 'Use This Template');
            $this->assertOnTheAdsPage($browser);
            $this->assertSame(0, BuilderAd::where('organization_id', $alpha->id)->count());
            $this->assertCalm($browser, ['422']);
        });
    }

    public function test_by_keyboard_each_step_takes_the_focus_and_escape_closes_the_gallery(): void
    {
        [$designer, $alpha, $burger] = $this->templates();

        $this->browse(function (Browser $browser) use ($designer, $alpha, $burger) {
            $this->openAds($browser, $designer, $alpha);
            $this->jsClick($browser, '@new-ad');
            $browser->waitFor('@new-ad-landscape')->pause(300);

            $browser->script("document.getElementById('new-ad-landscape').focus()");
            $browser->keys('@new-ad-landscape', '{enter}')->waitFor('@new-ad-own');
            $this->assertSame('new-ad-own', $browser->script('return document.activeElement?.id;')[0]);

            $browser->keys('@new-ad-own', '{tab}');
            $this->assertSame('new-ad-premium', $browser->script('return document.activeElement?.getAttribute("dusk");')[0]);
            $browser->keys('@new-ad-premium', '{enter}')->waitFor('@template-'.$burger->id);

            $browser->keys('@templates-search', '{escape}')->waitUntilMissing('@premium-templates')->assertMissing('@new-ad-own');
            $this->assertOnTheAdsPage($browser);
            $this->assertCalm($browser);
        });
    }

    public function test_on_a_phone_both_dialogs_fit_and_the_gallery_is_one_column(): void
    {
        [$designer, $alpha, $burger, $coffee] = $this->templates();

        $this->browse(function (Browser $browser) use ($designer, $alpha, $burger, $coffee) {
            $browser->resize(375, 812);
            $this->openAds($browser, $designer, $alpha);
            $this->jsClick($browser, '@new-ad');
            $browser->waitFor('@new-ad-landscape');
            $this->jsClick($browser, '@new-ad-landscape');
            $browser->waitFor('@new-ad-own');
            $this->assertFitsThePhone($browser, '[dusk="new-ad-dialog"]');
            $browser->screenshot('premium-new-ad-phone');

            $this->jsClick($browser, '@new-ad-premium');
            $browser->waitFor('@template-'.$coffee->id);
            $this->assertFitsThePhone($browser, '[dusk="premium-templates"]');

            $columns = $browser->script(<<<JS
                const a = document.querySelector('[dusk="template-{$burger->id}"]').getBoundingClientRect();
                const b = document.querySelector('[dusk="template-{$coffee->id}"]').getBoundingClientRect();
                return Math.abs(a.left - b.left) < 1;
            JS)[0];
            $this->assertTrue($columns, 'The gallery is more than one column on a phone');
            $browser->screenshot('premium-templates-phone');
            $browser->resize(1440, 900);
        });
    }

    /* ── The templates, set up ─────────────────────────────────────────── */

    /**
     * Two landscape templates — Burger menu with a picture of the platform's — and one portrait, and an organization's designer.
     *
     * @return array{0: User, 1: Organization, 2: BuilderAd, 3: BuilderAd, 4: BuilderAd, 5: BuilderAsset}
     */
    private function templates(): array
    {
        $this->seedSuperAdmin();
        $alpha = Organization::factory()->create(['name' => 'Alpha Mart']);
        $designer = $this->organizationMember($alpha, ['ad-view', 'ad-store', 'ad-update', 'ad-destroy'], 'designer@example.com', 'Designer');

        $name = Str::lower(Str::random(10));
        $photo = BuilderAsset::factory()->create([
            'organization_id' => null, 'title' => 'Burger photo', 'mime_type' => 'image/png', 'size' => 4000, 'width' => 640, 'height' => 360,
            'path' => $this->putImage("builder/platform/assets/{$name}.png", 200, 90, 30),
            'thumbnail_path' => $this->putImage("builder/platform/assets/thumbs/{$name}.png", 200, 90, 30),
        ]);

        $burger = $this->template('Burger menu', BuilderAd::LANDSCAPE, $photo->id, [180, 40, 40]);
        $coffee = $this->template('Coffee menu', BuilderAd::LANDSCAPE, null, [90, 60, 30]);
        $upright = $this->template('Upright menu', BuilderAd::PORTRAIT, null, [30, 90, 160]);

        return [$designer, $alpha, $burger, $coffee, $upright, $photo];
    }

    private function template(string $name, string $orientation, ?int $assetId, array $colour): BuilderAd
    {
        $document = BuilderAd::blankDocument($orientation);
        $document['elements'][] = [
            'id' => 'el_text', 'type' => 'text', 'name' => 'Headline', 'x' => 80, 'y' => 80, 'w' => 900, 'h' => 160,
            'rotation' => 0, 'opacity' => 1, 'z' => 0, 'locked' => false, 'visible' => true,
            'text' => $name, 'style' => ['fontSize' => 96, 'color' => '#ffffff'], 'animations' => [],
        ];

        if ($assetId !== null) {
            $document['elements'][] = [
                'id' => 'el_photo', 'type' => 'image', 'name' => 'Photo', 'x' => 80, 'y' => 320, 'w' => 640, 'h' => 360,
                'rotation' => 0, 'opacity' => 1, 'z' => 1, 'locked' => false, 'visible' => true,
                'assetId' => $assetId, 'style' => [], 'animations' => [],
            ];
        }

        $ad = BuilderAd::factory()->state(['organization_id' => null, 'name' => $name, 'orientation' => $orientation, 'document' => $document])->published()->create();
        $ad->media->update(['thumbnail_path' => $this->putImage("builder/platform/ads/{$ad->id}/published.png", ...$colour)]);

        return $ad->fresh();
    }

    private function openAds(Browser $browser, User $designer, Organization $organization): void
    {
        $this->freshSession($browser);
        $browser->loginAs($designer);
        $this->switchToOrganization($browser, $organization);
        $browser->visit('/builder');
        $this->waitForAlpine($browser);
        $browser->waitFor('@ads-empty');
        $this->watch($browser);
    }

    /* ── Watching the page ──────────────────────────────────────────────── */

    private function watch(Browser $browser): void
    {
        $browser->script(<<<'JS'
            if (window.__w) return;
            window.__w = { errors: [] };
            window.addEventListener('error', (e) => window.__w.errors.push(String(e.message)));
            window.addEventListener('unhandledrejection', (e) => window.__w.errors.push(String(e.reason)));
            const said = console.error;
            console.error = function (...parts) { window.__w.errors.push(parts.map(String).join(' ')); return said.apply(this, parts); };
        JS);
    }

    /** Nothing thrown or logged as an error but what the case expects. */
    private function assertCalm(Browser $browser, array $allow = []): void
    {
        $errors = array_values(array_filter($browser->script('return window.__w ? window.__w.errors : [];')[0],
            fn (string $error) => ! collect($allow)->contains(fn (string $expected) => str_contains($error, $expected))));
        $this->assertSame([], $errors, 'The page threw or logged errors');
    }

    private function assertOnTheAdsPage(Browser $browser): void
    {
        $this->assertSame('/builder', parse_url($browser->driver->getCurrentURL(), PHP_URL_PATH), 'The page was left');
    }

    /** The dialog is no wider than the phone, and neither is the page. */
    private function assertFitsThePhone(Browser $browser, string $dialog): void
    {
        $fit = $browser->script('const d = document.querySelector('.json_encode($dialog).'); const r = d.getBoundingClientRect();'
            .' return { inside: r.left >= 0 && r.right <= window.innerWidth + 0.5, own: d.scrollWidth - d.clientWidth,'
            .' page: document.documentElement.scrollWidth - document.documentElement.clientWidth };')[0];

        $this->assertTrue($fit['inside'], "{$dialog} runs out of the phone");
        $this->assertLessThanOrEqual(0, $fit['own'], "{$dialog} scrolls sideways on a phone");
        $this->assertLessThanOrEqual(0, $fit['page'], 'The page scrolls sideways on a phone');
    }

    /* ── A person's mouse, and the line ─────────────────────────────────── */

    /** $times presses of a real mouse on one place, $gap ms apart, each counting on from the last as Chrome counts them. */
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

    /** The middle of an element, brought into sight first. */
    private function centreOf(Browser $browser, string $css): array
    {
        return $browser->script('const el = document.querySelector('.json_encode($css).'); el.scrollIntoView({ block: "center" }); '
            .'const r = el.getBoundingClientRect(); return [Math.round(r.left + r.width / 2), Math.round(r.top + r.height / 2)];')[0];
    }

    /** Latency in ms for every request. */
    private function network(Browser $browser, int $latency): void
    {
        $tools = new ChromeDevToolsDriver($browser->driver);
        $tools->execute('Network.enable');
        $tools->execute('Network.emulateNetworkConditions', ['offline' => false, 'latency' => $latency, 'downloadThroughput' => -1, 'uploadThroughput' => -1]);
    }
}
