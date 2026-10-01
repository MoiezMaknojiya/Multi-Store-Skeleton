<?php

use App\Models\ActivityLog;
use App\Models\Media;
use App\Models\Organization;
use App\Models\Role;
use App\Models\User;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| An account never confirmed goes after a week, with its empty organization
|--------------------------------------------------------------------------
|
| Owner's rule, 2026-09-29 ("delete after 7 days"): a signup that never opens its link is removed by the daily
| accounts:prune-unverified, with the organization it made — which it could never fill, since an unconfirmed account can
| do nothing that uses the server's space. An organization somebody above the organizations put people or things in is left for
| a person to decide.
|
*/

/** An account that signed up $days days ago with its organization, and never confirmed. */
function signedUpDaysAgo(int $days, string $email = 'spam@example.com'): User
{
    $organization = Organization::factory()->create(['name' => "Organization of {$email}"]);
    $user = User::factory()->unverified()->create(['email' => $email, 'created_at' => now()->subDays($days)]);
    $user->organizations()->attach($organization->id, ['role_id' => Role::owner()->id]);

    return $user;
}

test('an account never confirmed is removed after seven days, with its organization', function () {
    $user = signedUpDaysAgo(8);
    $organizationId = $user->organizations()->value('organizations.id');

    $this->artisan('accounts:prune-unverified')->expectsOutputToContain('1 account(s) removed.')->assertSuccessful();

    expect(User::find($user->id))->toBeNull()
        ->and(Organization::find($organizationId))->toBeNull()
        ->and(DB::table('organization_user')->where('user_id', $user->id)->exists())->toBeFalse();

    $entry = ActivityLog::where('action', 'account.pruned')->sole();
    expect($entry->organization_id)->toBe($organizationId)
        ->and($entry->description)->toBe('Removed spam@example.com, never confirmed within 7 days, with the organization Organization of spam@example.com');
});

test('a week is counted to the second; a confirmed account, or a newer one, is never touched', function () {
    $almost = signedUpDaysAgo(6, 'almost@example.com');
    $this->travelTo(now());
    $exactly = User::factory()->unverified()->create(['email' => 'exact@example.com', 'created_at' => now()->subDays(User::UNVERIFIED_DAYS)]);
    $confirmed = signedUpDaysAgo(90, 'confirmed@example.com');
    $confirmed->markEmailAsVerified();

    $this->artisan('accounts:prune-unverified')->assertSuccessful();

    expect(User::find($almost->id))->not->toBeNull()
        ->and(User::find($exactly->id))->toBeNull()
        ->and(User::find($confirmed->id))->not->toBeNull();
});

test('an organization somebody put people or files in is kept, with the account, and the run says why', function () {
    $withMember = signedUpDaysAgo(10, 'shared@example.com');
    $sharedOrganization = $withMember->organizations()->first();
    createOrganizationMember($sharedOrganization, Role::OWNER);

    $withFile = signedUpDaysAgo(10, 'files@example.com');
    Media::factory()->create(['organization_id' => $withFile->organizations()->value('organizations.id')]);

    $this->artisan('accounts:prune-unverified')
        ->expectsOutputToContain('Kept shared@example.com: an organization of theirs has other people in it.')
        ->expectsOutputToContain('Kept files@example.com: an organization of theirs is not empty (media).')
        ->expectsOutputToContain('0 account(s) removed. 2 kept')
        ->assertSuccessful();

    expect(User::find($withMember->id))->not->toBeNull()
        ->and(User::find($withFile->id))->not->toBeNull()
        ->and(Organization::find($sharedOrganization->id))->not->toBeNull();
});

test('many at once are all removed, however the walk pages', function () {
    foreach (range(1, 5) as $n) {
        signedUpDaysAgo(8, "spam{$n}@example.com");
    }

    $this->artisan('accounts:prune-unverified')->expectsOutputToContain('5 account(s) removed.')->assertSuccessful();

    expect(User::whereNull('email_verified_at')->count())->toBe(0)
        ->and(Organization::count())->toBe(0);
});

test('it runs every night', function () {
    $event = collect(app(Schedule::class)->events())
        ->first(fn (Event $event) => str_contains((string) $event->command, 'accounts:prune-unverified'));

    expect($event)->not->toBeNull()
        ->and($event->expression)->toBe('15 3 * * *')
        ->and($event->withoutOverlapping)->toBeTrue();
});
