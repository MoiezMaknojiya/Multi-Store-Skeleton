<?php

namespace Tests\Browser;

use App\Models\BuilderAd;
use App\Models\Campaign;
use App\Models\Channel;
use App\Models\Organization;
use App\Models\PlayerAppRelease;
use App\Models\Role;
use App\Models\Screen;
use App\Models\Upload;
use Facebook\WebDriver\Chrome\ChromeDevToolsDriver;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Laravel\Dusk\Browser;
use Tests\DuskTestCase;
use Tests\Support\ApkFiles;

/**
 * The panel's fixes of the review of 2026-10-15, in a real browser: the campaign form's and a channel's Add Ad upload box
 * after Escape and Discard Changes (and the TV app's after Publish Version), a slow save's answer and the dialog asked since
 * (a listing's Edit, the platform team's invitation, an organization's Billing, Use This Template), the booking label while editing, the Roles
 * list that did not come, Select All under a real double click and its name, and the billing notice.
 *
 * Presses sent to Chrome itself (Input.dispatchMouseEvent) carry the click count a double click carries; a slow line is
 * DuskTestCase::network().
 */
class ReviewFixesPanelFlowTest extends DuskTestCase
{
    use DatabaseMigrations;

    /* ── E1: the upload box after Escape ───────────────────────────────── */

    public function test_the_campaign_form_shut_with_escape_opens_again_empty_and_gives_its_upload_up(): void
    {
        $admin = $this->seedSuperAdmin();
        $organization = Organization::factory()->create(['name' => 'Alpha Mart', 'accepts_network_ads' => true]);
        Screen::factory()->create(['organization_id' => $organization->id, 'name' => 'Deli TV', 'accepts_network_ads' => true]);
        $saved = Campaign::factory()->create(['name' => 'Summer Cola']);

        $this->browse(function (Browser $browser) use ($admin, $saved) {
            $this->freshSession($browser);
            $browser->loginAs($admin)->visit('/campaigns');
            $this->waitForAlpine($browser);
            $this->watch($browser);

            // Add: a file in, then Escape and Discard Changes.
            $this->clickAndAwait($browser, '@add-campaign', fn (Browser $b) => $b->waitFor('@campaign-form', 3));
            $this->jsType($browser, '@campaign-name', 'Winter Cola');
            $this->uploadThrough($browser, 'campaign', $this->fixtureImage('review-advert.png', 20, 90, 200));
            $this->assertSame(1, Upload::count());

            $this->shutWithEscape($browser, '@campaign-name', 'campaign-form-modal');
            $browser->waitUntilMissing('@campaign-form');
            // Nobody saved it: the upload is given up on the server, not left a day to count against the room.
            $browser->waitUsing(10, 200, fn () => Upload::count() === 0);

            // Opened again: an empty box, and the form agrees with it.
            $this->clickAndAwait($browser, '@add-campaign', fn (Browser $b) => $b->waitFor('@campaign-form', 3));
            $browser->assertMissing('@campaign-upload-row')
                ->assertDontSee('Uploaded. It is added when you save.');
            $this->jsType($browser, '@campaign-name', 'Winter Cola');
            $this->jsClick($browser, '@campaign-save');
            $browser->waitForText('Choose the advert to upload.');
            $this->shutWithEscape($browser, '@campaign-name', 'campaign-form-modal');
            $browser->waitUntilMissing('@campaign-form');

            // Edit: a replacement in, Escape and Discard; opened again it keeps the advert it has, and says so.
            $this->clickAndAwait($browser, '@edit-campaign-'.$saved->id, fn (Browser $b) => $b->waitFor('@campaign-form', 3));
            $this->uploadThrough($browser, 'campaign', $this->fixtureImage('review-replacement.png', 200, 90, 20));
            $this->shutWithEscape($browser, '@campaign-name', 'campaign-form-modal');
            $browser->waitUntilMissing('@campaign-form');
            $browser->waitUsing(10, 200, fn () => Upload::count() === 0);

            $this->clickAndAwait($browser, '@edit-campaign-'.$saved->id, fn (Browser $b) => $b->waitFor('@campaign-form', 3));
            $browser->assertMissing('@campaign-upload-row')
                ->assertVisible('@campaign-current-advert')
                ->assertSeeIn('@campaign-current-advert', 'Choose a file to replace it.');

            $this->assertCalm($browser);
        });
    }

