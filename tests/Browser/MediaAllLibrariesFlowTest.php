<?php

namespace Tests\Browser;

use App\Models\Media;
use App\Models\Organization;
use App\Models\Role;
use Facebook\WebDriver\Chrome\ChromeDevToolsDriver;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Laravel\Dusk\Browser;
use Tests\DuskTestCase;

/**
 * The Media page above the organizations (owner, 2026-10-23: "selectbox … platform library … us mein all ka option bhi dalo aur
 * listing mein kis organization ya user name upload kiya ha woo bhi dalo"). The Library list offers All Libraries beside the
 * platform's and each organization's; every row says whose library the file is in and who uploaded it, and when; the address
 * keeps All through a reload; and a file dropped under All goes to the platform's library, which the page says. Inside an
 * organization the page is as it was. The list is opened with a real double press sent to Chrome.
 */
class MediaAllLibrariesFlowTest extends DuskTestCase
{
    use DatabaseMigrations;

    public function test_all_libraries_lists_every_file_with_its_library_and_its_uploader(): void
    {
        $admin = $this->seedSuperAdmin();
        $alpha = Organization::factory()->create(['name' => 'Alpha Mart']);
        $member = $this->organizationMember($alpha, Role::OWNER, 'zohaib@example.com');
        $member->update(['first_name' => 'Zohaib', 'last_name' => 'Momin']);
        $promo = Media::factory()->platformOwned()->create(['title' => 'Platform promo', 'thumbnail_path' => null, 'created_by' => $admin->id]);
        $poster = Media::factory()->create(['organization_id' => $alpha->id, 'title' => 'Alpha poster', 'thumbnail_path' => null, 'created_by' => $member->id]);
        $path = $this->fixtureImage('Dropped under all.png', 40, 160, 90);

        $this->browse(function (Browser $browser) use ($admin, $member, $promo, $poster, $path) {
            $this->freshSession($browser);
            $browser->loginAs($admin)->resize(1920, 1080)->visit('/media');
            $this->waitForAlpine($browser);

            /* ── 1. The platform's library first: each row says whose it is and who uploaded it ── */
            $browser->waitFor('@media-owner-'.$promo->id)->assertDontSee('Alpha poster')
                ->assertSeeIn('@media-owner-'.$promo->id, 'Platform')
                ->assertSeeIn('@media-uploader-'.$promo->id, $admin->first_name.' '.$admin->last_name)
                ->assertMissing('@media-upload-target');
            $this->assertSame(['Platform library', 'All libraries', 'Alpha Mart'], $this->optionWords($browser, 'media-filter-library'));

            /* ── 2. A double press opens the list and leaves it open; All Libraries lists every file ── */
            $select = '[dusk="media-filter-library"]';
            $this->presses($browser, $this->centreOf($browser, $select), 2);
            $browser->pause(500);
            $this->assertTrue((bool) $browser->script('return document.querySelector('.json_encode($select).').matches(":open");')[0]);
            $this->key($browser, 'Escape');

            $browser->select('@media-filter-library', '')->waitFor('@media-owner-'.$poster->id)
                ->assertVisible('@media-owner-'.$promo->id)
                ->assertSeeIn('@media-owner-'.$poster->id, 'Alpha Mart')
                ->assertSeeIn('@media-uploader-'.$poster->id, 'Zohaib Momin')
                ->assertSeeIn('@media-upload-target', 'New files go to the Platform library.');
            $this->assertStringContainsString('library=', $browser->driver->getCurrentURL());

            /* ── 3. A reload opens on All, and a file dropped there joins the platform's library ── */
            $browser->refresh();
            $this->waitForAlpine($browser);
            $browser->waitFor('@media-owner-'.$poster->id)->assertSelected('@media-filter-library', '');

            $this->uploadThrough($browser, 'media', $path);
            $dropped = null;
            $browser->waitUsing(15, 200, function () use (&$dropped) {
                return ($dropped = Media::firstWhere('title', 'Dropped under all')) !== null;
            });
            $this->assertNull($dropped->organization_id);
            $this->assertSame($admin->id, $dropped->created_by);
            $browser->waitFor('@media-owner-'.$dropped->id)->assertSeeIn('@media-owner-'.$dropped->id, 'Platform');

            /* ── 4. Inside an organization the page is as it was: every file there is its own ── */
            $this->freshSession($browser);
            $browser->loginAs($member)->visit('/media');
            $this->waitForAlpine($browser);
            $browser->waitForText('Alpha poster')->assertMissing('@media-filter-library')
                ->assertMissing('@media-owner-'.$poster->id)->assertMissing('@media-uploader-'.$poster->id)
                ->assertDontSee('Uploaded by');
        });
    }

    /** The words of a select's options, as a person reads them. */
    private function optionWords(Browser $browser, string $dusk): array
    {
        return $browser->script('return [...document.querySelector(\'[dusk="'.$dusk.'"]\').options].map((option) => option.textContent.trim());')[0];
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

    private function key(Browser $browser, string $key): void
    {
        $tools = new ChromeDevToolsDriver($browser->driver);
        foreach (['keyDown', 'keyUp'] as $type) {
            $tools->execute('Input.dispatchKeyEvent', ['type' => $type, 'key' => $key, 'code' => $key, 'windowsVirtualKeyCode' => 27]);
        }
    }

    /** The middle of an element, brought into sight first. */
    private function centreOf(Browser $browser, string $css): array
    {
        return $browser->script('const el = document.querySelector('.json_encode($css).'); el.scrollIntoView({ block: "center" }); '
            .'const r = el.getBoundingClientRect(); return [Math.round(r.left + r.width / 2), Math.round(r.top + r.height / 2)];')[0];
    }
}
