<?php

namespace Tests\Browser;

use App\Models\PlayerAppRelease;
use Facebook\WebDriver\Chrome\ChromeDevToolsDriver;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Storage;
use Laravel\Dusk\Browser;
use Tests\DuskTestCase;
use Tests\Support\ApkFiles;

/**
 * Developer → TV App (owner, 2026-10-10): the super admin uploads the TV app through the shared uploader, the page shows the
 * version read from the file — or asks for it, half or whole, when the file does not say it — and Publish Version makes it
 * the version every TV is offered. Pressed as a person presses (presses sent to Chrome itself with a double click's count),
 * and watched: one request per Publish, the dialog that was asked for, nothing thrown.
 */
class DeveloperAppFlowTest extends DuskTestCase
{
    use DatabaseMigrations;

    /** @var list<string> */
    private array $made = [];

    protected function tearDown(): void
    {
        foreach ($this->made as $path) {
            @unlink($path);
            @rmdir(dirname($path));
        }

        parent::tearDown();
    }

    public function test_the_super_admin_publishes_the_version_the_file_says_with_one_request_for_a_double_press(): void
    {
        $admin = $this->seedSuperAdmin();
        $apk = $this->apkFile('TheDisplaySolution-1.0.apk', ApkFiles::apk(ApkFiles::manifest(versionCode: 1, versionName: '1.0')));

        $this->browse(function (Browser $browser) use ($admin, $apk) {
            $this->freshSession($browser);
            $browser->loginAs($admin)->visit('/dashboard');
            $this->waitForAlpine($browser);

            // Developer is the sidebar's last link.
            $last = $browser->script('const links = [...document.querySelectorAll("#main-sidebar nav a")]; return links[links.length - 1]?.getAttribute("href");')[0];
            $this->assertStringEndsWith('/developer', (string) $last);

            $browser->visit('/developer');
            $this->waitForAlpine($browser);
            $this->watch($browser);
            $browser->waitForText('No version uploaded yet.')
                ->assertAttribute('@tv-app-publish', 'disabled', 'true');

            $this->uploadThrough($browser, 'tv-app', $apk);
            $browser->waitFor('@tv-app-version-read')
                ->assertSeeIn('@tv-app-version-read', 'Version 1.0 (code 1), read from the file.')
                ->assertMissing('@tv-app-version-typed')
                ->type('@tv-app-notes', 'The first release.');

            $this->countRequests($browser, 'POST', '/developer/app/releases');
            $this->presses($browser, $this->centreOf($browser, '[dusk="tv-app-publish"]'), 2);

            $browser->waitFor('@tv-app-release-1')
                ->assertSeeIn('@tv-app-release-1', 'Offered to TVs')
                ->assertSeeIn('@tv-app-release-1', 'The first release.')
                ->assertDontSeeIn('@tv-app-release-1', 'Typed')
                ->waitForText('Version 1.0 is uploaded')
                // The form starts again: no file, no version, no notes.
                ->assertMissing('@tv-app-version-read')
                ->assertInputValue('@tv-app-notes', '');

            $this->assertSame(1, $this->requestsCounted($browser), 'A double press published twice');
            $this->assertCalm($browser);
        });

        $release = PlayerAppRelease::sole();
        $this->assertSame([1, '1.0', true], [$release->version_code, $release->version_name, $release->version_read_from_file]);
        $this->assertSame(hash_file('sha256', $apk), $release->sha256);
        $this->assertTrue(Storage::disk('public')->exists($release->path));
    }

    public function test_a_version_the_file_does_not_say_is_typed_and_must_be_newer(): void
    {
        $admin = $this->seedSuperAdmin();
        PlayerAppRelease::create(['version_code' => 1, 'version_name' => '1.0', 'path' => 'player-app/TheDisplaySolution-1.0-1.apk', 'size' => 10, 'sha256' => str_repeat('a', 64)]);
        $none = $this->apkFile('no-version.apk', ApkFiles::apk(ApkFiles::manifest(versionCode: null, versionName: null)));
        $half = $this->apkFile('half-version.apk', ApkFiles::apk(ApkFiles::manifest(versionCode: 3, versionName: null)));
        $other = $this->apkFile('a-game.apk', ApkFiles::apk(ApkFiles::manifest(package: 'com.example.game')));

        $this->browse(function (Browser $browser) use ($admin, $none, $half, $other) {
            $this->freshSession($browser);
            $browser->loginAs($admin)->visit('/developer');
            $this->waitForAlpine($browser);
            $this->watch($browser);
            $browser->waitFor('@tv-app-release-1');

            // Another app is refused under the file, before anything can be published.
            $this->uploadThrough($browser, 'tv-app', $other);
            $browser->waitForText('This is another app (com.example.game), not the TV app.')
                ->assertAttribute('@tv-app-publish', 'disabled', 'true');
            $browser->script('document.querySelector(\'[dusk="tv-app-upload-remove"]\').click();');

            // A file with no version: both halves are asked, checked on the page and then by the server.
            $this->uploadThrough($browser, 'tv-app', $none);
            $browser->waitFor('@tv-app-version-typed')
                ->assertSeeIn('@tv-app-version-typed', 'The version could not be read from this file.')
                ->assertVisible('@tv-app-version-name')
                ->assertVisible('@tv-app-version-code')
                ->assertSeeIn('@tv-app-current', 'TVs are offered 1.0 (code 1) now');

            $this->countRequests($browser, 'POST', '/developer/app/releases');
            $browser->click('@tv-app-publish')
                ->waitForText('Type the version name, such as 1.1.')
                ->assertSee('Type the version code, such as 2.');
            $this->assertSame(0, $this->requestsCounted($browser), 'An empty version was sent');

            $browser->type('@tv-app-version-name', '1.1')->type('@tv-app-version-code', '1')->click('@tv-app-publish')
                ->waitForText('This is version 1.1 (code 1): it must be newer than 1.0 (code 1), the one every TV is offered now.');

            $browser->clear('@tv-app-version-code')->type('@tv-app-version-code', '2')->click('@tv-app-publish')
                ->waitFor('@tv-app-release-2')
                ->assertSeeIn('@tv-app-release-2', 'Offered to TVs')
                ->assertSeeIn('@tv-app-release-2', 'Typed')
                ->assertDontSeeIn('@tv-app-release-1', 'Offered to TVs');

            // A file with half a version: only the other half is asked.
            $this->uploadThrough($browser, 'tv-app', $half);
            $browser->waitFor('@tv-app-version-typed')
                ->assertSeeIn('@tv-app-version-typed', 'The file says version code 3, but not its name.')
                ->assertVisible('@tv-app-version-name')
                ->assertMissing('@tv-app-version-code')
                ->type('@tv-app-version-name', '1.2')
                ->click('@tv-app-publish')
                ->waitFor('@tv-app-release-3');

            $this->assertCalm($browser);
        });

        $this->assertSame([3, '1.2', false], [PlayerAppRelease::current()->version_code, PlayerAppRelease::current()->version_name, PlayerAppRelease::current()->version_read_from_file]);
    }