    public function test_a_channels_add_ad_shut_with_escape_opens_again_with_an_empty_upload_box(): void
    {
        $admin = $this->seedSuperAdmin();
        $channel = Channel::factory()->create(['organization_id' => null, 'name' => 'GAMA Promos']);

        $this->browse(function (Browser $browser) use ($admin, $channel) {
            $this->freshSession($browser);
            $browser->loginAs($admin)->visit('/channels/'.$channel->id);
            $this->waitForAlpine($browser);
            $this->watch($browser);

            $this->clickAndAwait($browser, '@add-channel-ad', fn (Browser $b) => $b->waitFor('@channel-ad-form', 3));
            $this->jsClick($browser, '@channel-ad-source-upload');
            $browser->waitFor('@channel-ad-drop');
            $this->uploadThrough($browser, 'channel-ad', $this->fixtureImage('review-gama.png', 200, 30, 30));
            $this->assertSame(1, Upload::count());

            $this->shutWithEscape($browser, '@channel-ad-title', 'channel-ad-modal');
            $browser->waitUntilMissing('@channel-ad-form');
            $browser->waitUsing(10, 200, fn () => Upload::count() === 0);

            $this->clickAndAwait($browser, '@add-channel-ad', fn (Browser $b) => $b->waitFor('@channel-ad-form', 3));
            $this->jsClick($browser, '@channel-ad-source-upload');
            $browser->waitFor('@channel-ad-drop')
                ->assertMissing('@channel-ad-upload-row');
            $this->jsClick($browser, '@channel-ad-save');
            $browser->waitForText('Choose the file to upload.');

            $this->assertCalm($browser);
        });
    }

    /** The box's own × pressed with a real mouse while the save carrying its file is on a slow line: the box is held, so
     *  the upload is never given up under the save, and the save lands with its file — the campaign's and a channel's. */
    public function test_the_upload_box_is_held_while_the_save_carrying_its_file_is_on_its_way(): void
    {
        $admin = $this->seedSuperAdmin();
        $channel = Channel::factory()->create(['organization_id' => null, 'name' => 'GAMA Promos']);

        $this->browse(function (Browser $browser) use ($admin, $channel) {
            $this->freshSession($browser);
            $browser->loginAs($admin)->visit('/campaigns');
            $this->waitForAlpine($browser);
            $this->watch($browser);

            $this->clickAndAwait($browser, '@add-campaign', fn (Browser $b) => $b->waitFor('@campaign-form', 3));
            $this->jsType($browser, '@campaign-name', 'Winter Cola');
            $this->uploadThrough($browser, 'campaign', $this->fixtureImage('review-held.png', 20, 160, 90));
            $this->pressRemoveWhileSaving($browser, 'campaign', 'review-held.png', '@campaign-save');
            // The row is written at once and the slow line holds the answer: counted once the answer has shut the form,
            // so whatever the answer does to the box is counted too.
            $browser->waitUsing(15, 200, fn () => Campaign::where('name', 'Winter Cola')->exists());
            $browser->waitUntilMissing('@campaign-form', 15);
            $this->network($browser);
            $this->assertSame(0, $this->requestsCounted($browser), 'the box gave the advert up under the save');

            $browser->visit('/channels/'.$channel->id);
            $this->waitForAlpine($browser);
            $this->watch($browser);
            $this->clickAndAwait($browser, '@add-channel-ad', fn (Browser $b) => $b->waitFor('@channel-ad-form', 3));
            $this->jsClick($browser, '@channel-ad-source-upload');
            $browser->waitFor('@channel-ad-drop');
            $this->uploadThrough($browser, 'channel-ad', $this->fixtureImage('review-held-gama.png', 160, 30, 90));
            $this->pressRemoveWhileSaving($browser, 'channel-ad', 'review-held-gama.png', '@channel-ad-save');
            $browser->waitUsing(15, 200, fn () => $channel->ads()->count() === 1);
            $browser->waitUntilMissing('@channel-ad-form', 15);
            $this->network($browser);
            $this->assertSame(0, $this->requestsCounted($browser), 'the box gave the file up under the save');

            $this->assertCalm($browser);
        });
    }

    /* ── E2: a slow save's answer and the dialog asked since ───────────── */

