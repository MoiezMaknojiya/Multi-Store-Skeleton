<?php

namespace Tests\Browser;

use App\Models\PairingRequest;
use App\Models\Screen;
use App\Models\Store;
use App\Services\DevicePairing;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Str;
use Laravel\Dusk\Browser;
use Tests\DuskTestCase;

/**
 * What a TV does when the player page is closed and opened again.
 *
 * This happens every single day of a screen's life, in more than one state, so
 * the whole matrix is written down here rather than assumed. Two halves hold the
 * device's identity and it matters which is which:
 *
 *   localStorage (on the TV)  uuid, poll secret, the code on screen and its
 *                             expiry, and — once paired — the device token.
 *                             All of it survives a close; none of it survives a
 *                             browser that clears site data.
 *
 *   the database (on the server)  the pairing_requests row holding the minted
 *                             token until the device collects it, and the
 *                             screens row holding only the token's SHA-256.
 *
 * Cases covered elsewhere, so they are not repeated here:
 *   - paired, closed, reopened  → PlaylistFlowTest::test_a_tv_switched_off_overnight...
 *   - waiting on a code, reloaded → ScreenPairingTest (the code must not change)
 *   - screen deleted while running → ZeroToHeroTest (401 → fresh code)
 */
class PlayerRestartTest extends DuskTestCase
{
    use DatabaseMigrations;

    /**
     * Closed after the owner typed the code, before the TV could pick the token up.
     *
     * The owner does not wait at the TV — they type the code and walk off. If the
     * screen is switched off in that gap, the token is already minted and waiting
     * on the SERVER. Reopening must collect it, with nothing to type again.
     */
    public function test_a_tv_closed_between_the_owner_typing_the_code_and_collecting_the_token(): void
    {
        $store = Store::factory()->create(['name' => 'Alpha Mart']);
        $screen = Screen::factory()->create(['store_id' => $store->id, 'name' => 'Counter TV']);

        $this->browse(function (Browser $tv) use ($screen) {
            // -- The TV asks to be adopted --------------------------------------
            $tv->visit('/login');
            $tv->script('localStorage.clear();');
            $tv->visit('/player');
            $tv->waitFor('@pairing-code', 20);
            $tv->waitUntil('document.querySelector(\'[dusk="pairing-code"]\').textContent.trim().length === 6', 20);
            $code = trim($tv->text('@pairing-code'));

            // -- Switched off while the code is on screen ------------------------
            $tv->visit('/login');

            // -- The owner types it into the panel meanwhile ---------------------
            $this->assertTrue(app(DevicePairing::class)->claim($code, $screen));
            $this->assertTrue($screen->fresh()->isPaired());

            // The token exists, but it is on the server: the TV has never seen it.
            $this->assertNotNull(PairingRequest::where('code', $code)->value('claimed_token'));

            // -- Switched back on ------------------------------------------------
            $tv->visit('/player');

            // It collects the token and starts, without showing a code again. The
            // wait is short on purpose: a reopened player asks immediately rather
            // than sitting through a whole poll interval first.
            $tv->waitForText('No content', 20);
            $tv->assertSee('Counter TV');

            // Handed over exactly once — the row is gone, so a replayed poll gets
            // nothing and the token cannot be collected twice.
            $this->assertSame(0, PairingRequest::where('code', $code)->count());

            // And it is talking to the server as itself now.
            $tv->waitUsing(30, 500, fn () => $screen->fresh()->last_seen_at !== null);

            $tv->visit('/login');
            $tv->script('localStorage.clear();');
        });
    }

