<?php

namespace Tests\Browser;

use App\Models\BuilderAd;
use App\Models\Organization;
use App\Models\Role;
use App\Models\User;
use Facebook\WebDriver\Chrome\ChromeDevToolsDriver;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\RateLimiter;
use Laravel\Dusk\Browser;
use Tests\DuskTestCase;

/**
 * The Ads page pushed hard, the way a person really uses it (owner, 2026-10-07: "tum sahi terha test nahi kar rae
 * stress test nahi kar rae zoor laga k"). Every press here is a real mouse press sent to Chrome itself
 * (Input.dispatchMouseEvent), with the click count a person's double or triple click carries — a JavaScript
 * click, and even WebDriver's, counts every press as the first. Throughout, the page is watched: the requests it
 * sends, the errors it throws, whether its list empties, where it scrolls and where it goes.
 */
class AdsPageStressTest extends DuskTestCase
{
    use DatabaseMigrations;

    private const QUESTION = 'confirm-ad-deletion-confirm';

    public function test_delete_keeps_its_question_open_however_it_is_pressed_and_escape_shuts_it_once(): void
    {
        [$owner, $alpha, $ads] = $this->ads(7);
        $last = $ads->last();

        $this->browse(function (Browser $browser) use ($owner, $alpha, $last) {
            $this->openAds($browser, $owner, $alpha);
            $at = $this->deleteAtTheBottom($browser, $last->id);

            foreach ([[2, 120], [3, 100], [10, 40], [2, 400]] as [$times, $gap]) {
                $this->presses($browser, $at, $times, $gap);
                $browser->pause(600);
                $this->assertTrue($this->questionIsOpen($browser), "{$times} presses {$gap} ms apart shut the question they opened");

                // Escape five times, fast: the question goes, and nothing else does.
                foreach (range(1, 5) as $ignored) {
                    $this->key($browser, 'Escape');
                }
                $browser->pause(300);
                $this->assertFalse($this->questionIsOpen($browser));
            }

            $this->assertCalm($browser);
            $this->assertNotNull(BuilderAd::find($last->id));
        });
    }

    public function test_a_double_press_on_cancel_never_falls_through_to_the_ad_under_it(): void
    {
        [$owner, $alpha, $ads] = $this->ads(9);

        $this->browse(function (Browser $browser) use ($owner, $alpha, $ads) {
            $this->openAds($browser, $owner, $alpha);
            $this->jsClick($browser, '@delete-ad-'.$ads[4]->id);
            $browser->waitFor('@'.self::QUESTION);

            // Cancel's place, and an editor link under it once the question is gone — scrolled until there is one,
            // or the test could not see the fault.
            $found = null;
            foreach (range(0, 900, 60) as $scroll) {
                $browser->script("document.getElementById('main-content').scrollTop = {$scroll};");
                $found = $browser->script(<<<'JS'
                    const dialog = [...document.querySelectorAll('[role="dialog"]')].find((d) => d.getClientRects().length);
                    const cancel = [...dialog.querySelectorAll('button')].find((b) => b.textContent.trim() === 'Cancel');
                    const r = cancel.getBoundingClientRect();
                    const x = Math.round(r.left + r.width / 2), y = Math.round(r.top + r.height / 2);
                    const root = dialog.closest('[x-data^="customModal"]');
                    const under = document.elementsFromPoint(x, y).find((el) => !root.contains(el));
                    const link = under?.closest('a[href^="/builder/"]')?.getAttribute('href');
                    return link ? { at: [x, y], link } : null;
                JS)[0];
                if ($found) {
                    break;
                }
            }
            $this->assertNotNull($found, 'No editor link ever lay under Cancel: the test could not see the fault');

            $this->presses($browser, $found['at'], 2, 120);
            $browser->pause(1000);

            $this->assertSame('/builder', parse_url($browser->driver->getCurrentURL(), PHP_URL_PATH),
                "The second press of Cancel fell through to {$found['link']}");
            $this->assertFalse($this->questionIsOpen($browser));
            $this->assertCalm($browser);
        });
    }

