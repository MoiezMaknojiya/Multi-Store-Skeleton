<?php

namespace Tests\Browser;

use App\Models\AdCategory;
use App\Models\BuilderAd;
use App\Models\Organization;
use Facebook\WebDriver\Chrome\ChromeDevToolsDriver;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Laravel\Dusk\Browser;
use Tests\DuskTestCase;

/**
 * The Ad Builder's categories (owner, 2026-10-22): the platform's one list under Ad Builder → Categories; Create Ad's Categories
 * under For and above the shapes, several at once; the editor's own in its Stage panel; the Ads page's and the Premium
 * Templates' Category lists. Pressed as a person presses — presses sent to Chrome itself with a double click's count, Escape
 * from the keyboard — and watched for a list that shuts as it opens, a dialog that goes with Escape, a console error.
 */
class AdCategoriesFlowTest extends DuskTestCase
{
    use DatabaseMigrations;

    public function test_the_platform_makes_categories_and_a_new_ad_is_put_in_them_from_create_ad_and_the_editor(): void
    {
        $admin = $this->seedSuperAdmin();
        $beer = AdCategory::factory()->create(['name' => 'Beer']);
        $school = AdCategory::factory()->create(['name' => 'Education']);
        $plain = BuilderAd::factory()->create(['organization_id' => null, 'name' => 'Plain draft']);

        $this->browse(function (Browser $browser) use ($admin, $beer, $school, $plain) {
            $this->freshSession($browser);
            $browser->loginAs($admin)->visit('/builder/categories');
            $this->waitForAlpine($browser);

            /* ── 1. The list: Beer and Education; a name already there is refused under its field, a new one is added ── */
            $browser->waitFor('@edit-category-'.$beer->id)->assertSee('All Categories')->assertSee('Education');
            $browser->click('@add-category')->waitFor('@category-name')
                ->type('@category-name', ' BEER ')->click('@category-save')
                ->waitForText('There is a category called Beer already.');
            // The refusal's 422 is in the console as a failed request: the one error this page may show.
            $browser->driver->manage()->getLog('browser');
            $browser->clear('@category-name')->type('@category-name', 'Menu')->click('@category-save')
                ->waitUntilMissing('@category-name')->waitForText('Menu');
            $menu = AdCategory::where('name', 'Menu')->sole();
            $this->assertNoConsoleErrors($browser);

            /* ── 2. Create Ad: Categories under For and above the shapes ── */
            $browser->visit('/builder');
            $this->waitForAlpine($browser);
            $browser->waitFor('@ad-card-'.$plain->id)->click('@new-ad')->waitFor('@new-ad-categories');
            $tops = $browser->script('const top = (d) => document.querySelector(`[dusk="${d}"]`).getBoundingClientRect().top;'
                .'return [top("new-ad-owner"), top("new-ad-categories"), top("new-ad-landscape")];')[0];
            $this->assertTrue($tops[0] < $tops[1] && $tops[1] < $tops[2], 'For, then Categories, then the shapes: '.json_encode($tops));
            $browser->assertSeeIn('@new-ad-categories', 'Choose Categories')->assertMissing('@new-ad-categories-list');

            // A double press opens the list once: it stays open, nothing moved under the pointer.
            $this->presses($browser, $this->centreOf($browser, '[dusk="new-ad-categories"]'), 2);
            $browser->pause(400)->assertVisible('@new-ad-categories-list')
                ->assertAttribute('@new-ad-categories', 'aria-expanded', 'true');

            // Several at once, said on the button.
            $browser->click('@new-ad-categories-option-'.$menu->id)->click('@new-ad-categories-option-'.$beer->id)
                ->click('@new-ad-categories-option-'.$school->id)
                ->assertSeeIn('@new-ad-categories', '3 Chosen');

            // Escape shuts the list onto its button, and the dialog stays; the chips under it show the three.
            $this->key($browser, 'Escape');
            $browser->waitUntilMissing('@new-ad-categories-list')->assertVisible('@new-ad-dialog')
                ->assertVisible('@new-ad-categories-chip-'.$menu->id)->assertVisible('@new-ad-categories-chip-'.$beer->id);
            $this->assertSame('new-ad-categories', $browser->script('return document.activeElement?.getAttribute("dusk");')[0]);

            // A chip pressed takes its own off, and the keyboard goes back to the button.
            $browser->click('@new-ad-categories-chip-'.$school->id)->waitUntilMissing('@new-ad-categories-chip-'.$school->id)
                ->assertSeeIn('@new-ad-categories', '2 Chosen');
            $this->assertSame('new-ad-categories', $browser->script('return document.activeElement?.getAttribute("dusk");')[0]);
            $browser->click('@new-ad-categories')->waitFor('@new-ad-categories-list')
                ->assertNotChecked('@new-ad-categories-option-'.$school->id)->assertChecked('@new-ad-categories-option-'.$menu->id);
            $this->key($browser, 'Escape');
            $browser->waitUntilMissing('@new-ad-categories-list')->assertVisible('@new-ad-dialog');
            $browser->screenshot('categories-create-ad');

            /* ── 3. Landscape opens the editor with them, said by name on the box beside the ad's name ── */
            $browser->click('@new-ad-landscape')->waitFor('@ad-stage', 15);
            $this->waitForAlpine($browser);
            parse_str((string) parse_url($browser->driver->getCurrentURL(), PHP_URL_QUERY), $query);
            $asked = array_map('intval', explode(',', $query['categories'] ?? ''));
            sort($asked);
            $this->assertSame([$beer->id, $menu->id], $asked);
            $browser->waitForTextIn('@ad-categories', 'Beer, Menu');
            $beside = $browser->script('const name = document.querySelector(`[dusk="ad-name"]`).getBoundingClientRect();'
                .'const box = document.querySelector(`[dusk="ad-categories"]`).getBoundingClientRect();'
                .'return [Math.round(box.left - name.right), Math.round(Math.abs(box.top - name.top))];')[0];
            $this->assertTrue($beside[0] >= 0 && $beside[0] <= 16 && $beside[1] <= 2, 'The box stands right of the name: '.json_encode($beside));

            // A double press opens it once, and its list stays open.
            $this->presses($browser, $this->centreOf($browser, '[dusk="ad-categories"]'), 2);
            $browser->pause(400)->assertVisible('@ad-categories-list')
                ->assertChecked('@ad-categories-option-'.$beer->id)->assertNotChecked('@ad-categories-option-'.$school->id);

            // Changed in the editor: Education on, Beer off. Escape shuts the list and nothing else of the editor.
            $browser->click('@ad-categories-option-'.$school->id)->click('@ad-categories-option-'.$beer->id)
                ->assertSeeIn('@ad-categories', 'Education, Menu');
            $this->key($browser, 'Escape');
            $browser->waitUntilMissing('@ad-categories-list')->assertVisible('@ad-stage');
            $this->assertSame('ad-categories', $browser->script('return document.activeElement?.getAttribute("dusk");')[0]);

            // A press on the stage shuts it too.
            $browser->click('@ad-categories')->waitFor('@ad-categories-list');
            $this->presses($browser, $this->centreOf($browser, '[dusk="ad-stage"]'), 1);
            $browser->waitUntilMissing('@ad-categories-list')->screenshot('categories-editor');

            // Saved with the design.
            $browser->click('@ad-save')->waitForText('Ad saved', 15);

            // The bar holds everything at every laptop width the editor opens on: nothing of its left half under its buttons, the
            // name never squeezed, the box whole from 1536 px and a tag button below that (which opens the same list).
            $frame = (int) $browser->script('return window.outerWidth - window.innerWidth;')[0];
            try {
                foreach ([1024, 1280, 1366, 1440, 1536, 1920] as $width) {
                    // The window's own frame added, so the page itself is that wide (the editor opens from 1024 px).
                    $browser->resize($width + $frame, 800)->pause(300);
                    $this->assertSame($width, (int) $browser->script('return window.innerWidth;')[0]);
                    $bar = $browser->script(<<<'JS'
                        const header = document.querySelector('[dusk="ad-name"]').closest('header');
                        const [left, right] = [...header.children].filter((child) => child.tagName === 'DIV');
                        const width = (dusk) => Math.round(document.querySelector(`[dusk="${dusk}"]`).getBoundingClientRect().width);
                        return {
                            overlap: Math.round(Math.max(...[...left.children].map((child) => child.getBoundingClientRect().right)) - right.getBoundingClientRect().left),
                            publishInSight: document.querySelector('[dusk="ad-publish-menu"]').getBoundingClientRect().right <= window.innerWidth,
                            name: width('ad-name'), box: width('ad-categories'), compact: width('ad-categories-compact'),
                            orientation: width('ad-orientation'), owner: width('ad-owner'),
                        };
                    JS)[0];
                    $this->assertLessThanOrEqual(0, $bar['overlap'], "The bar's left half runs under its buttons at {$width}px: ".json_encode($bar));
                    $this->assertTrue($bar['publishInSight'], "Publish is pushed out at {$width}px.");
                    $this->assertGreaterThanOrEqual(124, $bar['name'], "The name is squeezed at {$width}px: ".json_encode($bar));
                    // Whose ad it is keeps a word at least (it had 25 px at 1024 before the box came; 46 now).
                    $this->assertGreaterThanOrEqual(40, $bar['owner'], "Whose it is is squeezed at {$width}px: ".json_encode($bar));
                    $this->assertGreaterThanOrEqual(60, $bar['orientation'], "The shape is squeezed at {$width}px: ".json_encode($bar));
                    if ($width >= 1536) {
                        $this->assertGreaterThanOrEqual(140, $bar['box'], "The box is squeezed at {$width}px: ".json_encode($bar));
                        $this->assertSame(0, $bar['compact']);
                    } else {
                        $this->assertSame(0, $bar['box']);
                        $this->assertGreaterThanOrEqual(36, $bar['compact']);
                        $browser->assertAttribute('@ad-categories-compact', 'aria-label', 'Categories (2): Education, Menu')
                            ->assertSeeIn('@ad-categories-compact', '2');
                    }
                    $browser->screenshot("categories-editor-{$width}");
                }

                // The tag button opens the same list, and Escape puts the keyboard back on it.
                $browser->resize(1024 + $frame, 800)->pause(300)->click('@ad-categories-compact')->waitFor('@ad-categories-list')
                    ->assertChecked('@ad-categories-option-'.$school->id);
                $this->key($browser, 'Escape');
                $browser->waitUntilMissing('@ad-categories-list');
                $this->assertSame('ad-categories-compact', $browser->script('return document.activeElement?.getAttribute("dusk");')[0]);
            } finally {
                $browser->resize(1920, 1080);
            }
            $ad = BuilderAd::where('name', 'Untitled ad')->sole();
            $this->assertSame([$school->id, $menu->id], $ad->categories()->pluck('ad_categories.id')->all());
            $this->assertNoConsoleErrors($browser);

            /* ── 4. The Ads page's Category list: Education holds the new ad alone, Beer none ── */
            $browser->visit('/builder');
            $this->waitForAlpine($browser);
            $browser->waitFor('@ad-card-'.$ad->id)->assertSeeIn('@ad-card-'.$ad->id, 'Education')
                ->select('@ads-filter-category', (string) $school->id)
                // A filter loads the list afresh: the cards go while it loads, so the one kept is waited for.
                ->waitUntilMissing('@ad-card-'.$plain->id)->waitFor('@ad-card-'.$ad->id)->pause(300)
                ->assertMissing('@ad-card-'.$plain->id);
            $this->assertStringContainsString('category='.$school->id, $browser->driver->getCurrentURL());

            $browser->select('@ads-filter-category', (string) $beer->id)
                ->waitFor('@table-no-match')->assertSeeIn('@table-no-match', 'The platform has no ads in Beer.')
                ->click('@table-clear-category')->waitFor('@ad-card-'.$plain->id)
                ->assertSelected('@ads-filter-category', '');
            $this->assertNoConsoleErrors($browser);
        });
    }