    /**
     * The player open in two tabs at once, which is one careless click away: the
     * panel's Open Player button opens a new tab every time it is pressed.
     *
     * Both tabs share one localStorage, so they show the same code and both poll
     * for it. Whoever wins clears the poll secret — and the loser must not read
     * that as "we were thrown out" and delete the token the winner just earned.
     * A TV that paired successfully would otherwise go back to asking for a code
     * the next time it was opened.
     */
    public function test_a_second_tab_does_not_undo_a_pairing_the_first_one_completed(): void
    {
        $store = Store::factory()->create(['name' => 'Alpha Mart']);
        $screen = Screen::factory()->unpaired()->create([
            'store_id' => $store->id, 'name' => 'Counter TV',
        ]);

        $this->browse(function (Browser $tv) use ($screen) {
            // Two WINDOWS of one browser, not two Dusk browsers: Dusk gives each
            // browser its own Chrome session, and separate sessions do not share
            // localStorage — which is the entire mechanism under test here.
            $tokenInStorage = fn () => $tv->script("return localStorage.getItem('signage.device.token');")[0];
            $switchTo = fn (string $handle) => $tv->driver->switchTo()->window($handle);

            // -- First tab asks to be adopted -----------------------------------
            $tv->visit('/login');
            $tv->script('localStorage.clear();');
            $tv->visit('/player');
            $tv->waitFor('@pairing-code', 20);
            $tv->waitUntil('document.querySelector(\'[dusk="pairing-code"]\').textContent.trim().length === 6', 20);
            $code = trim($tv->text('@pairing-code'));

            $tabA = $tv->driver->getWindowHandle();

            // -- The owner types the code into their panel ----------------------
            $this->assertTrue(app(DevicePairing::class)->claim($code, $screen));

            // -- A second tab is opened on the same player ----------------------
            $tv->script("window.open('/player', '_blank');");
            $handles = $tv->driver->getWindowHandles();
            $tabB = collect($handles)->first(fn ($handle) => $handle !== $tabA);
            $this->assertNotNull($tabB, 'the second tab never opened');

            $switchTo($tabB);

            // Sharing storage, it shows the very same code — and its own first
            // poll collects the token that is now waiting.
            $tv->waitForText('No content', 30);
            $this->assertNotNull($tokenInStorage(), 'the second tab never collected a token');

            // -- Back to the first tab, which is still polling ------------------
            // Its next poll finds the secret gone. That is the moment the token
            // used to be thrown away, sending a paired TV back to square one.
            $switchTo($tabA);
            $tv->waitForText('No content', 75);

            $this->assertNotNull($tokenInStorage(),
                'the other tab deleted the token this device had just been given');
            $tv->assertMissing('@pairing-code');

            // And nobody has to go and pair this television a second time.
            $this->assertTrue($screen->fresh()->isPaired());

            $switchTo($tabB);
            $tv->driver->close();
            $switchTo($tabA);
            $tv->visit('/login');
            $tv->script('localStorage.clear();');
        });
    }