    public function test_the_question_deletes_once_however_hard_and_however_slowly_it_is_pressed(): void
    {
        [$owner, $alpha, $ads] = $this->ads(4);

        $this->browse(function (Browser $browser) use ($owner, $alpha, $ads) {
            $this->openAds($browser, $owner, $alpha);

            foreach ([[2, 120, 0], [3, 100, 0], [3, 150, 1500], [10, 60, 800]] as $round => [$times, $gap, $latency]) {
                $this->network($browser, $latency);
                $ad = $ads[$round];
                $this->ask($browser, $ad->id, 'password');
                $before = $this->watched($browser)['deletes'];

                $this->presses($browser, $this->centreOf($browser, '[dusk="'.self::QUESTION.'"]'), $times, $gap);
                $browser->waitUntilMissing('@ad-card-'.$ad->id, 10)->pause(800);

                $this->assertSame(1, $this->watched($browser)['deletes'] - $before, "{$times} presses on Delete Ad sent more than one delete");
                $this->assertNull(BuilderAd::find($ad->id));
                $this->assertFalse($this->questionIsOpen($browser));
                $this->assertStringNotContainsString('Could not', $this->toasts($browser));
            }

            $this->network($browser, 0);
            $this->assertCalm($browser);
        });
    }

    public function test_an_answer_that_comes_late_never_shuts_the_next_question(): void
    {
        [$owner, $alpha, $ads] = $this->ads(3);
        [$first, $second] = [$ads[0], $ads[1]];

        $this->browse(function (Browser $browser) use ($owner, $alpha, $first, $second) {
            $this->openAds($browser, $owner, $alpha);
            $this->network($browser, 2500);

            $this->ask($browser, $first->id, 'password');
            $this->presses($browser, $this->centreOf($browser, '[dusk="'.self::QUESTION.'"]'), 1);
            $browser->pause(300);
            $this->key($browser, 'Escape');
            $browser->pause(300);

            // The next one is asked while the first is still on its way, and half its password is typed.
            $this->ask($browser, $second->id, 'passw');
            $browser->waitUntilMissing('@ad-card-'.$first->id, 10)->pause(800);

            $this->assertTrue($this->questionIsOpen($browser), "The first delete's answer shut the question about {$second->name}");
            $this->assertStringContainsString($second->name, $browser->text('[role="dialog"]:not([style*="none"]) h2 + p'));
            $this->assertSame('passw', $browser->value('#delete-ad-password'));
            $this->assertNotNull(BuilderAd::find($second->id));

            $this->network($browser, 0);
            $this->assertCalm($browser);
        });
    }

    public function test_an_ad_deleted_elsewhere_is_said_to_be_gone_and_leaves_the_list(): void
    {
        [$owner, $alpha, $ads] = $this->ads(3);
        $gone = $ads[1];

        $this->browse(function (Browser $browser) use ($owner, $alpha, $gone) {
            $this->openAds($browser, $owner, $alpha);

            // Another tab — or a colleague — deletes it while this page still lists it.
            BuilderAd::find($gone->id)->delete();

            $this->ask($browser, $gone->id, 'password');
            $this->presses($browser, $this->centreOf($browser, '[dusk="'.self::QUESTION.'"]'), 1);
            $browser->pause(1200);

            $this->assertFalse($this->questionIsOpen($browser), 'The question stayed open over an ad that is gone');
            $browser->assertMissing('@ad-card-'.$gone->id);
            $this->assertStringContainsString('already gone', $this->toasts($browser));
            $this->assertStringNotContainsString('Not Found', $this->toasts($browser));
        });
    }

