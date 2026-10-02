<?php

use App\Models\ActivityLog;
use App\Models\Channel;
use App\Models\ChannelAd;
use App\Models\Invitation;
use App\Models\Media;
use App\Models\Organization;
use App\Models\Permission;
use App\Models\PlaylistItem;
use App\Models\Role;
use App\Models\Screen;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/*
|--------------------------------------------------------------------------
| Settings → Organizations: the organization being worked in (docs/ORGANIZATION-SPEC.md rules 10, 11, 22 and 33)
|--------------------------------------------------------------------------
*/

beforeEach(function () {
    Storage::fake('public');

    $this->organization = Organization::factory()->create(['name' => 'Alpha Mart']);
    $this->owner = createOrganizationMember($this->organization, Role::OWNER);
    $this->admin = createOrganizationMember($this->organization, Role::ADMIN);
    $this->staff = createOrganizationMember($this->organization, Role::STAFF);
});

function settingsAs(User $user, Organization $organization)
{
    return test()->actingAs($user)->withSession(['current_organization_id' => $organization->id]);
}

function organizationDetails(array $overrides = []): array
{
    return [
        'name' => 'Alpha Mart Downtown', 'street' => '1 Main St', 'suite' => '', 'city' => 'Dallas',
        'state' => 'TX', 'zip_code' => '75001', 'country' => 'USA', ...$overrides,
    ];
}

/*
| Details
*/

test('a member with organization-update edits the details; Staff cannot even open the page', function () {
    settingsAs($this->admin, $this->organization)->get('/settings/organization')->assertOk()->assertSee('Organization Details')
        // A tab of Settings, beside the profile.
        ->assertSee('dusk="settings-tab-profile"', false)->assertSee('dusk="settings-tab-organization"', false);

    settingsAs($this->admin, $this->organization)->put('/settings/organization', organizationDetails())
        ->assertRedirect(route('organization-settings.edit'));
    expect($this->organization->fresh()->name)->toBe('Alpha Mart Downtown');

    settingsAs($this->staff, $this->organization)->get('/settings/organization')->assertForbidden();
    settingsAs($this->staff, $this->organization)->put('/settings/organization', organizationDetails(['name' => 'Hijacked']))->assertForbidden();
    expect($this->organization->fresh()->name)->toBe('Alpha Mart Downtown');
});

test('the details form cannot switch advertising on or change anything it does not show', function () {
    $this->organization->update(['created_by' => $this->owner->id]);

    // A save that goes through — so what it smuggled was ignored, not merely refused along with it.
    settingsAs($this->owner, $this->organization)->put('/settings/organization', organizationDetails([
        'accepts_network_ads' => true, 'is_active' => false, 'created_by' => $this->staff->id,
    ]))->assertSessionHasNoErrors()->assertRedirect(route('organization-settings.edit'));

    $organization = $this->organization->fresh();
    expect($organization->name)->toBe('Alpha Mart Downtown')
        ->and($organization->accepts_network_ads)->toBeFalse()
        ->and($organization->is_active)->toBeTrue()
        ->and($organization->created_by)->toBe($this->owner->id);
});

test('out of the box only an Owner is shown deleting the organization', function () {
    // Delete Organizations starts with the Owner role alone.
    settingsAs($this->owner, $this->organization)->get('/settings/organization')->assertSee('Delete Organization');
    settingsAs($this->admin, $this->organization)->get('/settings/organization')->assertDontSee('Delete Organization');
});

test('the name to type stands out in the sentence that asks for it, and is escaped like any other text', function () {
    // Owner, 2026-10-01: "Type Stiedemann, Reinger and Leffler to confirm" read as one run of words, the name lost in it.
    $this->organization->update(['name' => "O'Brien <b>Deli</b> & Co"]);

    settingsAs($this->owner, $this->organization)->get('/settings/organization')->assertOk()
        ->assertSee('Type <span class="confirm-name" dusk="confirm_name-typed-name">O&#039;Brien &lt;b&gt;Deli&lt;/b&gt; &amp; Co</span> to confirm', false)
        ->assertDontSee('<b>Deli</b>', false);
});