    /**
     * A television that lost its token but still knows which device it is.
     *
     * This is what a 401 leaves behind — the token is dropped, the uuid is not —
     * and it is exactly what happened to a real screen: it played for six
     * minutes, the server briefly answered from the wrong database, and the set
     * went back to showing a code.
     *
     * The instruction on that screen decides what the shop owner does next. Told
     * to "Add Screen" they make a SECOND screen and leave the real one stranded
     * with its whole playlist. Told to "Replace device" they put the new token on
     * the screen they already have and nothing is lost.
     */
    public function test_a_television_that_lost_only_its_token_is_sent_to_replace_device(): void
    {
        $store = Store::factory()->create(['name' => 'Alpha Mart']);
        $uuid = '828739f6-8ae4-4099-821c-377b8a3f87bf';
        $screen = Screen::factory()->withToken('real-token')->create([
            'store_id' => $store->id, 'name' => 'Counter TV', 'device_uuid' => $uuid,
        ]);

        $this->browse(function (Browser $tv) use ($uuid, $screen) {
            // The state a 401 leaves: the uuid survived, the token did not — here
            // a token the server will not recognise, which produces that 401.
            $tv->visit('/login');
            $tv->script(
                'localStorage.clear();'
                ."localStorage.setItem('signage.device.uuid', '{$uuid}');"
                ."localStorage.setItem('signage.device.token', 'a-token-that-is-no-longer-valid');"
            );

            $tv->visit('/player');

            // It is turned away, drops the token, and asks to be adopted again.
            $tv->waitFor('@pairing-code', 30);
            $tv->waitUntil('document.querySelector(\'[dusk="pairing-code"]\').textContent.trim().length === 6', 20);

            // And it is pointed at the right door.
            $tv->assertSeeIn('@pairing-where', 'Replace device');
            $tv->assertSee('set up before');

            // Never the screen's name: this page is open to anyone.
            $tv->assertDontSee('Counter TV');

            // And not the 401's parting words either. "This screen was removed" is
            // true when it was, and plainly false here — the screen is sitting in
            // the panel with its playlist. Two lines contradicting each other on a
            // wall in a shop is worse than either of them alone.
            $tv->assertDontSee('was removed');
            $tv->assertSee('playlist are safe');

            // The device id is printed so a set in a shop can be matched against
            // its row — the last block only, which is what the panel shows beside
            // each screen. Safe on a wall: a uuid is an identity, never a way in.
            $tv->assertSeeIn('@pairing-uuid', Str::afterLast($uuid, '-'));
            $tv->assertDontSeeIn('@pairing-uuid', '828739f6');

            // And the mouse pointer is left alone here: somebody is standing at
            // this television typing the code into their panel. It is hidden only
            // once content is playing.
            $this->assertSame('auto', $tv->script('return getComputedStyle(document.body).cursor;')[0],
                'the pointer is hidden on the pairing screen, where a person is using it');

            // The screen itself is untouched and waiting for that code.
            $this->assertSame('Counter TV', $screen->fresh()->name);

            $tv->visit('/login');
            $tv->script('localStorage.clear();');
        });
    }

    /**
     * A kiosk browser that wipes site data between sessions.
     *
     * The token lives in localStorage, so this is the one restart the TV cannot
     * recover from by itself — and the shop needs to be told what to do rather
     * than left staring at a screen that will never come back. It must ask to be
     * adopted again, and the screen the owner already made must SURVIVE, so its
     * name, orientation and playlist are not lost with it.
     */
    public function test_a_tv_that_lost_its_storage_asks_to_be_adopted_again_and_its_screen_survives(): void
    {
        $store = Store::factory()->create(['name' => 'Alpha Mart']);
        $screen = Screen::factory()->withToken('kiosk-token')->create([
            'store_id' => $store->id, 'name' => 'Counter TV',
        ]);

        $tokenHashBefore = $screen->token_hash;

        $this->browse(function (Browser $tv) use ($screen, $tokenHashBefore) {
            // -- Running normally ------------------------------------------------
            $tv->visit('/login');
            $tv->script("localStorage.clear(); localStorage.setItem('signage.device.token', 'kiosk-token');");
            $tv->visit('/player');
            $tv->waitForText('No content', 30);

            // -- The browser clears its site data, then the TV restarts ----------
            $tv->visit('/login');
            $tv->script('localStorage.clear();');
            $tv->visit('/player');

            // It cannot know who it was, so it asks to be adopted again.
            $tv->waitFor('@pairing-code', 30);
            $tv->waitUntil('document.querySelector(\'[dusk="pairing-code"]\').textContent.trim().length === 6', 20);
            $this->assertMatchesRegularExpression(
                '/^[ABCDEFGHJKLMNPQRSTUVWXYZ23456789]{6}$/',
                trim($tv->text('@pairing-code'))
            );

            // The screen itself is untouched — same row, same name, same token on
            // file. Nothing the owner set up was lost; they point "Replace device"
            // at the new code and the screen keeps its playlist.
            $fresh = $screen->fresh();
            $this->assertNotNull($fresh, 'the screen was destroyed by its device losing storage');
            $this->assertSame('Counter TV', $fresh->name);
            $this->assertSame($tokenHashBefore, $fresh->token_hash);

            $tv->visit('/login');
            $tv->script('localStorage.clear();');
        });
    }
}
