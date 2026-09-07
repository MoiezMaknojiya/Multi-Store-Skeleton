<?php

namespace Tests\Browser;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Laravel\Dusk\Browser;
use Tests\DuskTestCase;

class ActivityLogPageTest extends DuskTestCase
{
    use DatabaseMigrations;

    /** An action performed in the UI shows up on the Activity Log page. */
    public function test_actions_show_up_on_the_activity_log_page(): void
    {
        $this->seedSuperAdmin();

        $this->browse(function (Browser $browser) {
            $this->freshSession($browser);
            $browser->loginAs(User::where('email', 'admin@gmail.com')->first())->visit('/users');
            $this->waitForAlpine($browser);

            // Do something loggable: create a user through the real form.
            $this->clickAndAwait($browser, '@add-user', fn (Browser $b) => $b->waitFor('@user-form', 3));
            $this->jsType($browser, '@user-first-name', 'Audit');
            $this->jsType($browser, '@user-last-name', 'Trail');
            $this->jsType($browser, '@user-phone', '6665554444');
            $this->jsType($browser, '@user-email', 'audit@example.com');
            $this->jsType($browser, '@user-password', 'password123');
            $this->jsType($browser, '@user-password-confirm', 'password123');
            $this->jsClick($browser, '@user-save');
            $browser->waitForText('audit@example.com');

            // The entry shows on the Activity Log page under the DEFAULT range
            // ("Last 30 days"). This also guards the timezone fix: the just-created
            // log is stamped in server-UTC, the range is built from the browser's
            // local day — a fresh entry must never fall outside it, even near
            // midnight UTC.
            $browser->assertSee('Activity Log')
                ->visit('/activity');
            $this->waitForAlpine($browser);
            $browser->waitForText('user.created')
                ->assertSee('audit@example.com');
        });
    }
}
