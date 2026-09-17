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
            $browser->loginAs($admin)->visit('/stores');
            $this->waitForAlpine($browser);

            // Do something loggable: create a store through the real form.
            $this->clickAndAwait($browser, '@add-store', fn (Browser $b) => $b->waitFor('@store-form', 3));
            $this->jsType($browser, '@store-name', 'Audit Mart');
            $this->jsType($browser, '@store-street', '1 Ledger Lane');
            $this->jsType($browser, '@store-city', 'Dallas');
            $browser->select('@store-state', 'TX');
            $this->jsType($browser, '@store-zip', '75201');
            $this->jsType($browser, '@store-owner-email', 'audit@example.com');
            $this->jsClick($browser, '@store-save');
            $browser->waitForText('Store created.');

            // The entry shows on the Activity Log page under the DEFAULT range
            // ("Last 30 days"). This also guards the timezone fix: the just-created
            // log is stamped in server-UTC, the range is built from the browser's
            // local day — a fresh entry must never fall outside it, even near
            // midnight UTC.
            $browser->visit('/activity');
            $this->waitForAlpine($browser);
            $browser->waitForText('store.created')
                ->assertSee('Created store Audit Mart and invited audit@example.com to own it');
        });
    }
}
