<?php

use App\Models\Media;
use App\Models\Organization;
use Illuminate\Routing\Middleware\ThrottleRequests;

/*
|--------------------------------------------------------------------------
| The Media page, pushed on every side (owner, 2026-09-30: its filters beside the search, its box on the page)
|--------------------------------------------------------------------------
|
| Every combination of the listing's filters — with values a page never sends among them — answers, never with a
| 500; the storage the page is drawn with is the storage its list then says; and the drop box is on the page for
| exactly the people who may upload.
|
*/

beforeEach(function () {
    $this->withoutMiddleware(ThrottleRequests::class);
    $this->organization = Organization::factory()->create(['name' => 'Alpha Mart']);
    Media::factory()->count(3)->create(['organization_id' => $this->organization->id]);
});

test('every combination of the filters, sensible or not, answers and never breaks', function () {
    $owner = createOrganizationMember($this->organization);
    $admin = createSuperAdmin();

    $types = ['', 'image', 'video', 'html', 'IMAGE', 'pdf', "'; DROP TABLE media; --", str_repeat('x', 300)];
    $orientations = ['', 'landscape', 'portrait', 'square', '<script>'];
    $sorts = ['', 'newest', 'oldest', 'title_asc', 'title_desc', 'expiry_asc', 'expiry_desc', 'id desc', '../../'];
    $libraries = ['', 'platform', (string) $this->organization->id, '0', '-1', '99999999999', 'abc', '1 OR 1=1'];

    $turn = 0;

    foreach ([$owner, $admin] as $person) {
        foreach ($types as $type) {
            foreach ($orientations as $orientation) {
                foreach ($sorts as $sort) {
                    // Every library in turn, so each is met beside every other value.
                    $library = $libraries[$turn++ % count($libraries)];
                    $query = http_build_query(array_filter(compact('type', 'orientation', 'sort', 'library'), fn ($v) => $v !== ''));

                    $status = $this->actingAs($person)->withSession(['current_organization_id' => $this->organization->id])
                        ->getJson("/media/data?{$query}")->status();

                    expect($status)->toBeIn([200, 422], "/media/data?{$query} answered {$status}");
                }
            }
        }
    }

    // A value shaped like a list is not one value: never a 500.
    foreach (['type', 'orientation', 'sort', 'library', 'search', 'page', 'per_page'] as $key) {
        $status = $this->actingAs($owner)->withSession(['current_organization_id' => $this->organization->id])
            ->getJson("/media/data?{$key}[]=x")->status();
        expect($status)->toBeIn([200, 422], "{$key}[] answered {$status}");
    }
});

test('the storage the page is drawn with is the storage its list says, inside an organization and for a chosen organization', function () {
    $owner = createOrganizationMember($this->organization);

    $page = $this->actingAs($owner)->withSession(['current_organization_id' => $this->organization->id])->get('/media')->assertOk();
    $listed = $this->actingAs($owner)->withSession(['current_organization_id' => $this->organization->id])->getJson('/media/data')->json('storage');

    expect($page->viewData('storage'))->toBe($listed)
        ->and($listed['limit'])->toBe(512 * 1024 * 1024);

    // Above the organizations the page opens on the platform's own library, which has no wall.
    $admin = createSuperAdmin();
    expect($this->actingAs($admin)->get('/media')->assertOk()->viewData('storage'))->toBeNull()
        ->and($this->actingAs($admin)->getJson("/media/data?library={$this->organization->id}")->json('storage'))->toBe($listed);
});

test('the drop box is on the page for exactly the people who may upload, and no upload dialog is left anywhere', function () {
    $viewer = createOrganizationUser($this->organization, ['media-view'], 'Looks');
    $uploader = createOrganizationUser($this->organization, ['media-view', 'media-store'], 'Uploads');

    $this->actingAs($viewer)->withSession(['current_organization_id' => $this->organization->id])->get('/media')->assertOk()
        ->assertDontSee('dusk="media-dropzone"', false)
        ->assertDontSee('media-upload-modal');

    $this->actingAs($uploader)->withSession(['current_organization_id' => $this->organization->id])->get('/media')->assertOk()
        ->assertSee('dusk="media-dropzone"', false)
        ->assertSee('dusk="media-filters"', false)
        ->assertDontSee('media-upload-modal')
        ->assertDontSee('dusk="upload-media"', false);

    // The dashboard's first step leads to the page itself, with no flag to open a dialog that is not there.
    $owner = createOrganizationMember($this->organization);
    $steps = collect($this->actingAs($owner)->withSession(['current_organization_id' => $this->organization->id])->get('/dashboard')
        ->viewData('summary')['steps'])->keyBy('key');
    expect($steps['upload']['href'] ?? null)->toBe(route('media.view'));
});
