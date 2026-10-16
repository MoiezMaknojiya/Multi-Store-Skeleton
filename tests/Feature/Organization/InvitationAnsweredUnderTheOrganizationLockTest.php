<?php

use App\Models\ActivityLog;
use App\Models\Invitation;
use App\Models\Organization;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\Support\TransactionStatements;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| An invitation answered as its organization is deleted (the review of 2026-10-15, L5)
|--------------------------------------------------------------------------
|
| Organization::purgeContents takes the organization's row, deletes its memberships — on MySQL that locks the range of
| organization_user they stood in — and then waits for its invitations. An accept that held the person's row and the
| invitation's, and then wrote its membership into that range, met the delete the other way round: a deadlock (1213),
| a 500. Every answer to an organization's invitation now takes the organization's row first, as every change to a team
| does (OrganizationTeam::changeTeam), then the person's row, then the invitation's
| (InvitationResponseController::lockItsOrganization), so one of the two waits for the other. An organization deleted while
| the answer waited is said in words on the page the answer lands on — the dashboard for a signed-in person, the link's own
| "no longer valid" page for a guest — and the account itself deleted meanwhile is 404: never a membership of nothing. A
| decline at that moment deletes nothing and logs nothing (deleteDeclined), never reading the gone organization's name.
| SQLite has neither row locks nor snapshots, so these tests read the order of the statements (TransactionStatements), and
| play the delete at the moment the answer would wait for it.
|
*/

/** Run $competitor once, just before the first statement inside the request's own transaction: the lock it waits at. */
function beforeTheAnswersFirstLock(Closure $competitor): object
{
    $outside = DB::transactionLevel();
    $state = (object) ['done' => false];

    DB::connection()->beforeExecuting(function () use ($competitor, $outside, $state) {
        if (! $state->done && DB::transactionLevel() > $outside) {
            $state->done = true;
            $competitor();
        }
    });

    return $state;
}

beforeEach(function () {
    $this->alpha = Organization::factory()->create(['name' => 'Alpha Mart']);
    $this->person = User::factory()->create(['email' => 'sana@example.com']);
});

/** Answer the invitation through one of its three doors. */
function answerTheInvitation(TestCase $test, string $door, User $person, Invitation $invitation, string $token): TestResponse
{
    return match ($door) {
        'the link' => $test->actingAs($person)->post("/invitations/{$token}/accept"),
        'the dashboard' => $test->actingAs($person)->post("/dashboard/invitations/{$invitation->id}/accept"),
        'a new account from the link' => $test->post("/invitations/{$token}/register", [
            'first_name' => 'New', 'last_name' => 'Bie', 'phone' => '5551234567',
            'password' => 'Str0ng-Password!', 'password_confirmation' => 'Str0ng-Password!',
        ]),
    };
}

test('answering an invitation locks its organization, then the person, then the invitation, before it reads anything', function (string $door) {
    $invitation = Invitation::factory()->withToken($token = str_repeat('a', 64))->create(['organization_id' => $this->alpha->id, 'email' => 'sana@example.com']);
    $log = TransactionStatements::record();

    answerTheInvitation($this, $door, $this->person, $invitation, $token)->assertRedirect(route('dashboard'));

    // The order Organization::purgeContents, OrganizationTeam::changeTeam and an account's deletion take them: the
    // organization's row first. Locks take no snapshot, so the plain reads after them see what committed while they waited.
    expect(array_slice($log->of('/^insert into "organization_user"/'), 0, 4))->sequence(
        fn ($statement) => $statement->toStartWith('select "id" from "organizations" where "organizations"."id" = ?')->toEndWith('/* for update */'),
        fn ($statement) => $statement->toStartWith('select "id" from "users" where "users"."id" = ?')->toEndWith('/* for update */'),
        fn ($statement) => $statement->toStartWith('select "id" from "invitations" where "invitations"."id" = ?')->toEndWith('/* for update */'),
        fn ($statement) => $statement->toContain('from "organization_user" where "organization_id" = ? and "user_id" = ?'),
    );
    expect(roleKeyIn($this->person, $this->alpha))->toBe(Role::STAFF);
})->with(['the link', 'the dashboard']);