    public function test_a_slow_save_never_shuts_or_marks_the_dialog_opened_since_for_another_row(): void
    {
        $admin = $this->seedSuperAdmin();
        $alpha = Channel::factory()->create(['organization_id' => null, 'name' => 'Alpha Promos']);
        $beta = Channel::factory()->create(['organization_id' => null, 'name' => 'Beta Promos']);

        $this->browse(function (Browser $browser) use ($admin, $alpha, $beta) {
            $this->freshSession($browser);
            $browser->loginAs($admin)->visit('/channels');
            $this->waitForAlpine($browser);
            $this->watch($browser);
            $browser->waitFor('@edit-channel-'.$beta->id);

            // A rename that goes through, on a slow line; Escape and Discard; Beta opened and typed into.
            $this->network($browser, latency: 3000);
            $this->clickAndAwait($browser, '@edit-channel-'.$alpha->id, fn (Browser $b) => $b->waitFor('@channel-form', 3));
            $this->jsType($browser, '@channel-name', 'Alpha Promos 2');
            $this->jsClick($browser, '@channel-save');
            $this->shutWithEscape($browser, '@channel-name', 'channel-form-modal');
            $browser->waitUntilMissing('@channel-form');
            $this->clickAndAwait($browser, '@edit-channel-'.$beta->id, fn (Browser $b) => $b->waitFor('@channel-form', 3));
            $this->jsType($browser, '@channel-name', 'Beta, half typed');

            // The slow line holds the ANSWER, not the request: the row is renamed at once, and the page hears of it three
            // seconds on. What is checked is what that answer did, so the test waits for the answer itself — its toast —
            // which also brings the save's in-flight flag down for the next save.
            $browser->waitUsing(15, 200, fn () => $alpha->fresh()->name === 'Alpha Promos 2');
            $browser->waitForText('Channel updated', 15)
                ->pause(800)
                ->assertVisible('@channel-form')
                ->assertInputValue('@channel-name', 'Beta, half typed');

            // A rename refused on the slow line: said in a toast, never under Beta's name.
            $this->shutWithEscape($browser, '@channel-name', 'channel-form-modal');
            $browser->waitUntilMissing('@channel-form');
            $this->clickAndAwait($browser, '@edit-channel-'.$alpha->id, fn (Browser $b) => $b->waitFor('@channel-form', 3));
            $this->jsType($browser, '@channel-name', 'Beta Promos');
            $this->jsClick($browser, '@channel-save');
            $this->shutWithEscape($browser, '@channel-name', 'channel-form-modal');
            $browser->waitUntilMissing('@channel-form');
            $this->clickAndAwait($browser, '@edit-channel-'.$beta->id, fn (Browser $b) => $b->waitFor('@channel-form', 3));
            $this->jsType($browser, '@channel-name', 'Beta, typed again');

            $browser->waitForText('was not saved:', 15)
                ->assertVisible('@channel-form')
                ->assertInputValue('@channel-name', 'Beta, typed again');
            $this->assertSame(0, $browser->script('return document.querySelectorAll(\'[dusk="channel-form"] .form-error\').length;')[0],
                'the refusal of Alpha was put under Beta\'s fields');

            $this->network($browser);
            $this->assertSame('Beta Promos', $beta->fresh()->name);
            $this->assertCalm($browser);
        });
    }

