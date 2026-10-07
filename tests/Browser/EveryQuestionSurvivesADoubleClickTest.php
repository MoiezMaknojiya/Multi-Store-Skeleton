<?php

namespace Tests\Browser;

use App\Models\BuilderAd;
use App\Models\BuilderAsset;
use App\Models\Channel;
use App\Models\ChannelAd;
use App\Models\Invitation;
use App\Models\Media;
use App\Models\Organization;
use App\Models\Permission;
use App\Models\PlaylistItem;
use App\Models\Role;
use App\Models\Screen;
use App\Models\User;
use Facebook\WebDriver\Chrome\ChromeDevToolsDriver;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Laravel\Dusk\Browser;
use Tests\DuskTestCase;

/**
 * Every question the panel asks, pressed the way a person presses (owner, 2026-10-07: "zoor laga k"): on every page,
 * each button that opens a dialog is double-clicked with a real mouse — the second press must not shut what the first
 * opened — and then the dialog's Cancel is double-clicked: the second press must not fall through to whatever lay
 * under it (another row's Delete, a link to an editor). Real presses are sent to Chrome itself with the click count a
 * person's double click carries (Input.dispatchMouseEvent); a JavaScript click never meets either fault.
 */
class EveryQuestionSurvivesADoubleClickTest extends DuskTestCase
{
    use DatabaseMigrations;

    public function test_every_question_inside_an_organization(): void
    {
        $admin = $this->seedSuperAdmin();
        [$alpha, $screen, $ownChannel] = $this->anOrganization($admin);
        $member = $this->organizationMember($alpha, [
            ...Permission::ORGANIZATION, 'organization-view', 'organization-store', 'organization-destroy',
            'channel-view', 'channel-store', 'channel-update', 'channel-destroy', 'activity-view',
        ], 'manager@example.com', 'Manager with every permission');
        // An invitation from another organization, so the dashboard asks its Decline question too.
        Invitation::factory()->create(['organization_id' => Organization::factory()->create(['name' => 'Beta Deli'])->id, 'email' => 'manager@example.com']);

        $this->browse(function (Browser $browser) use ($member, $alpha, $screen, $ownChannel) {
            $this->freshSession($browser);
            $browser->loginAs($member);
            $this->switchToOrganization($browser, $alpha);

            $this->pressEveryQuestion($browser, ['/dashboard', '/screens', '/screens/'.$screen->id, '/media', '/channels', '/channels/'.$ownChannel->id,
                '/builder', '/builder/assets', '/members', '/roles', '/settings/organization', '/profile']);
        });
    }

    public function test_every_question_above_the_organizations(): void
    {
        $admin = $this->seedSuperAdmin();
        [, $screen] = $this->anOrganization($admin);
        $platformChannel = Channel::factory()->create(['name' => 'GAMA Wholesale']);
        ChannelAd::factory()->create(['channel_id' => $platformChannel->id, 'media_id' => Media::factory()->create(['organization_id' => null])->id]);
        User::factory()->create(['first_name' => 'Naveed', 'last_name' => 'Khan'])->organizations()->attach(0, ['role_id' => Role::create(['name' => 'Regional Admin', 'is_global' => true])->id]);

        $this->browse(function (Browser $browser) use ($admin, $screen, $platformChannel) {
            $this->freshSession($browser);
            $browser->loginAs($admin);

            $this->pressEveryQuestion($browser, ['/users', '/organizations', '/roles', '/permissions', '/channels', '/channels/'.$platformChannel->id,
                '/campaigns', '/activity', '/media', '/screens', '/screens/'.$screen->id, '/builder', '/builder/assets', '/profile']);
        });
    }

