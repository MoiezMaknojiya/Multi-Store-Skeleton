<?php

use App\Models\BuilderAd;
use App\Models\Channel;
use App\Models\Organization;
use App\Models\Permission;
use App\Models\Screen;

/*
|--------------------------------------------------------------------------
| Every button says its words in Title Case (owner, 2026-09-30: "Create Ad")
|--------------------------------------------------------------------------
|
| Read from the pages themselves, not from a list of labels: every page of the panel, above the organizations and inside
| one, is opened, and every button, every link drawn as a button, every tab and every menu item it holds — shown or
| waiting in a template — is read, its own words and the words its Alpine binding may show. Every word capitalised
| but a, an, the, and, or, for, to, in, of, on, at, by, from and with, unless first or last or a verb's own particle.
|
*/

/** A label as Title Case writes it (Microsoft's title capitalisation, the rule in 02-project-conventions.md). */
function titleCased(string $label): string
{
    $minor = ['a', 'an', 'the', 'and', 'but', 'or', 'nor', 'for', 'so', 'yet', 'as', 'at', 'by', 'from', 'in', 'into',
        'of', 'off', 'on', 'onto', 'out', 'over', 'to', 'up', 'via', 'with'];
    $particles = ['log in', 'log out', 'sign in', 'sign out', 'sign up', 'turn off', 'turn on', 'set up'];

    $words = explode(' ', $label);
    $lettered = array_keys(array_filter($words, fn (string $word) => preg_match('/[A-Za-z]/', $word) === 1));
    $first = $lettered[0] ?? null;
    $last = end($lettered);

    foreach ($words as $i => $word) {
        if (! preg_match('/^([^A-Za-z]*)([A-Za-z][A-Za-z\'’-]*)(.*)$/u', $word, $m)) {
            continue;
        }

        [, $pre, $core, $post] = $m;
        $lower = strtolower($core);
        $previous = $i > 0 ? strtolower(preg_replace('/[^A-Za-z]/', '', $words[$i - 1])) : '';

        if (strlen($core) > 1 && strtoupper($core) === $core) {
            continue;   // An abbreviation: OK, TV, A-Z.
        }

        $core = in_array($lower, $minor, true) && $i !== $first && $i !== $last && ! in_array("{$previous} {$lower}", $particles, true)
            ? $lower
            : ucfirst($core);

        $words[$i] = $pre.$core.$post;
    }

    return implode(' ', $words);
}

/**
 * The words of every button-like element of a page: its own text (its icons and its words for a screen reader
 * aside) and each label its x-text may show.
 *
 * @return list<string>
 */
function buttonWordsOf(string $html): array
{
    // PHP 8.3's parser is not a browser's: an attribute named "@click" whose value holds "=>" ends the tag early, so
    // Alpine's shorthand is renamed before it reads the page.
    $html = preg_replace('/(\s)@([A-Za-z][\w.:-]*)=/', '$1data-on-$2=', $html);

    $dom = new DOMDocument;
    libxml_use_internal_errors(true);
    $dom->loadHTML('<?xml encoding="utf-8" ?>'.$html);
    libxml_clear_errors();
    $xpath = new DOMXPath($dom);

    // Labels that are data, not words of ours: an organization's name in the switcher, the person's own menu, a page number,
    // a font's or a file's name in the editor's pickers, a layer's name.
    $data = ['organization-switcher', 'organization-switch-', 'sidebar-settings', 'pick-asset-', 'font-', 'layer-', 'element-', 'history-step'];

    $words = [];
    $nodes = $xpath->query('//button | //a[contains(@class, "btn-")] | //*[@role="menuitem"] | //*[@role="tab"] | //*[contains(@class, "tab-link")]');

    foreach ($nodes as $node) {
        $dusk = (string) $node->getAttribute('dusk').(string) $node->getAttribute('x-bind:dusk');

        if (collect($data)->contains(fn (string $prefix) => str_contains($dusk, $prefix))) {
            continue;
        }

        // Its own words: every text inside it but an icon, a screen reader's aside and a shortcut's hint.
        $copy = $node->cloneNode(true);
        foreach ((new DOMXPath($dom))->query('.//svg | .//*[contains(@class, "sr-only")] | .//kbd | .//*[contains(@class, "text-xs")][following-sibling::* or preceding-sibling::*]', $copy) as $aside) {
            $aside->parentNode?->removeChild($aside);
        }
        $own = trim(preg_replace('/\s+/u', ' ', $copy->textContent));

        if ($own !== '' && preg_match('/[A-Za-z]/', $own) && ! str_contains($own, '{{') && ! preg_match('/^\d/', $own)) {
            $words[] = $own;
        }

        // The words its binding may show: the button's own x-text, never a description beside it.
        foreach (['x-text'] as $attribute) {
            preg_match_all("/'([A-Z+][^'\\\\]*)'/", (string) $node->getAttribute($attribute), $literals);
            array_push($words, ...$literals[1]);
        }
    }

    return array_values(array_unique($words));
}