    /** The same on the platform's pages: the team's invitation on the Users page, and an organization's Billing. */
    public function test_a_slow_invitation_or_billing_answer_never_shuts_the_dialog_opened_since(): void
    {
        $admin = $this->seedSuperAdmin();
        $support = Role::create(['name' => 'Support', 'is_global' => true]);
        $alpha = Organization::factory()->create(['name' => 'Alpha Mart']);
        $beta = Organization::factory()->create(['name' => 'Beta Foods']);

        $this->browse(function (Browser $browser) use ($admin, $support, $alpha, $beta) {
            $this->freshSession($browser);
            $browser->loginAs($admin)->visit('/users');
            $this->waitForAlpine($browser);
            $this->watch($browser);

            // An invitation sent on a slow line; Escape and Discard; Invite again, and an address half typed.
            $this->network($browser, latency: 3000);
            $this->clickAndAwait($browser, '@invite-platform-member', fn (Browser $b) => $b->waitFor('@invite-platform-form', 3));
            $this->jsType($browser, '@invite-platform-email', 'ali@example.com');
            $browser->select('@invite-platform-role', (string) $support->id);
            $this->jsClick($browser, '@invite-platform-send');
            $this->shutWithEscape($browser, '@invite-platform-email', 'invite-platform-member');
            $browser->waitUntilMissing('@invite-platform-form');
            $this->clickAndAwait($browser, '@invite-platform-member', fn (Browser $b) => $b->waitFor('@invite-platform-form', 3));
            $this->jsType($browser, '@invite-platform-email', 'sara@exa');

            $browser->waitForText('Invitation sent to ali@example.com.', 15)
                ->pause(500)
                ->assertVisible('@invite-platform-form')
                ->assertInputValue('@invite-platform-email', 'sara@exa');
            $this->shutWithEscape($browser, '@invite-platform-email', 'invite-platform-member');

            // Billing saved on the slow line for Alpha Mart; Escape; Billing for Beta Foods stays open when Alpha's answer comes.
            $browser->visit('/organizations');
            $this->waitForAlpine($browser);
            $this->watch($browser);
            $browser->waitFor('@billing-organization-'.$beta->id, 15);
            $this->clickAndAwait($browser, '@billing-organization-'.$alpha->id, fn (Browser $b) => $b->waitForTextIn('@organization-billing', 'Billing · Alpha Mart', 3));
            $browser->waitFor('@billing-switch-premium_templates', 15)->waitUntilEnabled('@billing-switch-premium_templates', 15);
            $this->jsClick($browser, '@billing-switch-premium_templates');
            $this->jsClick($browser, '@organization-billing-save');
            // Escape on the dialog's Cancel, where its keyboard is (the first control when it opened, the summary still
            // loading): its switches and its field are disabled while the save is out, and WebDriver sends no key to those.
            $this->shutWithEscape($browser, '@organization-billing-cancel', 'organization-billing');
            $browser->waitUntilMissing('@organization-billing');
            $this->clickAndAwait($browser, '@billing-organization-'.$beta->id, fn (Browser $b) => $b->waitForTextIn('@organization-billing', 'Billing · Beta Foods', 3));

            // The row is written at once; the slow line holds the answer, so the check waits for the answer itself.
            $browser->waitUsing(15, 200, fn () => ! $alpha->fresh()->premium_templates_unlocked);
            $browser->waitForText('Locked Premium Templates for Alpha Mart.', 15)
                ->pause(800)
                ->assertVisible('@organization-billing')
                ->assertSeeIn('@organization-billing', 'Billing · Beta Foods');

            $this->network($browser);
            $this->assertTrue((bool) $beta->fresh()->premium_templates_unlocked);
            $this->assertCalm($browser);
        });
    }

