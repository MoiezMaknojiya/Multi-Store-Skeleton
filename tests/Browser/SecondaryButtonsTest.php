<?php

namespace Tests\Browser;

use App\Models\ActivityLog;
use App\Models\Invitation;
use App\Models\Media;
use App\Models\Permission;
use App\Models\PlaylistItem;
use App\Models\Role;
use App\Models\Screen;
use App\Models\Store;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Laravel\Dusk\Browser;
use Tests\DuskTestCase;

/**
 * The buttons a flow test walks past: the Cancel of each modal, the two warnings the copy box shows
 * before it replaces anything, the empty-state Invite, and the platform confirmations nothing else
 * presses. Small buttons, but a dead Cancel traps somebody in a modal and a missing warning replaces
 * a shop's playlist without saying so.
 */
class SecondaryButtonsTest extends DuskTestCase
{
    use DatabaseMigrations;

    public function test_the_playlist_modals_can_be_dismissed_and_the_copy_box_warns_first(): void
    {
        $this->seedSuperAdmin();
        $store = Store::factory()->create(['name' => 'Alpha Mart']);
        $owner = $this->storeMember($store, Role::OWNER);

        $here = Screen::factory()->create(['store_id' => $store->id, 'name' => 'Deli TV']);
        $there = Screen::factory()->create(['store_id' => $store->id, 'name' => 'Counter TV']);
        $media = Media::factory()->create(['store_id' => $store->id, 'title' => 'Opening Poster']);

        // Both screens already play something, so copying would replace what is on the other one.
        PlaylistItem::create(['screen_id' => $here->id, 'media_id' => $media->id, 'position' => 0, 'duration_seconds' => 10]);
        PlaylistItem::create(['screen_id' => $there->id, 'media_id' => $media->id, 'position' => 0, 'duration_seconds' => 10]);

        $this->browse(function (Browser $browser) use ($owner, $store, $here, $there) {
            $this->freshSession($browser);
            $browser->loginAs($owner);
            $this->switchToStore($browser, $store);

            $browser->visit('/screens/'.$here->id);
            $this->waitForAlpine($browser);
            $browser->waitForText('Opening Poster');

            // The schedule box closes on Cancel, and changes nothing.
            $this->clickAndAwait($browser, '@playlist-schedule-0', fn (Browser $b) => $b->waitFor('@schedule-modal', 3));
            $this->jsClick($browser, '@schedule-cancel');
            $this->waitForModalClosed($browser, '@schedule-modal');

            // The copy box says how much it would replace BEFORE the button is pressed…
            $this->clickAndAwait($browser, '@playlist-copy-open', fn (Browser $b) => $b->waitFor('@copy-modal', 3));

            // The other screens arrive with their own request, so wait for the list before reading it.
            $browser->waitFor('@copy-target-'.$there->id)->assertMissing('@copy-no-targets');
            $this->jsClick($browser, '@copy-target-'.$there->id);
            $browser->waitFor('@copy-warning')
                ->assertSeeIn('@copy-warning', '1');

            // …and Cancel leaves the other screen exactly as it was.
            $this->jsClick($browser, '@copy-cancel');
            $this->waitForModalClosed($browser, '@copy-modal');
            $this->assertSame(1, PlaylistItem::where('screen_id', $there->id)->count());
        });
    }

    public function test_a_store_with_one_screen_is_told_there_is_nowhere_to_copy_to(): void
    {
        $this->seedSuperAdmin();
        $store = Store::factory()->create(['name' => 'Solo Shop']);
        $owner = $this->storeMember($store, Role::OWNER);
        $only = Screen::factory()->create(['store_id' => $store->id, 'name' => 'The Only TV']);

        $this->browse(function (Browser $browser) use ($owner, $store, $only) {
            $this->freshSession($browser);
            $browser->loginAs($owner);
            $this->switchToStore($browser, $store);

            $browser->visit('/screens/'.$only->id);
            $this->waitForAlpine($browser);

            $this->clickAndAwait($browser, '@playlist-copy-open', fn (Browser $b) => $b->waitFor('@copy-modal', 3));
            $browser->waitFor('@copy-no-targets')->assertMissing('@copy-warning');
            $this->jsClick($browser, '@copy-cancel');
            $this->waitForModalClosed($browser, '@copy-modal');
        });
    }

