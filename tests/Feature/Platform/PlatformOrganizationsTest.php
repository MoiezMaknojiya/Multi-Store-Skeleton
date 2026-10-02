<?php

use App\Models\ActivityLog;
use App\Models\Invitation;
use App\Models\Organization;
use App\Models\Role;
use App\Models\Screen;
use App\Models\User;
use App\Notifications\InvitationNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

/*
|--------------------------------------------------------------------------
| Every organization, from the platform's side (docs/ORGANIZATION-SPEC.md rule 18)
|--------------------------------------------------------------------------
*/

beforeEach(function () {
    Notification::fake();

    $this->admin = createSuperAdmin(['organization-view', 'organization-store', 'organization-update', 'organization-destroy']);
});

function organizationFormPayload(array $overrides = []): array
{
    return [
        'name' => 'Gamma Grocers', 'street' => '9 Elm St', 'suite' => null, 'city' => 'Houston',
        'state' => 'TX', 'zip_code' => '77001', 'country' => 'USA', 'is_active' => true, ...$overrides,
    ];
}

test('the platform sees every organization with its members — and is offered Invite owner only where there is no Owner', function () {
    $owned = Organization::factory()->create(['name' => 'Alpha Mart']);
    createOrganizationMember($owned, Role::OWNER, ['first_name' => 'Olive', 'last_name' => 'Owner']);
    createOrganizationMember($owned, Role::STAFF);
    $orphan = Organization::factory()->create(['name' => 'Beta Deli']);
    createOrganizationMember($orphan, Role::STAFF);
    Organization::factory()->create(['name' => 'Gamma Grill']);

    $rows = collect($this->actingAs($this->admin)->getJson('/organizations/data')->assertOk()->json('organizations'))->keyBy('name');

    // No owners on the list: an organization may have several Owners — partners. Invite owner is for an organization with none
    // (owner's rule, 2026-09-17); more Owners come from the organization's own Members page, or Users → Organizations.
    expect($rows['Alpha Mart']['members_count'])->toBe(2)
        ->and($rows['Alpha Mart'])->not->toHaveKey('owners')->not->toHaveKey('owner_invitation')
        ->and($rows['Alpha Mart']['can'])->toBe(['update' => true, 'destroy' => true, 'invite_owner' => false])
        ->and($rows['Beta Deli']['can']['invite_owner'])->toBeTrue()
        ->and($rows['Gamma Grill']['can']['invite_owner'])->toBeTrue();

    // Without organization-store nobody is offered it at all.
    $support = createPlatformUser(['organization-view']);
    expect(collect($this->actingAs($support)->getJson('/organizations/data')->json('organizations'))->pluck('can.invite_owner')->unique()->all())->toBe([false]);
});

test('the Organizations page is the platform\'s — an organization member, even an Owner holding every organization permission, works in Settings → Organizations', function () {
    $organization = Organization::factory()->create(['name' => 'Own Organization']);
    $owner = createOrganizationMember($organization, Role::OWNER);
    Role::owner()->permissions()->syncWithoutDetaching(grantPermissions(['organization-view', 'organization-store', 'organization-update', 'organization-destroy'])->pluck('id'));

    $this->actingAs($owner)->withSession(['current_organization_id' => $organization->id]);

    $this->get('/organizations')->assertForbidden();
    $this->getJson('/organizations/data')->assertForbidden();
    $this->postJson('/organizations', organizationFormPayload())->assertForbidden();
    $this->putJson("/organizations/{$organization->id}", organizationFormPayload(['name' => 'Renamed']))->assertForbidden();
    $this->deleteJson("/organizations/{$organization->id}", ['confirm_name' => 'Own Organization', 'password' => 'password'])->assertForbidden();
    $this->postJson("/organizations/{$organization->id}/owner-invitation", ['email' => 'x@example.com'])->assertForbidden();

    expect($organization->fresh()->name)->toBe('Own Organization')
        ->and(Organization::count())->toBe(1);
});

test('creating an organization sends its owner an invitation and gives nobody access yet', function () {
    $this->actingAs($this->admin)->postJson('/organizations', [...organizationFormPayload(), 'owner_email' => 'Gina@Example.com'])
        ->assertCreated();

    $organization = Organization::where('name', 'Gamma Grocers')->firstOrFail();
    expect(DB::table('organization_user')->where('organization_id', $organization->id)->count())->toBe(0);

    $invitation = Invitation::sole();
    expect($invitation->organization_id)->toBe($organization->id)
        ->and($invitation->email)->toBe('gina@example.com')
        ->and($invitation->role_id)->toBe(Role::starter(Role::OWNER)->id);

    Notification::assertSentOnDemand(InvitationNotification::class);
    expect(ActivityLog::where('action', 'organization.created')->exists())->toBeTrue();
});

