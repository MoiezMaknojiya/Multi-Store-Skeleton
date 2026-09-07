<?php

use App\Models\User;

test('profile page is displayed', function () {
    $user = User::factory()->create();

    $response = $this
        ->actingAs($user)
        ->get('/profile');

    $response->assertOk();
});

test('profile information can be updated', function () {
    $user = User::factory()->create();

    $response = $this
        ->actingAs($user)
        ->patch('/profile', [
            'first_name' => 'Test',
            'last_name' => 'User',
            'phone' => '1234567890',
            'email' => 'test@example.com',
        ]);

    $response
        ->assertSessionHasNoErrors()
        ->assertRedirect('/profile');

    $user->refresh();

    $this->assertSame('Test', $user->first_name);
    $this->assertSame('User', $user->last_name);
    $this->assertSame('test@example.com', $user->email);
    $this->assertNull($user->email_verified_at);
});

test('profile update rejects missing required fields (backend mirrors the client rules)', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->from('/profile')
        ->patch('/profile', [
            'first_name' => '',
            'last_name' => '',
            'phone' => '',
            'email' => '',
        ])
        ->assertSessionHasErrors(['first_name', 'last_name', 'phone', 'email'])
        ->assertRedirect('/profile');
});

test('profile update rejects an invalid phone and email', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->from('/profile')
        ->patch('/profile', [
            'first_name' => 'Test',
            'last_name' => 'User',
            'phone' => '123',            // not 10 digits
            'email' => 'not-an-email',
        ])
        ->assertSessionHasErrors(['phone', 'email'])
        ->assertRedirect('/profile');
});

test('email verification status is unchanged when the email address is unchanged', function () {
    $user = User::factory()->create();

    $response = $this
        ->actingAs($user)
        ->patch('/profile', [
            'first_name' => $user->first_name,
            'last_name' => $user->last_name,
            'phone' => $user->phone,
            'email' => $user->email,
        ]);

    $response
        ->assertSessionHasNoErrors()
        ->assertRedirect('/profile');

    $this->assertNotNull($user->refresh()->email_verified_at);
});

test('user can delete their account', function () {
    $user = User::factory()->create();

    $response = $this
        ->actingAs($user)
        ->delete('/profile', [
            'password' => 'password',
        ]);

    $response
        ->assertSessionHasNoErrors()
        ->assertRedirect('/');

    $this->assertGuest();
    $this->assertNull($user->fresh());
});

test('a super admin cannot delete their own account (backend blocks it)', function () {
    $admin = createSuperAdmin();

    $this->actingAs($admin)
        ->from('/profile')
        ->delete('/profile', ['password' => 'password'])
        ->assertForbidden();

    $this->assertNotNull($admin->fresh()); // still there — cascade never ran
});

test('the delete-account section is hidden for super admins but shown to everyone else', function () {
    $admin = createSuperAdmin();
    $this->actingAs($admin)->get('/profile')->assertOk()->assertDontSee('Delete Account');

    $regular = User::factory()->create();
    $this->actingAs($regular)->get('/profile')->assertOk()->assertSee('Delete Account');
});

test('correct password must be provided to delete account', function () {
    $user = User::factory()->create();

    $response = $this
        ->actingAs($user)
        ->from('/profile')
        ->delete('/profile', [
            'password' => 'wrong-password',
        ]);

    $response
        ->assertSessionHasErrorsIn('userDeletion', 'password')
        ->assertRedirect('/profile');

    $this->assertNotNull($user->fresh());
});
