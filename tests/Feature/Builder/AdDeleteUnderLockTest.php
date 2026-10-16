<?php

use App\Models\ActivityLog;
use App\Models\BuilderAd;
use App\Models\Channel;
use App\Models\ChannelAd;
use App\Models\Media;
use App\Models\Organization;
use App\Models\PlaylistItem;
use App\Models\Screen;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Support\TransactionStatements;

/*
|--------------------------------------------------------------------------
| Deleting a design whose page a channel picks at the same moment (the review of 2026-10-15)
|--------------------------------------------------------------------------
|
| A published design is a library row, and a channel's Add Ad holds that row's lock while it writes its ad. The delete
| asked the channels once, before the password, and then deleted with no lock: an ad added in between was taken away
| by the foreign key's cascade with nobody deciding so. It now asks again under the same lock, as a media file's delete
| does. SQLite has no row locks, so the other person's ad lands the moment this delete takes its lock — after the
| question asked before the password, before the delete.
|
*/

test('a channel ad added while a design is being deleted is seen under its page\'s lock, and the delete refused', function () {
    Storage::fake('public');
    $organization = Organization::factory()->create();
    $this->actingAs(createOrganizationUser($organization, ['ad-view', 'ad-destroy']))
        ->withSession(['current_organization_id' => $organization->id]);
    $page = Media::factory()->adPage()->create(['organization_id' => $organization->id, 'title' => 'Winter sale']);
    $ad = BuilderAd::factory()->create(['organization_id' => $organization->id, 'name' => 'Winter sale', 'media_id' => $page->id]);
    $channel = Channel::factory()->create(['organization_id' => $organization->id, 'name' => 'Weekly Deals']);

    $landed = false;
    DB::listen(function (QueryExecuted $query) use (&$landed, $page, $channel) {
        if (! $landed && str_starts_with($query->sql, 'select "id" from "media" where "media"."id" =')) {
            $landed = true;
            ChannelAd::factory()->create(['channel_id' => $channel->id, 'media_id' => $page->id]);
        }
    });

    $this->deleteJson("/builder/{$ad->id}", ['password' => 'password'])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['name' => 'Still used by the channel Weekly Deals. Take it out of that channel first.']);

    // The design and its page stay, so the channel's ad is never taken away with them. (The ad itself was written inside this
    // request's transaction, which the refusal rolled back: in life it is the other person's own.)
    expect($landed)->toBeTrue()
        ->and(BuilderAd::find($ad->id))->not->toBeNull()
        ->and(Media::find($page->id))->not->toBeNull();
});

test("the delete's first statement is its page's lock, and the channels are read after it", function () {
    // MySQL's REPEATABLE READ answers a transaction's plain reads from the snapshot its first plain read takes, while a locking
    // read takes none. So the lock must open the transaction: then the channels are read once whoever held the page has
    // committed, and their ad is seen. SQLite has neither, so this reads the order (LocksShownGrammar writes the lock in).
    Storage::fake('public');
    $organization = Organization::factory()->create();
    $this->actingAs(createOrganizationUser($organization, ['ad-view', 'ad-destroy']))
        ->withSession(['current_organization_id' => $organization->id]);
    $page = Media::factory()->adPage()->create(['organization_id' => $organization->id]);
    $ad = BuilderAd::factory()->create(['organization_id' => $organization->id, 'media_id' => $page->id]);
    Screen::factory()->create(['organization_id' => $organization->id, 'default_media_id' => $page->id]);
    $log = TransactionStatements::record();

    $this->deleteJson("/builder/{$ad->id}", ['password' => 'password'])->assertOk();

    $statements = $log->of('/^delete from "builder_ads"/');
    $channels = array_key_first(preg_grep('/^select "name" from "channels" where "id" in \(select "channel_id" from "channel_ads"/', $statements));
    $screens = array_key_first(preg_grep('/^select "id" from "screens" where "default_media_id" = \? order by "id" asc/', $statements));
    $deleted = array_key_first(preg_grep('/^delete from "media"/', $statements));

    // Before: the transaction held the delete alone — no lock, and the channels read only before it, outside.
    expect($statements[0])->toStartWith('select "id" from "media" where "media"."id" = ?')
        ->and(TransactionStatements::locksForUpdate($statements[0]))->toBeTrue()
        ->and($channels)->toBe(1)
        ->and($deleted)->toBeGreaterThan($channels);

    // The screens the page is the holding picture of are held after the page and before it goes, as a media file's delete holds
    // them (MediaController::destroy): the foreign key would otherwise lock them only after the playlist lines it takes, while a
    // playlist's save holds its screen and then takes its lines — each waiting on the other, a deadlock on MySQL. (Before: no
    // such lock at all.)
    expect($screens)->not->toBeNull()
        ->and(TransactionStatements::locksForUpdate($statements[$screens]))->toBeTrue()
        ->and($screens)->toBeGreaterThan(0)
        ->and($screens)->toBeLessThan($deleted);
});

