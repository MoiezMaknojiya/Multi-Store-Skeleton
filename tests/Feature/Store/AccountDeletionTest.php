<?php

use App\Models\ActivityLog;
use App\Models\Channel;
use App\Models\Invitation;
use App\Models\Media;
use App\Models\Role;
use App\Models\Screen;
use App\Models\Store;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| Deleting an account — your own from Profile, or anybody's from the platform
| (docs/STORE-ORGANIZATION-SPEC.md rules 21 and 23)
|--------------------------------------------------------------------------
|
| An account is a login and nothing more. Deleting it takes the person's memberships and leaves
| nothing pointing at it — their sign-ins on every device, their password-reset link and the
| invitations waiting for their email go too — while everything they made stays with its store
| (`created_by` empties). Nothing cascades through the people they brought in.
|
*/

beforeEach(function () {
    $this->alpha = Store::factory()->create(['name' => 'Alpha Mart']);
    $this->beta = Store::factory()->create(['name' => 'Beta Deli']);
});

test('deleting your account takes your memberships, and only them', function () {
    $person = createStoreMember($this->alpha, Role::OWNER);
    $partner = createStoreMember($this->alpha, Role::OWNER);   // Alpha keeps an Owner
    $person->stores()->attach($this->beta->id, ['role_id' => Role::starter(Role::STAFF)->id]);
    $colleague = createStoreMember($this->beta, Role::OWNER);

    $upload = Media::factory()->create(['store_id' => $this->alpha->id, 'created_by' => $person->id]);
    $screen = Screen::factory()->create(['store_id' => $this->beta->id, 'created_by' => $person->id]);

    $this->actingAs($person)->delete('/profile', ['password' => 'password'])->assertRedirect('/');

    $this->assertGuest();
    expect(User::find($person->id))->toBeNull()
        ->and(DB::table('store_user')->where('user_id', $person->id)->count())->toBe(0);

    // The stores, their other people and their content all stay.
    expect(Store::find($this->alpha->id))->not->toBeNull()
        ->and(Store::find($this->beta->id))->not->toBeNull()
        ->and(User::find($partner->id))->not->toBeNull()
        ->and(User::find($colleague->id))->not->toBeNull()
        ->and(Media::find($upload->id)->created_by)->toBeNull()
        ->and(Screen::find($screen->id)->created_by)->toBeNull()
        ->and(ActivityLog::where('action', 'account.deleted')->exists())->toBeTrue();
});

test('the only Owner of a store must hand it on first', function () {
    $person = createStoreMember($this->alpha, Role::OWNER);
    $person->stores()->attach($this->beta->id, ['role_id' => Role::starter(Role::OWNER)->id]);
    createStoreMember($this->beta, Role::OWNER);   // Beta has another Owner; Alpha does not

    $this->actingAs($person)->from('/profile')->delete('/profile', ['password' => 'password'])
        ->assertRedirect('/profile')
        ->assertSessionHasErrorsIn('userDeletion', ['password' => 'You are the only Owner of Alpha Mart. Make someone else an Owner of it, or delete that store, first.']);

    expect(User::find($person->id))->not->toBeNull();
});

test('a person who invited others is deleted alone', function () {
    $owner = createStoreMember($this->alpha, Role::OWNER);
    $inviter = createStoreMember($this->alpha, Role::ADMIN);
    $invited = createStoreMember($this->alpha, Role::STAFF);

    $this->actingAs($inviter)->delete('/profile', ['password' => 'password'])
        ->assertSessionHasNoErrors()
        ->assertRedirect('/');

    expect(User::find($inviter->id))->toBeNull()
        ->and(User::find($invited->id))->not->toBeNull()
        ->and(roleKeyIn($invited, $this->alpha))->toBe(Role::STAFF)
        ->and(roleKeyIn($owner, $this->alpha))->toBe(Role::OWNER);
});

test('channels outlive the person who made them — they belong to the platform', function () {
    $maker = createPlatformUser(['channel-view'], 'Content');
    $channel = Channel::factory()->create(['name' => 'GAMA', 'created_by' => $maker->id]);

    $this->actingAs(createSuperAdmin(['user-view', 'user-destroy']))->deleteJson("/users/{$maker->id}", ['password' => 'password'])->assertOk();

    // Found first: a channel that had gone with its maker would read as "created by nobody" too.
    $kept = Channel::find($channel->id);

    expect(User::find($maker->id))->toBeNull()
        ->and($kept)->not->toBeNull()
        ->and($kept->created_by)->toBeNull();
});

