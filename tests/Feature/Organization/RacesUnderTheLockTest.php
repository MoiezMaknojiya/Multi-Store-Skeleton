<?php

use App\Models\ActivityLog;
use App\Models\Invitation;
use App\Models\Media;
use App\Models\Organization;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\OrganizationTeam;
use Illuminate\Cache\Events\ForgettingKey;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\Support\LocksShownGrammar;
use Tests\Support\TransactionStatements;

/*
|--------------------------------------------------------------------------
| What lands while another request waits for a lock (the review of 2026-10-15)
|--------------------------------------------------------------------------
|
| A decision about a team, a library or a role is taken under a row lock, so that another request at the same moment
| is seen. On MySQL that holds only when the lock is the transaction's first read — REPEATABLE READ answers every plain
| read from the snapshot the first one took — or when what decides is read with a lock too; and a row found before
| the lock may be gone after it. One PHP process cannot race itself and SQLite has neither snapshots nor locks, so
| these tests read the order of the statements (TransactionStatements) and play the other request at the very moment
| it would commit: from a model or cache event, or from just before a statement runs.
|
*/

/** Run $competitor once, just before the first statement inside the app's transaction that matches $pattern. */
function justBeforeInTheTransaction(string $pattern, Closure $competitor): object
{
    $outside = DB::transactionLevel();
    $state = (object) ['done' => false];

    DB::connection()->beforeExecuting(function (string $sql) use ($pattern, $competitor, $outside, $state) {
        if (! $state->done && DB::transactionLevel() > $outside && preg_match($pattern, $sql) === 1) {
            $state->done = true;
            $competitor();
        }
    });

    return $state;
}

/** Run $competitor once, just before the first statement anywhere that matches $pattern. */
function justBeforeTheStatement(string $pattern, Closure $competitor): object
{
    $state = (object) ['done' => false];

    DB::connection()->beforeExecuting(function (string $sql) use ($pattern, $competitor, $state) {
        if (! $state->done && preg_match($pattern, $sql) === 1) {
            $state->done = true;
            $competitor();
        }
    });

    return $state;
}

/** Run $competitor once, the moment $user's password has been confirmed — between a big delete's checks and its transaction. */
function onceThePasswordIsConfirmed(User $user, Closure $competitor): object
{
    $state = (object) ['done' => false];

    Event::listen(ForgettingKey::class, function (ForgettingKey $event) use ($user, $competitor, $state) {
        if (! $state->done && $event->key === 'confirm-password:'.$user->id) {
            $state->done = true;
            $competitor();
        }
    });

    return $state;
}

beforeEach(function () {
    $this->alpha = Organization::factory()->create(['name' => 'Alpha Mart']);
});

/*
|--------------------------------------------------------------------------
| Two co-owners going at once
|--------------------------------------------------------------------------
*/

/** Every locking statement that touches a membership, of those given. */
function membershipLocks(array $statements): array
{
    return array_values(array_filter($statements, fn (string $statement) => str_contains($statement, '"organization_user"')
        && (TransactionStatements::locksForShare($statement) || TransactionStatements::locksForUpdate($statement))));
}

test('deleting your account locks every organization you are in — Owner or not — by id, then your own row, before it reads anything', function () {
    $beta = Organization::factory()->create(['name' => 'Beta Mart']);
    $person = createOrganizationMember($this->alpha, Role::OWNER);
    $beta->users()->attach($person->id, ['role_id' => Role::starter(Role::STAFF)->id]);
    createOrganizationMember($this->alpha, Role::OWNER);
    createOrganizationMember($beta, Role::OWNER);
    $log = TransactionStatements::record();

    $this->actingAs($person)->delete('/profile', ['password' => 'password'])->assertRedirect('/');

    $statements = $log->of('/^delete from "users"/');

    // Locks take no snapshot, so the plain reads after them see what committed while they waited. Beta is held although
    // they are only Staff there: made its Owner meanwhile, by an Owner who then steps down on the strength of it, they
    // would go unseen. Their own row keeps anybody from putting them in another organization meanwhile (a membership's
    // foreign key reads it). A lock on a membership — before its organization's row — would meet a change to that team
    // the other way round and deadlock. (An integer key's list is written into the statement: whereIntegerInRaw.)
    expect($statements[0])->toStartWith('select "id" from "organizations" where "organizations"."id" in ('.$this->alpha->id.', '.$beta->id.') order by "id" asc')
        ->and(TransactionStatements::locksForUpdate($statements[0]))->toBeTrue()
        ->and($statements[1])->toStartWith('select "id" from "users" where "users"."id" = ?')
        ->and(TransactionStatements::locksForUpdate($statements[1]))->toBeTrue()
        ->and(membershipLocks($statements))->toBe([])
        ->and(User::find($person->id))->toBeNull();
});

