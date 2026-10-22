<?php

namespace Tests\Browser;

use App\Models\Channel;
use App\Models\ChannelAd;
use App\Models\Organization;
use App\Models\PlaylistItem;
use App\Models\Screen;
use Facebook\WebDriver\Chrome\ChromeDevToolsDriver;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Laravel\Dusk\Browser;
use Tests\DuskTestCase;

/**
 * A channel's shape (owner, 2026-10-22: a landscape channel and a portrait one for every vendor, and the screen made right by
 * itself when it is mounted flipped). Add Channel asks Landscape or Portrait — pressed with a real double click — and Edit says it
 * is fixed; a screen mounted upside down is offered the landscape channels and says the player turns them; and the television
 * turns its whole picture round. Watched for console errors throughout.
 */
class ChannelOrientationFlowTest extends DuskTestCase
{
    use DatabaseMigrations;

    public function test_a_channel_is_made_portrait_and_its_shape_is_fixed_after(): void
    {
        $admin = $this->seedSuperAdmin();

        $this->browse(function (Browser $browser) use ($admin) {
            $this->freshSession($browser);
            $browser->loginAs($admin)->visit('/channels');
            $this->waitForAlpine($browser);
            $browser->driver->manage()->getLog('browser');

            $browser->click('@add-channel')->waitFor('@channel-orientation')
                ->assertRadioSelected('channel-orientation', 'landscape');

            // A real double press on Portrait chooses it, and keeps it chosen.
            $this->presses($browser, $this->centreOf($browser, '[dusk="channel-orientation-portrait"]'), 2);
            $browser->pause(300)->assertRadioSelected('channel-orientation', 'portrait')
                ->type('@channel-name', 'GAMA Upright')->click('@channel-save')
                ->waitForText('Channel created');

            $channel = Channel::where('name', 'GAMA Upright')->sole();
            $this->assertSame('portrait', $channel->orientation);
            $browser->waitFor('@channel-orientation-'.$channel->id)->assertSeeIn('@channel-orientation-'.$channel->id, 'Portrait')
                ->screenshot('channel-orientation-list');

            // Edit says the shape and offers no way to change it.
            $browser->click('@edit-channel-'.$channel->id)->waitFor('@channel-orientation-fixed')
                ->assertSeeIn('@channel-orientation-fixed', 'Portrait channel. Its shape stays as it was made.')
                ->assertMissing('@channel-orientation')
                ->screenshot('channel-orientation-edit');

            $browser->visit('/channels/'.$channel->id)->waitFor('@channel-orientation')
                ->assertSeeIn('@channel-orientation', 'Portrait');
            $this->assertNoConsoleErrors($browser);
        });
    }

    public function test_a_screen_mounted_upside_down_is_offered_the_landscape_channels_and_the_television_turns_them(): void
    {
        $this->seedSuperAdmin();
        $alpha = Organization::factory()->create(['name' => 'Alpha Mart']);
        $owner = $this->organizationMember($alpha, ['screen-view', 'screen-playlist']);
        $screen = Screen::factory()->withToken('flipped-token')->create(['organization_id' => $alpha->id, 'name' => 'Counter TV', 'orientation' => 'landscape_flipped']);

        $wide = Channel::factory()->create(['name' => 'GAMA']);
        ChannelAd::factory()->lasting(10)->showing([
            'mime_type' => 'image/png', 'thumbnail_path' => null, 'path' => $this->putImage('media/platform/gama-wide.png', 200, 60, 30),
            'width' => 1920, 'height' => 1080, 'orientation' => 'landscape',
        ])->create(['channel_id' => $wide->id, 'title' => 'Wide promo']);
        $tall = Channel::factory()->portrait()->create(['name' => 'GAMA']);
        ChannelAd::factory()->lasting(10)->showing([
            'mime_type' => 'image/png', 'thumbnail_path' => null, 'path' => $this->putImage('media/platform/gama-tall.png', 30, 60, 200),
            'width' => 1080, 'height' => 1920, 'orientation' => 'portrait',
        ])->create(['channel_id' => $tall->id, 'title' => 'Tall promo']);

        $this->browse(function (Browser $browser) use ($owner, $alpha, $screen, $wide, $tall) {
            $this->freshSession($browser);
            $browser->loginAs($owner);
            $this->switchToOrganization($browser, $alpha);
            // What the last test's page asked once its session was gone (a 401) is no error of this one's.
            $browser->driver->manage()->getLog('browser');
            $browser->visit('/screens/'.$screen->id);
            $this->waitForAlpine($browser);

            $browser->waitFor('@picker-tab-channels')->assertSeeIn('@picker-count-channels', '1')
                ->click('@picker-tab-channels')->waitFor('@channel-picker')
                ->assertPresent('@playlist-add-channel-'.$wide->id)->assertMissing('@playlist-add-channel-'.$tall->id)
                ->assertSeeIn('@channels-shape-note', 'Landscape channels: this screen is mounted upside down, and the player turns them the right way up.')
                ->screenshot('channel-orientation-flipped-screen');

            $this->jsClick($browser, '@playlist-add-channel-'.$wide->id);
            $browser->waitFor('@playlist-channel-info-0')->click('@playlist-save')->waitForText('Playlist saved', 15);
            $this->assertSame([$wide->id], PlaylistItem::where('screen_id', $screen->id)->pluck('channel_id')->all());
            // No "plays with bars" note: a flipped landscape screen is a landscape screen.
            $browser->assertMissing('@playlist-orientation-0');
            $this->assertNoConsoleErrors($browser);
        });

        // The television: its whole picture turned half round, the channel's ad playing inside it.
        $this->browse(function (Browser $tv) {
            $tv->visit('/login');
            $tv->script("localStorage.clear(); localStorage.setItem('signage.device.token', 'flipped-token');");
            $tv->visit('/player');

            $tv->waitUntil("!!document.querySelector('#layer-a:not([hidden]) img, #layer-b:not([hidden]) img')", 30);
            $stage = $tv->script('const stage = document.getElementById("stage"); return [stage.dataset.orientation, getComputedStyle(stage).transform];')[0];
            $this->assertSame('landscape_flipped', $stage[0]);
            // rotate(180deg) as a matrix: cos 180 = -1 on both axes.
            $this->assertMatchesRegularExpression('/^matrix\(-1, [-0-9.e]+, [-0-9.e]+, -1, 0, 0\)$/', $stage[1]);
            $this->assertStringContainsString('gama-wide.png', $tv->script("return document.querySelector('#layer-a:not([hidden]) img, #layer-b:not([hidden]) img').src;")[0]);
            $tv->screenshot('channel-orientation-television');

            $tv->visit('/login');
            $tv->script('localStorage.clear();');
        });
    }

    private function assertNoConsoleErrors(Browser $browser): void
    {
        $errors = collect($browser->driver->manage()->getLog('browser'))->filter(fn (array $entry) => $entry['level'] === 'SEVERE')
            ->pluck('message')->reject(fn (string $message) => str_contains($message, '/dusk-storage/') && str_contains($message, '404'))
            ->values()->all();

        $this->assertSame([], $errors);
    }

    /** Presses sent to Chrome itself, with the click count a person's double or triple press carries. */
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

    /** The middle of an element, brought into sight first. */
    private function centreOf(Browser $browser, string $css): array
    {
        return $browser->script('const el = document.querySelector('.json_encode($css).'); el.scrollIntoView({ block: "center" }); '
            .'const r = el.getBoundingClientRect(); return [Math.round(r.left + r.width / 2), Math.round(r.top + r.height / 2)];')[0];
    }
}
