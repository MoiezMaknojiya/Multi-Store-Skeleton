<?php

use App\Models\ActivityLog;
use App\Models\Organization;
use App\Models\PlayerAppRelease;
use App\Models\Upload;
use App\Services\ApkInspector;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Support\ApkFiles;
use Tests\Support\Tus;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Developer → TV App: the super admin uploads the TV app, and every TV is offered the newest version
|--------------------------------------------------------------------------
|
| Owner, 2026-10-10: "mein new apk apne super admin mein upload karu waha super admin mein developer ka tab ho sub se end
| mein", then: the version read from the file, typed only when it cannot be read; the first release 1.0. The page and its
| writes are the super admin's alone (Permission::SUPER_ADMIN_ONLY behind `super-admin-tier`); /player-app/latest.json is
| what a television reads.
|
*/

beforeEach(function () {
    Storage::fake('public');
    Storage::fake('uploads');
    $this->admin = createSuperAdmin();
});

/** An APK of the TV app uploaded in chunks for Developer → TV App; the upload's id. */
function uploadTheApp(TestCase $test, string $bytes, string $name = 'app-release.apk'): string
{
    return Tus::upload($test, $bytes, ['name' => $name, 'type' => 'application/vnd.android.package-archive', 'purpose' => 'app-release']);
}

/** A version already uploaded. */
function aRelease(int $code, string $name): PlayerAppRelease
{
    $path = PlayerAppRelease::FOLDER.'/'.PlayerAppRelease::fileNameFor($name, $code);
    Storage::disk('public')->put($path, ApkFiles::apk(ApkFiles::manifest(versionCode: $code, versionName: $name)));

    return PlayerAppRelease::create([
        'version_code' => $code, 'version_name' => $name, 'path' => $path, 'size' => 100,
        'sha256' => str_repeat('a', 64), 'uploaded_by' => null,
    ]);
}

/* ── Who sees it ─────────────────────────────────────────────────────────── */

test('Developer is the super admin’s alone, last in the sidebar', function () {
    $html = $this->actingAs($this->admin)->get('/developer')->assertOk()->assertSee('dusk="developer-page"', false)->getContent();
    expect($html)->toContain('href="'.route('developer.index').'"')
        // Last: after the Activity Log, before the sidebar's foot.
        ->and(strpos($html, route('developer.index')))->toBeGreaterThan(strpos($html, route('activity.view')));

    // A platform role that holds everything else, and even one the TV app's permission reached by hand, stays out.
    $staff = createPlatformUser(['user-view', 'organization-view', 'activity-view'], 'Platform Staff');
    DB::table('role_has_permissions')->insert([
        'role_id' => $staff->globalRole()->id,
        'permission_id' => DB::table('permissions')->where('name', 'player-app-view')->value('id'),
        'created_at' => now(), 'updated_at' => now(),
    ]);
    $this->actingAs($staff)->get('/developer')->assertForbidden();
    $this->actingAs($staff)->get('/dashboard')->assertOk()->assertDontSee(route('developer.index'), false);
    $this->actingAs($staff)->getJson('/developer/app/releases/data')->assertForbidden();

    $organization = Organization::factory()->create();
    $owner = createOrganizationUser($organization, ['ad-view', 'screen-view'], 'Designer');
    $this->actingAs($owner)->withSession(['current_organization_id' => $organization->id])->get('/developer')->assertForbidden();

    auth()->logout();
    $this->get('/developer')->assertRedirect(route('login'));
});

test('no role but Super-Admin can be given the TV app’s permissions', function () {
    $this->actingAs($this->admin);
    $permission = DB::table('permissions')->where('name', 'player-app-store')->value('id');

    $this->postJson('/roles', ['name' => 'Release Managers', 'is_global' => true, 'type' => 'platform', 'permissions' => [$permission]])
        ->assertStatus(422)->assertJsonValidationErrors(['permissions' => 'Upload TV App belongs to the Super-Admin role alone.']);
});

