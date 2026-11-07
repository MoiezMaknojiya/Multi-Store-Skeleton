<?php

use App\Models\Media;
use App\Models\Organization;
use Illuminate\Support\Facades\Storage;

/**
 * Download on a Media page row (a customer who had lost their own copy of a file they uploaded, 2026-11-06): whoever may
 * see the library may keep a copy, as it is kept, under its title — and nothing outside the library they work in.
 */
beforeEach(function () {
    Storage::fake('public');
});

/** A file of `$organization` (null: the platform's) with these bytes on the disk at `$path`. */
function keptFile(?Organization $organization, string $title, string $path, string $bytes = 'the bytes as kept'): Media
{
    Storage::disk('public')->put($path, $bytes);

    return Media::factory()->create(['organization_id' => $organization?->id, 'title' => $title, 'path' => $path, 'size' => strlen($bytes)]);
}

test('a person who may see the library downloads its file as it is kept, under its title', function () {
    $organization = Organization::factory()->create();
    $viewer = createOrganizationUser($organization, ['media-view']);
    $file = keptFile($organization, 'Lunch menu', "media/{$organization->id}/01JLUNCH.webp", 'webp bytes');

    $response = $this->actingAs($viewer)->withSession(['current_organization_id' => $organization->id])
        ->get("/media/{$file->id}/download")->assertOk();

    expect($response->headers->get('content-disposition'))->toBe('attachment; filename="Lunch menu.webp"')
        ->and($response->streamedContent())->toBe('webp bytes');
});

test('the name is one a computer takes, and the kept file\'s own ending wins', function (string $title, string $path, string $name) {
    $organization = Organization::factory()->create();
    $viewer = createOrganizationUser($organization, ['media-view']);
    $file = keptFile($organization, $title, $path);

    $disposition = $this->actingAs($viewer)->withSession(['current_organization_id' => $organization->id])
        ->get("/media/{$file->id}/download")->assertOk()->headers->get('content-disposition');

    // Quoted only when the name needs it (Symfony's HeaderUtils): "Front window.webp", file.png.
    expect($disposition)->toMatch('/^attachment; filename="?'.preg_quote($name, '/').'"?$/');
})->with([
    'uploaded as a JPEG, kept as WebP' => ['Front window.jpg', 'media/1/01JFRONT.webp', 'Front window.webp'],
    'a video keeps its ending' => ['Promo clip', 'media/1/01JPROMO.mp4', 'Promo clip.mp4'],
    'what no file name may hold' => ['Menu: Lunch / Dinner?', 'media/1/01JMENU.webp', 'Menu- Lunch - Dinner.webp'],
    'nothing left of the title' => ['???', 'media/1/01JEMPTY.png', 'file.png'],
]);

test('another organization\'s file, the platform\'s and an Ad Builder page are not there for an organization\'s person', function () {
    $organization = Organization::factory()->create();
    $other = Organization::factory()->create();
    $viewer = createOrganizationUser($organization, ['media-view']);
    $theirs = keptFile($other, 'Their menu', "media/{$other->id}/01JTHEIRS.webp");
    $platforms = keptFile(null, 'Platform promo', 'media/platform/01JPLATFORM.webp');
    $page = keptFile($organization, 'An ad', "builder/{$organization->id}/ads/abc/index.html");
    $page->update(['type' => Media::TYPE_HTML, 'mime_type' => 'text/html']);

    foreach ([$theirs, $platforms, $page] as $file) {
        $this->actingAs($viewer)->withSession(['current_organization_id' => $organization->id])
            ->get("/media/{$file->id}/download")->assertNotFound();
    }
});

test('above the organizations every library is there: an organization\'s file and the platform\'s', function () {
    $organization = Organization::factory()->create();
    $superAdmin = createSuperAdmin();
    $theirs = keptFile($organization, 'Their menu', "media/{$organization->id}/01JTHEIRS.webp", 'theirs');
    $platforms = keptFile(null, 'Platform promo', 'media/platform/01JPLATFORM.webp', 'platform');

    expect($this->actingAs($superAdmin)->get("/media/{$theirs->id}/download")->assertOk()->streamedContent())->toBe('theirs')
        ->and($this->actingAs($superAdmin)->get("/media/{$platforms->id}/download")->assertOk()->streamedContent())->toBe('platform');
});

test('without View Media it is refused, and a file gone from the disk is not there', function () {
    $organization = Organization::factory()->create();
    $file = keptFile($organization, 'Lunch menu', "media/{$organization->id}/01JLUNCH.webp");

    $someoneElse = createOrganizationUser($organization, ['screen-view'], 'Screens Only');
    $this->actingAs($someoneElse)->withSession(['current_organization_id' => $organization->id])
        ->get("/media/{$file->id}/download")->assertForbidden();

    $viewer = createOrganizationUser($organization, ['media-view'], 'Viewer Role');
    Storage::disk('public')->delete($file->path);
    $this->actingAs($viewer)->withSession(['current_organization_id' => $organization->id])
        ->get("/media/{$file->id}/download")->assertNotFound();
});

test('the Media page offers Download on each row to whoever sees it', function () {
    $organization = Organization::factory()->create();
    $viewer = createOrganizationUser($organization, ['media-view']);

    $this->actingAs($viewer)->withSession(['current_organization_id' => $organization->id])->get('/media')->assertOk()
        ->assertSee("x-bind:href=\"'/media/' + item.id + '/download'\"", false)
        ->assertSee("x-bind:aria-label=\"'Download ' + item.title\"", false);
});