test('a draft published in another tab while its delete waited is deleted with its new page, never leaving it behind', function () {
    // The design was read as a draft; its first Publish committed before the delete's transaction began. Before: the delete
    // took the design alone, and its new page stayed — in no library, in no editor, counted to the 512 MB for good.
    Storage::fake('public');
    $organization = Organization::factory()->create();
    $this->actingAs(createOrganizationUser($organization, ['ad-view', 'ad-destroy']))
        ->withSession(['current_organization_id' => $organization->id]);
    $ad = BuilderAd::factory()->create(['organization_id' => $organization->id, 'name' => 'Winter sale', 'media_id' => null]);
    $page = Media::factory()->adPage()->create(['organization_id' => $organization->id, 'title' => 'Winter sale']);
    $screen = Screen::factory()->create(['organization_id' => $organization->id]);
    PlaylistItem::create(['screen_id' => $screen->id, 'media_id' => $page->id, 'position' => 0, 'duration_seconds' => 10]);

    // The other tab's Publish lands the moment this delete's transaction runs its first statement.
    $outside = DB::transactionLevel();
    $published = false;
    DB::connection()->beforeExecuting(function () use (&$published, $outside, $ad, $page) {
        if (! $published && DB::transactionLevel() > $outside) {
            $published = true;
            DB::table('builder_ads')->where('id', $ad->id)->update(['media_id' => $page->id, 'published_at' => now()]);
        }
    });

    $this->deleteJson("/builder/{$ad->id}", ['password' => 'password'])
        ->assertOk()
        ->assertJsonPath('message', 'Ad deleted, and taken off 1 playlist line');

    expect($published)->toBeTrue()
        ->and(BuilderAd::find($ad->id))->toBeNull()
        ->and(Media::find($page->id))->toBeNull()
        ->and(PlaylistItem::count())->toBe(0)
        ->and(ActivityLog::where('action', 'ad.deleted')->count())->toBe(1);
});

test('a draft published meanwhile is held first and let go, then deleted under its page\'s lock: never the design and then the page', function () {
    // A publish takes the page and then the design (AdPublisher). The draft's lock is its own transaction's only read; the
    // page is then held first in a transaction of its own, the channels read after it — the order every published delete keeps.
    Storage::fake('public');
    $organization = Organization::factory()->create();
    $this->actingAs(createOrganizationUser($organization, ['ad-view', 'ad-destroy']))
        ->withSession(['current_organization_id' => $organization->id]);
    $ad = BuilderAd::factory()->create(['organization_id' => $organization->id, 'media_id' => null]);
    $page = Media::factory()->adPage()->create(['organization_id' => $organization->id]);
    $outside = DB::transactionLevel();
    $published = false;
    DB::connection()->beforeExecuting(function () use (&$published, $outside, $ad, $page) {
        if (! $published && DB::transactionLevel() > $outside) {
            $published = true;
            DB::table('builder_ads')->where('id', $ad->id)->update(['media_id' => $page->id, 'published_at' => now()]);
        }
    });
    $log = TransactionStatements::record();

    $this->deleteJson("/builder/{$ad->id}", ['password' => 'password'])->assertOk();

    // (The other tab's Publish is the test's own update, run inside the draft's transaction: left out.)
    $draft = array_values(preg_grep('/^update "builder_ads"/', $log->of('/^select "id", "media_id" from "builder_ads" where "builder_ads"."id" = \?/'), PREG_GREP_INVERT));
    $deleting = $log->of('/^delete from "builder_ads"/');

    expect($draft)->toHaveCount(1)
        ->and(TransactionStatements::locksForUpdate($draft[0]))->toBeTrue()
        ->and($deleting[0])->toStartWith('select "id" from "media" where "media"."id" = ?')
        ->and(TransactionStatements::locksForUpdate($deleting[0]))->toBeTrue()
        ->and(preg_grep('/from "builder_ads".*for update/', $deleting))->toBe([]);
});