    public function test_inside_an_organization_a_premium_template_is_copied_into_the_categories_ticked_and_the_gallery_filters(): void
    {
        $this->seedSuperAdmin();
        $alpha = Organization::factory()->create(['name' => 'Alpha Mart']);
        $designer = $this->organizationMember($alpha, ['ad-view', 'ad-store', 'ad-update'], 'designer@example.com', 'Designer');
        $menu = AdCategory::factory()->create(['name' => 'Menu']);
        $beer = AdCategory::factory()->create(['name' => 'Beer']);
        $burger = BuilderAd::factory()->published()->create(['organization_id' => null, 'name' => 'Burger menu']);
        $burger->categories()->attach($menu->id);
        $lager = BuilderAd::factory()->published()->create(['organization_id' => null, 'name' => 'Lager night']);
        $lager->categories()->attach($beer->id);

        $this->browse(function (Browser $browser) use ($designer, $alpha, $menu, $beer, $burger, $lager) {
            $this->freshSession($browser);
            $browser->loginAs($designer);
            $this->switchToOrganization($browser, $alpha);
            $browser->visit('/builder');
            $this->waitForAlpine($browser);

            $browser->click('@new-ad')->waitFor('@new-ad-categories')->assertMissing('@new-ad-owner')
                ->click('@new-ad-categories')->waitFor('@new-ad-categories-list')
                ->click('@new-ad-categories-option-'.$beer->id)
                ->click('@new-ad-landscape')->waitFor('@new-ad-premium');
            $this->assertStringContainsString('categories='.$beer->id, $browser->attribute('@new-ad-own', 'href'));

            // The gallery: both templates, each naming its category; Menu narrows it to the burger.
            $browser->click('@new-ad-premium')->waitFor('@template-'.$burger->id)->assertVisible('@template-'.$lager->id)
                ->assertSeeIn('@template-'.$lager->id, 'Beer')
                ->select('@templates-category', (string) $menu->id)
                ->waitUntilMissing('@template-'.$lager->id)->assertVisible('@template-'.$burger->id);
            $browser->screenshot('categories-gallery');

            // Its copy is in the template's category and the one ticked.
            $browser->click('@use-template-'.$burger->id)->waitFor('@ad-stage', 15);
            $this->waitForAlpine($browser);
            $copy = BuilderAd::where('organization_id', $alpha->id)->sole();
            $this->assertSame([$beer->id, $menu->id], $copy->categories()->pluck('ad_categories.id')->all());
            $browser->waitForTextIn('@ad-categories', 'Beer, Menu');
            $this->assertNoConsoleErrors($browser);
        });
    }