    /** Use This Template on a slow line, the gallery shut with Escape and opened again for the other shape: the copy's
     *  answer leaves the page where it is — no editor from under the gallery open now — says it, and the list takes it. */
    public function test_a_template_copied_after_its_gallery_was_shut_leaves_the_page_where_it_is(): void
    {
        $this->seedSuperAdmin();
        $alpha = Organization::factory()->create(['name' => 'Alpha Mart']);
        $designer = $this->organizationMember($alpha, ['ad-view', 'ad-store', 'ad-update', 'ad-destroy'], 'designer@example.com', 'Designer');
        $burger = BuilderAd::factory()
            ->state(['organization_id' => null, 'name' => 'Burger menu', 'orientation' => BuilderAd::LANDSCAPE, 'document' => BuilderAd::blankDocument(BuilderAd::LANDSCAPE)])
            ->published()->create();
        $burger->media->update(['thumbnail_path' => $this->putImage("builder/platform/ads/{$burger->id}/published.png", 180, 40, 40)]);

        $this->browse(function (Browser $browser) use ($designer, $alpha, $burger) {
            $this->freshSession($browser);
            $browser->loginAs($designer);
            $this->switchToOrganization($browser, $alpha);
            $browser->visit('/builder');
            $this->waitForAlpine($browser);
            $browser->waitFor('@ads-empty');
            $this->watch($browser);

            $this->jsClick($browser, '@new-ad');
            $browser->waitFor('@new-ad-landscape');
            $this->jsClick($browser, '@new-ad-landscape');
            $this->jsClick($browser, '@new-ad-premium');
            $browser->waitFor('@use-template-'.$burger->id, 15);

            // Use This Template on the slow line; Escape; Create Ad again, Portrait, Premium Template.
            $this->network($browser, latency: 3000);
            $this->presses($browser, $this->centreOf($browser, '[dusk="use-template-'.$burger->id.'"]'), 1);
            $browser->waitForTextIn('@use-template-'.$burger->id, 'Copying...');
            $browser->keys('@templates-search', '{escape}');
            $browser->waitUntilMissing('@premium-templates');
            $this->jsClick($browser, '@new-ad');
            $browser->waitFor('@new-ad-portrait');
            $this->jsClick($browser, '@new-ad-portrait');
            $this->jsClick($browser, '@new-ad-premium');
            $browser->waitFor('@premium-templates');

            $browser->waitUsing(20, 200, fn () => BuilderAd::where('organization_id', $alpha->id)->exists());
            $browser->waitForText('Template copied to your ads', 15)->pause(800);

            // Before: the page left for the copy's editor from under the gallery opened since.
            $this->assertSame('/builder', parse_url($browser->driver->getCurrentURL(), PHP_URL_PATH), 'The page was left for the editor');
            $browser->assertVisible('@premium-templates');

            $this->network($browser);
            $browser->keys('@templates-search', '{escape}');
            $browser->waitUntilMissing('@premium-templates')
                ->waitFor('@ad-card-'.BuilderAd::where('organization_id', $alpha->id)->value('id'), 15);
            $this->assertSame(1, BuilderAd::where('organization_id', $alpha->id)->count());
            $this->assertCalm($browser);
        });
    }

    /** Developer → TV App: Publish Version starts the form again without giving up the upload the version was made from. */
    public function test_publishing_a_tv_app_version_never_gives_up_the_upload_it_was_made_from(): void
    {
        $admin = $this->seedSuperAdmin();
        $folder = sys_get_temp_dir().DIRECTORY_SEPARATOR.'dusk-apk-'.bin2hex(random_bytes(4));
        mkdir($folder);
        $apk = $folder.DIRECTORY_SEPARATOR.'TheDisplaySolution-1.0.apk';
        file_put_contents($apk, ApkFiles::apk(ApkFiles::manifest(versionCode: 1, versionName: '1.0')));

        try {
            $this->browse(function (Browser $browser) use ($admin, $apk) {
                $this->freshSession($browser);
                $browser->loginAs($admin)->visit('/developer');
                $this->waitForAlpine($browser);
                $this->watch($browser);

                $this->uploadThrough($browser, 'tv-app', $apk);
                $browser->waitFor('@tv-app-version-read');
                $upload = Upload::where('filename', 'TheDisplaySolution-1.0.apk')->value('id');
                $this->assertNotNull($upload);
                $this->countRequests($browser, 'DELETE', '/uploads/'.$upload);

                $this->jsClick($browser, '@tv-app-publish');
                $browser->waitUsing(15, 200, fn () => PlayerAppRelease::query()->exists());
                $browser->waitFor('@tv-app-release-'.PlayerAppRelease::query()->value('id'), 15)
                    ->waitUntilMissing('@tv-app-upload-row')
                    ->pause(500);

                // Before: the box's own clear() gave the published upload up, and the server answered 404.
                $this->assertSame(0, $this->requestsCounted($browser), 'the box gave the published upload up');
                $this->assertCalm($browser);
            });
        } finally {
            @unlink($apk);
            @rmdir($folder);
        }
    }

    /* ── E3: the booking label while editing ───────────────────────────── */

