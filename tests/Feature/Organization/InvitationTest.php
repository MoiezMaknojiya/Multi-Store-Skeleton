<?php

use App\Models\ActivityLog;
use App\Models\Invitation;
use App\Models\Role;
use App\Models\Organization;
use App\Models\User;
use App\Notifications\InvitationNotification;
use Illuminate\Contracts\Notifications\Dispatcher;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Notification;

/*
|--------------------------------------------------------------------------
| Joining an organization by invitation (docs/ORGANIZATION-SPEC.md rules 15–16)
|--------------------------------------------------------------------------
|
| Nobody creates anybody's account or knows anybody's password: an email goes out with a link,
| and the person behind that inbox decides.
|
*/

beforeEach(function () {
    Notification::fake();

    $this->organization = Organization::factory()->create(['name' => 'Alpha Mart']);
    $this->owner = createOrganizationMember($this->organization, Role::OWNER, ['first_name' => 'Olive', 'last_name' => 'Owner']);
    $this->staffRole = Role::starter(Role::STAFF);
});

function inviteAs(User $user, Organization $organization, string $email, int $roleId)
{
    return test()->actingAs($user)->withSession(['current_organization_id' => $organization->id])
        ->postJson('/members/invitations', ['email' => $email, 'role_id' => $roleId]);
}

/** The token the last invitation email to this address carried. */
function tokenSentTo(string $email): string
{
    $token = null;

    Notification::assertSentOnDemand(InvitationNotification::class,
        function (InvitationNotification $notification, array $channels, AnonymousNotifiable $notifiable) use ($email, &$token) {
            if ($notifiable->routes['mail'] === $email) {
                $token = $notification->token;

                return true;
            }

            return false;
        });

    return $token;
}

/*
| Sending
*/

test('an Owner invites by email: the mail carries the link, and only the token’s hash is stored', function () {
    inviteAs($this->owner, $this->organization, '  New.Person@Example.com ', $this->staffRole->id)->assertCreated();

    $invitation = Invitation::sole();
    expect($invitation->email)->toBe('new.person@example.com')
        ->and($invitation->organization_id)->toBe($this->organization->id)
        ->and($invitation->invited_by)->toBe($this->owner->id)
        ->and($invitation->expires_at->between(now()->addDays(6), now()->addDays(8)))->toBeTrue();

    $token = tokenSentTo('new.person@example.com');
    expect($invitation->token_hash)->toBe(hash('sha256', $token))->not->toBe($token);

    // The invitee is somebody else, signed out, with no account yet.
    auth()->logout();
    $this->get("/invitations/{$token}")->assertOk()->assertSee('Join Alpha Mart')->assertSee('data-state="register"', false);
    expect(ActivityLog::where('action', 'member.invited')->exists())->toBeTrue();
});

test('the invitation email reads properly', function () {
    inviteAs($this->owner, $this->organization, 'reader@example.com', $this->staffRole->id);

    Notification::assertSentOnDemand(InvitationNotification::class, function (InvitationNotification $notification) {
        $mail = $notification->toMail(new AnonymousNotifiable);
        $html = (string) $mail->render();

        // The app's own template (tests/Feature/System/EmailTemplatesTest.php draws it in full); here the
        // question is only whether THIS invitation's words and link reached it.
        return $mail->subject === "You're invited to join Alpha Mart"
            && str_contains($html, 'Olive Owner')
            && str_contains($html, 'Alpha Mart')
            && str_contains($html, 'Staff')
            && str_contains($html, '/invitations/'.$notification->token);
    });
});

