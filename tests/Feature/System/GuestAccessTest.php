<?php

use App\Models\Invitation;
use App\Models\Role;
use App\Models\Store;

/*
|--------------------------------------------------------------------------
| Signed out, the people pages are shut — the invitation link is the one door open
|--------------------------------------------------------------------------
*/

test('a guest asking for the app lands on the login page', function () {
    $this->get('/')->assertRedirect('/login');
});

test('every people endpoint wants a signed-in person', function () {
    $store = Store::factory()->create();
    $member = createStoreMember($store, Role::STAFF);
    $invitation = Invitation::factory()->create(['store_id' => $store->id]);

    $this->getJson('/members/data')->assertUnauthorized();
    $this->putJson("/members/{$member->id}", ['role_id' => 1])->assertUnauthorized();
    $this->deleteJson("/members/{$member->id}")->assertUnauthorized();
    $this->postJson('/members/leave')->assertUnauthorized();
    $this->postJson('/members/invitations', ['email' => 'x@example.com', 'role_id' => 1])->assertUnauthorized();
    $this->deleteJson("/members/invitations/{$invitation->id}")->assertUnauthorized();
    $this->getJson('/users/data')->assertUnauthorized();
    $this->getJson('/roles/data')->assertUnauthorized();
    $this->putJson('/settings/store', [])->assertUnauthorized();
    $this->deleteJson('/settings/store')->assertUnauthorized();

    $this->get('/members')->assertRedirect(route('login'));
    $this->get('/settings/store')->assertRedirect(route('login'));
});

test('the invitation link is open to guests — accepting still needs an account', function () {
    Invitation::factory()->withToken($token = str_repeat('k', 64))->create(['email' => 'guest@example.com']);

    $this->get("/invitations/{$token}")->assertOk();
    $this->post("/invitations/{$token}/accept")->assertRedirect(route('login'));
});