/* ── Reading an APK ──────────────────────────────────────────────────────── */

test('an APK says its package and version; anything else is no APK, and a manifest that cannot be read reads as nothing', function () {
    $inspector = app(ApkInspector::class);
    $read = function (string $bytes) use ($inspector) {
        $path = tempnam(sys_get_temp_dir(), 'apk');
        file_put_contents($path, $bytes);

        try {
            return $inspector->inspect($path);
        } finally {
            unlink($path);
        }
    };

    expect($read(ApkFiles::apk()))->toBe(['is_apk' => true, 'package' => PlayerAppRelease::PACKAGE, 'version_code' => 2, 'version_name' => '1.1'])
        ->and($read(ApkFiles::apk(ApkFiles::manifest(versionCode: null))))->toMatchArray(['is_apk' => true, 'version_code' => null, 'version_name' => '1.1'])
        ->and($read(ApkFiles::apk(ApkFiles::manifest(versionName: null))))->toMatchArray(['is_apk' => true, 'version_code' => 2, 'version_name' => null])
        ->and($read(ApkFiles::apk(ApkFiles::manifest(package: 'com.other.app'))))->toMatchArray(['package' => 'com.other.app'])
        ->and($read(ApkFiles::apk(withDex: false)))->toMatchArray(['is_apk' => false])
        ->and($read(ApkFiles::apk(withManifest: false)))->toMatchArray(['is_apk' => false])
        ->and($read('<html>not an app</html>'))->toMatchArray(['is_apk' => false])
        // A manifest cut short, or of text: an APK whose version cannot be read.
        ->and($read(ApkFiles::apk(substr(ApkFiles::manifest(), 0, 60))))->toBe(['is_apk' => true, 'package' => null, 'version_code' => null, 'version_name' => null])
        ->and($read(ApkFiles::apk('<manifest package="x"/>')))->toBe(['is_apk' => true, 'package' => null, 'version_code' => null, 'version_name' => null]);
});

/* ── Uploading ───────────────────────────────────────────────────────────── */

test('the super admin uploads the app: its version is read, it is published, and every TV is offered it', function () {
    $this->actingAs($this->admin);
    $bytes = ApkFiles::apk(ApkFiles::manifest(versionCode: 1, versionName: '1.0'));
    $id = uploadTheApp($this, $bytes);

    $this->postJson('/developer/app/inspect', ['upload' => $id])->assertOk()
        ->assertJson(['version_read' => true, 'version_code' => 1, 'version_name' => '1.0', 'current' => null]);

    $this->postJson('/developer/app/releases', ['upload' => $id, 'notes' => '  The first release.  '])->assertOk()
        ->assertJsonPath('message', 'Version 1.0 is uploaded: every TV with the app is offered it the next time it starts, or within a day.');

    $release = PlayerAppRelease::sole();
    expect($release)->toMatchArray(['version_code' => 1, 'version_name' => '1.0', 'version_read_from_file' => true, 'size' => strlen($bytes), 'notes' => 'The first release.', 'uploaded_by' => $this->admin->id])
        ->and($release->path)->toBe('player-app/TheDisplaySolution-1.0-1.apk')
        ->and($release->sha256)->toBe(hash('sha256', $bytes))
        ->and(Storage::disk('public')->get($release->path))->toBe($bytes)
        // The upload is used up, and it is logged.
        ->and(Upload::count())->toBe(0)
        ->and(ActivityLog::where('action', 'player_app.uploaded')->sole()->description)->toBe('Uploaded version 1.0 (code 1) of the TV app');

    auth()->logout();
    $this->get('/player-app/latest.json')->assertOk()
        ->assertHeader('Cache-Control', 'no-cache, no-store, private')
        ->assertExactJson([
            'package' => PlayerAppRelease::PACKAGE, 'version_code' => 1, 'version_name' => '1.0',
            'url' => Storage::disk('public')->url($release->path), 'size' => strlen($bytes), 'sha256' => hash('sha256', $bytes),
            'notes' => 'The first release.', 'published_at' => $release->created_at->toIso8601String(),
        ]);
});

