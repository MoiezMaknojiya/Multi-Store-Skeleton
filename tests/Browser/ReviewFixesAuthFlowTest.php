<?php

namespace Tests\Browser;

use App\Models\Organization;
use App\Models\Role;
use App\Models\User;
use Facebook\WebDriver\Chrome\ChromeDevToolsDriver;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\RateLimiter;
use Laravel\Dusk\Browser;
use RuntimeException;
use Tests\DuskTestCase;

/**
 * The review of 2026-10-15, the sign-in side, in the browser:
 *  - a changed email is approved from the address the account has before the new address gets its link (owner — "email
 *    badalne per email verification ho jese sub karte ha"), so an open session alone cannot move the account to another
 *    inbox; a double press on Send the Link Again sends one email;
 *  - "Check your inbox" says whose page it is, so a super admin who viewed as one account in this tab and as another in a
 *    second confirms nobody from the page left behind.
 * Presses are a real mouse's (Input.dispatchMouseEvent with the click count a person's double click carries).
 */
class ReviewFixesAuthFlowTest extends DuskTestCase
{
    use DatabaseMigrations;

    public function test_a_changed_email_is_approved_from_the_old_inbox_before_the_new_one_gets_its_link(): void
    {
        $user = User::factory()->create(['first_name' => 'Sara', 'email' => 'sara@example.com']);
        $this->forgetTheLinkBudgets($user);

        $this->browse(function (Browser $browser) use ($user) {
            $this->freshSession($browser);
            $browser->loginAs($user)->visit('/profile');
            $this->waitForAlpine($browser);

            $logSize = $this->mailLogSize();
            $this->jsType($browser, 'input[name="email"]', 'sara@newshop.com');
            $browser->waitForReload(fn (Browser $b) => $this->presses($b, $this->centreOf($b, 'form[x-data="profileInfo()"] button[type="submit"]'), 1));

            // Step 1: the approval went to the address the account has, and nothing to the new one.
            $browser->waitFor('@profile-email-step-approve')
                ->assertSeeIn('@profile-email-step-approve', 'Step 1 of 2:')
                ->assertSeeIn('@profile-email-step-approve', 'sara@newshop.com')
                ->assertSeeIn('@profile-email-step-approve', 'sara@example.com')
                ->assertInputValue('input[name="email"]', 'sara@example.com');
            $approval = $this->linkFromMailLog($logSize, 'approve-email');
            $this->assertNoLinkInMailLog($logSize, 'confirm-email');
            $this->assertSame(1, $this->emailsInLogSince($logSize, 'Approve a new sign-in email'));

            // A double press on Send the Link Again sends one email, not two.
            $logSize = $this->mailLogSize();
            $browser->waitForReload(fn (Browser $b) => $this->presses($b, $this->centreOf($b, '[dusk="profile-email-resend"]'), 2));
            $browser->waitFor('@profile-email-resent');
            $this->assertSame(1, $this->emailsInLogSince($logSize, 'Approve a new sign-in email'));
            $this->assertNoLinkInMailLog($logSize, 'confirm-email');

            // Step 2: approved from the old inbox, and only now does the new address get its link.
            $logSize = $this->mailLogSize();
            $browser->visit($approval)
                ->waitFor('@profile-email-step-confirm')
                ->assertSeeIn('@profile-email-step-confirm', 'Step 2 of 2:')
                ->assertSeeIn('@profile-email-step-confirm', 'sara@newshop.com');
            $this->assertSame('sara@example.com', $user->fresh()->email);

            $browser->visit($this->linkFromMailLog($logSize, 'confirm-email'))
                ->waitFor('@profile-email-changed')
                ->assertSeeIn('@profile-email-changed', 'Your email is now sara@newshop.com.')
                ->assertMissing('@profile-email-pending');
            $this->assertSame('sara@newshop.com', $user->fresh()->email);
        });
    }