test('the organization’s settings are the Organizations tab of Settings; the sidebar does not list them', function () {
    settingsAs($this->owner, $this->organization)->get('/profile')->assertOk()
        ->assertSee('dusk="settings-tab-organization"', false)
        ->assertSee('href="'.route('organization-settings.edit').'"', false);
    settingsAs($this->owner, $this->organization)->get('/dashboard')->assertOk()
        ->assertDontSee('href="'.route('organization-settings.edit').'"', false)
        ->assertSee('dusk="sidebar-settings"', false);

    // Staff hold no View Organizations, so their Settings has the profile alone.
    settingsAs($this->staff, $this->organization)->get('/profile')->assertOk()->assertDontSee('dusk="settings-tab-organization"', false);
});

/*
| An organization changes hands on the Members page
*/

test('an organization changes hands on the Members page — Settings has no handover of its own', function () {
    // The Owner makes a member Owner; the new Owner may then change the first one's role (owner's rule, 2026-09-17).
    settingsAs($this->owner, $this->organization)->putJson("/members/{$this->staff->id}", ['role_id' => Role::owner()->id])->assertOk();
    settingsAs($this->staff, $this->organization)->putJson("/members/{$this->owner->id}", ['role_id' => Role::starter(Role::ADMIN)->id])->assertOk();

    expect(roleKeyIn($this->staff, $this->organization))->toBe(Role::OWNER)
        ->and(roleKeyIn($this->owner, $this->organization))->toBe(Role::ADMIN);

    settingsAs($this->staff, $this->organization)->get('/settings/organization')->assertOk()
        ->assertDontSee('Transfer Ownership')->assertDontSee('dusk="transfer-ownership"', false);
    settingsAs($this->staff, $this->organization)->post('/settings/organization/transfer-ownership', [
        'user_id' => $this->owner->id, 'role_id' => Role::starter(Role::STAFF)->id, 'password' => 'password',
    ])->assertNotFound();

    expect(Permission::where('name', 'organization-transfer')->exists())->toBeFalse()
        ->and(roleKeyIn($this->owner, $this->organization))->toBe(Role::ADMIN);
});

/*
| Delete the organization
*/

test('deleting needs organization-destroy (an Owner\'s out of the box), the exact organization name and the password', function () {
    settingsAs($this->admin, $this->organization)->delete('/settings/organization', ['confirm_name' => 'Alpha Mart', 'password' => 'password'])
        ->assertForbidden();

    settingsAs($this->owner, $this->organization)->delete('/settings/organization', ['confirm_name' => 'alpha mart', 'password' => 'password'])
        ->assertSessionHasErrorsIn('organizationDeletion', 'confirm_name');

    settingsAs($this->owner, $this->organization)->delete('/settings/organization', ['confirm_name' => 'Alpha Mart', 'password' => 'nope'])
        ->assertSessionHasErrorsIn('organizationDeletion', 'password');

    expect(Organization::find($this->organization->id))->not->toBeNull();
});