test('the Profile and Settings pages list your organizations without a lock on anybody’s membership', function (string $page) {
    $person = createOrganizationMember($this->alpha, Role::OWNER);
    $connection = DB::connection();
    $connection->setQueryGrammar(new LocksShownGrammar($connection));
    $statements = [];
    DB::listen(function (QueryExecuted $query) use (&$statements) {
        $statements[] = $query->sql;
    });

    $this->actingAs($person)->withSession(['current_organization_id' => $this->alpha->id])->get($page)->assertOk();

    expect(preg_grep('/from "organization_user"/', $statements))->not->toBeEmpty()
        ->and(membershipLocks($statements))->toBe([]);
})->with(['/profile', '/settings/organization']);

test('put in another organization while the delete waited, it looks again with that one locked too', function (string $as) {
    $beta = Organization::factory()->create(['name' => 'Beta Mart']);
    $person = createOrganizationMember($this->alpha, Role::OWNER);
    createOrganizationMember($this->alpha, Role::OWNER);
    $betaOwner = createOrganizationMember($beta, Role::OWNER);
    $log = TransactionStatements::record();

    // Beta's Owner puts them in — as Staff, or as an Owner and then steps down — as the delete is given Alpha's row.
    $landed = justBeforeInTheTransaction('/^select "id" from "organizations" where "organizations"\."id" in/', function () use ($beta, $person, $betaOwner, $as) {
        $beta->users()->attach($person->id, ['role_id' => Role::starter($as)->id]);

        if ($as === Role::OWNER) {
            DB::table('organization_user')->where('organization_id', $beta->id)->where('user_id', $betaOwner->id)
                ->update(['role_id' => Role::starter(Role::STAFF)->id]);
        }
    });

    $response = $this->actingAs($person)->from('/profile')->delete('/profile', ['password' => 'password']);

    $firstLook = $log->of('/^select "id" from "organizations" where "organizations"\."id" in \('.$this->alpha->id.'\) order by/');
    $secondLook = $log->of('/^select "id" from "organizations" where "organizations"\."id" in \('.$this->alpha->id.', '.$beta->id.'\) order by/');

    // The first look decides nothing: it sees Beta, which it does not hold, and starts again holding it.
    expect($landed->done)->toBeTrue()
        ->and($firstLook)->not->toBe([])
        ->and(preg_grep('/having count\(\*\) = 1|^delete from "users"/', $firstLook))->toBe([])
        ->and($secondLook[0])->toStartWith('select "id" from "organizations" where "organizations"."id" in ('.$this->alpha->id.', '.$beta->id.')')
        ->and(TransactionStatements::locksForUpdate($secondLook[0]))->toBeTrue();

    if ($as === Role::OWNER) {
        $response->assertRedirect('/profile')
            ->assertSessionHasErrorsIn('userDeletion', ['password' => 'You are the only Owner of Beta Mart. Make someone else an Owner of it, or delete that organization, first.']);

        expect(User::find($person->id))->not->toBeNull()
            ->and(app(OrganizationTeam::class)->ownerCount($beta))->toBe(1);
    } else {
        $response->assertRedirect('/');

        expect(User::find($person->id))->toBeNull()
            ->and(app(OrganizationTeam::class)->ownerCount($beta))->toBe(1);
    }
})->with([
    'as Staff' => Role::STAFF,
    'as an Owner, who then steps down' => Role::OWNER,
]);