test('an invitation is refused for a member, a second invitation, or a platform account', function () {
    createOrganizationMember($this->organization, Role::STAFF, ['email' => 'member@example.com']);
    inviteAs($this->owner, $this->organization, 'MEMBER@example.com', $this->staffRole->id)
        ->assertStatus(422)->assertJsonValidationErrors('email');

    inviteAs($this->owner, $this->organization, 'twice@example.com', $this->staffRole->id)->assertCreated();
    inviteAs($this->owner, $this->organization, 'twice@example.com', $this->staffRole->id)
        ->assertStatus(422)->assertJsonValidationErrors('email');

    $ops = createPlatformUser(['user-view']);
    inviteAs($this->owner, $this->organization, $ops->email, $this->staffRole->id)
        ->assertStatus(422)->assertJsonValidationErrors('email');

    expect(Invitation::count())->toBe(1);
});

test('an invitation offers only roles of this organization that the inviter could give', function () {
    $admin = createOrganizationMember($this->organization, Role::ADMIN);

    inviteAs($admin, $this->organization, 'boss@example.com', Role::starter(Role::OWNER)->id)
        ->assertStatus(422)->assertJsonValidationErrors('role_id');

    $foreign = Role::create(['name' => 'Foreign', 'organization_id' => Organization::factory()->create()->id]);
    inviteAs($this->owner, $this->organization, 'foreign@example.com', $foreign->id)
        ->assertStatus(422)->assertJsonValidationErrors('role_id');

    expect(Invitation::count())->toBe(0);
});

test('resending sends a new link and kills the old one', function () {
    inviteAs($this->owner, $this->organization, 'again@example.com', $this->staffRole->id);
    $first = tokenSentTo('again@example.com');
    $invitation = Invitation::sole();

    Notification::fake();
    $this->actingAs($this->owner)->withSession(['current_organization_id' => $this->organization->id])
        ->postJson("/members/invitations/{$invitation->id}/resend")->assertOk();
    $second = tokenSentTo('again@example.com');

    expect($second)->not->toBe($first);
    $this->get("/invitations/{$first}")->assertOk()->assertSee('This invitation is no longer valid');
    $this->get("/invitations/{$second}")->assertOk()->assertSee('Join Alpha Mart');
});

test('revoking closes the link', function () {
    inviteAs($this->owner, $this->organization, 'revoked@example.com', $this->staffRole->id);
    $token = tokenSentTo('revoked@example.com');

    $this->actingAs($this->owner)->withSession(['current_organization_id' => $this->organization->id])
        ->deleteJson('/members/invitations/'.Invitation::sole()->id)->assertOk();

    expect(Invitation::count())->toBe(0);
    $this->get("/invitations/{$token}")->assertSee('This invitation is no longer valid');
});

test('another organization’s invitations, and invitations above your reach, cannot be touched', function () {
    $foreign = Invitation::factory()->create();
    $this->actingAs($this->owner)->withSession(['current_organization_id' => $this->organization->id])
        ->deleteJson("/members/invitations/{$foreign->id}")->assertNotFound();

    $ownerInvite = Invitation::factory()->create(['organization_id' => $this->organization->id, 'role_id' => Role::starter(Role::OWNER)->id]);
    $admin = createOrganizationMember($this->organization, Role::ADMIN);
    $this->actingAs($admin)->withSession(['current_organization_id' => $this->organization->id])
        ->deleteJson("/members/invitations/{$ownerInvite->id}")->assertForbidden();

    expect(Invitation::count())->toBe(2);
});

/*
| Responding
*/

test('a new person creates their account from the link and lands inside the organization', function () {
    Invitation::factory()->withToken($token = str_repeat('a', 64))->create([
        'organization_id' => $this->organization->id, 'email' => 'newbie@example.com', 'role_id' => $this->staffRole->id,
    ]);

    $this->post("/invitations/{$token}/register", [
        'first_name' => 'New', 'last_name' => 'Bie', 'phone' => '5551234567',
        'password' => 'Str0ng-Password!', 'password_confirmation' => 'Str0ng-Password!',
    ])->assertRedirect(route('dashboard'));

    $user = User::where('email', 'newbie@example.com')->firstOrFail();
    $this->assertAuthenticatedAs($user);
    expect($user->email_verified_at)->not->toBeNull()
        ->and(roleKeyIn($user, $this->organization))->toBe(Role::STAFF)
        ->and(session('current_organization_id'))->toBe($this->organization->id)
        ->and(Invitation::count())->toBe(0)
        ->and(ActivityLog::where('action', 'invitation.accepted')->exists())->toBeTrue();
});

