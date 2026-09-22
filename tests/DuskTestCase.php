<?php

namespace Tests;

use App\Models\BuilderAd;
use App\Models\BuilderAsset;
use App\Models\BuilderFont;
use App\Models\Campaign;
use App\Models\Media;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Store;
use App\Models\User;
use Facebook\WebDriver\Chrome\ChromeOptions;
use Facebook\WebDriver\Exception\TimeoutException;
use Facebook\WebDriver\Remote\DesiredCapabilities;
use Facebook\WebDriver\Remote\RemoteWebDriver;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Laravel\Dusk\Browser;
use Laravel\Dusk\TestCase as BaseTestCase;
use PHPUnit\Framework\Attributes\BeforeClass;
use RuntimeException;

abstract class DuskTestCase extends BaseTestCase
{
    /** @var list<string> media files present before this test ran */
    private array $mediaFilesAtStart = [];

    /**
     * Prepare for Dusk test execution.
     */
    #[BeforeClass]
    public static function prepare(): void
    {
        if (! static::runningInSail()) {
            static::startChromeDriver(['--port=9515']);
        }
    }

    /**
     * Hard safety net: Dusk wipes its database fresh on every test, so it must
     * NEVER be pointed at anything but the throwaway database/dusk.sqlite file.
     * Checked from the raw environment BEFORE the app boots or migrates anything.
     */
    protected function setUp(): void
    {
        $connection = getenv('DB_CONNECTION');
        $database = (string) getenv('DB_DATABASE');

        if ($connection !== 'sqlite' || ! str_contains($database, 'dusk.sqlite')) {
            static::fail(
                'Dusk tests must run against database/dusk.sqlite (via phpunit.dusk.xml). '
                ."Refusing to touch [{$connection}:{$database}]."
            );
        }

        parent::setUp();

        // Snapshot the media folder BEFORE the test writes anything, so tearDown
        // can tell what this test added from what was already there.
        $this->mediaFilesAtStart = $this->mediaFilesOnDisk();
    }

    /**
     * The disk half of the safety net above.
     *
     * Dusk writes to its own disk root now (storage/app/dusk-public — see the
     * public disk in config/filesystems.php), so nothing here can reach a real
     * upload. This sweep is the second line: without it a run still accumulates
     * files forever, because a test that FAILS never reaches the cleanup at the
     * end of its own body — which is how four stray files once piled up.
     *
     * It sweeps ONLY files that (a) appeared while this test ran and (b) one of
     * this test's own database rows points at. Anything else is left alone and
     * reported rather than removed. That caution is deliberate and stays even
     * with the disks separated: deleting on a guess in this folder's predecessor
     * once destroyed three of the owner's real videos.
     *
     * This runs before parent::tearDown() on purpose — DatabaseMigrations rolls
     * the database back in there, and the rows are needed to identify the files.
     */
    protected function tearDown(): void
    {
        $appeared = array_values(array_diff($this->mediaFilesOnDisk(), $this->mediaFilesAtStart));

        if ($appeared !== []) {
            $disk = Storage::disk('public');
            $ours = $this->pathsOwnedByThisTest();

            foreach ($appeared as $file) {
                if (isset($ours[$file])) {
                    $disk->delete($file);
                } else {
                    fwrite(STDERR, PHP_EOL."  Dusk left a file it cannot claim: {$file}".PHP_EOL);
                }
            }
        }

        parent::tearDown();
    }