    /**
     * On each page: find every button whose press opens a dialog, then double-press it and the dialog's Cancel.
     *
     * @param  list<string>  $pages
     */
    private function pressEveryQuestion(Browser $browser, array $pages): void
    {
        $faults = [];
        $asked = 0;

        foreach ($pages as $page) {
            $openers = $this->openPage($browser, $page);

            foreach ($openers as $opener) {
                $this->openPage($browser, $page);

                // Which dialog this button opens, if any: a JavaScript press, once.
                $opens = $browser->script("const b = document.querySelector('[data-stress=\"{$opener['n']}\"]'); if (!b || !b.getClientRects().length) return null; b.click(); return true;")[0];
                $browser->pause(450);
                if (! $opens || ! $this->dialogIsOpen($browser) || $this->path($browser) !== $page) {
                    continue;
                }
                $this->shutDialogs($browser);
                $browser->pause(650);
                $asked++;

                // 1. A double click on the button: the question it opens stays open.
                $at = $this->centreOf($browser, '[data-stress="'.$opener['n'].'"]');
                $this->presses($browser, $at, 2);
                $browser->pause(700);
                if ($this->path($browser) !== $page) {
                    $faults[] = "{$page} — “{$opener['text']}”: a double click left the page for ".$this->path($browser);

                    continue;
                }
                if (! $this->dialogIsOpen($browser)) {
                    $faults[] = "{$page} — “{$opener['text']}”: a double click shut the question it opened";

                    continue;
                }

                // 2. A double click on its Cancel: nothing under it is pressed.
                $cancel = $browser->script(<<<'JS'
                    const dialog = [...document.querySelectorAll('[role="dialog"]')].find((d) => d.getClientRects().length);
                    const button = dialog && [...dialog.querySelectorAll('button')].find((b) => b.getClientRects().length && (b.textContent.trim() === 'Cancel' || b.getAttribute('aria-label') === 'Close'));
                    if (!button) return null;
                    // A long form's Cancel may sit below the window: brought into sight first, as a person scrolls to it.
                    button.scrollIntoView({ block: 'center' });
                    const r = button.getBoundingClientRect();
                    return [Math.round(r.left + r.width / 2), Math.round(r.top + r.height / 2)];
                JS)[0];
                if ($cancel === null) {
                    $this->shutDialogs($browser);

                    continue;
                }
                $this->presses($browser, $cancel, 2);
                $browser->pause(800);
                if ($this->path($browser) !== $page) {
                    $faults[] = "{$page} — “{$opener['text']}”: the second press of Cancel fell through to ".$this->path($browser);
                } elseif ($this->dialogIsOpen($browser)) {
                    $faults[] = "{$page} — “{$opener['text']}”: after Cancel was pressed twice, a question is open: ".$this->openDialogTitle($browser);
                }
            }
        }

        $this->assertGreaterThan(15, $asked, 'Too few questions were found to press: the page lists have changed');
        $this->assertSame([], $faults, "Questions that a double click breaks:\n".implode("\n", $faults));
    }

    /** @return list<array{n: int, text: string}> the buttons on the page that may open a dialog, marked to be found again */
    private function openPage(Browser $browser, string $page): array
    {
        if ($this->path($browser) !== $page || $this->dialogIsOpen($browser)) {
            $browser->visit($page);
            $this->waitForAlpine($browser);
        }
        $browser->pause(900);

        return $browser->script(<<<'JS'
            const opens = /open|confirm|ask|invite|manage|remove|delete|revoke|leave|change|edit|pair|replace|decline|add/i;
            const never = /save|submit|duplicate|toggle|logout|impersonate|accept|fetch|sort|tab|filter|page|goTo|prev|next|clear|search|copy|retry|resend|publish|stop|play|zoom|undo|redo|select|pick|choose|move|switch|load|apply/i;
            const inDialog = (b) => b.closest('[role="dialog"], [x-data^="customModal"]');
            const buttons = [...document.querySelectorAll('button')].filter((b) => {
                const action = [...b.attributes].filter((a) => /^(@click|x-on:click)/.test(a.name)).map((a) => a.value).join(' ');
                return b.getClientRects().length && !b.disabled && !inDialog(b) && opens.test(action) && !never.test(action);
            });
            return buttons.map((b, n) => { b.setAttribute('data-stress', n); return { n, text: (b.getAttribute('aria-label') || b.textContent).trim().replace(/\s+/g, ' ') }; });
        JS)[0];
    }

    private function openDialogTitle(Browser $browser): string
    {
        return (string) $browser->script("return [...document.querySelectorAll('[role=\"dialog\"]')].find((d) => d.getClientRects().length)?.querySelector('h1, h2, h3')?.textContent.trim() ?? '';")[0];
    }

    private function dialogIsOpen(Browser $browser): bool
    {
        return (bool) $browser->script("return [...document.querySelectorAll('[role=\"dialog\"]')].some((d) => d.getClientRects().length > 0);")[0];
    }

