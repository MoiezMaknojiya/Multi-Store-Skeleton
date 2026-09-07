<?php

namespace Tests;

use App\Models\Store;
use App\Models\User;
use Facebook\WebDriver\Chrome\ChromeOptions;
use Facebook\WebDriver\Exception\TimeoutException;
use Facebook\WebDriver\Remote\DesiredCapabilities;
use Facebook\WebDriver\Remote\RemoteWebDriver;
use Illuminate\Support\Collection;
use Laravel\Dusk\Browser;
use Laravel\Dusk\TestCase as BaseTestCase;
use PHPUnit\Framework\Attributes\BeforeClass;

abstract class DuskTestCase extends BaseTestCase
{
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
    }

    /** Seed the base data (permissions, Super-Admin role + user) and return the super admin. */
    protected function seedSuperAdmin(): User
    {
        $this->seed();

        return User::where('email', 'admin@gmail.com')->firstOrFail();
    }

    /** Dusk reuses one Chrome session for the whole run, so cookies (auth + the
     *  selected store) leak from test to test — start each test with none. */
    protected function freshSession(Browser $browser): void
    {
        $browser->driver->manage()->deleteAllCookies();
    }

    /** Wait out a modal's closing fade so the next click can't land on the overlay. */
    protected function waitForModalClosed(Browser $browser, string $formSelector): void
    {
        $browser->waitUntilMissing($formSelector)->pause(400);
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
