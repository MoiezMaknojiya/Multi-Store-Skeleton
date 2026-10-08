<?php

use App\Models\ActivityLog;
use App\Models\Organization;
use App\Models\Role;
use App\Models\Screen;
use App\Services\BillingSummary;

/*
|--------------------------------------------------------------------------
| Billing — the first step (owner, 2026-10-07 and 2026-10-08)
|--------------------------------------------------------------------------
|
| "Ek screen free hongi aur us ko ek aur screen chiya toh $5 per month honga", Premium Templates and Platform Channels $10 each,
| "organization mein billing profile k ander ho jese sub ki honti ha", "har organization ki row per edit k barabar mein", "abhi sub
| k liya premium khula rakho". Nothing is charged yet: an organization reads what billing will ask on Settings → Billing, and the
| platform reads the same beside Edit on the Organizations page and turns the two switches (docs/BILLING-SPEC.md).
|
*/

beforeEach(function () {
    $this->organization = Organization::factory()->create(['name' => 'Smart Stop']);
});

/** A screen of the organization, paired at that moment. */
function pairedScreen(Organization $organization, string $name, string $pairedAt): Screen
{
    return Screen::factory()->create(['organization_id' => $organization->id, 'name' => $name, 'paired_at' => $pairedAt]);
}

/* ── The summary ─────────────────────────────────────────────────────────── */

test('the first screen paired is free and every further one $5 a month', function () {
    $summary = fn () => app(BillingSummary::class)->for($this->organization->fresh());

    expect($summary())->toMatchArray(['screen_count' => 0, 'free_screens' => 0, 'paid_screens' => 0, 'monthly_total' => 0]);

    $tv2 = pairedScreen($this->organization, 'Tv2', '2026-10-06 10:05:00');
    $tv1 = pairedScreen($this->organization, 'Tv1', '2026-10-06 10:00:00');
    expect($summary())->toMatchArray(['screen_count' => 2, 'free_screens' => 1, 'paid_screens' => 1, 'monthly_total' => 5]);

    $tv3 = pairedScreen($this->organization, 'Tv3', '2026-10-06 10:05:00');
    $tv4 = pairedScreen($this->organization, 'Tv4', '2026-10-07 09:00:00');
    $screens = $summary()['screens'];

    // The one paired first is the free one; two paired at the same moment are put in the order they were made.
    expect(array_column($screens, 'name'))->toBe(['Tv1', 'Tv2', 'Tv3', 'Tv4'])
        ->and(array_column($screens, 'free'))->toBe([true, false, false, false])
        ->and(array_column($screens, 'monthly'))->toBe([0, 5, 5, 5])
        ->and($summary())->toMatchArray(['screen_count' => 4, 'paid_screens' => 3, 'screen_price' => 5, 'monthly_total' => 15]);

    // Another organization's screens are not counted.
    pairedScreen(Organization::factory()->create(), 'Elsewhere', '2026-10-01 00:00:00');
    expect($summary()['screen_count'])->toBe(4);
});

test('a new organization starts with everything unlocked, and the prices are the owner’s', function () {
    $features = app(BillingSummary::class)->for(Organization::factory()->create()->fresh())['features'];

    expect($features)->toBe([
        'premium_templates' => ['unlocked' => true, 'price' => 10],
        'platform_channels' => ['unlocked' => true, 'price' => 10],
    ]);
});

/* ── Settings → Billing, inside an organization ──────────────────────────── */

test('the Owner reads Settings → Billing: the month, the features, every screen and whom to ask', function () {
    $owner = createOrganizationMember($this->organization, Role::OWNER);
    pairedScreen($this->organization, 'Tv1', '2026-10-06 10:00:00');
    pairedScreen($this->organization, 'Tv2', '2026-10-06 10:01:00');
    $this->organization->forceFill(['platform_channels_unlocked' => false])->save();

    $this->actingAs($owner)->withSession(['current_organization_id' => $this->organization->id]);

    $this->get('/profile')->assertOk()->assertSee('dusk="settings-tab-billing"', false);

    $this->get('/settings/billing')->assertOk()
        ->assertSee('dusk="billing-page"', false)
        ->assertSee('Billing has not started yet')
        ->assertSeeInOrder(['Estimated every month', '$5', 'for 2 screens'])
        ->assertSeeInOrder(['First screen', 'Free', '1 more screen × $5', '$5'])
        ->assertSeeInOrder(['Premium Templates', '$10', 'Unlocked', 'Platform Channels', '$10', 'Locked', 'Your own ads and uploads', 'Free'])
        ->assertSeeInOrder(['Tv1', 'Oct 6, 2026', 'Free', 'Tv2', 'Oct 6, 2026', '$5', 'Total', '$5'])
        ->assertSee('No invoices yet')
        ->assertSee('Contact us, and we unlock it for you.')
        ->assertSee("Tell us your organization's name, Smart Stop.", false);
});

test('the contact lines are the owner’s once given', function () {
    config(['signage.billing_contact_email' => 'help@example.com', 'signage.billing_contact_phone' => '555 0100']);
    $owner = createOrganizationMember($this->organization, Role::OWNER);

    $this->actingAs($owner)->withSession(['current_organization_id' => $this->organization->id])
        ->get('/settings/billing')->assertOk()
        ->assertSee('mailto:help@example.com', false)
        ->assertSee('555 0100')
        ->assertDontSee('Contact us, and we unlock it for you.');
});

