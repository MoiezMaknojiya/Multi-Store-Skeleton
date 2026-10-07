<?php

namespace Tests\Browser;

use App\Models\Invitation;
use App\Models\Organization;
use App\Models\Role;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Laravel\Dusk\Browser;
use Tests\DuskTestCase;

/**
 * A new password and its confirmation are shown and hidden together, by the one eye on the confirmation (owner,
 * 2026-10-06: "password ki file ki eyes hata do aur confirm password ki field ki eyes per dono password show and hide
 * ho") — on signup, a password reset, an invitation's account form and the profile, where the current password keeps
 * an eye of its own.
 */
class PasswordPairsTest extends DuskTestCase
{
    use DatabaseMigrations;

    public function test_a_password_and_its_confirmation_show_and_hide_together_from_the_confirmations_eye(): void
    {
        $this->seedSuperAdmin();
        $organization = Organization::factory()->create(['name' => 'Alpha Mart']);
        $owner = $this->organizationMember($organization, Role::OWNER);
        Invitation::create([
            'organization_id' => $organization->id, 'email' => 'new.hire@example.com', 'role_id' => Role::starter(Role::STAFF)->id,
            'token_hash' => Invitation::hashToken($token = str_repeat('p', 64)), 'expires_at' => now()->addDays(7),
        ]);

        $this->browse(function (Browser $browser) use ($owner, $token) {
            $this->freshSession($browser);

            foreach (['/register', '/reset-password/'.str_repeat('r', 64).'?email=someone@example.com', '/invitations/'.$token] as $page) {
                $browser->visit($page);
                $this->waitForAlpine($browser);
                $this->assertTheyGoTogether($browser, '#password', '#password_confirmation', $page);
            }

            $browser->loginAs($owner)->visit('/profile');
            $this->waitForAlpine($browser);
            $this->assertTheyGoTogether($browser, '#update_password_password', '#update_password_password_confirmation', '/profile');

            // The current password is a field of its own, with its own eye.
            $this->assertSame(1, $browser->script("return document.querySelector('#update_password_current_password').parentElement.querySelectorAll('button').length")[0]);
        });
    }

    /** No eye on the password; the confirmation's one turns both into text and back, and says it does both. */
    private function assertTheyGoTogether(Browser $browser, string $password, string $confirmation, string $page): void
    {
        $state = fn (): array => $browser->script(<<<JS
            const first = document.querySelector('{$password}');
            const second = document.querySelector('{$confirmation}');
            const eye = second.parentElement.querySelector('button');
            return [first.type, second.type, first.parentElement.querySelectorAll('button').length, eye ? eye.getAttribute('aria-label') : null];
        JS)[0];

        $this->assertSame(['password', 'password', 0, 'Show passwords'], $state(), $page);

        $browser->script("document.querySelector('{$confirmation}').parentElement.querySelector('button').click()");
        $browser->waitUsing(3, 100, fn () => $state()[0] === 'text');
        $this->assertSame(['text', 'text', 0, 'Hide passwords'], $state(), $page);

        $browser->script("document.querySelector('{$confirmation}').parentElement.querySelector('button').click()");
        $browser->waitUsing(3, 100, fn () => $state()[0] === 'password');
        $this->assertSame(['password', 'password', 0, 'Show passwords'], $state(), $page);
    }
}