test('a version the file does not say is typed; a version the file says is the one kept, whatever is typed', function () {
    $this->actingAs($this->admin);

    $unread = uploadTheApp($this, ApkFiles::apk(ApkFiles::manifest(versionCode: null, versionName: null)));
    $this->postJson('/developer/app/inspect', ['upload' => $unread])->assertOk()->assertJson(['version_read' => false, 'version_code' => null]);
    $this->postJson('/developer/app/releases', ['upload' => $unread])->assertStatus(422)
        ->assertJsonValidationErrors([
            'version_code' => 'The version could not be read from this file: type its version code.',
            'version_name' => 'The version could not be read from this file: type its version name.',
        ]);
    $this->postJson('/developer/app/releases', ['upload' => $unread, 'version_code' => 1, 'version_name' => '1.0'])->assertOk();
    expect(PlayerAppRelease::sole())->toMatchArray(['version_code' => 1, 'version_name' => '1.0', 'version_read_from_file' => false]);

    $read = uploadTheApp($this, ApkFiles::apk(ApkFiles::manifest(versionCode: 3, versionName: '1.2')));
    $this->postJson('/developer/app/releases', ['upload' => $read, 'version_code' => 99, 'version_name' => '9.9'])->assertOk();
    expect(PlayerAppRelease::current())->toMatchArray(['version_code' => 3, 'version_name' => '1.2', 'version_read_from_file' => true]);

    // A file that says half its version: that half is kept, and only the other is asked.
    $half = uploadTheApp($this, ApkFiles::apk(ApkFiles::manifest(versionCode: 4, versionName: null)));
    $this->postJson('/developer/app/inspect', ['upload' => $half])->assertOk()->assertJson(['version_read' => false, 'version_code' => 4, 'version_name' => null]);
    $this->postJson('/developer/app/releases', ['upload' => $half])->assertStatus(422)
        ->assertJsonValidationErrors(['version_name' => 'The version could not be read from this file: type its version name.'])
        ->assertJsonMissingValidationErrors(['version_code']);
    $this->postJson('/developer/app/releases', ['upload' => $half, 'version_code' => 50, 'version_name' => '1.3'])->assertOk();
    expect(PlayerAppRelease::current())->toMatchArray(['version_code' => 4, 'version_name' => '1.3', 'version_read_from_file' => false]);
});

test('a version must be newer than the one every TV is offered', function () {
    $this->actingAs($this->admin);
    aRelease(2, '1.1');

    $same = uploadTheApp($this, ApkFiles::apk(ApkFiles::manifest(versionCode: 2, versionName: '1.1')));
    $this->postJson('/developer/app/inspect', ['upload' => $same])->assertOk()->assertJsonPath('current', ['version_code' => 2, 'version_name' => '1.1']);
    $this->postJson('/developer/app/releases', ['upload' => $same])->assertStatus(422)
        ->assertJsonValidationErrors(['file' => 'This is version 1.1 (code 2): it must be newer than 1.1 (code 2), the one every TV is offered now.']);

    $typed = uploadTheApp($this, ApkFiles::apk(ApkFiles::manifest(versionCode: null, versionName: null)));
    $this->postJson('/developer/app/releases', ['upload' => $typed, 'version_code' => 1, 'version_name' => '1.0'])->assertStatus(422)
        ->assertJsonValidationErrors(['version_code' => 'This is version 1.0 (code 1): it must be newer than 1.1 (code 2), the one every TV is offered now.']);

    expect(PlayerAppRelease::count())->toBe(1);
});