    public function test_a_check_your_inbox_page_left_on_another_account_confirms_nobody(): void
    {
        $admin = $this->seedSuperAdmin();
        $smartStop = Organization::factory()->create(['name' => 'Smart Stop']);
        $first = $this->organizationMember($smartStop, Role::OWNER, 'first@example.com');
        $second = $this->organizationMember(Organization::factory()->create(['name' => 'Alpha Mart']), Role::OWNER, 'second@example.com');
        $first->forceFill(['email_verified_at' => null])->save();
        $second->forceFill(['email_verified_at' => null])->save();

        $this->browse(function (Browser $browser) use ($admin, $first, $second, $smartStop) {
            $this->freshSession($browser);
            $browser->loginAs($admin)->visit('/users');
            $this->waitForAlpine($browser);
            $browser->waitForText($first->email);
            $browser->waitForReload(fn (Browser $b) => $this->jsClick($b, '@impersonate-'.$first->id));
            $browser->waitForLocation('/verify-email')->waitFor('@verify-email-confirm-for-them');
            // A person of one organization works in it from their first request (ChooseTheOnlyOrganization), this page included.
            $this->assertSame($first->id.':'.$smartStop->id, $browser->script('return document.querySelector(\'meta[name="session-context"]\')?.content ?? null;')[0]);

            // Another tab of the same browser goes back to the super admin and on to the second account.
            $browser->script('window.__switched = false; const token = document.querySelector("meta[name=csrf-token]").content; '
                .'const post = (url) => fetch(url, { method: "POST", headers: { "X-CSRF-TOKEN": token } }); '
                .'post("/impersonate/stop").then(() => post("/users/'.$second->id.'/impersonate")).then(() => { window.__switched = true; });');
            $browser->waitUntil('window.__switched === true', 10);

            // This tab's Confirm Email and Continue, pressed with a real mouse: refused, said on the page, nobody confirmed.
            $browser->waitForReload(fn (Browser $b) => $this->presses($b, $this->centreOf($b, '[dusk="verify-email-confirm-for-them"]'), 1));
            $browser->waitFor('@stale-tab')
                ->assertSeeIn('@stale-tab', 'This page is out of date: this browser is now signed in as')
                ->assertSeeIn('@verify-email-address', 'second@example.com');
            $this->assertFalse($first->fresh()->hasVerifiedEmail());
            $this->assertFalse($second->fresh()->hasVerifiedEmail());

            // The page drawn now is the second account's: there, the button confirms it.
            $browser->waitForReload(fn (Browser $b) => $this->presses($b, $this->centreOf($b, '[dusk="verify-email-confirm-for-them"]'), 1));
            $browser->waitForLocation('/dashboard')->waitForText('second@example.com is confirmed.');
            $this->assertTrue($second->fresh()->hasVerifiedEmail());
            $this->assertFalse($first->fresh()->hasVerifiedEmail());
        });
    }

    public function test_a_super_admin_viewing_as_an_account_not_confirmed_yet_cannot_correct_its_email(): void
    {
        $admin = $this->seedSuperAdmin();
        $customer = $this->organizationMember(Organization::factory()->create(['name' => 'Smart Stop']), Role::OWNER, 'customer@example.com');
        $customer->forceFill(['email_verified_at' => null])->save();

        $this->browse(function (Browser $browser) use ($admin, $customer) {
            $this->freshSession($browser);
            $browser->loginAs($admin)->visit('/users');
            $this->waitForAlpine($browser);
            $browser->waitForText($customer->email);
            $browser->waitForReload(fn (Browser $b) => $this->jsClick($b, '@impersonate-'.$customer->id));

            // "Check your inbox" sends nobody to the profile to change the address while viewing as them …
            $browser->waitForLocation('/verify-email')->waitFor('@verify-email-confirm-for-them')->assertMissing('@verify-email-change');

            // … and there the email is read-only, saying whose it is to correct.
            $browser->visit('/profile');
            $this->waitForAlpine($browser);
            $browser->assertSee('Only '.$customer->name.' can correct this email, signed in themselves.');
            $this->assertTrue($browser->script('return document.querySelector(\'input[name="email"]\').readOnly;')[0]);

            // Forced past the page, the server refuses it under the field and keeps the address.
            $browser->script('const f = document.querySelector(\'input[name="email"]\'); f.readOnly = false; f.value = "admin-inbox@example.com";');
            $browser->waitForReload(fn (Browser $b) => $this->presses($b, $this->centreOf($b, 'form[x-data="profileInfo()"] button[type="submit"]'), 1));
            $browser->waitFor('[data-error-for="email"]')
                ->assertSeeIn('[data-error-for="email"]', 'Only '.$customer->name.' can correct their email, signed in themselves: not by Log In As.');
            $this->assertSame('customer@example.com', $customer->fresh()->email);
        });
    }

    /** No link to $linkPath in the mail written since $from. */
    /**
     * The link budget (User::LINKS_PER_ACCOUNT, LINKS_PER_VISITOR) counts in the Dusk server's file cache, which outlives the
     * database each test migrates afresh: user ids start at 1 again, so a test run straight after another that sent links to
     * account 1, or from this browser's address, would start with the budget spent ("Too many emails asked for").
     */
    private function forgetTheLinkBudgets(User $user): void
    {
        foreach (['minute', 'hour'] as $window) {
            RateLimiter::clear("confirm-links:account:{$window}:{$user->id}");
            RateLimiter::clear("confirm-links:visitor:{$window}:127.0.0.1");
        }
    }

    private function assertNoLinkInMailLog(int $from, string $linkPath): void
    {
        try {
            $this->linkFromMailLog($from, $linkPath);
        } catch (RuntimeException) {
            $this->addToAssertionCount(1);

            return;
        }

        $this->fail("An email carried a {$linkPath} link it should not have.");
    }

    /** How many emails with this subject the app wrote to the log since $from (MAIL_MAILER=log). */
    private function emailsInLogSince(int $from, string $subject): int
    {
        $path = storage_path('logs/laravel.log');
        clearstatcache(true, $path);
        $written = file_exists($path) ? (string) file_get_contents($path, false, null, $from) : '';

        return substr_count($written, 'Subject: '.$subject);
    }

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

    private function centreOf(Browser $browser, string $css): array
    {
        return $browser->script('const el = document.querySelector('.json_encode($css).'); el.scrollIntoView({ block: "center" }); '
            .'const r = el.getBoundingClientRect(); return [Math.round(r.left + r.width / 2), Math.round(r.top + r.height / 2)];')[0];
    }
}
