<?php

use App\Models\User;

test('login screen can be rendered', function () {
    $response = $this->get('/login');

    // The form itself, not only a 200: where it posts, and the two fields it posts.
    $response->assertOk()
        ->assertSee('action="'.route('login').'"', false)
        ->assertSee('name="email"', false)
        ->assertSee('name="password"', false)
        ->assertSee('dusk="login-submit"', false);
});

test('users can authenticate using the login screen', function () {
    $user = User::factory()->create();

    $response = $this->post('/login', [
        'email' => $user->email,
        'password' => 'password',
    ]);

    $this->assertAuthenticated();
    $response->assertRedirect(route('dashboard', absolute: false));
});

test('users can not authenticate with invalid password', function () {
    $user = User::factory()->create();

    $this->post('/login', [
        'email' => $user->email,
        'password' => 'wrong-password',
    ]);

    $this->assertGuest();
});

test('users can logout', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->post('/logout');

    $this->assertGuest();
    $response->assertRedirect('/');
});
