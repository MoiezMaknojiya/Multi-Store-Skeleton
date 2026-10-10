<?php

use App\Models\Organization;
use App\Models\PlayerAppRelease;
use App\Models\Upload;
use App\Services\ApkInspector;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Support\ApkFiles;
use Tests\Support\Tus;

/*
|--------------------------------------------------------------------------
| Developer → TV App, attacked
|--------------------------------------------------------------------------
|
| What every TV is offered to install comes from here, so nobody but the super admin may put a file behind it, and the file
| must be the TV app with a version newer than the last. These try the ways around that: the endpoints as anybody else,
| somebody else's upload or one opened for another door, fields in every shape, manifests built to break the reader, two
| uploads of one version at the same moment, and the open address a television reads, asked too often.
|
*/

beforeEach(function () {
    Storage::fake('public');
    Storage::fake('uploads');
    $this->admin = createSuperAdmin();
});

/** An APK of the TV app uploaded in chunks for Developer → TV App, as whoever is signed in; the upload's id. */
function uploadAnApp(object $test, string $bytes, string $name = 'app.apk'): string
{
    return Tus::upload($test, $bytes, ['name' => $name, 'type' => 'application/vnd.android.package-archive', 'purpose' => 'app-release']);
}

/** What the inspector makes of a manifest inside an otherwise good APK. */
function readManifest(string $manifest): array
{
    $path = tempnam(sys_get_temp_dir(), 'apk');
    file_put_contents($path, ApkFiles::apk($manifest));

    try {
        return app(ApkInspector::class)->inspect($path);
    } finally {
        unlink($path);
    }
}

test('every endpoint is shut to anybody but the super admin, whatever their role was given by hand', function () {
    $release = PlayerAppRelease::create(['version_code' => 1, 'version_name' => '1.0', 'path' => 'player-app/x.apk', 'size' => 1, 'sha256' => str_repeat('a', 64)]);
    $all = DB::table('permissions')->whereIn('name', ['player-app-view', 'player-app-store', 'player-app-destroy'])->pluck('id');

    $staff = createPlatformUser(['user-view', 'organization-view'], 'Platform Staff');
    $organization = Organization::factory()->create();
    $owner = createOrganizationMember($organization);

    $ownerRole = DB::table('organization_user')->where('user_id', $owner->id)->where('organization_id', $organization->id)->value('role_id');

    foreach ([$staff->globalRole()->id, $ownerRole] as $roleId) {
        foreach ($all as $permission) {
            DB::table('role_has_permissions')->insertOrIgnore(['role_id' => $roleId, 'permission_id' => $permission, 'created_at' => now(), 'updated_at' => now()]);
        }
    }

    foreach ([[$staff, null], [$owner, $organization->id]] as [$person, $organizationId]) {
        $this->actingAs($person)->withSession(['current_organization_id' => $organizationId]);

        $this->get('/developer')->assertForbidden();
        $this->getJson('/developer/app/releases/data')->assertForbidden();
        $this->postJson('/developer/app/inspect', ['upload' => (string) Str::uuid()])->assertForbidden();
        $this->postJson('/developer/app/releases', ['version_code' => 9, 'version_name' => '9.0'])->assertForbidden();
        $this->deleteJson("/developer/app/releases/{$release->id}")->assertForbidden();
        Tus::open($this, 1000, ['name' => 'app.apk', 'type' => 'application/vnd.android.package-archive', 'purpose' => 'app-release'])->assertForbidden();
    }

    expect(PlayerAppRelease::count())->toBe(1)->and(Upload::count())->toBe(0);
});

test("another super admin's upload, and an upload opened for another door, are not this door's", function () {
    $this->actingAs($this->admin);
    $mine = uploadAnApp($this, ApkFiles::apk());

    $other = createSuperAdmin();
    $this->actingAs($other);
    $this->postJson('/developer/app/inspect', ['upload' => $mine])->assertStatus(422)
        ->assertJsonValidationErrors(['file' => 'That upload is not finished, or it has expired. Choose the file again.']);
    $this->postJson('/developer/app/releases', ['upload' => $mine])->assertStatus(422)
        ->assertJsonValidationErrors(['file' => 'That upload is not finished, or it has expired. Choose the file again.']);

    // An APK taken in as a library file is the Media page's: it may not become the TV app, nor the TV app's a library file.
    $this->actingAs($this->admin);
    $media = Tus::open($this, 100, ['name' => 'menu.jpg', 'type' => 'image/jpeg', 'purpose' => 'media']);
    $mediaId = Tus::idOf($media);
    $this->postJson('/developer/app/inspect', ['upload' => $mediaId])->assertStatus(422);
    $this->postJson('/developer/app/releases', ['upload' => $mediaId])->assertStatus(422)->assertJsonValidationErrors(['file']);
    $this->postJson('/media', ['upload' => $mine])->assertStatus(422)->assertJsonValidationErrors(['file']);

    expect(PlayerAppRelease::count())->toBe(0);
});