    /** Every dialog shut the way a person shuts one — Escape — and any still open told to close. */
    private function shutDialogs(Browser $browser): void
    {
        foreach (range(1, 2) as $ignored) {
            $this->key($browser, 'Escape');
        }
        $browser->script(<<<'JS'
            document.querySelectorAll('[x-data^="customModal"]').forEach((m) => {
                const name = (m.getAttribute('x-data').match(/customModal\(\s*['"]([^'"]+)/) || [])[1];
                if (name) window.dispatchEvent(new CustomEvent('close-modal', { detail: name }));
            });
        JS);
        $browser->pause(200);
    }

    private function path(Browser $browser): string
    {
        return (string) parse_url($browser->driver->getCurrentURL(), PHP_URL_PATH);
    }

    private function centreOf(Browser $browser, string $css): array
    {
        return $browser->script('const el = document.querySelector('.json_encode($css)."); el.scrollIntoView({ block: 'center' }); const r = el.getBoundingClientRect(); return [Math.round(r.left + r.width / 2), Math.round(r.top + r.height / 2)];")[0];
    }

    /** $times presses of a real mouse on one place, 120 ms apart, each counting on from the last as Chrome counts them. */
    private function presses(Browser $browser, array $at, int $times): void
    {
        $tools = new ChromeDevToolsDriver($browser->driver);
        $tools->execute('Input.dispatchMouseEvent', ['type' => 'mouseMoved', 'x' => $at[0], 'y' => $at[1]]);

        for ($press = 1; $press <= $times; $press++) {
            foreach (['mousePressed', 'mouseReleased'] as $type) {
                $tools->execute('Input.dispatchMouseEvent', [
                    'type' => $type, 'x' => $at[0], 'y' => $at[1], 'button' => 'left',
                    'buttons' => $type === 'mousePressed' ? 1 : 0, 'clickCount' => $press,
                ]);
            }
            if ($press < $times) {
                usleep(120000);
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

    /** An organization with something on every page: people, an invitation, files, screens, a channel, ads and assets. */
    private function anOrganization(User $admin): array
    {
        $alpha = Organization::factory()->create(['name' => 'Alpha Mart']);
        $owner = User::factory()->create(['first_name' => 'Ali', 'last_name' => 'Raza', 'email' => 'ali@example.com']);
        $owner->organizations()->attach($alpha->id, ['role_id' => Role::owner()->id]);

        $cashier = Role::create(['name' => 'Weekend Cashier', 'organization_id' => $alpha->id]);
        $cashier->permissions()->sync(Permission::whereIn('name', ['screen-view', 'media-view'])->pluck('id'));
        User::factory()->create(['first_name' => 'Bilal', 'last_name' => 'Ahmed', 'email' => 'bilal@example.com'])
            ->organizations()->attach($alpha->id, ['role_id' => $cashier->id]);
        Invitation::factory()->create(['organization_id' => $alpha->id, 'email' => 'pending@example.com', 'role_id' => $cashier->id, 'invited_by' => $owner->id]);

        $menu = Media::factory()->create(['organization_id' => $alpha->id, 'title' => 'Breakfast Menu Board']);
        Media::factory()->create(['organization_id' => $alpha->id, 'title' => 'Lunch Specials']);
        $screen = Screen::factory()->create(['organization_id' => $alpha->id, 'name' => 'Front Counter TV']);
        Screen::factory()->create(['organization_id' => $alpha->id, 'name' => 'Drive-through Screen', 'last_seen_at' => null]);
        PlaylistItem::create(['screen_id' => $screen->id, 'media_id' => $menu->id, 'position' => 0, 'duration_seconds' => 10])
            ->scheduleRules()->create(['start_time' => '06:00', 'end_time' => '11:30', 'position' => 0]);

        foreach (range(1, 4) as $n) {
            BuilderAd::factory()->withText('Deal '.$n)->published()->create(['organization_id' => $alpha->id, 'name' => 'Weekend Burgers '.$n]);
        }
        BuilderAsset::factory()->create(['organization_id' => $alpha->id]);

        $ownChannel = Channel::factory()->create(['organization_id' => $alpha->id, 'name' => 'Alpha Deals']);
        ChannelAd::factory()->create(['channel_id' => $ownChannel->id, 'media_id' => Media::factory()->create(['organization_id' => $alpha->id, 'title' => 'Mango Promotion'])->id]);

        return [$alpha, $screen, $ownChannel];
    }
}