    public function test_five_ads_deleted_one_after_another_at_speed_never_put_an_editor_link_under_the_pointer(): void
    {
        [$owner, $alpha, $ads] = $this->ads(9);

        $this->browse(function (Browser $browser) use ($owner, $alpha) {
            $browser->resize(1280, 700);
            $this->openAds($browser, $owner, $alpha);
            $browser->script("document.getElementById('main-content').scrollTop = 120;");
            $browser->pause(300);
            $this->watch($browser);

            $second = $browser->script("return document.querySelectorAll('[dusk^=\"delete-ad-\"]')[1].getAttribute('dusk')")[0];
            $at = $this->centreOf($browser, '[dusk="'.$second.'"]', scroll: false);

            foreach (range(1, 5) as $round) {
                $under = $browser->script("const el = document.elementFromPoint({$at[0]}, {$at[1]}); return { dusk: el?.closest('[dusk]')?.getAttribute('dusk') ?? null, link: el?.closest('a')?.getAttribute('href') ?? null };")[0];
                $this->assertNull($under['link'], "Round {$round}: an editor link lay where the next Delete was");
                $this->assertStringStartsWith('delete-ad-', (string) $under['dusk'], "Round {$round}: no Delete under the pointer");

                $this->presses($browser, $at, 1);
                $browser->waitFor('@'.self::QUESTION, 3);
                $this->jsType($browser, '#delete-ad-password', 'password');
                $this->presses($browser, $this->centreOf($browser, '[dusk="'.self::QUESTION.'"]', scroll: false), 1);
                $browser->waitUntil("!document.querySelector('[dusk=\"".self::QUESTION."\"]').getClientRects().length", 10);
            }

            $browser->pause(800);
            $watched = $this->watched($browser);
            $this->assertSame(4, BuilderAd::count());
            $this->assertFalse($watched['emptied'], 'The list emptied itself between deletes');
            $this->assertCount(1, $watched['scrolls'], 'The page moved between deletes: '.json_encode($watched['scrolls']));
            $this->assertCalm($browser);
        });
    }

    public function test_wrong_passwords_are_refused_then_counted_and_the_question_holds(): void
    {
        [$owner, $alpha, $ads] = $this->ads(2);

        $this->browse(function (Browser $browser) use ($owner, $alpha, $ads) {
            $this->openAds($browser, $owner, $alpha);
            $this->ask($browser, $ads[0]->id, 'wrong-0');
            $confirm = $this->centreOf($browser, '[dusk="'.self::QUESTION.'"]');

            $said = [];
            foreach (range(1, 6) as $try) {
                $this->jsType($browser, '#delete-ad-password', 'wrong-'.$try);
                $this->presses($browser, $confirm, 1);
                $browser->pause(900);
                $said[] = trim($browser->script("return document.getElementById('delete-ad-password-error')?.textContent ?? ''")[0]);
            }

            // The count lives in the file cache every test shares: a later test's person may have this one's id.
            RateLimiter::clear('confirm-password:'.$owner->id);

            $this->assertSame(array_fill(0, 5, 'The password is incorrect.'), array_slice($said, 0, 5));
            $this->assertStringStartsWith('Too many wrong passwords.', $said[5]);
            $this->assertTrue($this->questionIsOpen($browser));
            $this->assertNotNull(BuilderAd::find($ads[0]->id));
            $this->assertCalm($browser, allow: ['Failed to delete ad']);
        });
    }

    public function test_a_connection_lost_mid_delete_is_said_and_the_ad_waits_for_the_next_press(): void
    {
        [$owner, $alpha, $ads] = $this->ads(2);
        $ad = $ads[0];

        $this->browse(function (Browser $browser) use ($owner, $alpha, $ad) {
            $this->openAds($browser, $owner, $alpha);
            $this->ask($browser, $ad->id, 'password');
            $confirm = $this->centreOf($browser, '[dusk="'.self::QUESTION.'"]');

            $this->network($browser, 0, offline: true);
            $this->presses($browser, $confirm, 2, 120);
            $browser->pause(1500);

            $this->assertTrue($this->questionIsOpen($browser));
            $this->assertStringContainsString('Could not delete ad', $this->toasts($browser));
            $this->assertNotNull(BuilderAd::find($ad->id));
            $browser->assertPresent('@ad-card-'.$ad->id);

            $this->network($browser, 0);
            $this->presses($browser, $confirm, 1);
            $browser->waitUntilMissing('@ad-card-'.$ad->id, 10);
            $this->assertNull(BuilderAd::find($ad->id));
            $this->assertCalm($browser, allow: ['Failed to delete ad', 'Network Error']);
        });
    }

