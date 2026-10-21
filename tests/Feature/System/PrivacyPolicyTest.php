<?php

use App\Models\User;

/*
 * The privacy policy at /privacy (owner, 2026-10-21, for the Amazon Appstore, which asks for one on the app's page and
 * inside the app): open to everybody, linked from every door before the panel, and naming whom to write to only when the
 * owner has said it (config signage.privacy_contact_email, else the billing contact).
 */

test('anybody can read the privacy policy, signed in or not, confirmed or not', function () {
    $this->get('/privacy')->assertOk()->assertSee('Privacy Policy');

    $this->actingAs(User::factory()->create())->get('/privacy')->assertOk();
    $this->actingAs(User::factory()->unverified()->create())->get('/privacy')->assertOk();
});

test('its contents list points to every section it has', function () {
    $html = (string) $this->get('/privacy')->getContent();

    preg_match_all('/href="#([a-z-]+)"/', $html, $links);
    preg_match_all('/<h2 id="([a-z-]+)"/', $html, $headings);

    // The Contact section is there by default: the support address the Amazon Appstore listing gives customers.
    expect($links[1])->toBe(['main-content', 'what-we-collect', 'tv-app', 'how-we-use-it', 'who-else', 'cookies',
        'how-long', 'your-choices', 'security', 'children', 'changes', 'contact'])
        ->and(array_values(array_diff($headings[1], ['privacy-contents'])))->toBe(array_slice($links[1], 1));
});

test('it names the privacy contact, else the billing one, else has no Contact section', function () {
    config(['signage.privacy_contact_email' => 'privacy@example.com', 'signage.billing_contact_email' => 'billing@example.com']);
    $this->get('/privacy')->assertSee('mailto:privacy@example.com', false)->assertSee('href="#contact"', false)
        ->assertDontSee('billing@example.com');

    config(['signage.privacy_contact_email' => '']);
    $this->get('/privacy')->assertSee('mailto:billing@example.com', false);

    config(['signage.billing_contact_email' => '']);
    $this->get('/privacy')->assertDontSee('mailto:', false)->assertDontSee('href="#contact"', false)
        ->assertDontSee('Contact us');
});

test('every door before the panel links to it', function (string $url) {
    $this->get($url)->assertOk()->assertSee('href="'.route('privacy').'"', false);
})->with([
    'sign in' => '/login',
    'sign up' => '/register',
    'forgot password' => '/forgot-password',
    'new password' => '/reset-password/some-token?email=someone%40example.com',
]);
