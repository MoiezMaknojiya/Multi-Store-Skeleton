<?php

namespace Tests\Browser;

use App\Models\Media;
use App\Models\Organization;
use App\Models\Role;
use Facebook\WebDriver\Chrome\ChromeDevToolsDriver;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Laravel\Dusk\Browser;
use Tests\DuskTestCase;

/**
 * A tab left open while the session changed in another (QA round, 2026-10-09). The page shows Smart Stop; "another tab" switches
 * the session to Alpha Mart; a Rename pressed with a real mouse here is refused in words, nothing changes, and the page reloads as
 * the session now is. Before, it answered an empty red toast and the rename ran against Alpha Mart.
 */
class StaleTabFlowTest extends DuskTestCase
{
    use DatabaseMigrations;

    public function test_a_page_left_open_after_switching_organization_elsewhere_is_refused_and_reloaded(): void
    {
        $this->seedSuperAdmin();
        $smart = Organization::factory()->create(['name' => 'Smart Stop']);
        $alpha = Organization::factory()->create(['name' => 'Alpha Mart']);
        $person = $this->organizationMember($smart, Role::OWNER, 'tosif@example.com');
        $person->organizations()->attach($alpha->id, ['role_id' => Role::starter(Role::STAFF)->id]);
        $file = Media::factory()->create(['organization_id' => $smart->id, 'title' => 'Texas Toast', 'thumbnail_path' => null]);

        $this->browse(function (Browser $browser) use ($person, $smart, $alpha, $file) {
            $this->freshSession($browser);
            $browser->loginAs($person);
            $this->switchToOrganization($browser, $smart);
            $browser->visit('/media');
            $this->waitForAlpine($browser);
            $browser->waitForText('Texas Toast');

            // Another tab of the same browser switches to Alpha Mart: the same session, from outside this page.
            $browser->script('fetch("/organizations/switch", { method: "POST", headers: { "Content-Type": "application/x-www-form-urlencoded", '
                .'"X-CSRF-TOKEN": document.querySelector("meta[name=csrf-token]").content }, body: "organization_id='.$alpha->id.'" });');
            $browser->pause(1200);

            $this->press($browser, 'button[aria-label="Rename Texas Toast"]');
            $browser->waitFor('@media-edit-title')->pause(300);
            $this->press($browser, '[dusk="media-save"]');

            $browser->waitForText('This page is out of date: you switched to Alpha Mart in another tab. Nothing was changed.')
                ->screenshot('stale-tab-refused');
            $this->assertSame('Texas Toast', $file->fresh()->title);
            $this->assertSame(1, (int) $browser->script('return document.querySelectorAll(\'[role="status"] span.flex-1\').length;')[0], 'The refusal was said more than once');

            // The page loads again as the session now is: Alpha Mart, where Texas Toast is not.
            $browser->waitUsing(10, 200, fn () => str_ends_with((string) $browser->script('return document.querySelector(\'meta[name="session-context"]\').content;')[0], ':'.$alpha->id));
            $browser->waitForText('Alpha Mart')->assertDontSee('Texas Toast');
        });
    }

    private function press(Browser $browser, string $css): void
    {
        $at = $browser->script('const el = document.querySelector('.json_encode($css).'); el.scrollIntoView({ block: "center" }); const r = el.getBoundingClientRect(); '
            .'return [Math.round(r.left + r.width / 2), Math.round(r.top + r.height / 2)];')[0];
        $tools = new ChromeDevToolsDriver($browser->driver);
        $tools->execute('Input.dispatchMouseEvent', ['type' => 'mouseMoved', 'x' => $at[0], 'y' => $at[1]]);
        foreach (['mousePressed', 'mouseReleased'] as $type) {
            $tools->execute('Input.dispatchMouseEvent', ['type' => $type, 'x' => $at[0], 'y' => $at[1], 'button' => 'left', 'buttons' => $type === 'mousePressed' ? 1 : 0, 'clickCount' => 1]);
        }
    }
}