test('a platform account cannot be made an organization owner', function () {
    $ops = createPlatformUser(['user-view']);

    $this->actingAs($this->admin)->postJson('/organizations', [...organizationFormPayload(), 'owner_email' => $ops->email])
        ->assertStatus(422)->assertJsonValidationErrors('owner_email');

    expect(Organization::where('name', 'Gamma Grocers')->exists())->toBeFalse();
});

test('giving an organization an owner: anybody is invited, a member is made Owner at once — and then the organization has one', function () {
    $organization = Organization::factory()->create(['name' => 'Orphan Mart']);
    $staff = createOrganizationMember($organization, Role::STAFF);

    $this->actingAs($this->admin)->postJson("/organizations/{$organization->id}/owner-invitation", ['email' => 'partner@example.com'])
        ->assertCreated();
    expect(Invitation::where('email', 'partner@example.com')->value('role_id'))->toBe(Role::starter(Role::OWNER)->id);

    // The member made Owner takes the organization's one way in as its Owner: the invitation to another address goes.
    $this->actingAs($this->admin)->postJson("/organizations/{$organization->id}/owner-invitation", ['email' => strtoupper($staff->email)])
        ->assertOk()
        ->assertJsonPath('message', "{$staff->name} is now an Owner of Orphan Mart. The earlier invitation for partner@example.com no longer works.");
    expect(roleKeyIn($staff, $organization))->toBe(Role::OWNER)
        ->and(Invitation::forOrganization($organization)->exists())->toBeFalse()
        ->and(ActivityLog::where('action', 'organization.owner_assigned')->value('description'))
        ->toBe("Made {$staff->name} ({$staff->email}) an Owner of Orphan Mart, replacing the invitation for partner@example.com");

    // It has an Owner now: more come from its own Members page, or Users → Organizations.
    foreach ([$staff->email, 'another@example.com'] as $email) {
        $this->actingAs($this->admin)->postJson("/organizations/{$organization->id}/owner-invitation", ['email' => $email])
            ->assertStatus(422)->assertJsonValidationErrors(['email' => 'Orphan Mart already has an Owner.']);
    }
    expect(Invitation::forOrganization($organization)->exists())->toBeFalse();
});

test('the platform deletes an organization only with its name typed, and everything it owns goes — the organization itself too', function () {
    $organization = Organization::factory()->create(['name' => 'Doomed Deli']);
    $screen = Screen::factory()->create(['organization_id' => $organization->id]);
    $member = createOrganizationMember($organization, Role::OWNER);
    $cashier = Role::create(['name' => 'Doomed Cashier', 'organization_id' => $organization->id]);

    $this->actingAs($this->admin)->deleteJson("/organizations/{$organization->id}", ['confirm_name' => 'doomed deli'])
        ->assertStatus(422)->assertJsonValidationErrors('confirm_name');

    $this->actingAs($this->admin)->deleteJson("/organizations/{$organization->id}", ['confirm_name' => 'Doomed Deli', 'password' => 'password'])->assertOk();

    $this->assertDatabaseMissing('organizations', ['id' => $organization->id]);
    expect(Screen::find($screen->id))->toBeNull()
        ->and(Role::find($cashier->id))->toBeNull()
        ->and(User::find($member->id))->not->toBeNull()
        ->and(roleKeyIn($member, $organization))->toBeNull()
        ->and(ActivityLog::where('action', 'organization.deleted')->value('organization_id'))->toBe($organization->id);
});

test('the delete dialog picks out the name to type, and Alpine writes it as text', function () {
    // Owner, 2026-10-01: the name stands out in "Type … to confirm". x-text, never x-html: a name is never markup.
    $this->actingAs($this->admin)->get('/organizations')->assertOk()
        ->assertSee('Type <span class="confirm-name" x-text="selectedItem?.name" dusk="delete-organization-typed-name"></span> to confirm', false);
});

test('support holding only organization-view reads the list and changes nothing', function () {
    $support = createPlatformUser(['organization-view']);
    $organization = Organization::factory()->create();

    $this->actingAs($support)->getJson('/organizations/data')->assertOk();
    $this->actingAs($support)->postJson('/organizations', [...organizationFormPayload(), 'owner_email' => 'x@example.com'])->assertForbidden();
    $this->actingAs($support)->deleteJson("/organizations/{$organization->id}", ['confirm_name' => $organization->name])->assertForbidden();
});

