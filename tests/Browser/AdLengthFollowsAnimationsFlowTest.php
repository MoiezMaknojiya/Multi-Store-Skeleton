<?php

namespace Tests\Browser;

use App\Models\BuilderAd;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Laravel\Dusk\Browser;
use Tests\DuskTestCase;

/**
 * An ad lasts at least as long as its animations (owner, 2026-10-14: "agar ad builder mein animation 10 seconds se
 * ziyada ho toh minimum second wahi dikhaye aur max toh 5 minutes ha"; asked what happens when an exit is set past the
 * length: "Length khud badhe"). The length rises by itself and the designer is told where they are; the Stage panel
 * says the least and why; a length typed under it is held at it; taking the animation away does not lower the length;
 * and a design opened with animations past its length is given the length they need, ready to be saved.
 */
class AdLengthFollowsAnimationsFlowTest extends DuskTestCase
{
    use DatabaseMigrations;

    private const EDITOR = 'Alpine.$data(document.querySelector(\'[x-data^="adEditor"]\'))';

    public function test_an_exit_past_the_length_raises_it_and_the_stage_panel_says_so(): void
    {
        [$designer, $organization] = $this->designer();

        $this->browse(function (Browser $browser) use ($designer, $organization) {
            $this->freshSession($browser);
            $browser->loginAs($designer);
            $this->switchToOrganization($browser, $organization);
            $browser->visit('/builder/create?orientation=landscape');
            $this->waitForAlpine($browser);
            $browser->waitFor('@ad-stage');

            // A new ad lasts ten seconds; its text is to leave at fifteen, which takes 0.6 s.
            $this->assertSame(10, $this->length($browser));
            $this->jsClick($browser, '@add-text');
            $browser->waitFor('@panel-tab-animation');
            $this->jsClick($browser, '@panel-tab-animation');
            $browser->waitFor('@anim-out-effect');
            $this->setValue($browser, '@anim-out-effect', 'fade', 'change');
            $browser->waitFor('@anim-out-at');
            $this->setValue($browser, '@anim-out-at', '15', 'change');

            // Said where the designer is — the Length is in the Stage panel, out of sight — and it is what the exit needs.
            $browser->waitForText('The ad now lasts 16 seconds, so its animations are seen to the end.');
            $this->assertSame(16, $this->length($browser));
            $browser->assertMissing('@anim-out-timing');

            // The Stage panel: the length, and why it is no less.
            $browser->script(self::EDITOR.'.clearSelection();');
            $browser->waitFor('@ad-length')->assertInputValue('@ad-length', '16')
                ->assertSeeIn('@ad-length-note', 'At least 16 seconds: the animations run that long. At most 5 minutes.');
            $this->assertSame('16', $browser->attribute('@ad-length', 'min'));

            // Typed under it, it stays at the least; above it, it is kept.
            $this->setValue($browser, '@ad-length', '12', 'change');
            $browser->pause(200)->assertInputValue('@ad-length', '16');
            $this->setValue($browser, '@ad-length', '20', 'change');
            $browser->pause(200)->assertInputValue('@ad-length', '20');

            // However far Undo goes back, no step leaves the ad shorter than its animations at that step: the exit and
            // the length it raised are one step. And Redo brings it all back.
            $steps = $browser->script(
                'const editor = '.self::EDITOR.'; const seen = [];'
                .'while (editor.history.canUndo()) { editor.undo(); seen.push([editor.doc.duration ?? null, editor.leastAdSeconds()]); }'
                .'while (editor.history.canRedo()) editor.redo();'
                .'return seen;'
            )[0];
            $this->assertNotEmpty($steps);
            foreach ($steps as [$length, $least]) {
                $this->assertGreaterThanOrEqual($least, $length, 'a step of the history is shorter than its animations: '.json_encode($steps));
            }
            $this->assertSame(10, end($steps)[0], 'back at the start, the ad lasts ten again');
            $this->assertSame(20, $this->length($browser));

            // The exit taken away: the length stays where it was, and the note says the range again.
            $browser->script(self::EDITOR.'.selectedIds = ['.self::EDITOR.'.doc.elements[0].id];');
            $this->jsClick($browser, '@panel-tab-animation');
            $browser->waitFor('@anim-out-effect');
            $this->setValue($browser, '@anim-out-effect', '', 'change');
            $browser->pause(200);
            $this->assertSame(20, $this->length($browser), 'the length does not fall by itself');
            $browser->script(self::EDITOR.'.clearSelection();');
            $browser->waitFor('@ad-length-note')->assertSeeIn('@ad-length-note', '10 seconds to 5 minutes, on every screen and channel.');

            // Saved, the server keeps it.
            $browser->script(self::EDITOR.'.save();');
            $browser->waitUsing(10, 200, fn () => BuilderAd::query()->exists() && BuilderAd::sole()->document['duration'] === 20);
        });
    }

    public function test_a_design_opened_with_animations_past_its_length_is_given_the_length_they_need(): void
    {
        [$designer, $organization] = $this->designer();

        // As a design saved before the length followed its animations holds it: ten seconds, an exit at twenty.
        $ad = BuilderAd::factory()->withText('Late exit')->create(['organization_id' => $organization->id, 'name' => 'Late exit']);
        $document = $ad->document;
        $document['duration'] = 10;
        $document['elements'][0]['animations'] = ['out' => ['effect' => 'fade', 'at' => 20, 'duration' => 0.6]];
        BuilderAd::withoutTimestamps(fn () => $ad->forceFill(['document' => $document])->save());

        $this->browse(function (Browser $browser) use ($designer, $organization, $ad) {
            $this->freshSession($browser);
            $browser->loginAs($designer);
            $this->switchToOrganization($browser, $organization);
            $browser->visit('/builder/'.$ad->id);
            $this->waitForAlpine($browser);
            $browser->waitFor('@ad-stage');

            $browser->waitForText('The ad now lasts 21 seconds, so its animations are seen to the end.');
            $this->assertSame(21, $this->length($browser));
            $this->assertTrue($browser->script('return '.self::EDITOR.'.dirty;')[0], 'the length it was given is a change to save');

            $browser->script(self::EDITOR.'.save();');
            $browser->waitUsing(10, 200, fn () => $ad->fresh()->document['duration'] === 21);
        });
    }

    /** @return array{0: User, 1: Organization} */
    private function designer(): array
    {
        $this->seedSuperAdmin();
        $organization = Organization::factory()->create(['name' => 'Alpha Mart']);

        return [$this->organizationMember($organization, ['ad-view', 'ad-store', 'ad-update'], 'designer@example.com', 'Designer'), $organization];
    }

    private function length(Browser $browser): ?int
    {
        return $browser->script('return '.self::EDITOR.'.doc.duration ?? null;')[0];
    }

    private function setValue(Browser $browser, string $selector, string $value, string $event): void
    {
        $css = str_starts_with($selector, '@') ? '[dusk="'.substr($selector, 1).'"]' : $selector;

        $browser->script(
            "const el = document.querySelector('{$css}');"
            .'el.value = '.json_encode($value).';'
            ."el.dispatchEvent(new Event('{$event}', { bubbles: true }));"
        );
    }
}