test('a new account made from the link locks the organization, then the invitation, before it reads anything', function () {
    $invitation = Invitation::factory()->withToken($token = str_repeat('b', 64))->create(['organization_id' => $this->alpha->id, 'email' => 'newbie@example.com']);
    $log = TransactionStatements::record();

    answerTheInvitation($this, 'a new account from the link', $this->person, $invitation, $token)->assertRedirect(route('dashboard'));

    expect(array_slice($log->of('/^insert into "users"/'), 0, 3))->sequence(
        fn ($statement) => $statement->toStartWith('select "id" from "organizations" where "organizations"."id" = ?')->toEndWith('/* for update */'),
        fn ($statement) => $statement->toStartWith('select "id" from "invitations" where "invitations"."id" = ?')->toEndWith('/* for update */'),
        fn ($statement) => $statement->toStartWith('select * from "users" where lower(email) = ?'),
    );
    expect(roleKeyIn(User::where('email', 'newbie@example.com')->sole(), $this->alpha))->toBe(Role::STAFF);
});

test('a platform invitation has no organization row to wait for: the person, then the invitation', function () {
    $support = Role::create(['name' => 'Support', 'is_global' => true]);
    Invitation::factory()->forPlatform()->withToken($token = str_repeat('c', 64))->create(['email' => 'sana@example.com', 'role_id' => $support->id]);
    $log = TransactionStatements::record();

    $this->actingAs($this->person)->post("/invitations/{$token}/accept")->assertRedirect(route('dashboard'));

    $statements = $log->of('/^insert into "organization_user"/');

    expect(array_slice($statements, 0, 2))->sequence(
        fn ($statement) => $statement->toStartWith('select "id" from "users" where "users"."id" = ?')->toEndWith('/* for update */'),
        fn ($statement) => $statement->toStartWith('select "id" from "invitations" where "invitations"."id" = ?')->toEndWith('/* for update */'),
    );
    // The tier rule reads the person's organizations (a plain read, after the locks); nothing locks one.
    expect(array_filter($statements, fn (string $statement) => str_contains($statement, '"organizations"')
        && (TransactionStatements::locksForUpdate($statement) || TransactionStatements::locksForShare($statement))))->toBe([]);
});

test('an invitation answered as its organization is deleted says so on the page it lands on, and joins nobody', function (string $door) {
    $invitation = Invitation::factory()->withToken($token = str_repeat('d', 64))->create([
        'organization_id' => $this->alpha->id,
        'email' => $door === 'a new account from the link' ? 'newbie@example.com' : 'sana@example.com',
    ]);

    // The platform's Delete — or the Owner's, on Settings → Organizations — commits while the answer waits for the
    // organization's row: its memberships and its invitations go with it.
    $landed = beforeTheAnswersFirstLock(fn () => DB::transaction(fn () => Organization::whereKey($this->alpha->id)->first()->delete()));

    $response = answerTheInvitation($this, $door, $this->person, $invitation, $token);

    if ($door === 'a new account from the link') {
        // A guest: the link's own page, as for any link that stopped working — never the 404 page's "the link has a typo".
        $response->assertRedirect(route('invitations.show', $token));
        $this->assertGuest();
        $this->get(route('invitations.show', $token))->assertOk()
            ->assertSee('This invitation is no longer valid')->assertSee('been cancelled');
    } else {
        // Signed in (the link confirmed the email): the dashboard says why, in words, and offers nothing more.
        $response->assertRedirect(route('dashboard'));
        $this->get(route('dashboard'))->assertOk()
            ->assertSee('dusk="dashboard-invitation-problem"', false)
            ->assertSee('Alpha Mart has been deleted, so this invitation can no longer be used.')
            ->assertDontSee('dusk="dashboard-invitations"', false);
    }

    // Played inside this request's transaction, which commits (on MySQL the delete commits apart, before the lock is had):
    // the organization and its invitation are gone, and nothing was joined, made or logged for it.
    expect($landed->done)->toBeTrue()
        ->and(Organization::whereKey($this->alpha->id)->exists())->toBeFalse()
        ->and(Invitation::whereKey($invitation->id)->exists())->toBeFalse()
        ->and(DB::table('organization_user')->where('organization_id', $this->alpha->id)->exists())->toBeFalse()
        ->and(User::where('email', 'newbie@example.com')->exists())->toBeFalse()
        ->and(ActivityLog::whereIn('action', ['invitation.accepted', 'user.registered'])->exists())->toBeFalse();
})->with(['the link', 'the dashboard', 'a new account from the link']);