    public function test_deleting_the_newest_version_asks_once_even_for_a_double_press_and_offers_the_one_before(): void
    {
        $admin = $this->seedSuperAdmin();
        foreach ([[1, '1.0'], [2, '1.1']] as [$code, $name]) {
            $path = 'player-app/'.PlayerAppRelease::fileNameFor($name, $code);
            Storage::disk('public')->put($path, 'apk');
            PlayerAppRelease::create(['version_code' => $code, 'version_name' => $name, 'path' => $path, 'size' => 3, 'sha256' => str_repeat('a', 64)]);
        }

        $this->browse(function (Browser $browser) use ($admin) {
            $this->freshSession($browser);
            $browser->loginAs($admin)->visit('/developer');
            $this->waitForAlpine($browser);
            $this->watch($browser);
            $browser->waitFor('@tv-app-release-2');

            // The dialog is drawn over the button: the second press of a double click must not shut it.
            $this->presses($browser, $this->centreOf($browser, '[dusk="tv-app-delete-2"]'), 2);
            $browser->pause(600)
                ->assertVisible('@confirm-tv-app-deletion-confirm')
                ->assertSee('TVs are offered the version before it instead');

            $this->countRequests($browser, 'DELETE', '/developer/app/releases/2');
            $this->presses($browser, $this->centreOf($browser, '[dusk="confirm-tv-app-deletion-confirm"]'), 2);
            $browser->waitForText('Version 1.1 is deleted. TVs are offered 1.0 now; a TV that has 1.1 keeps it.')
                ->waitUntilMissing('@tv-app-release-2')
                ->waitForTextIn('@tv-app-release-1', 'Offered to TVs');

            $this->assertSame(1, $this->requestsCounted($browser), 'A double press deleted twice');
            $this->assertCalm($browser);
        });

        $this->assertSame(1, PlayerAppRelease::count());
        $this->assertFalse(Storage::disk('public')->exists('player-app/'.PlayerAppRelease::fileNameFor('1.1', 2)));
    }

    /** A file of these bytes with this name, in a folder of its own; taken away after the test. */
    private function apkFile(string $name, string $bytes): string
    {
        $folder = sys_get_temp_dir().DIRECTORY_SEPARATOR.'dusk-apk-'.bin2hex(random_bytes(4));
        mkdir($folder);
        $path = $folder.DIRECTORY_SEPARATOR.$name;
        file_put_contents($path, $bytes);
        $this->made[] = $path;

        return $path;
    }

    /* ── Watching the page, and a person's mouse ────────────────────────── */

    private function watch(Browser $browser): void
    {
        $browser->script(<<<'JS'
            if (window.__w) return;
            window.__w = { errors: [] };
            window.addEventListener('error', (e) => window.__w.errors.push(String(e.message)));
            window.addEventListener('unhandledrejection', (e) => window.__w.errors.push(String(e.reason)));
            const said = console.error;
            console.error = function (...parts) { window.__w.errors.push(parts.map(String).join(' ')); return said.apply(this, parts); };
        JS);
    }

    private function assertCalm(Browser $browser): void
    {
        $this->assertSame([], $browser->script('return window.__w ? window.__w.errors : [];')[0], 'The page threw or logged errors');
    }

    private function presses(Browser $browser, array $at, int $times, int $gap = 120): void
    {
        $tools = new ChromeDevToolsDriver($browser->driver);
        $tools->execute('Input.dispatchMouseEvent', ['type' => 'mouseMoved', 'x' => $at[0], 'y' => $at[1]]);

        for ($press = 1; $press <= $times; $press++) {
            foreach (['mousePressed', 'mouseReleased'] as $type) {
                $tools->execute('Input.dispatchMouseEvent', [
                    'type' => $type, 'x' => $at[0], 'y' => $at[1], 'button' => 'left',
                    'buttons' => $type === 'mousePressed' ? 1 : 0, 'clickCount' => min($press, 3),
                ]);
            }
            if ($press < $times) {
                usleep($gap * 1000);
            }
        }
    }

    private function centreOf(Browser $browser, string $css): array
    {
        return $browser->script('const el = document.querySelector('.json_encode($css).'); el.scrollIntoView({ block: "center" }); '
            .'const r = el.getBoundingClientRect(); return [Math.round(r.left + r.width / 2), Math.round(r.top + r.height / 2)];')[0];
    }
}
