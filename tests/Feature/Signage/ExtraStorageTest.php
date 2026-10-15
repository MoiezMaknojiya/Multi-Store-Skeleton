<?php

use App\Models\ActivityLog;
use App\Models\Media;
use App\Models\Organization;
use App\Models\Upload;
use App\Services\OrganizationStorage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Support\Tus;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Extra storage beside the 512 MB (docs/BILLING-SPEC.md §8)
|--------------------------------------------------------------------------
|
| Owner, 2026-10-15: "kisi ko extra 1 gb dena ho toh uska 512mb full ho jaye toh kese bharao?" — then: a whole number of GB,
| free for now and only shown. The platform gives it in the Billing dialog; every door that asks the organization's wall asks
| it with the extra, the refusal names that wall, and taking it away deletes nothing.
|
*/

beforeEach(function () {
    Storage::fake('public');
    Storage::fake('uploads');

    $this->organization = Organization::factory()->create(['name' => 'Alpha Mart']);
    $this->manager = createOrganizationUser($this->organization, ['media-view', 'media-store'], 'Manager');
});

/** The extra storage, as the Billing dialog's save writes it. */
function giveExtraStorage(Organization $organization, int $gigabytes): void
{
    $organization->forceFill(['extra_storage_gb' => $gigabytes])->save();
}

/** A file of the organization's library of $bytes, with no preview, so the sums are exact. */
function holding(Organization $organization, int $bytes): void
{
    Media::factory()->create(['organization_id' => $organization->id, 'size' => $bytes, 'thumbnail_path' => null]);
}

function asTheManager(TestCase $test): TestCase
{
    return $test->actingAs($test->manager)->withSession(['current_organization_id' => $test->organization->id]);
}

function megabytes(int $count): int
{
    return $count * 1024 * 1024;
}

/* ── The wall ────────────────────────────────────────────────────────────── */

test('every organization starts with 512 MB, and each GB given raises its wall by a gigabyte', function () {
    expect(Organization::factory()->create()->fresh()->extra_storage_gb)->toBe(0)
        ->and(OrganizationStorage::limitOf(0))->toBe(536_870_912)
        ->and(OrganizationStorage::limitOf(1))->toBe(1_610_612_736)
        ->and(OrganizationStorage::limitOf(-3))->toBe(536_870_912)
        ->and(OrganizationStorage::inWords(OrganizationStorage::limitOf(1)))->toBe('1.5 GB')
        ->and(OrganizationStorage::inWords(OrganizationStorage::limitOf(2)))->toBe('2.5 GB')
        ->and(OrganizationStorage::inWords(2 * 1024 ** 3))->toBe('2 GB')
        ->and(OrganizationStorage::inWords(megabytes(512)))->toBe('512 MB');

    giveExtraStorage($this->organization, 1);

    expect(app(OrganizationStorage::class)->limitFor($this->organization->id))->toBe(1_610_612_736)
        ->and(app(OrganizationStorage::class)->summary($this->organization->id)['limit'])->toBe(1_610_612_736);
    // The meters read it from the pages' own answers.
    asTheManager($this)->getJson('/media/data')->assertOk()->assertJsonPath('storage.limit', 1_610_612_736);
});

test('an upload past 512 MB is refused, and fits once a GB is given; the refusal names the wall', function () {
    holding($this->organization, megabytes(500));
    asTheManager($this);

    $this->postJson('/media', ['file' => UploadedFile::fake()->create('menu.jpg', 20 * 1024)])->assertStatus(422)
        ->assertJsonValidationErrors(['file' => 'Not enough storage: this needs 20 MB, and Alpha Mart has 12 MB left of its 512 MB. Delete files you no longer use to make room.']);

    giveExtraStorage($this->organization, 1);
    $this->postJson('/media', ['file' => UploadedFile::fake()->create('menu.jpg', 20 * 1024)])->assertOk();

    // Up to the new wall, and not past it: 5 MB left of the 1.5 GB.
    holding($this->organization, OrganizationStorage::limitOf(1) - megabytes(520) - megabytes(5));
    $this->postJson('/media', ['file' => UploadedFile::fake()->create('poster.jpg', 20 * 1024)])->assertStatus(422)
        ->assertJsonValidationErrors(['file' => 'Not enough storage: this needs 20 MB, and Alpha Mart has 5 MB left of its 1.5 GB. Delete files you no longer use to make room.']);
});

test('a chunked upload is opened against the wall with the extra too', function () {
    holding($this->organization, megabytes(500));
    asTheManager($this);

    Tus::open($this, megabytes(20), ['name' => 'menu.jpg', 'purpose' => 'media'])->assertStatus(422)
        ->assertJsonValidationErrors(['file' => 'Not enough storage: this needs 20 MB, and Alpha Mart has 12 MB left of its 512 MB. Delete files you no longer use to make room.']);

    giveExtraStorage($this->organization, 1);
    Tus::open($this, megabytes(20), ['name' => 'menu.jpg', 'purpose' => 'media'])->assertCreated();

    expect(Upload::count())->toBe(1);
});

test('taking the extra away deletes nothing, and the next upload waits until the files fit', function () {
    giveExtraStorage($this->organization, 1);
    holding($this->organization, 1024 ** 3);

    $this->actingAs(createSuperAdmin())
        ->putJson("/organizations/{$this->organization->id}/billing", ['premium_templates_unlocked' => true, 'platform_channels_unlocked' => true, 'extra_storage_gb' => 0])
        ->assertOk()->assertJsonPath('message', 'Removed the extra storage (512 MB in all) for Alpha Mart.');

    expect(Media::where('organization_id', $this->organization->id)->count())->toBe(1);

    asTheManager($this)->postJson('/media', ['file' => UploadedFile::fake()->create('menu.jpg', 1)])->assertStatus(422)
        ->assertJsonValidationErrors(['file' => 'Not enough storage: this needs 1 KB, and Alpha Mart has no space left of its 512 MB. Delete files you no longer use to make room.']);
});

