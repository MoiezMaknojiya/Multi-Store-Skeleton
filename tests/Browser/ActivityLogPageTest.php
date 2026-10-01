<?php

namespace Tests\Browser;

use Illuminate\Foundation\Testing\DatabaseMigrations;
use Laravel\Dusk\Browser;
use Tests\DuskTestCase;

class ActivityLogPageTest extends DuskTestCase
{
    use DatabaseMigrations;

    /** An action performed in the UI shows up on the Activity Log page. */
    public function test_actions_show_up_on_the_activity_log_page(): void
    {
        $admin = $this->seedSuperAdmin();

        $this->browse(function (Browser $browser) use ($admin) {
            $this->freshSession($browser);
            $browser->loginAs($admin)->visit('/organizations');
            $this->waitForAlpine($browser);

            // Do something loggable: create an organization through the real form.
            $this->clickAndAwait($browser, '@add-organization', fn (Browser $b) => $b->waitFor('@organization-form', 3));
            $this->jsType($browser, '@organization-name', 'Audit Mart');
            $this->jsType($browser, '@organization-street', '1 Ledger Lane');
            $this->jsType($browser, '@organization-city', 'Dallas');
            $browser->select('@organization-state', 'TX');
            $this->jsType($browser, '@organization-zip', '75201');
            $this->jsType($browser, '@organization-owner-email', 'audit@example.com');
            $this->jsClick($browser, '@organization-save');
            $browser->waitForText('Organization created.');

            // The entry shows on the Activity Log page under the DEFAULT range
            // ("Last 30 days"). This also guards the timezone fix: the just-created
            // log is stamped in server-UTC, the range is built from the browser's
            // local day — a fresh entry must never fall outside it, even near
            // midnight UTC.
            $browser->visit('/activity');
            $this->waitForAlpine($browser);
            // The action reads in words ("Organization created"), its code name kept as the badge's tooltip.
            $browser->waitForText('Created organization Audit Mart and invited audit@example.com to own it')
                ->assertSee('Organization created')
                ->assertPresent('[title="organization.created"]');
        });
    }
}
