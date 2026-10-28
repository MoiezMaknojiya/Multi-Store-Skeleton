<?php

/*
|--------------------------------------------------------------------------
| The player turns a portrait stage only while the display lies flat
|--------------------------------------------------------------------------
|
| A Fire TV's WebView puts a video on a hardware plane that never turns with the stage — the plane's box is
| the video element's un-turned rect — so a CSS-turned portrait stage showed the video sideways and small
| (Roadstar 4, 2026-10-27). The Android player app turns the DISPLAY the way the set is mounted instead, and
| the page, seeing a viewport taller than wide, draws a portrait screen as it draws a landscape one: the
| stage is the viewport and nothing is turned. So the quarter turns live inside a landscape media query and
| nowhere else. TurnedDisplayTest (Dusk) watches the same page in both window shapes.
|
*/

it('turns a portrait stage only while the display lies flat', function () {
    $html = $this->get('/player')->assertOk()->getContent();
    preg_match_all('~<style>(.*?)</style>~s', $html, $styles);
    $css = implode("\n", $styles[1]);

    $start = strpos($css, '@media (orientation: landscape)');
    expect($start)->not->toBeFalse('the turn has no landscape media query');

    // The block: from its first brace to the one that closes it, braces inside counted.
    $open = strpos($css, '{', $start);
    $depth = 0;
    for ($close = $open, $length = strlen($css); $close < $length; $close++) {
        if ($css[$close] === '{') {
            $depth++;
        } elseif ($css[$close] === '}' && --$depth === 0) {
            break;
        }
    }
    $inside = substr($css, $open, $close - $open + 1);
    $outside = substr($css, 0, $start).substr($css, $close + 1);

    expect($inside)
        ->toContain('#stage[data-orientation="portrait"] { transform: rotate(90deg); }')
        ->toContain('#stage[data-orientation="portrait_flipped"] { transform: rotate(270deg); }')
        ->toContain('width: 100vh; height: 100vw;');
    expect($outside)
        ->not->toContain('rotate(90deg)')
        ->not->toContain('rotate(270deg)')
        // A set mounted upside down is still turned by the page: that half is the app's to make.
        ->toContain('#stage[data-orientation="landscape_flipped"] { transform: rotate(180deg); }');
});