test('an account the platform deletes while its person deletes it too is 404, never a 500', function () {
    $person = createOrganizationMember($this->alpha, Role::OWNER);
    createOrganizationMember($this->alpha, Role::OWNER);

    // The platform's Users page deletes the account, and commits, as this delete waits for the person's row.
    $landed = justBeforeInTheTransaction('/^select "id" from "users" where "users"\."id" = \?/', fn () => DB::table('users')->where('id', $person->id)->delete());

    $this->actingAs($person)->delete('/profile', ['password' => 'password'])->assertNotFound();

    // The platform's delete was played inside this request's transaction, which the 404 rolled back with it; on MySQL it
    // is committed apart. What counts: nothing was written for an account that is gone (a log line naming it was a 500).
    expect($landed->done)->toBeTrue()
        ->and(ActivityLog::where('action', 'account.deleted')->exists())->toBeFalse();
});

test('two co-owners deleting their accounts at once: the second is refused, and the organization keeps an Owner', function () {
    $first = createOrganizationMember($this->alpha, Role::OWNER);
    $second = createOrganizationMember($this->alpha, Role::OWNER);

    // The first co-owner's delete commits while the second confirms the password.
    $landed = onceThePasswordIsConfirmed($second, fn () => $first->delete());

    $this->actingAs($second)->from('/profile')->delete('/profile', ['password' => 'password'])
        ->assertRedirect('/profile')
        ->assertSessionHasErrorsIn('userDeletion', ['password' => 'You are the only Owner of Alpha Mart. Make someone else an Owner of it, or delete that organization, first.']);

    expect($landed->done)->toBeTrue()
        ->and(User::find($first->id))->toBeNull()
        ->and(User::find($second->id))->not->toBeNull()
        ->and(app(OrganizationTeam::class)->ownerCount($this->alpha))->toBe(1);
});

/*
|--------------------------------------------------------------------------
| An organization deleted while somebody works in it
|--------------------------------------------------------------------------
*/

test('deleting an organization locks its row before it reads or deletes anything', function () {
    $log = TransactionStatements::record();

    $this->actingAs(createSuperAdmin())->deleteJson("/organizations/{$this->alpha->id}", ['confirm_name' => 'Alpha Mart', 'password' => 'password'])
        ->assertOk();

    $statements = $log->of('/^delete from "organizations"/');

    expect($statements[0])->toStartWith('select "id" from "organizations" where "organizations"."id" = ?')
        ->and(TransactionStatements::locksForUpdate($statements[0]))->toBeTrue();
});

test('an upload that commits while the delete waits for the organization goes with it, file and all', function () {
    Storage::fake('public');
    $path = "media/{$this->alpha->id}/late.jpg";

    // The upload held the organization's row (OrganizationStorage::withRoom) and commits as the delete is given it — or, without
    // a lock of the delete's own, as the row itself is deleted.
    $landed = justBeforeInTheTransaction('/^(select "id" from "organizations" where "organizations"\."id" = \?|delete from "organizations")/', function () use ($path) {
        Storage::disk('public')->put($path, 'bytes');
        Media::factory()->create(['organization_id' => $this->alpha->id, 'path' => $path, 'thumbnail_path' => null]);
    });

    $this->actingAs(createSuperAdmin())->deleteJson("/organizations/{$this->alpha->id}", ['confirm_name' => 'Alpha Mart', 'password' => 'password'])
        ->assertOk();

    expect($landed->done)->toBeTrue()
        ->and(Media::where('path', $path)->exists())->toBeFalse();
    Storage::disk('public')->assertMissing($path);
});