test('only the TV app: another kind of file is refused before a byte, and another app or no app once it is in', function () {
    $this->actingAs($this->admin);

    Tus::open($this, 1000, ['name' => 'menu.jpg', 'type' => 'image/jpeg', 'purpose' => 'app-release'])
        ->assertStatus(415)->assertSee(PlayerAppRelease::ONLY_AN_APK);
    Tus::open($this, PlayerAppRelease::MAX_BYTES + 1, ['name' => 'big.apk', 'type' => 'application/vnd.android.package-archive', 'purpose' => 'app-release'])
        ->assertStatus(413)->assertSee('The app may not be larger than 200 MB.');

    $other = uploadTheApp($this, ApkFiles::apk(ApkFiles::manifest(package: 'com.example.game')));
    $this->postJson('/developer/app/inspect', ['upload' => $other])->assertStatus(422)
        ->assertJsonValidationErrors(['file' => 'This is another app (com.example.game), not the TV app.']);
    $this->postJson('/developer/app/releases', ['upload' => $other])->assertStatus(422)
        ->assertJsonValidationErrors(['file' => 'This is another app (com.example.game), not the TV app.']);

    $notAnApp = uploadTheApp($this, '<html>surprise</html>', 'evil.apk');
    $this->postJson('/developer/app/inspect', ['upload' => $notAnApp])->assertStatus(422)->assertJsonValidationErrors(['file' => PlayerAppRelease::ONLY_AN_APK]);
    $this->postJson('/developer/app/releases', ['upload' => $notAnApp])->assertStatus(422)->assertJsonValidationErrors(['file' => PlayerAppRelease::ONLY_AN_APK]);

    expect(PlayerAppRelease::count())->toBe(0)->and(Storage::disk('public')->allFiles())->toBe([]);
});

test('nobody but the super admin opens an upload for the TV app', function () {
    $staff = createPlatformUser(['user-view'], 'Platform Staff');
    $this->actingAs($staff);
    Tus::open($this, 1000, ['name' => 'app.apk', 'type' => 'application/vnd.android.package-archive', 'purpose' => 'app-release'])->assertForbidden();

    $organization = Organization::factory()->create();
    $this->actingAs(createOrganizationUser($organization, ['media-store'], 'Uploader'))->withSession(['current_organization_id' => $organization->id]);
    Tus::open($this, 1000, ['name' => 'app.apk', 'type' => 'application/vnd.android.package-archive', 'purpose' => 'app-release'])->assertForbidden();
});

/* ── The list, and deleting ──────────────────────────────────────────────── */

test('the list says which version TVs are offered; deleting the newest offers the one before, and its file goes', function () {
    $this->actingAs($this->admin);
    $first = aRelease(1, '1.0');
    $second = aRelease(2, '1.1');

    $rows = $this->getJson('/developer/app/releases/data')->assertOk()->json('releases');
    expect(array_column($rows, 'version_code'))->toBe([2, 1])
        ->and(array_column($rows, 'current'))->toBe([true, false])
        ->and($rows[0]['url'])->toBe(Storage::disk('public')->url($second->path));

    $this->deleteJson("/developer/app/releases/{$second->id}")->assertOk()
        ->assertJsonPath('message', 'Version 1.1 is deleted. TVs are offered 1.0 now; a TV that has 1.1 keeps it.');
    Storage::disk('public')->assertMissing($second->path);
    Storage::disk('public')->assertExists($first->path);
    expect(ActivityLog::where('action', 'player_app.deleted')->sole()->description)->toBe('Deleted version 1.1 (code 2) of the TV app');
    $this->getJson('/player-app/latest.json')->assertOk()->assertJsonPath('version_code', 1);

    $this->deleteJson("/developer/app/releases/{$first->id}")->assertOk()
        ->assertJsonPath('message', 'Version 1.0 is deleted. No version is offered to TVs now.');
    $this->getJson('/player-app/latest.json')->assertNotFound()->assertJsonPath('message', 'No version of the TV app has been uploaded yet.');
});
