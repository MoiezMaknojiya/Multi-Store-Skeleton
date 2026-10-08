<?php

use App\Models\ActivityLog;
use App\Models\Channel;
use App\Models\Organization;
use App\Models\Screen;

/*
|--------------------------------------------------------------------------
| A channel the platform makes for one organization (owner, 2026-10-08)
|--------------------------------------------------------------------------
|
| "Super admin ke Create Channel form mein Organization list, taake kisi ek organization ke liye free channel ban sake ... yeh wala
| kardo." Above the organizations Add Channel asks whom it is for: All organizations — the platform's channel, unlocked with
| Platform Channels ($10) — or one organization, whose own channel it is, free, for its screens alone (docs/BILLING-SPEC.md §1).
| Said once, at its making.
|
*/

beforeEach(function () {
    $this->alpha = Organization::factory()->create(['name' => 'Alpha Mart']);
    $this->beta = Organization::factory()->create(['name' => 'Beta Foods']);
    $this->actingAs(createSuperAdmin());
});

test('made for one organization, a channel is that organization’s own: listed, opened and changed there, and free', function () {
    $id = $this->postJson('/channels', ['name' => 'Alpha Deals', 'organization_id' => $this->alpha->id])->assertOk()->json('channel.id');
    $channel = Channel::findOrFail($id);

    expect($channel->organization_id)->toBe($this->alpha->id)
        ->and(ActivityLog::where('action', 'channel.created')->sole())
        ->description->toBe('Created channel Alpha Deals for Alpha Mart')
        ->organization_id->toBe($this->alpha->id);

    // Above the organizations its row says whose screens it reaches.
    expect(collect($this->getJson('/channels/data')->json('channels'))->firstWhere('id', $id)['organization_name'])->toBe('Alpha Mart');

    // Inside Alpha Mart it is its own: not read-only, offered to its screens, and free while Platform Channels are locked.
    $alphaPerson = createOrganizationUser($this->alpha, ['channel-view', 'channel-update', 'screen-view', 'screen-playlist'], 'Manager');
    $this->actingAs($alphaPerson)->withSession(['current_organization_id' => $this->alpha->id]);
    $this->alpha->forceFill(['platform_channels_unlocked' => false])->save();

    $row = collect($this->getJson('/channels/data')->assertOk()->json('channels'))->firstWhere('id', $id);
    expect($row['read_only'])->toBeFalse();
    $this->putJson("/channels/{$id}", ['name' => 'Alpha Weekend Deals'])->assertOk();

    $screen = Screen::factory()->create(['organization_id' => $this->alpha->id]);
    $offered = collect($this->getJson("/screens/{$screen->id}/available-channels")->assertOk()->json('channels'))->firstWhere('id', $id);
    expect($offered)->toMatchArray(['is_organization_channel' => true, 'locked' => false]);

    $version = $this->getJson("/screens/{$screen->id}/playlist")->json('version');
    $this->putJson("/screens/{$screen->id}/playlist", ['version' => $version, 'items' => [['channel_id' => $id]]])->assertOk();

    // Beta Foods never sees it.
    $this->actingAs(createOrganizationUser($this->beta, ['channel-view'], 'Viewer'))->withSession(['current_organization_id' => $this->beta->id]);
    expect(collect($this->getJson('/channels/data')->json('channels'))->pluck('id'))->not->toContain($id);
    $this->get("/channels/{$id}")->assertNotFound();
});

test('with no organization chosen it is the platform’s, for every organization', function () {
    $id = $this->postJson('/channels', ['name' => 'GAMA', 'organization_id' => ''])->assertOk()->json('channel.id');

    expect(Channel::find($id)->organization_id)->toBeNull()
        ->and(ActivityLog::where('action', 'channel.created')->sole()->description)->toBe('Created channel GAMA');

    $this->postJson('/channels', ['name' => 'GAMA 2'])->assertOk();
    expect(Channel::whereNull('organization_id')->count())->toBe(2);
});

test('a name stands apart within what that organization’s Channels tab lists: its own and the platform’s', function () {
    Channel::factory()->create(['name' => 'GAMA']);
    Channel::factory()->create(['organization_id' => $this->beta->id, 'name' => 'Deals']);

    $this->postJson('/channels', ['name' => 'GAMA', 'organization_id' => $this->alpha->id])->assertStatus(422)
        ->assertJsonValidationErrors(['name' => "There is already a channel with this name in this organization's list. Choose a different name."]);

    // Another organization's channel of that name is nothing to Alpha Mart's list.
    $this->postJson('/channels', ['name' => 'Deals', 'organization_id' => $this->alpha->id])->assertOk();
});

test('an organization that does not exist, or a choice that is no organization, is refused in words', function () {
    foreach ([999999 => 'That organization no longer exists. Reload the page and choose again.',
        0 => 'Choose All organizations or one organization from the list.',
        'abc' => 'Choose All organizations or one organization from the list.'] as $choice => $message) {
        $this->postJson('/channels', ['name' => 'Try '.$choice, 'organization_id' => $choice])->assertStatus(422)
            ->assertJsonValidationErrors(['organization_id' => $message]);
    }

    $this->postJson('/channels', ['name' => 'Arrays', 'organization_id' => [$this->alpha->id]])->assertStatus(422)->assertJsonValidationErrors('organization_id');

    expect(Channel::count())->toBe(0);
});

test('whom a channel is for stays as it was made, whatever an edit posts', function () {
    $channel = Channel::factory()->create(['organization_id' => $this->alpha->id, 'name' => 'Alpha Deals']);
    $platform = Channel::factory()->create(['name' => 'GAMA']);

    $this->putJson("/channels/{$channel->id}", ['name' => 'Alpha Deals', 'organization_id' => $this->beta->id])->assertOk();
    $this->putJson("/channels/{$platform->id}", ['name' => 'GAMA', 'organization_id' => $this->alpha->id])->assertOk();

    expect($channel->fresh()->organization_id)->toBe($this->alpha->id)
        ->and($platform->fresh()->organization_id)->toBeNull();
});

test('inside an organization the list is not there, and a posted organization is dropped unread', function () {
    $person = createOrganizationUser($this->alpha, ['channel-view', 'channel-store'], 'Channel maker');
    $this->actingAs($person)->withSession(['current_organization_id' => $this->alpha->id]);

    $this->get('/channels')->assertOk()->assertDontSee('dusk="channel-organization"', false);

    foreach ([$this->beta->id, 999999, 'x'] as $choice) {
        $this->postJson('/channels', ['name' => "Ours {$choice}", 'organization_id' => $choice])->assertOk();
    }

    expect(Channel::pluck('organization_id')->unique()->values()->all())->toBe([$this->alpha->id]);

    // Above the organizations the list is there, All organizations first.
    $this->actingAs(createSuperAdmin())->flushSession();
    $this->get('/channels')->assertOk()
        ->assertSee('dusk="channel-organization"', false)
        ->assertSeeInOrder(['All organizations', 'Alpha Mart', 'Beta Foods']);
});