/** Run $competitor once, just before the decline deletes the invitation's own row (by its id, never by its organization). */
function beforeTheDeclineDeletesIt(Closure $competitor): object
{
    $state = (object) ['done' => false];

    DB::connection()->beforeExecuting(function (string $sql) use ($competitor, $state) {
        if (! $state->done && preg_match('/^delete from "invitations" where ("invitations"\.)?"id" = \?/', $sql)) {
            $state->done = true;
            $competitor();
        }
    });

    return $state;
}

test('an invitation declined as its organization is deleted is never a 500, and logs no decline that did not happen', function (string $door) {
    $invitation = Invitation::factory()->withToken($token = str_repeat('f', 64))->create(['organization_id' => $this->alpha->id, 'email' => 'sana@example.com']);

    // Organization::purgeContents commits as the decline deletes the row: it took the invitation first, so the decline
    // deletes nothing (on MySQL it waits for that delete's lock on the row, then finds it gone).
    $landed = beforeTheDeclineDeletesIt(fn () => DB::transaction(fn () => Organization::whereKey($this->alpha->id)->first()->delete()));

    $response = match ($door) {
        'the link' => $this->actingAs($this->person)->post("/invitations/{$token}/decline"),
        'the link, signed out' => $this->post("/invitations/{$token}/decline"),
        'the dashboard' => $this->actingAs($this->person)->post("/dashboard/invitations/{$invitation->id}/decline"),
    };

    // The answer a link declined twice gets: the invitation is no longer open either way.
    $response->assertRedirect(route($door === 'the link, signed out' ? 'login' : 'dashboard'))->assertSessionHas('status', 'Invitation declined.');

    expect($landed->done)->toBeTrue()
        ->and(Organization::whereKey($this->alpha->id)->exists())->toBeFalse()
        ->and(ActivityLog::where('action', 'invitation.declined')->exists())->toBeFalse();
})->with(['the link', 'the link, signed out', 'the dashboard']);

test('an invitation declined with nothing in its way is still deleted and logged, naming its organization', function (string $door) {
    $invitation = Invitation::factory()->withToken($token = str_repeat('g', 64))->create(['organization_id' => $this->alpha->id, 'email' => 'sana@example.com']);

    match ($door) {
        'the link' => $this->actingAs($this->person)->post("/invitations/{$token}/decline"),
        'the link, signed out' => $this->post("/invitations/{$token}/decline"),
        'the dashboard' => $this->actingAs($this->person)->post("/dashboard/invitations/{$invitation->id}/decline"),
    };

    $log = ActivityLog::where('action', 'invitation.declined')->sole();

    expect(Invitation::whereKey($invitation->id)->exists())->toBeFalse()
        ->and($log->organization_id)->toBe($this->alpha->id)
        ->and($log->description)->toContain('declined the invitation to Alpha Mart')
        ->and($log->actor_id)->toBe($this->person->id);
})->with(['the link', 'the link, signed out', 'the dashboard']);

test('an account the platform deletes while its person accepts an invitation is 404, never a membership of nobody', function (string $door) {
    $invitation = Invitation::factory()->withToken($token = str_repeat('e', 64))->create(['organization_id' => $this->alpha->id, 'email' => 'sana@example.com']);

    // The platform's Users page deletes the account, and commits, as the accept waits for the person's row. Only the row:
    // on MySQL the account's own deletion hook (its invitations) is a statement of its own, which the accept can pass.
    $outside = DB::transactionLevel();
    $landed = (object) ['done' => false];
    DB::connection()->beforeExecuting(function (string $sql) use ($outside, $landed) {
        if (! $landed->done && DB::transactionLevel() > $outside && str_starts_with($sql, 'select "id" from "users" where "users"."id" = ?')) {
            $landed->done = true;
            DB::table('users')->where('id', $this->person->id)->delete();
        }
    });

    answerTheInvitation($this, $door, $this->person, $invitation, $token)->assertNotFound();

    // Played inside this request's transaction, which the 404 rolled back with it; on MySQL it is committed apart.
    expect($landed->done)->toBeTrue()
        ->and(DB::table('organization_user')->where('organization_id', $this->alpha->id)->exists())->toBeFalse()
        ->and(ActivityLog::where('action', 'invitation.accepted')->exists())->toBeFalse();
})->with(['the link', 'the dashboard']);