    public function test_the_invitations_tab_offers_invite_from_its_empty_state(): void
    {
        $this->seedSuperAdmin();
        $store = Store::factory()->create(['name' => 'Alpha Mart']);
        $owner = $this->storeMember($store, Role::OWNER);

        $this->browse(function (Browser $browser) use ($owner, $store) {
            $this->freshSession($browser);
            $browser->loginAs($owner);
            $this->switchToStore($browser, $store);

            $browser->visit('/members');
            $this->waitForAlpine($browser);
            $this->clickAndAwait($browser, '@tab-invitations', fn (Browser $b) => $b->waitFor('@invite-member-empty', 3));

            // The empty state's own Invite opens the same form as the header button.
            $this->clickAndAwait($browser, '@invite-member-empty', fn (Browser $b) => $b->waitFor('@invite-form', 3));
            $browser->assertVisible('@invite-email');
        });
    }

    public function test_the_platform_confirmations_nothing_else_presses(): void
    {
        $primary = $this->seedSuperAdmin(); // SEED_ADMIN_PASSWORD is "test" (phpunit.dusk.xml)

        $role = Role::create(['name' => 'Support', 'is_global' => true]);
        [$invitation] = Invitation::open(null, 'teammate@example.com', $role, $primary);
        $spare = Permission::create(['name' => 'spare-view', 'label' => 'Spare View']);

        $this->browse(function (Browser $browser) use ($primary, $invitation, $spare) {
            $this->freshSession($browser);
            $browser->loginAs($primary);

            /* ── A platform invitation is revoked ─────────────────────────── */
            // The team's invitations are a card of their own on the Users page, fetched on
            // their own request — so wait for the row inside that card.
            $browser->visit('/users');
            $this->waitForAlpine($browser);
            $browser->waitForTextIn('@platform-invitations', 'teammate@example.com');

            $this->clickAndAwait(
                $browser,
                '@revoke-platform-invitation-'.$invitation->id,
                fn (Browser $b) => $b->waitFor('@revoke-platform-invitation-confirm', 3)
            );
            $this->jsClick($browser, '@revoke-platform-invitation-confirm');

            $browser->waitUsing(10, 200, fn () => Invitation::find($invitation->id) === null);

            /* ── A permission is deleted, with the password ───────────────── */
            $browser->visit('/permissions');
            $this->waitForAlpine($browser);
            $browser->waitForText('spare-view');

            $this->clickAndAwait(
                $browser,
                '@delete-permission-'.$spare->id,
                fn (Browser $b) => $b->waitFor('@confirm-permission-deletion-confirm', 3)
            );
            $this->jsType($browser, '@delete-permission-password', 'test');
            $this->jsClick($browser, '@confirm-permission-deletion-confirm');

            $browser->waitUsing(10, 200, fn () => Permission::find($spare->id) === null);

            /* ── Yearly maintenance runs, and says what it did ────────────── */
            $browser->visit('/activity');
            $this->waitForAlpine($browser);
            $this->clickAndAwait($browser, '@activity-maintain-button', fn (Browser $b) => $b->waitFor('@activity-maintain-confirm', 3));
            $this->jsClick($browser, '@activity-maintain-confirm');

            // Partitions only exist on MySQL; here the same job deletes the rows older than two
            // years, and a log written today has none — so the page's own report is "nothing to
            // do", which only a maintenance run that really came back can say.
            $browser->waitForText('Maintenance complete — nothing to do');
            $this->assertTrue(ActivityLog::where('action', 'activity.maintenance')->exists(),
                'the maintenance run is in the log it maintains');
        });
    }
}