    public function test_editing_a_campaign_a_screen_ticked_counts_its_seconds_once_and_an_oversold_break_is_said(): void
    {
        $admin = $this->seedSuperAdmin();
        $organization = Organization::factory()->create(['name' => 'Alpha Mart', 'accepts_network_ads' => true]);
        $deli = Screen::factory()->create(['organization_id' => $organization->id, 'name' => 'Deli TV', 'accepts_network_ads' => true]);
        $bakery = Screen::factory()->create(['organization_id' => $organization->id, 'name' => 'Bakery TV', 'accepts_network_ads' => true]);
        $ours = Campaign::factory()->create(['name' => 'Winter Cola', 'duration_seconds' => 15]);
        $ours->screens()->sync([$deli->id]);
        Campaign::factory()->create(['name' => 'Bread Week', 'duration_seconds' => 50])->screens()->sync([$bakery->id]);

        $this->browse(function (Browser $browser) use ($admin, $ours, $deli, $bakery) {
            $this->freshSession($browser);
            $browser->loginAs($admin)->visit('/campaigns');
            $this->waitForAlpine($browser);
            // The list's rows arrive after the page: the row's Edit is not there before them.
            $browser->waitFor('@edit-campaign-'.$ours->id);

            $this->clickAndAwait($browser, '@edit-campaign-'.$ours->id, fn (Browser $b) => $b->waitFor('@campaign-screen-'.$bakery->id, 5));
            $this->assertStringContainsString('15s of 60s booked', $this->labelOf($browser, $deli->id));
            $this->assertStringContainsString('50s of 60s booked', $this->labelOf($browser, $bakery->id));

            // Ticked: the campaign's 15 join Bread Week's 50 — over the break, and said in amber.
            $this->jsClick($browser, '@campaign-screen-'.$bakery->id);
            $browser->waitUsing(5, 100, fn () => str_contains($this->labelOf($browser, $bakery->id), '65s of 60s booked'));
            $this->assertTrue($browser->script('return document.querySelector(\'[dusk="campaign-screen-'.$bakery->id.'"]\').closest("label").querySelector(".text-amber-700") !== null;')[0]);

            // Unticked: the campaign leaves the Deli's break.
            $this->jsClick($browser, '@campaign-screen-'.$deli->id);
            $browser->waitUsing(5, 100, fn () => preg_match('/(^|\D)0s of 60s booked/', $this->labelOf($browser, $deli->id)) === 1);
        });
    }

    /* ── E4: the Roles list that did not come ──────────────────────────── */

    public function test_the_roles_list_that_did_not_come_says_so_and_try_again_brings_it(): void
    {
        $admin = $this->seedSuperAdmin();

        $this->browse(function (Browser $browser) use ($admin) {
            $this->freshSession($browser);
            $browser->loginAs($admin)->visit('/roles');
            $this->waitForAlpine($browser);
            $browser->waitFor('@role-row-'.Role::superAdminId());

            // The line goes, and the list is asked for again.
            $this->network($browser, offline: true);
            $browser->script('Alpine.$data(document.querySelector(\'[dusk="roles-table"]\').closest("[x-data]")).fetch();');
            $browser->waitFor('@roles-load-failed', 10)
                ->assertSeeIn('@roles-load-failed', 'Could not load the roles.')
                ->assertDontSee('No roles yet.');

            $this->network($browser);
            $this->presses($browser, $this->centreOf($browser, '[dusk="roles-load-failed-try-again"]'), 1);
            $browser->waitFor('@role-row-'.Role::superAdminId(), 10)
                ->assertMissing('@roles-load-failed');
        });
    }

    /* ── E5: Select All under a real double click ──────────────────────── */

    public function test_select_all_is_one_press_for_a_double_click(): void
    {
        $admin = $this->seedSuperAdmin();
        $organization = Organization::factory()->create(['name' => 'Alpha Mart', 'accepts_network_ads' => true]);
        $deli = Screen::factory()->create(['organization_id' => $organization->id, 'name' => 'Deli TV', 'accepts_network_ads' => true]);
        $bakery = Screen::factory()->create(['organization_id' => $organization->id, 'name' => 'Bakery TV', 'accepts_network_ads' => true]);

        $this->browse(function (Browser $browser) use ($admin, $organization, $deli, $bakery) {
            $this->freshSession($browser);
            $browser->loginAs($admin)->visit('/campaigns');
            $this->waitForAlpine($browser);

            $this->clickAndAwait($browser, '@add-campaign', fn (Browser $b) => $b->waitFor('@campaign-organization-all-'.$organization->id, 5));
            $button = '[dusk="campaign-organization-all-'.$organization->id.'"]';
            // Its name holds the words it shows (WCAG 2.5.3), and whose screens they are.
            $browser->assertAttribute('@campaign-organization-all-'.$organization->id, 'aria-label', 'Select All screens of Alpha Mart');

            // A mouse that sends two: every screen chosen, and the button now clears them.
            $this->presses($browser, $this->centreOf($browser, $button), 2);
            $browser->pause(300)
                ->assertChecked('@campaign-screen-'.$deli->id)
                ->assertChecked('@campaign-screen-'.$bakery->id)
                ->assertSeeIn('@campaign-organization-all-'.$organization->id, 'Clear All')
                ->assertAttribute('@campaign-organization-all-'.$organization->id, 'aria-label', 'Clear All screens of Alpha Mart');

            // One press, a moment later, clears them.
            $browser->pause(600);
            $this->presses($browser, $this->centreOf($browser, $button), 1);
            $browser->pause(300)
                ->assertNotChecked('@campaign-screen-'.$deli->id)
                ->assertNotChecked('@campaign-screen-'.$bakery->id)
                ->assertSeeIn('@campaign-organization-all-'.$organization->id, 'Select All');
        });
    }