test('switching organizations is for members, into their own organizations only', function () {
    [$mine, $theirs] = Organization::factory()->count(2)->create();
    $member = createOrganizationMember($mine, Role::STAFF);
    $member->organizations()->attach($theirs->id, ['role_id' => Role::starter(Role::VIEWER)->id]);
    $stranger = Organization::factory()->create();

    $this->actingAs($member)->post('/organizations/switch', ['organization_id' => $theirs->id])->assertRedirect(route('dashboard'));
    expect(session('current_organization_id'))->toBe($theirs->id);

    $this->actingAs($member)->post('/organizations/switch', ['organization_id' => $stranger->id])->assertForbidden();
    $this->actingAs($this->admin)->post('/organizations/switch', ['organization_id' => $mine->id])->assertForbidden();
});

test('inviting the same owner again sends a fresh link — even once the first one expired', function () {
    $organization = Organization::factory()->create(['name' => 'Late Larder']);

    $this->actingAs($this->admin)->postJson("/organizations/{$organization->id}/owner-invitation", ['email' => 'gina@example.com'])->assertCreated();
    $invitation = Invitation::forOrganization($organization)->sole();
    $invitation->update(['expires_at' => now()->subDay()]);
    $oldHash = $invitation->token_hash;

    $this->actingAs($this->admin)->postJson("/organizations/{$organization->id}/owner-invitation", ['email' => 'Gina@Example.com'])
        ->assertOk()
        ->assertJson(['email_sent' => true]);

    $renewed = Invitation::forOrganization($organization)->sole();
    expect($renewed->id)->toBe($invitation->id)
        ->and($renewed->token_hash)->not->toBe($oldHash)
        ->and($renewed->isExpired())->toBeFalse();
    Notification::assertSentOnDemandTimes(InvitationNotification::class, 2);
});

test('a different address replaces the earlier owner invitation, so a mistyped email stops working', function () {
    $organization = Organization::factory()->create(['name' => 'Typo Treats']);
    [$typo, $typoToken] = Invitation::open($organization, 'gina@exmaple.com', Role::starter(Role::OWNER), $this->admin);
    [$staffInvite] = Invitation::open($organization, 'sam@example.com', Role::starter(Role::STAFF), $this->admin);

    $this->actingAs($this->admin)->postJson("/organizations/{$organization->id}/owner-invitation", ['email' => 'gina@example.com'])
        ->assertCreated()
        ->assertJsonPath('message', 'An invitation to own Typo Treats was sent to gina@example.com. The earlier invitation for gina@exmaple.com no longer works.');

    expect(Invitation::find($typo->id))->toBeNull()
        ->and(Invitation::find($staffInvite->id))->not->toBeNull()   // only owner invitations are replaced
        ->and(Invitation::forOrganization($organization)->where('email', 'gina@example.com')->value('role_id'))->toBe(Role::starter(Role::OWNER)->id);

    auth()->logout();
    $this->get("/invitations/{$typoToken}")->assertViewIs('invitations.invalid');
});

test('an address the organization already invited to another role is made the owner invitation', function () {
    $organization = Organization::factory()->create();
    [$staffInvite] = Invitation::open($organization, 'sam@example.com', Role::starter(Role::STAFF), $this->admin);

    $this->actingAs($this->admin)->postJson("/organizations/{$organization->id}/owner-invitation", ['email' => 'sam@example.com'])->assertOk();

    expect(Invitation::forOrganization($organization)->sole()->id)->toBe($staffInvite->id)
        ->and($staffInvite->fresh()->role_id)->toBe(Role::starter(Role::OWNER)->id);
});

test('an organization that has an Owner is given none from here, and keeps the owner invitations its own people sent', function () {
    $organization = Organization::factory()->create(['name' => 'Busy Bakery']);
    $owner = createOrganizationMember($organization, Role::OWNER);
    [$coOwnerInvite] = Invitation::open($organization, 'partner@example.com', Role::starter(Role::OWNER), $owner);

    $this->actingAs($this->admin)->postJson("/organizations/{$organization->id}/owner-invitation", ['email' => 'second@example.com'])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['email' => 'Busy Bakery already has an Owner.']);

    expect(Invitation::find($coOwnerInvite->id))->not->toBeNull()
        ->and(Invitation::forOrganization($organization)->count())->toBe(1);
    Notification::assertNothingSent();
});