    /**
     * Every file on the shelves a test can write to: the media libraries (a store's,
     * and the platform's under media/platform), the shelf channels kept their files on
     * before they took them from the libraries, the campaigns, and the Ad Builder's own
     * (its assets and published pages, and the fonts it installs).
     *
     * @return list<string>
     */
    private function mediaFilesOnDisk(): array
    {
        try {
            $disk = Storage::disk('public');

            return array_merge(...array_map(
                fn (string $shelf) => $disk->allFiles($shelf),
                ['media', 'channels', 'campaigns', 'builder', 'fonts'],
            ));
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * Every file path a row of the (throwaway) test database points at: the media library's files and
     * thumbnails (a channel's ads among them — a channel holds library rows, docs/CHANNEL-CONTENT-SPEC.md),
     * the campaigns, and the Ad Builder's assets, the posters of its ads and the fonts it installed.
     *
     * @return array<string, true>
     */
    private function pathsOwnedByThisTest(): array
    {
        $paths = [];

        try {
            $rows = collect()
                ->concat(Media::withoutGlobalScopes()->get())
                ->concat(Campaign::all());

            foreach ($rows as $row) {
                foreach (array_filter([$row->path, $row->thumbnail_path]) as $path) {
                    $paths[str_replace('\\', '/', $path)] = true;
                }
            }

            // The Ad Builder: its shelf, the posters of its ads (a published page is a media row,
            // above), and the fonts it installed — each named by a row of this test's own.
            foreach (BuilderAsset::all() as $asset) {
                foreach (array_filter([$asset->path, $asset->thumbnail_path]) as $path) {
                    $paths[str_replace('\\', '/', $path)] = true;
                }
            }

            foreach (BuilderAd::whereNotNull('thumbnail_path')->pluck('thumbnail_path') as $path) {
                $paths[str_replace('\\', '/', $path)] = true;
            }

            foreach (BuilderFont::all() as $font) {
                foreach (array_filter([$font->css_path, ...($font->files ?? [])]) as $path) {
                    $paths[str_replace('\\', '/', $path)] = true;
                }
            }
        } catch (\Throwable) {
            // No usable database (already torn down, table missing): claim nothing,
            // and therefore delete nothing.
            return [];
        }

        return $paths;
    }

    /** Seed the base data (permissions, Super-Admin role + user, the Owner role if it is missing) and return the super admin. */
    protected function seedSuperAdmin(): User
    {
        $this->seed();

        return User::where('email', 'admin@gmail.com')->firstOrFail();
    }

    /**
     * A member of the store. Pass a starter role key (Role::OWNER, Role::STAFF…) for one of the
     * starter store roles, or a list of permission names for a custom role of that store holding
     * exactly those. Seed first (seedSuperAdmin) so the roles and permissions exist.
     *
     * @param  string|array<int, string>  $roleKeyOrPermissions
     */
    protected function storeMember(Store $store, string|array $roleKeyOrPermissions = Role::OWNER, string $email = 'owner@example.com', string $roleName = 'Shop Team'): User
    {
        if (is_string($roleKeyOrPermissions)) {
            $roleId = Role::starter($roleKeyOrPermissions)->id;
        } else {
            $role = Role::create(['name' => $roleName, 'store_id' => $store->id]);
            $role->permissions()->sync(Permission::whereIn('name', $roleKeyOrPermissions)->pluck('id'));
            $roleId = $role->id;
        }

        $user = User::factory()->create(['email' => $email]);
        $user->stores()->attach($store->id, ['role_id' => $roleId]);

        return $user;
    }

    /** How much the log already holds — hand it to tokenFromMailLog so only mail sent after now is read. */
    protected function mailLogSize(): int
    {
        $path = storage_path('logs/laravel.log');
        clearstatcache(true, $path);

        return file_exists($path) ? (int) filesize($path) : 0;
    }

    /**
     * The token in a link of the email the app wrote to the log after $from (MAIL_MAILER=log), so
     * a test follows the link a person would really receive — `reset-password`, `invitations`.
     *
     * Only the part written after $from is read, so an earlier test's message can never be
     * mistaken for this one. Mail is encoded quoted-printable, which folds long lines with a
     * trailing "=", and a token is easily long enough to be split in half — so the soft breaks
     * are undone before anything is matched.
     */
    protected function tokenFromMailLog(int $from, string $linkPath): string
    {
        $path = storage_path('logs/laravel.log');

        if (! file_exists($path)) {
            throw new RuntimeException('No log file: the application never wrote the email.');
        }

        $written = (string) file_get_contents($path, false, null, $from);
        $unfolded = str_replace(["=\r\n", "=\n"], '', $written);

        if (! preg_match('#/'.preg_quote($linkPath, '#').'/([A-Za-z0-9]{20,})#', $unfolded, $match)) {
            throw new RuntimeException("The email carried no {$linkPath} link.");
        }

        return $match[1];
    }

    /** Dusk keeps its first browser open from one test of a class to the next (it
     *  closes it only once the class is done), so cookies (auth + the selected store)
     *  carry over from test to test — start each test with none. */
    protected function freshSession(Browser $browser): void
    {
        $browser->driver->manage()->deleteAllCookies();
    }

    /** Wait until a modal is shut, so the next click cannot land on its backdrop. The
     *  modal (components/modal.blade.php) has no transition: the form, the panel and the
     *  backdrop are hidden in the same update, so the form going is the whole of it. */
    protected function waitForModalClosed(Browser $browser, string $formSelector): void
    {
        $browser->waitUntilMissing($formSelector);
    }

    /**
     * A real PNG of one flat colour, written where a file input can attach it
     * (storage/framework/testing — never the disk the application serves), so an
     * upload goes through the same checks a person's file does. Returns its path.
     */
    protected function fixtureImage(string $name, int $r = 30, int $g = 120, int $b = 200): string
    {
        $directory = storage_path('framework/testing');
        File::ensureDirectoryExists($directory);
        $path = $directory.DIRECTORY_SEPARATOR.$name;

        $image = imagecreatetruecolor(640, 360);
        imagefilledrectangle($image, 0, 0, 640, 360, imagecolorallocate($image, $r, $g, $b));
        imagepng($image, $path);

        return $path;
    }

    /**
     * A real PNG put straight onto the (isolated) Dusk disk at $path, for a row the
     * test plants in the database to name — the player then has a genuine picture to
     * fetch from /dusk-storage. Returns $path.
     */
    protected function putImage(string $path, int $r, int $g, int $b): string
    {
        $image = imagecreatetruecolor(640, 360);
        imagefilledrectangle($image, 0, 0, 640, 360, imagecolorallocate($image, $r, $g, $b));

        ob_start();
        imagepng($image);
        $binary = (string) ob_get_clean();

        Storage::disk('public')->put($path, $binary);

        return $path;
    }

    /**
     * Start counting the requests the page's own scripts send with $method to an
     * address ending in $path — XMLHttpRequest (what axios uses) and fetch alike.
     *
     * A check in the browser and the server's own often answer with the very same
     * words, and the database cannot tell them apart either, so the count is what
     * proves a check stopped something before it left.
     */
    protected function countRequests(Browser $browser, string $method, string $path): void
    {
        $what = json_encode(['method' => strtoupper($method), 'path' => $path], JSON_UNESCAPED_SLASHES);

        // The hooks go in once per page; asking again only starts a fresh count, for the new
        // method and address, so nothing is ever counted twice.
        $browser->script(<<<JS
            window.__requestsCounted = 0;
            window.__countingWhat = {$what};

            if (! window.__countingRequests) {
                window.__countingRequests = true;

                const matches = (method, url) => String(method || 'GET').toUpperCase() === window.__countingWhat.method
                    && String(url).split('?')[0].endsWith(window.__countingWhat.path);

                const open = XMLHttpRequest.prototype.open;
                XMLHttpRequest.prototype.open = function (method, url, ...rest) {
                    if (matches(method, url)) window.__requestsCounted++;
                    return open.call(this, method, url, ...rest);
                };

                const send = window.fetch;
                window.fetch = function (input, init = {}) {
                    const url = typeof input === 'string' ? input : (input.url ?? String(input));
                    if (matches(init.method ?? input.method, url)) window.__requestsCounted++;
                    return send.call(window, input, init);
                };
            }
        JS);
    }

    /** How many of the requests countRequests() was asked about have left the page since. */
    protected function requestsCounted(Browser $browser): int
    {
        return (int) $browser->script('return window.__requestsCounted ?? -1;')[0];
    }

    /**
     * Start recording what becomes of every plain (full-page) form submit on this page.
     *
     * A listener on the window hears a submit last — after the form's own handler and
     * after the global guard (resources/js/core/form-guard.js) — so `defaultPrevented`
     * there is the final word: 'stopped' means the browser sent nothing at all. A page
     * that did send one is replaced by the server's answer, and the record goes with it.
     */
    protected function recordFormSubmits(Browser $browser): void
    {
        // One listener per page, however often this is asked; asking again starts a fresh record.
        $browser->script(<<<'JS'
            window.__formSubmits = [];

            if (! window.__recordingFormSubmits) {
                window.__recordingFormSubmits = true;
                window.addEventListener('submit', (event) => {
                    window.__formSubmits.push(event.defaultPrevented ? 'stopped' : 'sent');
                });
            }
        JS);
    }

    /**
     * Every submit since recordFormSubmits(), in order — or null once the page has
     * been replaced, which is what a submit that went through does to it.
     *
     * @return list<string>|null
     */
    protected function formSubmits(Browser $browser): ?array
    {
        return $browser->script('return window.__formSubmits ?? null;')[0];
    }

    /** Alpine attaches its click handlers a beat after page load — wait until it
     *  has actually initialized the page before interacting with anything. */
    protected function waitForAlpine(Browser $browser): void
    {
        $browser->waitUntil("!!(document.querySelector('[x-data]') && document.querySelector('[x-data]')._x_dataStack)");
    }

    /** Dispatch a real DOM click via JS. WebDriver's positional clicks are flaky in
     *  headless Chrome (they intermittently hit nothing); a JS click reaches the
     *  exact element and runs the same listeners a user's click would. */
    protected function jsClick(Browser $browser, string $selector): void
    {
        $css = str_starts_with($selector, '@')
            ? '[dusk="'.substr($selector, 1).'"]'
            : $selector;

        $browser->script("document.querySelector('{$css}').click();");
    }

    /** Type via JS with an input event so Alpine's x-model syncs — WebDriver's
     *  native sendKeys intermittently drops keystrokes in headless Chrome. */
    protected function jsType(Browser $browser, string $selector, string $value): void
    {
        $css = str_starts_with($selector, '@')
            ? '[dusk="'.substr($selector, 1).'"]'
            : $selector;

        $escaped = str_replace(['\\', "'"], ['\\\\', "\\'"], $value);

        $browser->script(
            "const el = document.querySelector('{$css}');"
            ."el.value = '{$escaped}';"
            ."el.dispatchEvent(new Event('input', { bubbles: true }));"
        );
    }

    /** Put a store into session context through the real UI. A single-store user is
     *  auto-selected (the selector redirects to the dashboard); a multi-store user
     *  lands on the selector and picks the store card. Assumes the browser is
     *  already authenticated as the user. */
    protected function switchToStore(Browser $browser, Store $store): void
    {
        $browser->visit('/select-store');

        if (str_contains($browser->driver->getCurrentURL(), '/select-store')) {
            $browser->waitForText($store->name)
                ->waitForReload(fn (Browser $b) => $this->jsClick($b, '@switch-store-'.$store->id));
        }
        // else: single store — already auto-selected and on the dashboard.
    }

    /** Click a trigger and wait for its effect, retrying if the click was missed. */
    protected function clickAndAwait(Browser $browser, string $trigger, \Closure $await): void
    {
        foreach (range(1, 3) as $attempt) {
            $this->jsClick($browser, $trigger);

            try {
                $await($browser);

                return;
            } catch (TimeoutException $e) {
                if ($attempt === 3) {
                    throw $e;
                }
            }
        }
    }

    /**
     * Create the RemoteWebDriver instance.
     */
    protected function driver(): RemoteWebDriver
    {
        $options = (new ChromeOptions)->addArguments(collect([
            $this->shouldStartMaximized() ? '--start-maximized' : '--window-size=1920,1080',
            '--disable-search-engine-choice-screen',
            '--disable-smooth-scrolling',
        ])->unless($this->hasHeadlessDisabled(), function (Collection $items) {
            return $items->merge([
                '--disable-gpu',
                '--headless=new',
            ]);
        })->all());

        return RemoteWebDriver::create(
            $_ENV['DUSK_DRIVER_URL'] ?? env('DUSK_DRIVER_URL') ?? 'http://localhost:9515',
            DesiredCapabilities::chrome()->setCapability(
                ChromeOptions::CAPABILITY, $options
            )
        );
    }
}
