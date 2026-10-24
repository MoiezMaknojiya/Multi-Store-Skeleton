<?php

namespace Tests\Browser;

use App\Models\BuilderAsset;
use Facebook\WebDriver\Chrome\ChromeDevToolsDriver;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Laravel\Dusk\Browser;
use Tests\DuskTestCase;

/**
 * A shelf file renamed from the Assets page (owner, 2026-10-24: "har image ke liye uska proper product name use karo, jaise
 * 'Alani-12oz'"): the pencil over the picture opens Rename File with the name it has, an empty name is said under the field before
 * anything is sent, a double press on Save renames it once, and the tile shows the new name.
 */
class AssetRenameFlowTest extends DuskTestCase
{
    use DatabaseMigrations;

    public function test_a_file_is_renamed_from_its_tile(): void
    {
        $admin = $this->seedSuperAdmin();
        $file = BuilderAsset::factory()->create(['organization_id' => null, 'title' => 'GAMA - ghost-03 (product)', 'thumbnail_path' => null]);

        $this->browse(function (Browser $browser) use ($admin, $file) {
            $this->freshSession($browser);
            $browser->loginAs($admin)->visit('/builder/assets');
            $this->waitForAlpine($browser);
            $browser->waitFor('@asset-card-'.$file->id)
                ->mouseover('@asset-card-'.$file->id)
                ->click('@rename-asset-'.$file->id)
                ->waitFor('@asset-form')
                ->assertInputValue('@asset-edit-title', 'GAMA - ghost-03 (product)');

            /* ── An empty name is said under the field, and nothing is sent ── */
            $this->countRequests($browser, 'PUT', '/builder/assets/'.$file->id);
            $this->jsType($browser, '@asset-edit-title', '');
            $browser->click('@asset-save')
                ->waitForTextIn('@asset-form', 'Title is required.');
            $this->assertSame(0, $this->requestsCounted($browser));

            /* ── A double press on Save renames it once ── */
            $this->jsType($browser, '@asset-edit-title', 'Ghost-16oz-AW-Root-Beer');
            $this->presses($browser, $this->centreOf($browser, '[dusk="asset-save"]'), 2);
            $browser->waitUntilMissing('@asset-form')
                ->waitForTextIn('@asset-title-'.$file->id, 'Ghost-16oz-AW-Root-Beer');
            $this->assertSame(1, $this->requestsCounted($browser));
            $this->assertSame('Ghost-16oz-AW-Root-Beer', $file->fresh()->title);

            /* ── The search finds it by its new name ── */
            $browser->type('@crud-search', 'Root-Beer')->pause(700)->waitFor('@asset-card-'.$file->id)->assertSeeIn('@asset-title-'.$file->id, 'Ghost-16oz-AW-Root-Beer');
        });
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