    /** No error in the console — the factory's pictures, which have no file under /dusk-storage, aside. */
    private function assertNoConsoleErrors(Browser $browser): void
    {
        $errors = collect($browser->driver->manage()->getLog('browser'))->filter(fn (array $entry) => $entry['level'] === 'SEVERE')
            ->pluck('message')->reject(fn (string $message) => str_contains($message, '/dusk-storage/') && str_contains($message, '404'))
            ->values()->all();

        $this->assertSame([], $errors);
    }

    /** Presses sent to Chrome itself, with the click count a person's double or triple press carries. */
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

    private function key(Browser $browser, string $key): void
    {
        $tools = new ChromeDevToolsDriver($browser->driver);
        foreach (['keyDown', 'keyUp'] as $type) {
            $tools->execute('Input.dispatchKeyEvent', ['type' => $type, 'key' => $key, 'code' => $key, 'windowsVirtualKeyCode' => 27]);
        }
    }

    /** The middle of an element, brought into sight first. */
    private function centreOf(Browser $browser, string $css): array
    {
        return $browser->script('const el = document.querySelector('.json_encode($css).'); el.scrollIntoView({ block: "center" }); '
            .'const r = el.getBoundingClientRect(); return [Math.round(r.left + r.width / 2), Math.round(r.top + r.height / 2)];')[0];
    }
}