test('the tab is there with View Billing alone: a member without it has none, and the platform has its own place', function () {
    $staff = createOrganizationMember($this->organization, Role::STAFF);
    $this->actingAs($staff)->withSession(['current_organization_id' => $this->organization->id]);

    $this->get('/profile')->assertOk()->assertDontSee('dusk="settings-tab-billing"', false);
    $this->get('/settings/billing')->assertForbidden();

    // A custom role given View Billing has the tab.
    $reader = createOrganizationUser($this->organization, ['billing-view'], 'Bookkeeper');
    $this->actingAs($reader)->withSession(['current_organization_id' => $this->organization->id]);
    $this->get('/settings/billing')->assertOk();

    // Above the organizations there is no tab: the Organizations page says it, organization by organization.
    $this->actingAs(createSuperAdmin())->flushSession();
    $this->get('/settings/billing')->assertNotFound();
});

/* ── Billing beside Edit, above the organizations ────────────────────────── */

test('the super admin reads an organization’s billing and turns its switches, each change logged', function () {
    pairedScreen($this->organization, 'Tv1', '2026-10-06 10:00:00');
    pairedScreen($this->organization, 'Tv2', '2026-10-06 10:01:00');
    $this->actingAs(createSuperAdmin());

    $row = collect($this->getJson('/organizations/data')->assertOk()->json('organizations'))->firstWhere('id', $this->organization->id);
    expect($row['can']['billing'])->toBeTrue();

    $this->getJson("/organizations/{$this->organization->id}/billing")->assertOk()
        ->assertJsonPath('billing.organization.name', 'Smart Stop')
        ->assertJsonPath('billing.monthly_total', 5)
        ->assertJsonPath('billing.features.premium_templates.unlocked', true);

    $this->putJson("/organizations/{$this->organization->id}/billing", ['premium_templates_unlocked' => false, 'platform_channels_unlocked' => true])
        ->assertOk()
        ->assertJsonPath('message', 'Locked Premium Templates for Smart Stop.')
        ->assertJsonPath('billing.features.premium_templates.unlocked', false);

    $this->putJson("/organizations/{$this->organization->id}/billing", ['premium_templates_unlocked' => true, 'platform_channels_unlocked' => false])
        ->assertOk()
        ->assertJsonPath('message', 'Unlocked Premium Templates and Locked Platform Channels for Smart Stop.');

    $this->putJson("/organizations/{$this->organization->id}/billing", ['premium_templates_unlocked' => true, 'platform_channels_unlocked' => false])
        ->assertOk()->assertJsonPath('message', 'Nothing changed.');

    expect($this->organization->fresh())
        ->premium_templates_unlocked->toBeTrue()
        ->platform_channels_unlocked->toBeFalse()
        ->and(ActivityLog::where('action', 'organization.billing_updated')->orderBy('id')->pluck('description')->all())->toBe([
            'Locked Premium Templates for Smart Stop',
            'Unlocked Premium Templates for Smart Stop',
            'Locked Platform Channels for Smart Stop',
        ])
        ->and(ActivityLog::where('action', 'organization.billing_updated')->pluck('organization_id')->unique()->all())->toBe([$this->organization->id]);
});

test('a platform role with View Billing reads it; only Change Billing turns the switches', function () {
    $reader = createPlatformUser(['organization-view', 'billing-view'], 'Accounts');
    $this->actingAs($reader);

    expect(collect($this->getJson('/organizations/data')->json('organizations'))->firstWhere('id', $this->organization->id)['can']['billing'])->toBeTrue();
    $this->getJson("/organizations/{$this->organization->id}/billing")->assertOk();
    $this->putJson("/organizations/{$this->organization->id}/billing", ['premium_templates_unlocked' => false, 'platform_channels_unlocked' => false])->assertForbidden();

    $this->get('/organizations')->assertOk()
        ->assertSee('dusk="organization-billing"', false)
        ->assertDontSee('dusk="billing-switch-premium_templates"', false);

    $this->actingAs(createPlatformUser(['organization-view'], 'Support'));
    expect(collect($this->getJson('/organizations/data')->json('organizations'))->firstWhere('id', $this->organization->id)['can']['billing'])->toBeFalse();
    $this->getJson("/organizations/{$this->organization->id}/billing")->assertForbidden();

    $this->actingAs(createPlatformUser(['organization-view', 'billing-view', 'billing-update'], 'Billing desk'));
    $this->putJson("/organizations/{$this->organization->id}/billing", ['premium_templates_unlocked' => false, 'platform_channels_unlocked' => true])->assertOk();
    expect($this->organization->fresh()->premium_templates_unlocked)->toBeFalse();
});

test('the switches are said in words when they are missing or not yes or no', function () {
    $this->actingAs(createSuperAdmin());

    $this->putJson("/organizations/{$this->organization->id}/billing", [])->assertStatus(422)->assertJsonValidationErrors([
        'premium_templates_unlocked' => 'Say whether Premium Templates are unlocked.',
        'platform_channels_unlocked' => 'Say whether Platform Channels are unlocked.',
    ]);
});

test('the Owner and Admin roles hold View Billing from the start, and Change Billing is never an organization’s', function () {
    expect(Role::owner()->permissions()->pluck('name'))->toContain('billing-view')->not->toContain('billing-update')
        ->and(Role::starter(Role::ADMIN)->permissions()->pluck('name'))->toContain('billing-view')->not->toContain('billing-update')
        ->and(Role::starter(Role::STAFF)->permissions()->pluck('name'))->not->toContain('billing-view');
});