test('deleting an organization takes everything it owns, the organization and the roles made in it included — never the people or the history', function () {
    // Everything Alpha Mart owns…
    $file = Media::factory()->create([
        'organization_id' => $this->organization->id,
        'path' => "media/{$this->organization->id}/".Str::ulid().'.jpg',
        'thumbnail_path' => "media/{$this->organization->id}/thumbs/".Str::ulid().'.jpg',
    ]);
    Storage::disk('public')->put($file->path, 'image');
    Storage::disk('public')->put($file->thumbnail_path, 'thumb');

    $screen = Screen::factory()->withToken('alpha-tv')->create(['organization_id' => $this->organization->id]);
    $channel = Channel::factory()->create();
    $platformAd = ChannelAd::factory()->lasting(10)->create(['channel_id' => $channel->id]);
    // The organization's own channel, showing a file of the organization's own library.
    $ownChannel = Channel::factory()->create(['organization_id' => $this->organization->id]);
    $ownAd = ChannelAd::factory()->create(['channel_id' => $ownChannel->id]);
    Storage::disk('public')->put($ownAd->media->path, 'ad');
    Storage::disk('public')->put($ownAd->media->thumbnail_path, 'ad thumb');
    PlaylistItem::create(['screen_id' => $screen->id, 'media_id' => $file->id, 'position' => 0, 'duration_seconds' => 10]);
    PlaylistItem::create(['screen_id' => $screen->id, 'channel_id' => $channel->id, 'position' => 1]);
    $custom = Role::create(['name' => 'Cashier', 'organization_id' => $this->organization->id]);
    $invite = Invitation::factory()->create(['organization_id' => $this->organization->id]);
    ActivityLog::record('screen.paired', $screen, 'Paired screen Alpha TV', $this->owner);

    // The Admin works here under the organization's own role.
    DB::table('organization_user')->where('organization_id', $this->organization->id)->where('user_id', $this->admin->id)->update(['role_id' => $custom->id]);

    // …and a neighbour that must not feel a thing.
    $beta = Organization::factory()->create();
    $betaOwner = createOrganizationMember($beta, Role::OWNER);
    $betaScreen = Screen::factory()->create(['organization_id' => $beta->id]);
    $betaFile = Media::factory()->create(['organization_id' => $beta->id]);

    // The Staff member also works in Beta.
    $this->staff->organizations()->attach($beta->id, ['role_id' => Role::starter(Role::VIEWER)->id]);

    settingsAs($this->owner, $this->organization)->delete('/settings/organization', ['confirm_name' => 'Alpha Mart', 'password' => 'password'])
        ->assertRedirect(route('dashboard'));

    $this->assertDatabaseMissing('organizations', ['id' => $this->organization->id]);
    expect(DB::table('organization_user')->where('organization_id', $this->organization->id)->count())->toBe(0)
        ->and(Invitation::find($invite->id))->toBeNull()
        ->and(Role::find($custom->id))->toBeNull()
        ->and(Screen::find($screen->id))->toBeNull()
        ->and(PlaylistItem::where('screen_id', $screen->id)->count())->toBe(0)
        ->and(Media::find($file->id))->toBeNull()
        ->and(Channel::find($ownChannel->id))->toBeNull()
        ->and(ChannelAd::find($ownAd->id))->toBeNull()
        ->and(Media::find($ownAd->media_id))->toBeNull();
    foreach ([$file->path, $file->thumbnail_path, $ownAd->media->path, $ownAd->media->thumbnail_path] as $path) {
        Storage::disk('public')->assertMissing($path);
    }

    // The deleted organization's television is locked out, instead of playing on.
    $this->withHeader('Authorization', 'Bearer alpha-tv')->getJson('/device/playlist')->assertUnauthorized();

    // People keep their accounts — the one who worked under the organization's own role too; the platform's channel stays.
    expect(User::find($this->staff->id))->not->toBeNull()
        ->and(User::find($this->admin->id))->not->toBeNull()
        ->and(Channel::find($channel->id))->not->toBeNull()
        ->and(ChannelAd::find($platformAd->id))->not->toBeNull()
        ->and(session('current_organization_id'))->toBeNull();

    // The neighbour is untouched — including the Staff member's place in it.
    expect(Screen::find($betaScreen->id))->not->toBeNull()
        ->and(Media::find($betaFile->id))->not->toBeNull()
        ->and(roleKeyIn($betaOwner, $beta))->toBe(Role::OWNER)
        ->and(roleKeyIn($this->staff, $beta))->toBe(Role::VIEWER);

    // The history stays: what happened in the organization, and its deletion.
    expect(ActivityLog::where('organization_id', $this->organization->id)->where('action', 'screen.paired')->exists())->toBeTrue()
        ->and(ActivityLog::where('action', 'organization.deleted')->latest('id')->value('description'))
        // Two files: the poster, and the one its own channel shows — a channel's files are library files.
        ->toContain('Alpha Mart')->toContain('1 screen')->toContain('2 media files');
});

test('outside an organization there are no organization settings', function () {
    $support = createPlatformUser(['organization-view', 'organization-update']);

    $this->actingAs($support)->get('/settings/organization')->assertNotFound();
    $this->actingAs($support)->get('/profile')->assertOk()->assertDontSee('dusk="settings-tab-organization"', false);
});

test('leaving from the Organizations tab comes back to it — unless the organization left is the one being worked in', function () {
    $beta = Organization::factory()->create(['name' => 'Beta Deli']);
    $this->admin->organizations()->attach($beta->id, ['role_id' => Role::starter(Role::STAFF)->id]);

    settingsAs($this->admin, $this->organization)->from('/settings/organization')->delete("/profile/organizations/{$beta->id}")
        ->assertRedirect('/settings/organization')->assertSessionHas('status', 'You left Beta Deli.');

    settingsAs($this->admin, $this->organization)->from('/settings/organization')->delete("/profile/organizations/{$this->organization->id}")
        ->assertRedirect(route('profile.edit'));
    expect(session('current_organization_id'))->toBeNull()
        ->and(roleKeyIn($this->admin, $this->organization))->toBeNull();
});
