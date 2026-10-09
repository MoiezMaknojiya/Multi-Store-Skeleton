<?php

use App\Models\Media;
use App\Models\Organization;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| A page's own request always gets words it can show (QA round, 2026-10-09)
|--------------------------------------------------------------------------
|
| Every page toasts `message ?? '…'`, and `??` keeps an empty string. A bare abort(404) answered "message": "", so a red
| box with nothing in it was shown. A missing model or route named Laravel's insides ("No query results for model
| [App\Models\Media]", "The route … could not be found"), and a server error said "Server Error". Laravel's own words are
| replaced (bootstrap/app.php, respond). Ours are kept.
|
*/

beforeEach(function () {
    $this->organization = Organization::factory()->create();
    $this->person = createOrganizationUser($this->organization, ['media-view', 'media-update'], 'Designer');
});

test('a file that is gone, or never was, is said in words, and no model or route is named', function () {
    $other = Media::factory()->create(['organization_id' => Organization::factory()->create()->id]);
    $words = 'This is no longer here: it may have been deleted, or changed in another tab. Reload the page to see what is there now.';

    $this->actingAs($this->person)->withSession(['current_organization_id' => $this->organization->id])
        ->putJson("/media/{$other->id}", ['title' => 'x'])->assertNotFound()->assertJsonPath('message', $words);
    $this->actingAs($this->person)->putJson('/media/999999', ['title' => 'x'])->assertNotFound()->assertJsonPath('message', $words);
    $this->actingAs($this->person)->postJson('/no/such/route')->assertNotFound()->assertJsonPath('message', $words);
});

test('a refusal by permission, an ended session and a server error are said in words', function () {
    $this->actingAs($this->person)->withSession(['current_organization_id' => $this->organization->id])
        ->getJson('/members/data')->assertForbidden()
        ->assertJsonPath('message', 'You are not allowed to do this. If your role was changed, reload the page.');

    auth()->logout();
    $this->getJson('/media/data')->assertUnauthorized()->assertJsonPath('message', 'Your session has ended. Sign in again to carry on.');

    config(['app.debug' => false]);
    Route::middleware('web')->get('/qa-boom', fn () => throw new RuntimeException('a secret detail'));
    $answer = $this->getJson('/qa-boom')->assertStatus(500);
    expect($answer->json('message'))->toBe('Something went wrong on our side. Nothing was changed. Please try again in a moment.')
        ->and($answer->getContent())->not->toContain('a secret detail');
});

test('words of our own are kept', function () {
    Route::middleware('web')->get('/qa-own-404', fn () => abort(404, 'That screen was removed.'));
    Route::middleware('web')->get('/qa-own-403', fn () => abort(403, 'The platform team works above the organizations.'));

    $this->getJson('/qa-own-404')->assertNotFound()->assertJsonPath('message', 'That screen was removed.');
    $this->getJson('/qa-own-403')->assertForbidden()->assertJsonPath('message', 'The platform team works above the organizations.');
});