    public function test_the_last_ad_of_page_two_takes_the_list_back_to_page_one(): void
    {
        [$owner, $alpha, $ads] = $this->ads(51);

        $this->browse(function (Browser $browser) use ($owner, $alpha) {
            $this->openAds($browser, $owner, $alpha);
            $this->jsClick($browser, 'button[aria-label="Next page"]');
            $browser->waitUntil("document.querySelectorAll('[dusk^=\"ad-card-\"]').length === 1", 10);

            $only = (int) str_replace('ad-card-', '', $browser->script("return document.querySelector('[dusk^=\"ad-card-\"]').getAttribute('dusk')")[0]);
            $this->ask($browser, $only, 'password');
            $this->presses($browser, $this->centreOf($browser, '[dusk="'.self::QUESTION.'"]'), 2, 120);

            $browser->waitUntil("document.querySelectorAll('[dusk^=\"ad-card-\"]').length === 50", 10);
            $this->assertNull(BuilderAd::find($only));
            $this->assertCalm($browser);
        });
    }

    public function test_copy_pressed_twice_makes_one_copy(): void
    {
        [$owner, $alpha, $ads] = $this->ads(1);

        $this->browse(function (Browser $browser) use ($owner, $alpha, $ads) {
            $this->openAds($browser, $owner, $alpha);
            $this->presses($browser, $this->centreOf($browser, '[dusk="duplicate-ad-'.$ads[0]->id.'"]'), 2, 120);
            $browser->pause(2000);

            $this->assertSame(2, BuilderAd::count(), 'A double press on Copy made more than one copy');
            $this->assertCalm($browser);
        });
    }

    /* ── The page, set up and watched ───────────────────────────────────── */

    /** @return array{0: User, 1: Organization, 2: Collection<int, BuilderAd>} the ads newest first, as the page lists them */
    private function ads(int $count): array
    {
        $alpha = Organization::factory()->create(['name' => 'Alpha Mart']);
        $owner = $this->organizationMember($alpha, Role::OWNER);
        $ads = collect(range(1, $count))->map(fn (int $n) => BuilderAd::factory()->withText('Deal '.$n)->published()
            ->create(['organization_id' => $alpha->id, 'name' => 'Menu '.$n, 'updated_at' => now()->subMinutes($n)]));

        return [$owner, $alpha, $ads];
    }

    private function openAds(Browser $browser, User $owner, Organization $organization): void
    {
        $this->freshSession($browser);
        $browser->loginAs($owner);
        $this->switchToOrganization($browser, $organization);
        $browser->visit('/builder');
        $this->waitForAlpine($browser);
        $browser->waitFor('@ads-grid');
        $this->watch($browser);
    }

    /** What the page did: deletes and posts sent, errors, whether its list emptied, where it scrolled. */
    private function watch(Browser $browser): void
    {
        $browser->script(<<<'JS'
            if (window.__w) return;
            window.__w = { deletes: 0, posts: 0, errors: [], emptied: false, scrolls: new Set() };
            const open = XMLHttpRequest.prototype.open;
            XMLHttpRequest.prototype.open = function (method) {
                if (String(method).toUpperCase() === 'DELETE') window.__w.deletes++;
                if (String(method).toUpperCase() === 'POST') window.__w.posts++;
                return open.apply(this, arguments);
            };
            window.addEventListener('error', (e) => window.__w.errors.push(String(e.message)));
            window.addEventListener('unhandledrejection', (e) => window.__w.errors.push(String(e.reason)));
            const said = console.error;
            console.error = function (...parts) { window.__w.errors.push(parts.map(String).join(' ')); return said.apply(this, parts); };
            const grid = document.querySelector('[dusk="ads-grid"]');
            new MutationObserver(() => { if (getComputedStyle(grid).display === 'none') window.__w.emptied = true; })
                .observe(grid, { attributes: true, attributeFilter: ['style'] });
            setInterval(() => window.__w.scrolls.add(Math.round(document.getElementById('main-content').scrollTop)), 10);
        JS);
    }