test('an organization deleted in another tab a moment ago is 404, and is not deleted or logged twice', function () {
    $done = false;
    Organization::retrieved(function (Organization $found) use (&$done) {
        if (! $done && $found->id === $this->alpha->id) {
            $done = true;
            DB::transaction(fn () => Organization::whereKey($found->id)->first()->delete());
        }
    });

    $this->actingAs(createSuperAdmin())->deleteJson("/organizations/{$this->alpha->id}", ['confirm_name' => 'Alpha Mart', 'password' => 'password'])
        ->assertNotFound()
        ->assertJsonPath('message', 'This is no longer here: it may have been deleted, or changed in another tab. Reload the page to see what is there now.');

    expect($done)->toBeTrue()
        ->and(ActivityLog::where('action', 'organization.deleted')->exists())->toBeFalse();
});

test('a change to the team of an organization that is gone is 404, and never runs', function () {
    $gone = Organization::factory()->create();
    $gone->delete();
    $ran = false;

    expect(fn () => app(OrganizationTeam::class)->changeTeam($gone, function () use (&$ran) {
        $ran = true;
    }))->toThrow(ModelNotFoundException::class);

    expect($ran)->toBeFalse();
});

test('adding a person to an organization deleted a moment ago is 404, and leaves no membership of nothing', function () {
    $person = User::factory()->create();
    $staff = Role::starter(Role::STAFF);

    // The platform's Delete commits right after the Users page's request has found the organization.
    $done = false;
    Organization::retrieved(function (Organization $found) use (&$done) {
        if (! $done && $found->id === $this->alpha->id) {
            $done = true;
            DB::transaction(fn () => Organization::whereKey($found->id)->first()->delete());
        }
    });

    $superAdmin = createSuperAdmin();
    $this->actingAs($superAdmin)->postJson("/users/{$person->id}/organizations", ['organization_id' => $this->alpha->id, 'role_id' => $staff->id])
        ->assertNotFound();

    expect($done)->toBeTrue()
        ->and(DB::table('organization_user')->where('organization_id', $this->alpha->id)->exists())->toBeFalse()
        ->and(ActivityLog::where('action', 'member.assigned')->exists())->toBeFalse();

    // …so nothing invisible holds the role, and it can still be deleted.
    $this->actingAs($superAdmin)->deleteJson("/roles/{$staff->id}", ['password' => 'password'])->assertOk();
});

test('putting a person in an organization from the platform locks the organization, the person, then the role, and only then reads their tier', function () {
    $person = User::factory()->create();
    $log = TransactionStatements::record();

    $this->actingAs(createSuperAdmin())->postJson("/users/{$person->id}/organizations", ['organization_id' => $this->alpha->id, 'role_id' => Role::starter(Role::STAFF)->id])
        ->assertCreated();

    $statements = $log->of('/^insert into "organization_user"/');

    // The order every change to a team takes them — an account's deletion and the prune lock the organizations, then the
    // person — so none of them can wait for the other the other way round. The person's row is held shared: an invitation
    // being accepted holds it exclusively (InvitationResponseController::join), so one of the two waits for the other,
    // and the platform membership is read — the transaction's first plain read, its snapshot — after that wait. The role
    // after the person (the review of 2026-10-15): the accept holds the person while its membership's foreign key reads the
    // role, so a change holding the role while it waited for the person could close a circle with it.
    expect(array_slice($statements, 0, 4))->sequence(
        fn ($statement) => $statement->toStartWith('select "id" from "organizations" where "organizations"."id" = ?')->toEndWith('/* for update */'),
        fn ($statement) => $statement->toStartWith('select "id" from "users" where "users"."id" = ?')->toEndWith('/* for share */'),
        fn ($statement) => $statement->toStartWith('select "id" from "roles" where "roles"."id" = ?')->toEndWith('/* for share */'),
        fn ($statement) => $statement->toContain('from "organization_user" where "user_id" = ? and "organization_id" = ?'),
    );
    expect(roleKeyIn($person, $this->alpha))->toBe(Role::STAFF);
});

/*
|--------------------------------------------------------------------------
| A role deleted while somebody is given it
|--------------------------------------------------------------------------
*/