/** Every page's button words that are not Title Case, as "page: words". */
function wordsNotTitleCased(array $pages, callable $open): array
{
    $wrong = [];

    foreach ($pages as $page) {
        foreach (buttonWordsOf($open($page)) as $words) {
            // A label that runs on into a sentence (a hint beside it) is judged by its first line of words alone.
            if ($words !== titleCased($words)) {
                $wrong[] = "{$page}: \"{$words}\" (".titleCased($words).')';
            }
        }
    }

    return $wrong;
}

test('every button above the organizations says its words in Title Case', function () {
    $admin = createSuperAdmin();
    Organization::factory()->create(['name' => 'Alpha Mart']);
    $channel = Channel::factory()->create();

    $pages = ['/dashboard', '/users', '/organizations', '/permissions', '/roles', '/activity', '/channels', "/channels/{$channel->id}",
        '/campaigns', '/builder', '/builder/assets', '/builder/create?orientation=landscape', '/media', '/screens', '/profile'];

    $wrong = wordsNotTitleCased($pages, fn (string $page) => $this->actingAs($admin)->get($page)->assertOk()->getContent());

    expect($wrong)->toBe([]);
});

test('every button inside an organization says its words in Title Case', function () {
    $organization = Organization::factory()->create(['name' => 'Alpha Mart']);
    $owner = createOrganizationUser($organization, [...Permission::ORGANIZATION, 'organization-view', 'organization-store', 'organization-destroy', 'organization-update',
        'channel-view', 'channel-store', 'channel-update', 'channel-destroy', 'activity-view'], 'Everything');
    $screen = Screen::factory()->create(['organization_id' => $organization->id]);
    $channel = Channel::factory()->create(['organization_id' => $organization->id]);
    $ad = BuilderAd::factory()->create(['organization_id' => $organization->id]);

    $pages = ['/dashboard', '/screens', "/screens/{$screen->id}", '/media', '/channels', "/channels/{$channel->id}",
        '/builder', '/builder/assets', "/builder/{$ad->id}", '/members', '/roles', '/activity', '/settings/organization', '/settings/billing', '/profile'];

    $wrong = wordsNotTitleCased($pages, fn (string $page) => $this->actingAs($owner)
        ->withSession(['current_organization_id' => $organization->id])->get($page)->assertOk()->getContent());

    expect($wrong)->toBe([]);
});

test('the doors before the panel say their buttons in Title Case too', function () {
    $wrong = wordsNotTitleCased(['/login', '/register', '/forgot-password'], fn (string $page) => $this->get($page)->assertOk()->getContent());

    expect($wrong)->toBe([]);
});

test('the Title Case rule itself', function (string $label, string $expected) {
    expect(titleCased($label))->toBe($expected);
})->with([
    ['Remove from organization', 'Remove from Organization'],
    ['Log in as', 'Log In As'],
    ['Sign in to accept', 'Sign In to Accept'],
    ['Go to the dashboard', 'Go to the Dashboard'],
    ['Copy and replace', 'Copy and Replace'],
    ['Turn off for this organization', 'Turn Off for This Organization'],
    ['Choose a picture…', 'Choose a Picture…'],
    ['OK', 'OK'],
    ['+ Add a colour', '+ Add a Colour'],
]);
