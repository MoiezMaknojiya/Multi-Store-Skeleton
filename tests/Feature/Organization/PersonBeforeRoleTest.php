<?php

use App\Models\ActivityLog;
use App\Models\Invitation;
use App\Models\Organization;
use App\Models\Role;
use App\Models\User;
use App\Services\OrganizationTeam;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;
use Tests\Support\TransactionStatements;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| A person's row before a role's (the review of 2026-10-15, L5)
|--------------------------------------------------------------------------
|
| An invitation's accept holds the person's row (FOR UPDATE) while its membership's foreign key reads the role, and an
| account's deletion holds its row while it clears the roles it made. Putting a person in an organization from the platform,
| and writing an invitation (whose foreign key reads its inviter's row), held the role first and then waited for the person:
| the other way round. With a save of that role on the Roles page queued between them — MySQL queues a shared request behind
| a waiting exclusive one — or the account's own deletion, each waited for the other, and MySQL ended one with a deadlock, a
| 500. Every such change now takes the organization, the person, then the role (OrganizationTeam::changeTeam's $naming), the
| order the accept takes them. SQLite has neither row locks nor deadlocks, so these tests read the order of the statements
| (TransactionStatements) and play the deletion at the moment the change would wait for the person's row.
|
*/

beforeEach(function () {
    Notification::fake();
    $this->alpha = Organization::factory()->create(['name' => 'Alpha Mart']);
});

/** Run $competitor once, just before the first statement inside the request's own transaction. */
function beforeTheChangesFirstLock(Closure $competitor): object
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

/** Send one of the invitations a person writes, as $inviter. */
function writeAnInvitation(TestCase $test, string $door, User $inviter, Organization $organization): TestResponse
{
    return match ($door) {
        'the Members page' => $test->actingAs($inviter)->withSession(['current_organization_id' => $organization->id])
            ->postJson('/members/invitations', ['email' => 'new@example.com', 'role_id' => Role::starter(Role::STAFF)->id]),
        'the platform team' => $test->actingAs($inviter)
            ->postJson('/users/invitations', ['email' => 'new@example.com', 'role_id' => Role::create(['name' => 'Support', 'is_global' => true])->id]),
        'Invite Owner' => $test->actingAs($inviter)->postJson("/organizations/{$organization->id}/owner-invitation", ['email' => 'new@example.com']),
    };
}

test('a change to a team names the person before the role it gives', function () {
    $person = User::factory()->create();
    $log = TransactionStatements::record();

    app(OrganizationTeam::class)->changeTeam($this->alpha, fn () => null, giving: Role::starter(Role::STAFF), naming: $person);

    expect($log->of('/"roles"/'))->sequence(
        fn ($statement) => $statement->toStartWith('select "id" from "organizations" where "organizations"."id" = ?')->toEndWith('/* for update */'),
        fn ($statement) => $statement->toStartWith('select "id" from "users" where "users"."id" = ?')->toEndWith('/* for share */'),
        fn ($statement) => $statement->toStartWith('select "id" from "roles" where "roles"."id" = ?')->toEndWith('/* for share */'),
    );
});

test('a change naming a person deleted a moment ago is 404, and never runs', function () {
    $person = User::factory()->create();
    $person->delete();
    $ran = false;

    expect(fn () => app(OrganizationTeam::class)->changeTeam($this->alpha, function () use (&$ran) {
        $ran = true;
    }, naming: $person))->toThrow(ModelNotFoundException::class);

    expect($ran)->toBeFalse();
});

test('an inviter deleted from the platform as the invitation is written is 404, never a 500 from its foreign key', function (string $door) {
    $inviter = $door === 'the Members page' ? createOrganizationMember($this->alpha, Role::OWNER) : createSuperAdmin();

    // The platform's Users page deletes the account, and commits, as this change waits for the inviter's row. Only the row:
    // on MySQL its deletion hook is statements of its own, committed apart from this one.
    $landed = beforeTheChangesFirstLock(fn () => DB::table('users')->where('id', $inviter->id)->delete());

    writeAnInvitation($this, $door, $inviter, $this->alpha)
        ->assertNotFound()
        ->assertJsonPath('message', 'This is no longer here: it may have been deleted, or changed in another tab. Reload the page to see what is there now.');

    // Played inside this request's transaction, which the 404 rolled back with it; on MySQL it is committed apart.
    expect($landed->done)->toBeTrue()
        ->and(Invitation::where('email', 'new@example.com')->exists())->toBeFalse()
        ->and(ActivityLog::whereIn('action', ['member.invited', 'platform.invited', 'organization.owner_invited'])->exists())->toBeFalse();
    Notification::assertNothingSent();
})->with(['the Members page', 'the platform team', 'Invite Owner']);

test('a person deleted from the platform as they are put in an organization is still 404, never a membership of nobody', function () {
    $person = User::factory()->create();
    $landed = beforeTheChangesFirstLock(fn () => DB::table('users')->where('id', $person->id)->delete());

    $this->actingAs(createSuperAdmin())
        ->postJson("/users/{$person->id}/organizations", ['organization_id' => $this->alpha->id, 'role_id' => Role::starter(Role::STAFF)->id])
        ->assertNotFound();

    expect($landed->done)->toBeTrue()
        ->and(DB::table('organization_user')->where('organization_id', $this->alpha->id)->exists())->toBeFalse()
        ->and(ActivityLog::where('action', 'member.assigned')->exists())->toBeFalse();
});

test('every invitation names its inviter’s row before any role, and before the invitations Invite Owner retires', function (string $door) {
    $inviter = $door === 'the Members page' ? createOrganizationMember($this->alpha, Role::OWNER) : createSuperAdmin();

    if ($door === 'Invite Owner') {
        // An Owner invitation to another address, sent before: retired (deleted) under the lock as this one is written.
        Invitation::open($this->alpha, 'earlier@example.com', Role::owner(), $inviter);
    }

    $log = TransactionStatements::record();

    writeAnInvitation($this, $door, $inviter, $this->alpha)->assertSuccessful();

    $statements = $log->of('/^insert into "invitations"/');
    $first = fn (string $pattern) => array_key_first(preg_grep($pattern, $statements));

    $inviterRow = $first('/^select "id" from "users" where "users"."id" = \? limit 1 \/\* for share \*\/$/');

    expect($inviterRow)->not->toBeNull()
        ->and($inviterRow)->toBeLessThan($first('/^insert into "invitations"/'));

    if ($door !== 'Invite Owner') {
        expect($inviterRow)->toBeLessThan($first('/^select "id" from "roles"/'));
    } else {
        expect($inviterRow)->toBeLessThan($first('/^delete from "invitations"/'))
            ->and(Invitation::where('email', 'earlier@example.com')->exists())->toBeFalse();
    }
})->with(['the Members page', 'the platform team', 'Invite Owner']);