test('someone with an account signs in first, then accepts', function () {
    $existing = User::factory()->create(['email' => 'Worker@Example.com']);
    Invitation::factory()->withToken($token = str_repeat('b', 64))->create([
        'organization_id' => $this->organization->id, 'email' => 'worker@example.com', 'role_id' => $this->staffRole->id,
    ]);

    // Signed out: no form to create a second account — sign in, and come back here.
    $this->get("/invitations/{$token}")->assertSee('data-state="login"', false);
    expect(session('url.intended'))->toBe(route('invitations.show', $token));

    $this->post("/invitations/{$token}/register", [
        'first_name' => 'X', 'last_name' => 'Y', 'phone' => '5551234567', 'password' => 'Str0ng-Password!', 'password_confirmation' => 'Str0ng-Password!',
    ])->assertRedirect(route('invitations.show', $token));
    expect(User::whereRaw('lower(email) = ?', ['worker@example.com'])->count())->toBe(1);

    $this->actingAs($existing)->get("/invitations/{$token}")->assertSee('data-state="accept"', false);
    $this->actingAs($existing)->post("/invitations/{$token}/accept")->assertRedirect(route('dashboard'));

    expect(roleKeyIn($existing, $this->organization))->toBe(Role::STAFF)->and(Invitation::count())->toBe(0);
});

test('an invitation for somebody else can be neither accepted nor declined', function () {
    $stranger = User::factory()->create();
    Invitation::factory()->withToken($token = str_repeat('c', 64))->create(['organization_id' => $this->organization->id, 'email' => 'someone@example.com']);

    $this->actingAs($stranger)->get("/invitations/{$token}")->assertSee('data-state="mismatch"', false);
    $this->actingAs($stranger)->post("/invitations/{$token}/accept")->assertForbidden();
    $this->actingAs($stranger)->post("/invitations/{$token}/decline")->assertForbidden();

    expect(Invitation::count())->toBe(1)->and(roleKeyIn($stranger, $this->organization))->toBeNull();
});

test('an expired or unknown link does nothing but say so', function () {
    Invitation::factory()->expired()->withToken($token = str_repeat('d', 64))->create([
        'organization_id' => $this->organization->id, 'email' => 'late@example.com',
    ]);

    $this->get("/invitations/{$token}")->assertSee('This invitation is no longer valid');
    $this->get('/invitations/'.str_repeat('z', 64))->assertSee('This invitation is no longer valid');

    $this->post("/invitations/{$token}/register", [
        'first_name' => 'Late', 'last_name' => 'Comer', 'phone' => '5551234567', 'password' => 'Str0ng-Password!', 'password_confirmation' => 'Str0ng-Password!',
    ])->assertRedirect(route('invitations.show', $token));

    expect(User::where('email', 'late@example.com')->exists())->toBeFalse();
});

test('declining deletes the invitation', function () {
    Invitation::factory()->withToken($token = str_repeat('e', 64))->create(['organization_id' => $this->organization->id]);

    $this->post("/invitations/{$token}/decline")->assertRedirect(route('login'));

    expect(Invitation::count())->toBe(0)->and(ActivityLog::where('action', 'invitation.declined')->exists())->toBeTrue();
});

test('a guest declining for an existing account is logged under that account, not System', function () {
    $person = User::factory()->create(['email' => 'sam@example.com']);
    Invitation::factory()->withToken($token = str_repeat('d', 64))->create(['organization_id' => $this->organization->id, 'email' => 'sam@example.com']);

    $this->post("/invitations/{$token}/decline")->assertRedirect(route('login'));

    $log = ActivityLog::where('action', 'invitation.declined')->sole();
    expect($log->actor_id)->toBe($person->id)->and($log->actor_name)->toBe($person->name);
});