test('fields in every shape are refused in our own words, never a 500, and nothing is written', function () {
    $this->actingAs($this->admin);
    $unread = fn () => uploadAnApp($this, ApkFiles::apk(ApkFiles::manifest(versionCode: null, versionName: null)));

    $shapes = [
        'upload as a list' => [['upload' => ['x']], 'file', 'That upload is not finished, or it has expired. Choose the file again.'],
        'upload not an id' => [['upload' => "' OR 1=1 --"], 'file', 'That upload is not finished, or it has expired. Choose the file again.'],
        'no file at all' => [['version_code' => 2, 'version_name' => '1.1'], 'file', 'Choose the APK to upload.'],
        'code as a list' => [['upload' => $unread(), 'version_code' => [2], 'version_name' => '1.1'], 'version_code', 'The version code is a whole number, such as 2.'],
        'code with a point' => [['upload' => $unread(), 'version_code' => '2.5', 'version_name' => '1.1'], 'version_code', 'The version code is a whole number, such as 2.'],
        'code as hex' => [['upload' => $unread(), 'version_code' => '0x10', 'version_name' => '1.1'], 'version_code', 'The version code is a whole number, such as 2.'],
        'code nought' => [['upload' => $unread(), 'version_code' => 0, 'version_name' => '1.1'], 'version_code', 'The version code is a whole number, such as 2.'],
        'code below nought' => [['upload' => $unread(), 'version_code' => -3, 'version_name' => '1.1'], 'version_code', 'The version code is a whole number, such as 2.'],
        'code too big for Android' => [['upload' => $unread(), 'version_code' => '99999999999', 'version_name' => '1.1'], 'version_code', 'The version code is a whole number, such as 2.'],
        'name as a list' => [['upload' => $unread(), 'version_code' => 2, 'version_name' => ['1.1']], 'version_name', 'The version name is letters, numbers, dots and dashes, such as 1.1.'],
        'name with tags' => [['upload' => $unread(), 'version_code' => 2, 'version_name' => '<script>x</script>'], 'version_name', 'The version name is letters, numbers, dots and dashes, such as 1.1.'],
        'name as a path' => [['upload' => $unread(), 'version_code' => 2, 'version_name' => '../../.env'], 'version_name', 'The version name is letters, numbers, dots and dashes, such as 1.1.'],
        'name too long' => [['upload' => $unread(), 'version_code' => 2, 'version_name' => str_repeat('1', 33)], 'version_name', 'The version name may be 32 characters at most.'],
        'notes as a list' => [['upload' => $unread(), 'version_code' => 2, 'version_name' => '1.1', 'notes' => ['x']], 'notes', 'What is new is text, 500 characters at most.'],
        'notes too long' => [['upload' => $unread(), 'version_code' => 2, 'version_name' => '1.1', 'notes' => str_repeat('a', 501)], 'notes', 'What is new may be 500 characters at most.'],
    ];

    foreach ($shapes as $shape => [$body, $field, $words]) {
        $response = $this->postJson('/developer/app/releases', $body);

        expect($response->status())->toBe(422, "{$shape} answered {$response->status()}")
            ->and($response->json("errors.{$field}.0"))->toBe($words, "{$shape} said something else");
    }

    expect(PlayerAppRelease::count())->toBe(0)->and(Storage::disk('public')->allFiles())->toBe([]);
});

test('a version typed with tags or a path never reaches a file name, and the file stays in its folder', function () {
    $this->actingAs($this->admin);
    $id = uploadAnApp($this, ApkFiles::apk(ApkFiles::manifest(versionCode: null, versionName: null)));

    $this->postJson('/developer/app/releases', ['upload' => $id, 'version_code' => 7, 'version_name' => '1.0 beta+rc_1'])->assertOk();

    expect(PlayerAppRelease::sole()->path)->toBe('player-app/TheDisplaySolution-1.0-beta-rc_1-7.apk')
        ->and(Storage::disk('public')->allFiles())->toBe(['player-app/TheDisplaySolution-1.0-beta-rc_1-7.apk']);
});