test('a role given while its delete was being confirmed is seen under the lock: the delete is refused and the person keeps it', function () {
    $cashier = Role::create(['name' => 'Cashier', 'organization_id' => $this->alpha->id]);
    $cashier->permissions()->sync(Permission::where('name', 'screen-view')->pluck('id'));
    $person = User::factory()->create();
    $superAdmin = createSuperAdmin();

    // Members → Change Role, or an invitation accepted, commits while the super admin confirms the password.
    $landed = onceThePasswordIsConfirmed($superAdmin, fn () => DB::table('organization_user')->insert([
        'user_id' => $person->id, 'organization_id' => $this->alpha->id, 'role_id' => $cashier->id,
        'created_at' => now(), 'updated_at' => now(),
    ]));

    $this->actingAs($superAdmin)->deleteJson("/roles/{$cashier->id}", ['password' => 'password'])
        ->assertStatus(422)
        ->assertJsonPath('message', 'Please unassign Cashier from everyone first: 1 person still holds it.');

    expect($landed->done)->toBeTrue()
        ->and(Role::find($cashier->id))->not->toBeNull()
        ->and(DB::table('organization_user')->where('user_id', $person->id)->value('role_id'))->toBe($cashier->id)
        ->and(ActivityLog::where('action', 'role.deleted')->exists())->toBeFalse();
});

test('deleting a role locks its organization, the invitations to it and the role, then counts its holders again under a lock', function () {
    $cashier = Role::create(['name' => 'Cashier', 'organization_id' => $this->alpha->id]);
    [$invitation] = Invitation::open($this->alpha, 'new.cashier@example.com', $cashier, createOrganizationMember($this->alpha, Role::OWNER));
    $log = TransactionStatements::record();

    $this->actingAs(createSuperAdmin())->deleteJson("/roles/{$cashier->id}", ['password' => 'password'])
        ->assertOk()->assertJsonPath('message', 'Role Cashier deleted. 1 pending invitation to it was revoked.');

    $statements = $log->of('/^delete from "roles"/');

    // The invitations by id, never as a range of the role's index: an invitation being sent holds the role (shared)
    // before it adds its row there, and a range held while waiting for the role would close a circle with it. (An
    // integer key's list is written into the statement, not bound: whereKey() → whereIntegerInRaw().)
    expect(array_slice($statements, 0, 5))->sequence(
        fn ($statement) => $statement->toStartWith('select "id" from "organizations" where "organizations"."id" = ?')->toEndWith('/* for update */'),
        fn ($statement) => $statement->toBe('select "id" from "invitations" where "role_id" = ?'),
        fn ($statement) => $statement->toStartWith('select "id" from "invitations" where "invitations"."id" in ('.$invitation->id.') order by "id" asc')->toEndWith('/* for update */'),
        fn ($statement) => $statement->toStartWith('select "id" from "roles" where "roles"."id" = ?')->toEndWith('/* for update */'),
        fn ($statement) => $statement->toStartWith('select count(*) as "aggregate" from "organization_user" where "role_id" = ?')->toEndWith('/* for share */'),
    );
});

test('an invitation sent while its role is being deleted is revoked with it, and said so', function () {
    Notification::fake();
    $cashier = Role::create(['name' => 'Cashier', 'organization_id' => $this->alpha->id]);
    $owner = createOrganizationMember($this->alpha, Role::OWNER);

    // Members → Invite commits while the delete waits for the role (it holds the role, shared, until then).
    $landed = justBeforeInTheTransaction('/^select "id" from "roles" where "roles"\."id" = \?/', fn () => Invitation::open($this->alpha, 'late@example.com', $cashier, $owner));

    $this->actingAs(createSuperAdmin())->deleteJson("/roles/{$cashier->id}", ['password' => 'password'])
        ->assertOk()->assertJsonPath('message', 'Role Cashier deleted. 1 pending invitation to it was revoked.');

    expect($landed->done)->toBeTrue()
        ->and(Invitation::where('email', 'late@example.com')->exists())->toBeFalse();
});

/*
|--------------------------------------------------------------------------
| An invitation to a role, or a link renewed, as it goes
|--------------------------------------------------------------------------
*/

