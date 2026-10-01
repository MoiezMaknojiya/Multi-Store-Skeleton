<?php

use App\Models\Invitation;
use App\Models\Organization;
use App\Models\Role;
use App\Models\User;

/*
 * Every page says in its tab where you are ("Sign in · Digital Lifts") and names itself with one <h1> (rule 02,
 * "Every page speaks to a keyboard and a screen reader"). The panel's pages are held to it in a browser by
 * EveryPageRendersTest; these are the doors before the panel.
 */

test('the doors in say where they are in the tab and name themselves with one h1', function (string $url, string $title) {
    $html = $this->get($url)->assertOk()->getContent();

    expect($html)->toContain('<title>'.e($title).' · '.e(config('app.name')).'</title>')
        ->and(substr_count($html, '<h1'))->toBe(1);
})->with([
    'sign in' => ['/login', 'Sign in'],
    'sign up' => ['/register', 'Create your account'],
    'forgot password' => ['/forgot-password', 'Forgot password'],
    'new password' => ['/reset-password/some-token?email=someone%40example.com', 'Choose a new password'],
]);

test('an account waiting for its email is told so in the tab too', function () {
    $html = $this->actingAs(User::factory()->unverified()->create())->get('/verify-email')->assertOk()->getContent();

    expect($html)->toContain('<title>Check your inbox · '.e(config('app.name')).'</title>')
        ->and(substr_count($html, '<h1'))->toBe(1);
});

test('an invitation names the place it invites to, and a dead link says it is one', function () {
    $organization = Organization::factory()->create(['name' => 'Alpha Mart']);
    $token = str_repeat('k', 64);
    Invitation::factory()->withToken($token)->create([
        'organization_id' => $organization->id,
        'email' => 'new.cashier@example.com',
        'role_id' => Role::starter(Role::STAFF)->id,
    ]);

    expect($this->get('/invitations/'.$token)->assertOk()->getContent())
        ->toContain('<title>Join Alpha Mart · '.e(config('app.name')).'</title>');

    expect($this->get('/invitations/'.str_repeat('z', 64))->getContent())
        ->toContain('<title>Invitation no longer valid · '.e(config('app.name')).'</title>');
});
