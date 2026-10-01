<?php

use App\Models\Invitation;
use App\Models\Organization;
use App\Models\Role;

/*
|--------------------------------------------------------------------------
| Signed out, the people pages are shut — the invitation link is the one door open
|--------------------------------------------------------------------------
*/

test('a guest asking for the app lands on the login page', function () {
    $this->get('/')->assertRedirect('/login');
});

test('every people endpoint wants a signed-in person', function () {
    $organization = Organization::factory()->create();
    $member = createOrganizationMember($organization, Role::STAFF);
    $invitation = Invitation::factory()->create(['organization_id' => $organization->id]);

    $this->getJson('/members/data')->assertUnauthorized();
    $this->putJson("/members/{$member->id}", ['role_id' => 1])->assertUnauthorized();
    $this->deleteJson("/members/{$member->id}")->assertUnauthorized();
    $this->postJson('/members/leave')->assertUnauthorized();
    $this->postJson('/members/invitations', ['email' => 'x@example.com', 'role_id' => 1])->assertUnauthorized();
    $this->deleteJson("/members/invitations/{$invitation->id}")->assertUnauthorized();
    $this->getJson('/users/data')->assertUnauthorized();
    $this->getJson('/roles/data')->assertUnauthorized();
    $this->putJson('/settings/organization', [])->assertUnauthorized();
    $this->deleteJson('/settings/organization')->assertUnauthorized();

    $this->get('/members')->assertRedirect(route('login'));
    $this->get('/settings/organization')->assertRedirect(route('login'));

    // And every other route of the people pages, read from the route table — so one added later is asked too.
    $routes = collect(['members', 'users', 'roles', 'settings', 'profile'])->flatMap(fn (string $prefix) => routesUnder($prefix));

    expect($routes)->not->toBeEmpty();

    foreach ($routes as [$method, $uri]) {
        expect($this->json($method, $uri)->status())->toBe(401, "{$method} {$uri}");
    }
});

test('the invitation link is open to guests — accepting still needs an account', function () {
    Invitation::factory()->withToken($token = str_repeat('k', 64))->create(['email' => 'guest@example.com']);

    $this->get("/invitations/{$token}")->assertOk();
    $this->post("/invitations/{$token}/accept")->assertRedirect(route('login'));
});