test('an invitation is written under the organization’s lock, the inviter’s, then the role’s', function (string $door) {
    Notification::fake();
    $owner = createOrganizationMember($this->alpha, Role::OWNER);
    $support = Role::create(['name' => 'Support', 'is_global' => true]);
    $superAdmin = createSuperAdmin();
    $log = TransactionStatements::record();

    (match ($door) {
        'members page' => $this->actingAs($owner)->withSession(['current_organization_id' => $this->alpha->id])
            ->postJson('/members/invitations', ['email' => 'new@example.com', 'role_id' => Role::starter(Role::STAFF)->id]),
        'platform team' => $this->actingAs($superAdmin)->postJson('/users/invitations', ['email' => 'new@example.com', 'role_id' => $support->id]),
    })->assertCreated();

    $statements = $log->of('/^insert into "invitations"/');
    $locks = array_values(array_filter($statements, fn (string $statement) => TransactionStatements::locksForShare($statement) || TransactionStatements::locksForUpdate($statement)));

    expect($locks)->toHaveCount($door === 'members page' ? 3 : 2)
        ->and($statements[0])->toBe($locks[0]);

    if ($door === 'members page') {
        expect(array_shift($locks))->toStartWith('select "id" from "organizations" where "organizations"."id" = ?')->toEndWith('/* for update */');
    }

    // The inviter before the role (the review of 2026-10-15): the invitation's foreign key reads the inviter's row, and an
    // invitation being accepted — or an account being deleted — holds that row while it waits for a role.
    expect($locks)->sequence(
        fn ($statement) => $statement->toStartWith('select "id" from "users" where "users"."id" = ?')->toEndWith('/* for share */'),
        fn ($statement) => $statement->toStartWith('select "id" from "roles" where "roles"."id" = ?')->toEndWith('/* for share */'),
    );
})->with(['members page', 'platform team']);

test('an invitation to a role deleted a moment ago is refused in words, never a 500 nor an invitation to nothing', function (string $door) {
    Notification::fake();
    $cashier = Role::create(['name' => 'Cashier', 'organization_id' => $this->alpha->id]);
    $cashier->permissions()->sync(Permission::where('name', 'screen-view')->pluck('id'));
    $support = Role::create(['name' => 'Support', 'is_global' => true]);
    $owner = createOrganizationMember($this->alpha, Role::OWNER);
    $superAdmin = createSuperAdmin();
    $role = $door === 'members page' ? $cashier : $support;

    // RoleController::destroy commits while this request checks the email, after it found the role and its reach.
    $landed = justBeforeTheStatement('/^select exists ?\(select \* from "invitations"/', fn () => Role::whereKey($role->id)->delete());

    $response = match ($door) {
        'members page' => $this->actingAs($owner)->withSession(['current_organization_id' => $this->alpha->id])
            ->postJson('/members/invitations', ['email' => 'new@example.com', 'role_id' => $cashier->id]),
        'platform team' => $this->actingAs($superAdmin)->postJson('/users/invitations', ['email' => 'new@example.com', 'role_id' => $support->id]),
    };

    $response->assertStatus(422)->assertJsonValidationErrors([
        'role_id' => $door === 'members page' ? 'Choose a role that exists in this organization.' : 'Choose a platform role.',
    ]);

    expect($landed->done)->toBeTrue()
        ->and(Role::find($role->id))->toBeNull()
        ->and(Invitation::exists())->toBeFalse()
        ->and(ActivityLog::whereIn('action', ['member.invited', 'platform.invited'])->exists())->toBeFalse();
    Notification::assertNothingSent();
})->with(['members page', 'platform team']);