test('platform and organization accounts never cross over', function () {
    // A platform account cannot join an organization…
    $ops = createPlatformUser(['user-view']);
    Invitation::factory()->withToken($organizationToken = str_repeat('f', 64))->create(['organization_id' => $this->organization->id, 'email' => strtolower($ops->email)]);

    $this->actingAs($ops)->post("/invitations/{$organizationToken}/accept")
        ->assertRedirect(route('invitations.show', $organizationToken))
        ->assertSessionHas('error', 'Platform accounts cannot join an organization.');

    // …and an organization member cannot join the platform team.
    $platformRole = Role::create(['name' => 'Support', 'is_global' => true]);
    Invitation::factory()->forPlatform()->withToken($platformToken = str_repeat('g', 64))->create([
        'email' => strtolower($this->owner->email), 'role_id' => $platformRole->id,
    ]);

    $this->actingAs($this->owner)->post("/invitations/{$platformToken}/accept")
        ->assertSessionHas('error');

    expect(DB::table('organization_user')->where('user_id', $ops->id)->where('organization_id', $this->organization->id)->exists())->toBeFalse();
    expect(DB::table('organization_user')->where('user_id', $this->owner->id)->where('organization_id', 0)->exists())->toBeFalse();
});

test('accepting while already a member only clears the invitation', function () {
    $member = createOrganizationMember($this->organization, Role::VIEWER);
    Invitation::factory()->withToken($token = str_repeat('h', 64))->create([
        'organization_id' => $this->organization->id, 'email' => strtolower($member->email), 'role_id' => Role::starter(Role::ADMIN)->id,
    ]);

    $this->actingAs($member)->post("/invitations/{$token}/accept")->assertRedirect(route('dashboard'));

    expect(roleKeyIn($member, $this->organization))->toBe(Role::VIEWER)
        ->and(Invitation::count())->toBe(0)
        ->and(ActivityLog::where('action', 'invitation.accepted')->where('actor_id', $member->id)->exists())->toBeTrue();
});

test('deleting an organization takes its invitations with it, so their links answer like an unknown one', function () {
    Invitation::factory()->withToken($token = str_repeat('i', 64))->create(['organization_id' => $this->organization->id]);

    $this->organization->delete();

    expect(Invitation::count())->toBe(0);
    $this->get("/invitations/{$token}")->assertSee('This invitation is no longer valid');
});

test('the public end of the link is rate limited', function () {
    foreach (range(1, 20) as $attempt) {
        $this->get('/invitations/'.str_repeat('q', 64))->assertOk();
    }

    $this->get('/invitations/'.str_repeat('q', 64))->assertStatus(429);
});

test('a mail server that refuses leaves the invitation standing and says so, instead of an error page', function () {
    Exceptions::fake();
    $this->app->instance(Dispatcher::class, new class implements Dispatcher
    {
        public function send($notifiables, $notification)
        {
            throw new RuntimeException('SMTP is down');
        }

        public function sendNow($notifiables, $notification, ?array $channels = null)
        {
            throw new RuntimeException('SMTP is down');
        }
    });

    inviteAs($this->owner, $this->organization, 'new.hire@example.com', $this->staffRole->id)
        ->assertCreated()
        ->assertJson([
            'email_sent' => false,
            'message' => 'The invitation for new.hire@example.com was created, but the email could not be sent. Use Resend to try again.',
        ]);

    $invitation = Invitation::where('email', 'new.hire@example.com')->sole();
    Exceptions::assertReported(fn (RuntimeException $e) => $e->getMessage() === 'SMTP is down');

    $this->postJson("/members/invitations/{$invitation->id}/resend")
        ->assertOk()
        ->assertJson(['email_sent' => false]);
});