test('a manifest built to break the reader reads as nothing, never as an error or as a version it does not say', function () {
    $good = ApkFiles::manifest(versionCode: 5, versionName: '1.4');

    // Words in the manifest that are no version, and a package that is no package: asked for, never kept.
    expect(readManifest(ApkFiles::manifest(versionName: '<b>1.0</b>')))->toMatchArray(['version_name' => null, 'version_code' => 2])
        ->and(readManifest(ApkFiles::manifest(versionName: str_repeat('9', 40))))->toMatchArray(['version_name' => null])
        ->and(readManifest(ApkFiles::manifest(package: '../../evil')))->toMatchArray(['package' => null])
        ->and(readManifest(ApkFiles::manifest(versionCode: 0)))->toMatchArray(['version_code' => null]);

    // Every size, count and offset a header has, set to the extremes.
    $poke = function (string $bytes, int $at, string $value): string {
        return substr_replace($bytes, $value, $at, strlen($value));
    };
    $extremes = [pack('V', 0), pack('V', 0xFFFFFFFF), pack('V', 0x7FFFFFFF), pack('V', 1), pack('V', 8)];
    $shortExtremes = [pack('v', 0), pack('v', 0xFFFF), pack('v', 8), pack('v', 1)];

    for ($at = 0; $at + 4 <= strlen($good); $at += 2) {
        foreach ($extremes as $value) {
            $read = readManifestOnly($poke($good, $at, $value));
            expect($read['version_name'] === null || preg_match('/^[\pL\pN ._+-]{1,32}$/u', $read['version_name']) === 1)->toBeTrue()
                ->and($read['package'] === null || preg_match('/^[A-Za-z][\w.]{0,199}$/', $read['package']) === 1)->toBeTrue()
                ->and($read['version_code'] === null || ($read['version_code'] >= 1 && $read['version_code'] <= 2100000000))->toBeTrue();
        }
        foreach ($shortExtremes as $value) {
            readManifestOnly($poke($good, $at, $value));
        }
    }

    // Bytes broken by chance, the same way every run.
    mt_srand(20261010);
    for ($round = 0; $round < 300; $round++) {
        $broken = $good;
        for ($n = mt_rand(1, 8); $n > 0; $n--) {
            $broken[mt_rand(0, strlen($broken) - 1)] = chr(mt_rand(0, 255));
        }
        $read = readManifestOnly(mt_rand(0, 4) === 0 ? substr($broken, 0, mt_rand(0, strlen($broken))) : $broken);
        expect($read['version_name'] === null || preg_match('/^[\pL\pN ._+-]{1,32}$/u', $read['version_name']) === 1)->toBeTrue();
    }
});

/** What the reader makes of manifest bytes alone; it must never throw. */
function readManifestOnly(string $bytes): array
{
    return app(ApkInspector::class)->manifest($bytes);
}

test('a manifest larger than any app has is not read at all, and the version is asked for', function () {
    $huge = ApkFiles::manifest().str_repeat("\0", 2 * 1024 * 1024 + 10);

    expect(readManifest($huge))->toBe(['is_apk' => true, 'package' => null, 'version_code' => null, 'version_name' => null]);
});

test('two uploads of one version at the same moment make one row, and the loser’s file is taken away', function () {
    $this->actingAs($this->admin);
    $id = uploadAnApp($this, ApkFiles::apk(ApkFiles::manifest(versionCode: 3, versionName: '1.2')));

    // The other tab's row lands between this request's checks and its own row.
    PlayerAppRelease::creating(function () {
        static $once = false;
        if (! $once) {
            $once = true;
            DB::table('player_app_releases')->insert([
                'version_code' => 3, 'version_name' => '1.2', 'version_read_from_file' => true, 'path' => 'player-app/other-tab.apk',
                'size' => 1, 'sha256' => str_repeat('b', 64), 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    });

    $this->postJson('/developer/app/releases', ['upload' => $id])->assertStatus(422)
        ->assertJsonValidationErrors(['file' => 'Version code 3 has just been uploaded. Reload the page.']);

    expect(PlayerAppRelease::count())->toBe(1)
        ->and(PlayerAppRelease::sole()->path)->toBe('player-app/other-tab.apk')
        ->and(Storage::disk('public')->allFiles())->toBe([]);
});

test('deleting a version that is gone, or an id that is no id, says it is not there', function () {
    $this->actingAs($this->admin);
    $release = PlayerAppRelease::create(['version_code' => 1, 'version_name' => '1.0', 'path' => 'player-app/x.apk', 'size' => 1, 'sha256' => str_repeat('a', 64)]);

    $this->deleteJson("/developer/app/releases/{$release->id}")->assertOk();
    $this->deleteJson("/developer/app/releases/{$release->id}")->assertNotFound();
    $this->deleteJson('/developer/app/releases/abc')->assertNotFound();
    $this->deleteJson('/developer/app/releases/'.urlencode('1 OR 1=1'))->assertNotFound();
});

test('the address a television reads takes nothing, writes nothing and is limited per address', function () {
    PlayerAppRelease::create(['version_code' => 1, 'version_name' => '1.0', 'path' => 'player-app/x.apk', 'size' => 1, 'sha256' => str_repeat('a', 64)]);

    $this->postJson('/player-app/latest.json')->assertStatus(405);
    $this->deleteJson('/player-app/latest.json')->assertStatus(405);
    $this->getJson('/player-app/latest.json?version_code[]=x&package=<script>')->assertOk()->assertJsonPath('version_code', 1);

    for ($i = 2; $i <= 60; $i++) {
        $this->getJson('/player-app/latest.json');
    }
    $this->getJson('/player-app/latest.json')->assertStatus(429);

    // Another address has a budget of its own.
    $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.9'])->getJson('/player-app/latest.json')->assertOk();
});