    private function watched(Browser $browser): array
    {
        return $browser->script('return { deletes: window.__w.deletes, posts: window.__w.posts, errors: window.__w.errors, emptied: window.__w.emptied, scrolls: [...window.__w.scrolls] }')[0];
    }

    /** Still on the Ads page, and nothing thrown or logged as an error but what the case expects. */
    private function assertCalm(Browser $browser, array $allow = []): void
    {
        $this->assertSame('/builder', parse_url($browser->driver->getCurrentURL(), PHP_URL_PATH));
        $errors = array_values(array_filter($this->watched($browser)['errors'],
            fn (string $error) => ! collect($allow)->contains(fn (string $expected) => str_contains($error, $expected))));
        $this->assertSame([], $errors, 'The page threw or logged errors');
    }

    /* ── A person's mouse and keyboard ─────────────────────────────────── */

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

    private function key(Browser $browser, string $key): void
    {
        $tools = new ChromeDevToolsDriver($browser->driver);
        foreach (['keyDown', 'keyUp'] as $type) {
            $tools->execute('Input.dispatchKeyEvent', ['type' => $type, 'key' => $key, 'code' => $key, 'windowsVirtualKeyCode' => 27]);
        }
    }

    /** Latency in ms for every request — or no connection at all. */
    private function network(Browser $browser, int $latency, bool $offline = false): void
    {
        $tools = new ChromeDevToolsDriver($browser->driver);
        $tools->execute('Network.enable');
        $tools->execute('Network.emulateNetworkConditions', ['offline' => $offline, 'latency' => $latency, 'downloadThroughput' => -1, 'uploadThroughput' => -1]);
    }

    /** The middle of an element, brought into sight first unless told not to move the page. */
    private function centreOf(Browser $browser, string $css, bool $scroll = true): array
    {
        return $browser->script('const el = document.querySelector('.json_encode($css).'); '
            .($scroll ? "el.scrollIntoView({ block: 'center' }); " : '')
            .'const r = el.getBoundingClientRect(); return [Math.round(r.left + r.width / 2), Math.round(r.top + r.height / 2)];')[0];
    }

    /** The last card's Delete, low on the page — outside where the question's panel comes, or the test proves nothing. */
    private function deleteAtTheBottom(Browser $browser, int $id): array
    {
        $browser->script("document.querySelector('[dusk=\"delete-ad-{$id}\"]').scrollIntoView({ block: 'end' })");
        $browser->pause(300);
        $at = $this->centreOf($browser, '[dusk="delete-ad-'.$id.'"]', scroll: false);

        $this->jsClick($browser, '@delete-ad-'.$id);
        $browser->waitFor('@'.self::QUESTION);
        $outside = $browser->script("const p = document.querySelector('[dusk=\"".self::QUESTION."\"]').closest('[role=\"dialog\"]').getBoundingClientRect(); return {$at[0]} < p.left || {$at[0]} > p.right || {$at[1]} < p.top || {$at[1]} > p.bottom;")[0];
        $this->assertTrue($outside, 'The Delete pressed lies under the question\'s panel: the test could not see the fault');
        $this->key($browser, 'Escape');
        $browser->pause(300);

        return $at;
    }

    /** The question about one ad, with a password typed into it. */
    private function ask(Browser $browser, int $id, string $password): void
    {
        $this->jsClick($browser, '@delete-ad-'.$id);
        $browser->waitFor('@'.self::QUESTION, 3)->pause(150);
        $this->jsType($browser, '#delete-ad-password', $password);
    }

    private function questionIsOpen(Browser $browser): bool
    {
        return (bool) $browser->script("return document.querySelector('[dusk=\"".self::QUESTION."\"]').getClientRects().length > 0;")[0];
    }

    private function toasts(Browser $browser): string
    {
        return (string) $browser->script("return document.querySelector('[role=\"status\"][aria-live]')?.innerText ?? '';")[0];
    }
}
