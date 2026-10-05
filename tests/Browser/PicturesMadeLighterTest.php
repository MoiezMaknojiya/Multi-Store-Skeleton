<?php

namespace Tests\Browser;

use App\Models\Media;
use App\Models\Organization;
use App\Services\OrganizationStorage;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Storage;
use Laravel\Dusk\Browser;
use Tests\DuskTestCase;

/**
 * A picture is made light on its way in (owner, 2026-10-05: "upload par tasveer khud halki karne wala feature bana
 * do", and "Badi tasveer ko chhota karna yeh bhi bana do"), as a person meets it on the Media page: a photograph bigger
 * than 4K goes into the library as a 4K WebP, lighter than what was chosen, and its row says by how much — in the
 * words the storage meter uses. The rules themselves are PictureOptimizerTest's.
 */
class PicturesMadeLighterTest extends DuskTestCase
{
    use DatabaseMigrations;

    public function test_a_photo_bigger_than_4k_is_brought_down_and_made_lighter_and_its_row_says_by_how_much(): void
    {
        $organization = Organization::factory()->create(['name' => 'Alpha Mart']);
        $owner = $this->organizationMember($organization);

        $this->browse(function (Browser $browser) use ($owner, $organization) {
            $this->freshSession($browser);
            $browser->loginAs($owner);
            $this->switchToOrganization($browser, $organization);
            $browser->visit('/media');
            $this->waitForAlpine($browser);
            $browser->waitFor('@media-dropzone');

            // A photograph of 4800 x 2700, as a camera writes one: a JPEG, chosen with the box.
            $browser->script(<<<'JS'
                window.__sent = null;
                const canvas = document.createElement('canvas');
                canvas.width = 4800;
                canvas.height = 2700;
                const context = canvas.getContext('2d');
                const sky = context.createLinearGradient(0, 0, 0, 2700);
                sky.addColorStop(0, '#1e3a8a');
                sky.addColorStop(1, '#f59e0b');
                context.fillStyle = sky;
                context.fillRect(0, 0, 4800, 2700);

                for (let i = 0; i < 400; i++) {
                    context.fillStyle = `hsl(${(i * 37) % 360}, 70%, 50%)`;
                    context.beginPath();
                    context.arc((i * 997) % 4800, (i * 613) % 2700, 20 + (i % 90), 0, Math.PI * 2);
                    context.fill();
                }

                canvas.toBlob((blob) => {
                    const list = new DataTransfer();
                    list.items.add(new File([blob], 'Storefront.jpg', { type: 'image/jpeg' }));
                    const input = document.querySelector('[dusk="media-file"]');
                    input.files = list.files;
                    input.dispatchEvent(new Event('change', { bubbles: true }));
                    window.__sent = blob.size;
                }, 'image/jpeg', 0.95);
            JS);
            $browser->waitUsing(30, 200, fn () => is_int($browser->script('return window.__sent;')[0]));
            $sent = (int) $browser->script('return window.__sent;')[0];

            $browser->waitUsing(60, 200, fn () => Media::count() === 1);
            $media = Media::sole();

            // Stored as a 4K WebP, lighter than what came, with its preview made from it.
            $this->assertSame('image/webp', $media->mime_type);
            $this->assertSame([3840, 2160, 'landscape'], [$media->width, $media->height, $media->orientation]);
            $this->assertStringEndsWith('.webp', $media->path);
            $this->assertSame($media->size, Storage::disk('public')->size($media->path));
            $this->assertLessThan($sent, $media->size);
            $this->assertNotNull($media->thumbnail_path);

            // The row says by how much, in the meter's words.
            $said = 'Added · made lighter: '.OrganizationStorage::inWords($sent).' to '.OrganizationStorage::inWords($media->size);
            $browser->waitUsing(10, 200, fn () => $browser->script(
                "return document.querySelector('[dusk=\"media-upload-status\"]')?.textContent.trim();"
            )[0] === $said);

            // And it is in the library as any file is, the same size in the list as in the row.
            $browser->waitForText('Storefront')
                ->waitForTextIn('@media-size', OrganizationStorage::inWords($media->size));
        });
    }
}