    /* ── E6: the billing notice ────────────────────────────────────────── */

    public function test_the_billing_notice_never_says_every_feature_is_open_over_a_locked_one(): void
    {
        $this->seedSuperAdmin();
        $organization = Organization::factory()->create(['name' => 'Smart Stop']);
        $organization->forceFill(['premium_templates_unlocked' => false])->save();
        $owner = $this->organizationMember($organization, Role::OWNER, 'owner@smartstop.example');

        $this->browse(function (Browser $browser) use ($owner, $organization) {
            $this->freshSession($browser);
            $browser->loginAs($owner);
            $this->switchToOrganization($browser, $organization);
            $browser->visit('/settings/billing')
                ->waitFor('@billing-not-started')
                ->assertSeeIn('@billing-not-started', 'Billing has not started yet, so nothing is charged.')
                ->assertDontSee('every feature is open')
                ->assertSeeIn('@billing-feature-premium_templates', 'Locked')
                ->assertSeeIn('@billing-feature-platform_channels', 'Unlocked');
        });
    }

    /* ── Helpers ───────────────────────────────────────────────────────── */

    /** Escape in the dialog; with changes in it the dialog asks first, and Discard Changes is pressed. */
    private function shutWithEscape(Browser $browser, string $field, string $modal): void
    {
        $browser->keys($field, '{escape}')->pause(250);

        $asks = $browser->script('const ask = document.querySelector(\'[dusk="'.$modal.'-discard-ask"]\'); return !! ask && ask.offsetParent !== null;')[0];

        if ($asks) {
            $this->jsClick($browser, '@'.$modal.'-discard');
        }
    }

    /** Save pressed on a slow line, then the box's own × on its uploaded row with a real mouse while the save is out:
     *  every DELETE of that upload is counted, and the box is said to be held. */
    private function pressRemoveWhileSaving(Browser $browser, string $box, string $file, string $save): void
    {
        $upload = Upload::where('filename', $file)->value('id');
        $this->assertNotNull($upload);
        $this->countRequests($browser, 'DELETE', '/uploads/'.$upload);

        $this->network($browser, latency: 3000);
        $this->jsClick($browser, $save);
        $browser->pause(300);

        $this->assertTrue($browser->script('return document.querySelector(\'[dusk="'.$box.'-dropzone"]\').closest("[inert]") !== null;')[0],
            'the box is not held while the save carrying its file is out');
        $this->presses($browser, $this->centreOf($browser, '[dusk="'.$box.'-upload-remove"]'), 1);
        $browser->pause(300)->assertVisible('@'.$box.'-upload-row');
    }

    /** What the screen's row of the campaign form says beside its name. */
    private function labelOf(Browser $browser, int $screenId): string
    {
        return (string) $browser->script('return document.querySelector(\'[dusk="campaign-screen-'.$screenId.'"]\').closest("label").innerText;')[0];
    }

    private function watch(Browser $browser): void
    {
        $browser->script(<<<'JS'
            if (window.__w) return;
            window.__w = { errors: [] };
            window.addEventListener('error', (e) => window.__w.errors.push(String(e.message)));
            window.addEventListener('unhandledrejection', (e) => window.__w.errors.push(String(e.reason)));
        JS);
    }

    private function assertCalm(Browser $browser): void
    {
        $this->assertSame([], $browser->script('return window.__w ? window.__w.errors : [];')[0], 'The page threw');
    }

    /** Presses sent to Chrome itself, each carrying the click count a person's double or triple click carries. */
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