/* ── Giving it, in the Billing dialog ────────────────────────────────────── */

test('the super admin gives extra storage in the Billing dialog, each change logged; left out, it stays', function () {
    $this->actingAs(createSuperAdmin());
    $save = fn (array $more) => $this->putJson("/organizations/{$this->organization->id}/billing", ['premium_templates_unlocked' => true, 'platform_channels_unlocked' => true, ...$more]);

    $this->getJson("/organizations/{$this->organization->id}/billing")->assertOk()->assertJsonPath('billing.storage', [
        'included' => 536_870_912, 'extra_gb' => 0, 'max_extra_gb' => 50, 'limit' => 536_870_912, 'used' => 0,
    ]);

    $save(['extra_storage_gb' => 1])->assertOk()
        ->assertJsonPath('message', 'Set extra storage to 1 GB (1.5 GB in all) for Alpha Mart.')
        ->assertJsonPath('billing.storage.extra_gb', 1)
        ->assertJsonPath('billing.storage.limit', 1_610_612_736)
        // Free for now: the month is what it was.
        ->assertJsonPath('billing.monthly_total', 20);

    // A page from before the field sends the switches alone: the extra stays.
    $save([])->assertOk()->assertJsonPath('message', 'Nothing changed.');
    $save(['extra_storage_gb' => 1])->assertOk()->assertJsonPath('message', 'Nothing changed.');

    $save(['premium_templates_unlocked' => false, 'extra_storage_gb' => 3])->assertOk()
        ->assertJsonPath('message', 'Locked Premium Templates and Set extra storage to 3 GB (3.5 GB in all) for Alpha Mart.');

    expect($this->organization->fresh()->extra_storage_gb)->toBe(3)
        ->and(ActivityLog::where('action', 'organization.billing_updated')->orderBy('id')->pluck('description')->all())->toBe([
            'Set extra storage to 1 GB (1.5 GB in all) for Alpha Mart',
            'Locked Premium Templates for Alpha Mart',
            'Set extra storage to 3 GB (3.5 GB in all) for Alpha Mart',
        ])
        ->and(ActivityLog::where('action', 'organization.billing_updated')->pluck('organization_id')->unique()->all())->toBe([$this->organization->id]);
});

test('the extra is a whole number of GB from 0 to 50, refused in words otherwise', function () {
    $this->actingAs(createSuperAdmin());

    foreach ([-1, 51, 1.5, '2', 'abc', [1], null, true, '1e2'] as $sent) {
        $this->putJson("/organizations/{$this->organization->id}/billing", ['premium_templates_unlocked' => true, 'platform_channels_unlocked' => true, 'extra_storage_gb' => $sent])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['extra_storage_gb' => 'Extra storage is a whole number of GB, from 0 to 50.']);
    }

    $this->putJson("/organizations/{$this->organization->id}/billing", ['premium_templates_unlocked' => true, 'platform_channels_unlocked' => true, 'extra_storage_gb' => 50])->assertOk();

    expect($this->organization->fresh()->extra_storage_gb)->toBe(50)
        ->and(ActivityLog::where('action', 'organization.billing_updated')->count())->toBe(1);
});

test('the dialog offers the field to Change Billing alone, and says the extra to a reader', function () {
    $this->actingAs(createSuperAdmin())->get('/organizations')->assertOk()
        ->assertSee('dusk="billing-extra-storage"', false)
        ->assertSee('max="50"', false);

    $this->actingAs(createPlatformUser(['organization-view', 'billing-view'], 'Accounts'))->get('/organizations')->assertOk()
        ->assertDontSee('dusk="billing-extra-storage"', false)
        ->assertSee('dusk="organization-billing-extra"', false);
});

/* ── Reading it, inside the organization ─────────────────────────────────── */

test('Settings → Billing shows what is used of the wall, the 512 MB included and the extra, free for now', function () {
    $reader = createOrganizationUser($this->organization, ['billing-view'], 'Accounts');
    holding($this->organization, megabytes(300));
    $page = fn () => $this->actingAs($reader)->withSession(['current_organization_id' => $this->organization->id])->get('/settings/billing')->assertOk();

    $page()->assertSee('dusk="billing-storage"', false)
        ->assertSeeInOrder(['Storage', '300 MB of 512 MB used', 'Included', '512 MB', 'Need more room? Contact us below.'])
        ->assertDontSee('dusk="billing-storage-extra"', false)
        ->assertDontSee('dusk="billing-month-storage"', false);

    giveExtraStorage($this->organization, 1);

    $page()->assertSeeInOrder(['1 GB extra storage', 'Free for now'])
        ->assertSeeInOrder(['300 MB of 1.5 GB used', 'Included', '512 MB', 'Extra', '1 GB, free for now'])
        // Free for now: the two features' $20 alone.
        ->assertSee('dusk="billing-monthly-total">$20<', false);
});

test('an organization almost full is told so on its Billing tab', function () {
    $reader = createOrganizationUser($this->organization, ['billing-view'], 'Accounts');
    holding($this->organization, megabytes(500));

    $this->actingAs($reader)->withSession(['current_organization_id' => $this->organization->id])->get('/settings/billing')->assertOk()
        ->assertSee('Almost full. Delete files you no longer use to make room.');
});