test('a deleted account leaves nothing pointing at it: its sign-ins everywhere and its reset link go', function () {
    // A real installation keeps sessions in the database; the tests keep them in memory.
    config(['session.driver' => 'database']);

    $person = createStoreMember($this->alpha, Role::STAFF);
    $person->stores()->attach($this->beta->id, ['role_id' => Role::starter(Role::VIEWER)->id]);
    $other = User::factory()->create();
    $screen = Screen::factory()->create(['store_id' => $this->alpha->id, 'created_by' => $person->id]);

    foreach (['laptop' => $person, 'phone' => $person, 'someone-else' => $other] as $sessionId => $user) {
        DB::table('sessions')->insert(['id' => $sessionId, 'user_id' => $user->id, 'payload' => '', 'last_activity' => now()->timestamp]);
    }
    DB::table('password_reset_tokens')->insert(['email' => $person->email, 'token' => bcrypt('reset'), 'created_at' => now()]);

    $this->actingAs(createSuperAdmin(['user-view', 'user-destroy']))->deleteJson("/users/{$person->id}", ['password' => 'password'])->assertOk();

    expect(DB::table('store_user')->where('user_id', $person->id)->exists())->toBeFalse()
        ->and(DB::table('sessions')->where('user_id', $person->id)->exists())->toBeFalse()
        ->and(DB::table('sessions')->where('id', 'someone-else')->exists())->toBeTrue()
        ->and(DB::table('password_reset_tokens')->where('email', $person->email)->exists())->toBeFalse()
        // What they made stays with the store.
        ->and(Screen::find($screen->id)->created_by)->toBeNull();
});

test('deleting an account withdraws every invitation waiting for its email — to a store or the platform — and nobody else’s', function () {
    $admin = createSuperAdmin(['user-view', 'user-destroy']);
    $person = createStoreMember($this->alpha, Role::STAFF, ['email' => 'Sam.Person@Example.com']);
    $betaOwner = createStoreMember($this->beta, Role::OWNER);

    // Waiting for Sam (invitations keep the address lower-cased): one from Beta, one to the platform team.
    [$toBeta] = Invitation::open($this->beta, 'sam.person@example.com', Role::starter(Role::VIEWER), $betaOwner);
    [$toPlatform] = Invitation::open(null, 'SAM.PERSON@example.com', Role::create(['name' => 'Support', 'is_global' => true]), $admin);
    // Somebody else's invitation, and one Sam sent — the store's, not Sam's.
    [$someoneElse] = Invitation::open($this->beta, 'someone@example.com', Role::starter(Role::STAFF), $betaOwner);
    [$sentBySam] = Invitation::open($this->alpha, 'friend@example.com', Role::starter(Role::VIEWER), $person);

    $this->actingAs($admin)->deleteJson("/users/{$person->id}", ['password' => 'password'])->assertOk();

    expect(Invitation::find($toBeta->id))->toBeNull()
        ->and(Invitation::find($toPlatform->id))->toBeNull()
        ->and(Invitation::find($someoneElse->id))->not->toBeNull()
        ->and(Invitation::find($sentBySam->id))->not->toBeNull()
        ->and(Invitation::find($sentBySam->id)->invited_by)->toBeNull()
        ->and(ActivityLog::where('action', 'user.deleted')->latest('id')->value('description'))
        ->toBe("Deleted the account of {$person->name} (Sam.Person@Example.com) and the 2 pending invitations to that email");
});

test('deleting your own account withdraws the invitations waiting for your email too', function () {
    $person = createStoreMember($this->alpha, Role::STAFF);
    $betaOwner = createStoreMember($this->beta, Role::OWNER);
    [$toBeta] = Invitation::open($this->beta, $person->email, Role::starter(Role::VIEWER), $betaOwner);

    $this->actingAs($person)->delete('/profile', ['password' => 'password'])->assertRedirect('/');

    expect(Invitation::find($toBeta->id))->toBeNull()
        ->and(ActivityLog::where('action', 'account.deleted')->latest('id')->value('description'))
        ->toBe("Deleted their own account ({$person->email}) and the 1 pending invitation to that email");
});