test('two deletes of one draft at the same moment: the second finds it gone under its lock, 404, and it is logged once', function () {
    Storage::fake('public');
    $organization = Organization::factory()->create();
    $this->actingAs(createOrganizationUser($organization, ['ad-view', 'ad-destroy']))
        ->withSession(['current_organization_id' => $organization->id]);
    $ad = BuilderAd::factory()->create(['organization_id' => $organization->id, 'media_id' => null]);

    // The other tab's delete commits the moment this one's transaction runs its first statement.
    $outside = DB::transactionLevel();
    $done = false;
    DB::connection()->beforeExecuting(function () use (&$done, $outside, $ad) {
        if (! $done && DB::transactionLevel() > $outside) {
            $done = true;
            DB::table('builder_ads')->where('id', $ad->id)->delete();
        }
    });

    // Before: "Ad deleted" for a design already gone, and a line in the log for it. (The other tab's delete ran inside this
    // request's transaction, which the 404 rolled back: in life it is the other tab's own, committed, and logged by it.)
    $this->deleteJson("/builder/{$ad->id}", ['password' => 'password'])->assertNotFound();

    expect($done)->toBeTrue()
        ->and(ActivityLog::where('action', 'ad.deleted')->count())->toBe(0);
});

test('a design deleted in another tab while its delete waited for the page is 404, and is not logged twice', function () {
    Storage::fake('public');
    $organization = Organization::factory()->create();
    $this->actingAs(createOrganizationUser($organization, ['ad-view', 'ad-destroy']))
        ->withSession(['current_organization_id' => $organization->id]);
    $page = Media::factory()->adPage()->create(['organization_id' => $organization->id]);
    $ad = BuilderAd::factory()->create(['organization_id' => $organization->id, 'media_id' => $page->id]);

    // The other tab's delete commits the moment this one asks for the page's lock.
    $outside = DB::transactionLevel();
    $done = false;
    DB::connection()->beforeExecuting(function (string $sql) use (&$done, $outside, $ad) {
        if (! $done && DB::transactionLevel() > $outside && str_starts_with($sql, 'select "id" from "media" where "media"."id" =')) {
            $done = true;
            BuilderAd::findOrFail($ad->id)->delete();
        }
    });

    // Before: "Ad deleted", and a second line in the log for a design already gone. (The other tab's delete ran inside this
    // request's transaction, which the 404 rolled back: in life it is the other tab's own, committed.)
    $this->deleteJson("/builder/{$ad->id}", ['password' => 'password'])->assertNotFound();

    expect($done)->toBeTrue()
        ->and(ActivityLog::where('action', 'ad.deleted')->count())->toBe(0);
});

test('a design no channel shows is deleted under the same lock, page and all', function () {
    Storage::fake('public');
    $organization = Organization::factory()->create();
    $this->actingAs(createOrganizationUser($organization, ['ad-view', 'ad-destroy']))
        ->withSession(['current_organization_id' => $organization->id]);
    $page = Media::factory()->adPage()->create(['organization_id' => $organization->id]);
    $ad = BuilderAd::factory()->create(['organization_id' => $organization->id, 'media_id' => $page->id]);
    $draft = BuilderAd::factory()->create(['organization_id' => $organization->id, 'media_id' => null]);

    $this->deleteJson("/builder/{$ad->id}", ['password' => 'password'])->assertOk();
    $this->deleteJson("/builder/{$draft->id}", ['password' => 'password'])->assertOk();

    expect(BuilderAd::count())->toBe(0)->and(Media::find($page->id))->toBeNull();
});
