<?php

use App\Models\ActivityLog;
use App\Models\Invitation;
use App\Models\Organization;
use App\Models\Role;
use App\Services\OrganizationTeam;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Tests\Support\TransactionStatements;

/*
|--------------------------------------------------------------------------
| Invite Owner as the organization changes (the review of 2026-10-15, L6)
|--------------------------------------------------------------------------
|
| The platform's Invite Owner made a member Owner, or wrote an Owner invitation, with nothing held: an organization deleted
| after its address had found it failed the invitations' foreign key — a 500 — and an Owner given meanwhile on its Members
| page went unseen. It now decides under the organization's lock (OrganizationTeam::changeTeam), the transaction's first
| read, which answers 404 when the row is gone. SQLite has neither row locks nor snapshots, so these tests read the order of
| the statements (TransactionStatements) and play the other request at the moment this one would wait for it.
|
*/

beforeEach(function () {
    Notification::fake();
    $this->orphan = Organization::factory()->create(['name' => 'Orphan Mart']);
    $this->admin = createSuperAdmin();
});

test('an organization deleted after its address found it is 404, never a 500 nor an Owner of nothing', function (string $who) {
    $email = match ($who) {
        'a new address' => 'gina@example.com',
        'an address invited before' => Invitation::open($this->orphan, 'sam@example.com', Role::starter(Role::STAFF), $this->admin)[0]->email,
        'one of its members' => createOrganizationMember($this->orphan, Role::STAFF)->email,
    };

    // The platform's Delete, in another tab, commits right after this request's address found the organization.
    $done = false;
    Organization::retrieved(function (Organization $found) use (&$done) {
        if (! $done && $found->id === $this->orphan->id) {
            $done = true;
            DB::transaction(fn () => Organization::whereKey($found->id)->first()->delete());
        }
    });

    $this->actingAs($this->admin)->postJson("/organizations/{$this->orphan->id}/owner-invitation", ['email' => $email])
        ->assertNotFound()
        ->assertJsonPath('message', 'This is no longer here: it may have been deleted, or changed in another tab. Reload the page to see what is there now.');

    expect($done)->toBeTrue()
        ->and(Invitation::where('organization_id', $this->orphan->id)->exists())->toBeFalse()
        ->and(DB::table('organization_user')->where('organization_id', $this->orphan->id)->exists())->toBeFalse()
        ->and(ActivityLog::whereIn('action', ['organization.owner_invited', 'organization.owner_assigned'])->exists())->toBeFalse();
    Notification::assertNothingSent();
})->with(['a new address', 'an address invited before', 'one of its members']);

test('an Owner given while the platform waited for the organization is seen: refused, and no second way in', function (string $who) {
    $email = $who === 'a new address' ? 'gina@example.com' : createOrganizationMember($this->orphan, Role::STAFF)->email;
    $outside = DB::transactionLevel();
    $landed = (object) ['done' => false];

    // Its Members page — or Users → Organizations — makes somebody its Owner, and commits, as this request is given the row.
    DB::connection()->beforeExecuting(function (string $sql) use ($outside, $landed) {
        if (! $landed->done && DB::transactionLevel() > $outside && str_starts_with($sql, 'select "id" from "organizations"')) {
            $landed->done = true;
            createOrganizationMember($this->orphan, Role::OWNER, ['email' => 'partner@example.com']);
        }
    });

    $this->actingAs($this->admin)->postJson("/organizations/{$this->orphan->id}/owner-invitation", ['email' => $email])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['email' => 'Orphan Mart already has an Owner.']);

    // The Owner was played inside this request's transaction, which the refusal rolled back with it; on MySQL it is committed
    // apart. What counts: no invitation was written, and nobody else was made Owner beside them.
    expect($landed->done)->toBeTrue()
        ->and(Invitation::where('organization_id', $this->orphan->id)->exists())->toBeFalse()
        ->and(app(OrganizationTeam::class)->ownerCount($this->orphan))->toBe(0)
        ->and(ActivityLog::whereIn('action', ['organization.owner_invited', 'organization.owner_assigned'])->exists())->toBeFalse();
    Notification::assertNothingSent();
})->with(['a new address', 'one of its members']);

test('Invite Owner locks the organization, then the super admin’s own row, before it reads anything', function (string $who) {
    $email = $who === 'a new address' ? 'gina@example.com' : ($member = createOrganizationMember($this->orphan, Role::STAFF))->email;
    $log = TransactionStatements::record();

    $this->actingAs($this->admin)->postJson("/organizations/{$this->orphan->id}/owner-invitation", ['email' => $email])
        ->assertSuccessful();

    $statements = $log->of($who === 'a new address' ? '/^insert into "invitations"/' : '/^update "organization_user"/');

    // The order every change to a team takes (OrganizationTeam::changeTeam), and Organization::purgeContents too: a lock
    // takes no snapshot, so the Owner count read after it sees what committed while it waited. The inviter's row next, whom
    // an invitation names, before the invitations retired and the Owner role its foreign key reads (the review of 2026-10-15).
    expect(array_slice($statements, 0, 3))->sequence(
        fn ($statement) => $statement->toStartWith('select "id" from "organizations" where "organizations"."id" = ?')->toEndWith('/* for update */'),
        fn ($statement) => $statement->toStartWith('select "id" from "users" where "users"."id" = ?')->toEndWith('/* for share */'),
        fn ($statement) => $statement->toStartWith('select count(*) as "aggregate" from "organization_user" inner join "roles"'),
    );

    if ($who === 'a new address') {
        expect(Invitation::forOrganization($this->orphan)->sole()->role_id)->toBe(Role::owner()->id);
        Notification::assertCount(1);
    } else {
        expect(roleKeyIn($member, $this->orphan))->toBe(Role::OWNER);
    }
})->with(['a new address', 'one of its members']);