test('resending an invitation revoked a moment ago is 404, never a 500', function (string $door) {
    Notification::fake();
    $owner = createOrganizationMember($this->alpha, Role::OWNER);
    $superAdmin = createSuperAdmin();
    [$invitation] = $door === 'members page'
        ? Invitation::open($this->alpha, 'new@example.com', Role::starter(Role::STAFF), $owner)
        : Invitation::open(null, 'new@example.com', Role::create(['name' => 'Support', 'is_global' => true]), $superAdmin);

    // Revoked in another tab — or taken with its role — right after this request found it.
    $done = false;
    Invitation::retrieved(function (Invitation $found) use (&$done, $invitation) {
        if (! $done && $found->id === $invitation->id) {
            $done = true;
            DB::table('invitations')->where('id', $found->id)->delete();
        }
    });

    $response = match ($door) {
        'members page' => $this->actingAs($owner)->withSession(['current_organization_id' => $this->alpha->id])
            ->postJson("/members/invitations/{$invitation->id}/resend"),
        'platform team' => $this->actingAs($superAdmin)->postJson("/users/invitations/{$invitation->id}/resend"),
    };

    $response->assertNotFound()
        ->assertJsonPath('message', 'This is no longer here: it may have been deleted, or changed in another tab. Reload the page to see what is there now.');

    expect($done)->toBeTrue()
        ->and(ActivityLog::where('action', 'invitation.resent')->exists())->toBeFalse();
    Notification::assertNothingSent();
})->with(['members page', 'platform team']);

test('giving a role reads it again under a shared lock, after the organization row', function () {
    $owner = createOrganizationMember($this->alpha, Role::OWNER);
    $staff = createOrganizationMember($this->alpha, Role::STAFF);
    $log = TransactionStatements::record();

    $this->actingAs($owner)->withSession(['current_organization_id' => $this->alpha->id])
        ->putJson("/members/{$staff->id}", ['role_id' => Role::starter(Role::ADMIN)->id])
        ->assertOk();

    $statements = $log->of('/^update "organization_user"/');

    expect(array_slice($statements, 0, 2))->sequence(
        fn ($statement) => $statement->toStartWith('select "id" from "organizations" where "organizations"."id" = ?')->toEndWith('/* for update */'),
        fn ($statement) => $statement->toStartWith('select "id" from "roles" where "roles"."id" = ?')->toEndWith('/* for share */'),
    );
});

test('a role deleted a moment before it is given is refused in words, never a 500 nor a membership of nothing', function (string $door) {
    $cashier = Role::create(['name' => 'Cashier', 'organization_id' => $this->alpha->id]);
    $cashier->permissions()->sync(Permission::where('name', 'screen-view')->pluck('id'));
    $owner = createOrganizationMember($this->alpha, Role::OWNER);
    $member = createOrganizationMember($this->alpha, Role::STAFF);
    $newcomer = User::factory()->create();

    // RoleController::destroy commits right after this request found the role.
    $done = false;
    Role::retrieved(function (Role $found) use (&$done, $cashier) {
        if (! $done && $found->id === $cashier->id) {
            $done = true;
            Role::whereKey($found->id)->delete();
        }
    });

    $response = match ($door) {
        'members page' => $this->actingAs($owner)->withSession(['current_organization_id' => $this->alpha->id])
            ->putJson("/members/{$member->id}", ['role_id' => $cashier->id]),
        'platform: add to organization' => $this->actingAs(createSuperAdmin())
            ->postJson("/users/{$newcomer->id}/organizations", ['organization_id' => $this->alpha->id, 'role_id' => $cashier->id]),
        'platform: change role' => $this->actingAs(createSuperAdmin())
            ->putJson("/users/{$member->id}/organizations/{$this->alpha->id}/role", ['role_id' => $cashier->id]),
    };

    $response->assertStatus(422)->assertJsonValidationErrors(['role_id' => 'Choose a role that exists in this organization.']);

    expect($done)->toBeTrue()
        ->and(DB::table('organization_user')->where('role_id', $cashier->id)->exists())->toBeFalse()
        ->and(roleKeyIn($member, $this->alpha))->toBe(Role::STAFF)
        ->and(DB::table('organization_user')->where('user_id', $newcomer->id)->exists())->toBeFalse();
})->with(['members page', 'platform: add to organization', 'platform: change role']);
