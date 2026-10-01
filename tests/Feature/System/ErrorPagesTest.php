<?php

use App\Models\Organization;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| The panel's own error pages (owner, 2026-09-30)
|--------------------------------------------------------------------------
|
| 404, 403, 419, 429, 500, 503 and every other 4xx and 5xx say what happened in words and lead back to the
| dashboard, in the panel's look — drawn with their own styles, so they still show when the built files or the
| database are what broke — and never with a framework's words or an exception's inside.
|
*/

beforeEach(function () {
    Route::middleware('web')->group(function () {
        Route::get('/_errors/{code}', fn (int $code) => abort($code))->whereNumber('code');
        Route::get('/_errors/crash', fn () => throw new RuntimeException('SQLSTATE secret table users_backup'));
    });
});

/** One error page, checked for what every one of them must be. */
function errorPage(string $uri, int $status, string $title): string
{
    $html = test()->get($uri)->assertStatus($status)->getContent();

    expect($html)->toContain("<title>{$title} · ".config('app.name').'</title>')
        ->and(substr_count($html, '<h1'))->toBe(1)
        ->and($html)->toContain('dusk="error-dashboard"')
        ->and($html)->toContain('href="'.url('/dashboard').'"')
        // Its own styles: no built file to be missing.
        ->and($html)->not->toContain('/build/assets/');

    return $html;
}

test('a page that is not there says so, and nothing of how the app is built', function () {
    $html = errorPage('/no-such-page', 404, 'Page not found');

    expect($html)->toContain('Error 404')->toContain('It may have been moved or deleted')->not->toContain('Not Found');

    // A row that is not there is a 404 with the same words, never the model's name.
    $html = errorPage('/_errors/404', 404, 'Page not found');
    expect($html)->not->toContain('App\\Models');
});

test('a refusal says why in plain words: Laravel\'s own are replaced, ours are kept', function () {
    $organization = Organization::factory()->create();
    $viewer = createOrganizationUser($organization, ['media-view'], 'Looks At Files');

    $html = $this->actingAs($viewer)->withSession(['current_organization_id' => $organization->id])->get('/screens')->assertForbidden()->getContent();
    expect($html)->toContain('You cannot open this')->toContain('Your role does not allow it.')
        ->not->toContain('This action is unauthorized.');

    // A refusal of our own keeps its reason.
    $html = $this->actingAs(createSuperAdmin())->post('/organizations/switch', ['organization_id' => $organization->id])->assertForbidden()->getContent();
    expect($html)->toContain('The platform team works above the organizations. Use &quot;Log In As&quot; to see an organization as one of its members.');
});

test('an expired page, too many tries, a crash and a pause each say what happened', function () {
    config(['app.debug' => false]);

    expect(errorPage('/_errors/419', 419, 'This page expired'))->toContain('dusk="error-back"')->toContain('Go Back');
    expect(errorPage('/_errors/429', 429, 'Too many tries'))->toContain('Please wait a minute');
    expect(errorPage('/_errors/503', 503, 'Back in a moment'))->toContain('We are updating the site');

    // A crash says nothing of what crashed.
    $html = errorPage('/_errors/crash', 500, 'Something went wrong');
    expect($html)->toContain('It is on our side, not yours.')->not->toContain('SQLSTATE')->not->toContain('users_backup');
});

test('any other refusal or failure still has a page of the app\'s own, with its code', function () {
    config(['app.debug' => false]);

    expect(errorPage('/_errors/405', 405, 'That did not work'))->toContain('Error 405')->toContain('Go Back');
    expect(errorPage('/_errors/413', 413, 'That did not work'))->toContain('Error 413');
    expect(errorPage('/_errors/502', 502, 'Something went wrong'))->toContain('Error 502');

    // Text that is not text (RejectMalformedText) is a 400 of the same kind.
    $html = $this->get("/login?q=\xC3\x28")->assertStatus(400)->getContent();
    expect($html)->toContain('That did not work')->toContain('Error 400');
});

test('a request a page makes still gets its answer as JSON', function () {
    $this->getJson('/no-such-page')->assertNotFound()->assertJsonStructure(['message']);
});

test('every refusal and failure the app may answer has a page of its own, with its code and one heading', function () {
    config(['app.debug' => false]);

    $codes = [...range(400, 418), 421, 422, 423, 424, 425, 426, 428, 429, 431, 451, ...range(500, 508), 510, 511];

    foreach ($codes as $code) {
        $html = $this->get("/_errors/{$code}")->assertStatus($code)->getContent();

        expect($html)->toContain("Error {$code}")
            ->and(substr_count($html, '<h1'))->toBe(1, "{$code} has more or fewer than one heading")
            ->and($html)->toContain('dusk="error-dashboard"')
            ->and($html)->not->toContain('Illuminate\\')
            ->and($html)->not->toContain('Symfony\\');
    }
});
