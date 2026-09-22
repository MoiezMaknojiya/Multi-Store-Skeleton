<?php

use App\Models\User;
use App\Notifications\ResetPasswordNotification;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;

test('reset password link screen can be rendered', function () {
    $response = $this->get('/forgot-password');

    // The form itself, not only a 200: where it posts, and the one field it posts.
    $response->assertOk()
        ->assertSee('dusk="forgot-password-form"', false)
        ->assertSee('action="'.route('password.email').'"', false)
        ->assertSee('name="email"', false);
});

test('reset password link can be requested', function () {
    Notification::fake();

    $user = User::factory()->create();

    $this->post('/forgot-password', ['email' => $user->email]);

    Notification::assertSentTo($user, ResetPasswordNotification::class);
});

test('reset password screen can be rendered', function () {
    Notification::fake();

    $user = User::factory()->create();

    $this->post('/forgot-password', ['email' => $user->email]);

    Notification::assertSentTo($user, ResetPasswordNotification::class, function ($notification) {
        $response = $this->get('/reset-password/'.$notification->token);

        $response->assertStatus(200);

        return true;
    });
});

test('password can be reset with valid token', function () {
    Notification::fake();

    $user = User::factory()->create();

    $this->post('/forgot-password', ['email' => $user->email]);

    Notification::assertSentTo($user, ResetPasswordNotification::class, function ($notification) use ($user) {
        // A password different from the factory's, so the check below can tell a reset from nothing at all.
        $response = $this->post('/reset-password', [
            'token' => $notification->token,
            'email' => $user->email,
            'password' => 'new-password',
            'password_confirmation' => 'new-password',
        ]);

        $response
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('login'));

        return true;
    });

    $stored = $user->fresh()->password;

    expect(Hash::check('new-password', $stored))->toBeTrue()
        ->and(Hash::check('password', $stored))->toBeFalse();
});

test('the reset pages take only plain values — a list in the link or the form is a 422, never a 500', function () {
    // ?email[]=x once reached the view as an array and every link of that shape broke the page.
    $this->get('/reset-password/some-token?email[]=someone@example.com')->assertOk();

    // token[]=x reached password_verify() as an array: a TypeError, so a 500 on the reset form.
    $user = User::factory()->create();

    $this->from('/reset-password/some-token')->post('/reset-password', [
        'token' => ['some-token'],
        'email' => $user->email,
        'password' => 'a-new-password',
        'password_confirmation' => 'a-new-password',
    ])->assertSessionHasErrors('token');

    // After a sign-in attempt with email[]=x the page is drawn again with that old input — as nothing.
    $this->from('/login')->post('/login', ['email' => ['someone@example.com'], 'password' => 'x'])
        ->assertRedirect('/login');
    $this->get('/login')->assertOk();
});
