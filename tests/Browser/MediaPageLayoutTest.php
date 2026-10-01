<?php

namespace Tests\Browser;

use App\Models\Media;
use App\Models\Organization;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Laravel\Dusk\Browser;
use Tests\DuskTestCase;

/**
 * The Media page at every width a person opens it on (owner, 2026-09-30: "Type, Orientation, Sort by and search ek
 * line mein", the drop box on the page): the three filters and the search share one line — the storage beside them
 * on a laptop — nothing is pushed down when the list arrives, and nothing makes the page scroll sideways.
 */
class MediaPageLayoutTest extends DuskTestCase
{
    use DatabaseMigrations;

    /**
     * [width, height, whether the storage fits on their line, whether the filters and the search fit one line]: from
     * a laptop up all of it is one line, at 1280 px the storage goes above them, and narrower they wrap too.
     */
    private const WIDTHS = [[1920, 1080, true, true], [1440, 900, true, true], [1366, 768, true, true], [1280, 800, false, true],
        [1024, 768, false, false], [768, 1024, false, false]];

    public function test_the_filters_and_the_search_share_one_line_and_nothing_jumps_as_the_list_arrives(): void
    {
        $organization = Organization::factory()->create(['name' => 'Alpha Mart']);
        $owner = $this->organizationMember($organization);
        Media::factory()->count(12)->create(['organization_id' => $organization->id, 'thumbnail_path' => null]);

        $this->browse(function (Browser $browser) use ($owner, $organization) {
            $this->freshSession($browser);
            $browser->loginAs($owner);
            $this->switchToOrganization($browser, $organization);

            foreach (self::WIDTHS as [$width, $height, $besideTheMeter, $oneLine]) {
                $browser->resize($width, $height);
                $browser->visit('/media');

                // The first paint, before the list: where the drop box stands…
                $before = $browser->script("return Math.round(document.querySelector('[dusk=\"media-dropzone\"]').getBoundingClientRect().top);")[0];
                $this->waitForAlpine($browser);
                $browser->waitUntilMissingText('Loading...', 10);
                $line = $this->line($browser);

                // …is where it stands once the list is in: the storage came with the page.
                $this->assertSame($before, $line['dropTop'], "the page jumped as the list arrived at {$width} px");
                if ($oneLine) {
                    $this->assertSame(1, $line['filterLines'], "the filters and the search are not on one line at {$width} px");
                }
                $this->assertSame($besideTheMeter, $line['besideMeter'], "at {$width} px the storage ".($besideTheMeter ? 'should' : 'should not').' be on their line');
                $this->assertFalse($line['sideways'], "the page scrolls sideways at {$width} px");
                $this->assertTrue($line['meterShown'], "no storage at {$width} px");
            }

            // A phone: one under the other, the whole width, and nothing sideways.
            $browser->resize(375, 812)->visit('/media');
            $this->waitForAlpine($browser);
            $browser->waitUntilMissingText('Loading...', 10);
            $phone = $this->line($browser);
            $this->assertFalse($phone['sideways'], 'the page scrolls sideways on a phone');
            $this->assertTrue($phone['filtersFullWidth'], 'a filter on a phone is not the width of the page');

            $browser->resize(1920, 1080);
        });
    }

    public function test_above_the_organizations_the_library_joins_the_line_and_a_organizations_storage_appears_with_it(): void
    {
        $admin = $this->seedSuperAdmin();
        $alpha = Organization::factory()->create(['name' => 'Alpha Mart']);
        Media::factory()->create(['organization_id' => $alpha->id, 'title' => 'Alpha poster', 'thumbnail_path' => null]);

        $this->browse(function (Browser $browser) use ($admin, $alpha) {
            $this->freshSession($browser);
            $browser->loginAs($admin)->resize(1920, 1080)->visit('/media');
            $this->waitForAlpine($browser);
            $browser->waitUntilMissingText('Loading...', 10);

            // The platform's own library has no wall: no storage, and the four lists and the search on one line.
            $line = $this->line($browser, ['media-filter-library', 'media-filter-type', 'media-filter-orientation', 'media-sort', 'crud-search']);
            $this->assertFalse($line['meterShown']);
            $this->assertSame(1, $line['filterLines']);

            // An organization's library: its storage, and an upload that would go there.
            $browser->select('@media-filter-library', (string) $alpha->id)->waitForText('Alpha poster')
                ->waitFor('@storage-meter')->assertSeeIn('@storage-meter-text', 'of 512 MB used');
            $this->assertSame(1, $this->line($browser, ['media-filter-library', 'media-filter-type', 'media-filter-orientation', 'media-sort', 'crud-search'])['filterLines']);
        });
    }

    /** Where the parts of the page's first line stand. */
    private function line(Browser $browser, array $filters = ['media-filter-type', 'media-filter-orientation', 'media-sort', 'crud-search']): array
    {
        $names = json_encode($filters);

        return $browser->script(<<<JS
            const box = (name) => document.querySelector('[dusk="' + name + '"]');
            const tops = {$names}.map((name) => Math.round(box(name).getBoundingClientRect().top + box(name).getBoundingClientRect().height / 2));
            const meter = box('storage-meter');
            const meterShown = !!meter && meter.getClientRects().length > 0;
            const meterMiddle = meterShown ? meter.getBoundingClientRect().top + meter.getBoundingClientRect().height / 2 : null;
            return {
                filterLines: new Set(tops).size,
                besideMeter: meterShown && Math.abs(meterMiddle - tops[0]) < 20,
                meterShown,
                dropTop: Math.round(box('media-dropzone').getBoundingClientRect().top),
                sideways: document.documentElement.scrollWidth > window.innerWidth + 1,
                filtersFullWidth: {$names}.every((name) => box(name).getBoundingClientRect().width >= window.innerWidth - 80),
            };
        JS)[0];
    }
}
