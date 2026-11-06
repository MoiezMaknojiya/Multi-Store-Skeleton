<?php

use Tests\Support\PageScript;

/**
 * A Fire TV's own part watches every video's first frame (resources/js/player/fire-tv.js; the owner's Fire TV Stick,
 * 2026-11-06): a video left after its metadata with no first frame, no error and nothing loading is set up once more,
 * and one still there is given up, so the next item comes on instead of an empty glass for the video's whole length.
 * Run under Node with a video and a clock of the test's own.
 */
function watchAFireTvVideo(string $story): array
{
    return PageScript::run(<<<JS
        import { startOnAFireTv } from './resources/js/player/fire-tv.js';

        // A clock the test moves: every setTimeout waits for tick().
        let now = 0;
        const timers = [];
        globalThis.setTimeout = (callback, ms) => { const timer = { at: now + ms, callback }; timers.push(timer); return timer; };
        globalThis.clearTimeout = (timer) => { const at = timers.indexOf(timer); if (at >= 0) timers.splice(at, 1); };
        const tick = (ms) => {
            now += ms;
            for (let due = timers.filter((t) => t.at <= now); due.length; due = timers.filter((t) => t.at <= now)) {
                due.forEach((t) => { timers.splice(timers.indexOf(t), 1); t.callback(); });
            }
        };

        // A <video> as far as the watch reads it: its state, its events, and what is done to it.
        const done = [];
        const listeners = {};
        const video = {
            tagName: 'VIDEO', isConnected: true, readyState: 0, networkState: 2, error: null, paused: false,
            addEventListener: (type, callback) => { (listeners[type] = listeners[type] || []).push(callback); },
            load() { done.push('load'); this.readyState = 0; this.networkState = 2; this.paused = true; },
            play() { done.push('play'); this.paused = false; return Promise.resolve(); },
            pause() { done.push('pause'); this.paused = true; },
        };
        const fire = (type) => (listeners[type] || []).forEach((callback) => callback());
        const metadata = () => { video.readyState = 1; fire('loadedmetadata'); };
        const firstFrame = () => { video.readyState = 2; fire('loadeddata'); };
        const nothingLoading = () => { video.networkState = 1; };

        const own = {};
        startOnAFireTv(own);
        let gaveUp = 0;
        own.watchFirstFrame(video, () => { gaveUp++; });

        {$story}
    JS);
}

it('sets a video stopped after its metadata up once more, and plays it again', function () {
    $result = watchAFireTvVideo(<<<'JS'
        metadata(); nothingLoading();
        tick(2900);
        const before = done.slice();
        tick(200);
        report({ before, after: done, gaveUp });
    JS);

    expect($result['before'])->toBe([])
        ->and($result['after'])->toBe(['load', 'play'])
        ->and($result['gaveUp'])->toBe(0);
});

it('keeps a video made ready behind paused when it sets it up once more', function () {
    $result = watchAFireTvVideo(<<<'JS'
        video.paused = true;
        metadata(); nothingLoading();
        tick(3000);
        report({ done, paused: video.paused, gaveUp });
    JS);

    expect($result['done'])->toBe(['load', 'pause'])
        ->and($result['paused'])->toBeTrue()
        ->and($result['gaveUp'])->toBe(0);
});

it('gives up a video that stops again after it was set up once more', function () {
    $result = watchAFireTvVideo(<<<'JS'
        metadata(); nothingLoading();
        tick(3000);
        metadata(); nothingLoading();
        tick(2900);
        const before = gaveUp;
        tick(200);
        report({ before, gaveUp, done });
    JS);

    expect($result['before'])->toBe(0)
        ->and($result['gaveUp'])->toBe(1)
        ->and($result['done'])->toBe(['load', 'play']);
});

it('leaves a video alone that has its first frame, is still loading on a slow line, has failed or has gone', function () {
    $result = watchAFireTvVideo(<<<'JS'
        const seen = {};
        // A slow line: its metadata, then still loading for half a minute.
        metadata();
        tick(30000);
        seen.slow = done.slice();
        // Then its first frame: nothing is ever done to it after.
        firstFrame(); nothingLoading();
        tick(30000);
        seen.afterFrame = done.slice();
        report({ seen, gaveUp });
    JS);

    expect($result['seen']['slow'])->toBe([])
        ->and($result['seen']['afterFrame'])->toBe([])
        ->and($result['gaveUp'])->toBe(0);

    $failed = watchAFireTvVideo(<<<'JS'
        metadata(); nothingLoading(); video.error = { code: 4 };
        tick(10000);
        const afterError = done.length;
        video.error = null; video.isConnected = false;
        tick(10000);
        report({ afterError, done: done.length, gaveUp });
    JS);
    expect($failed)->toBe(['afterError' => 0, 'done' => 0, 'gaveUp' => 0]);
});

it('watches only a plain video, never the player\'s own decoder', function () {
    $result = watchAFireTvVideo(<<<'JS'
        const decoded = Object.assign({}, video, { tagName: 'DECODED-VIDEO' });
        let added = 0;
        decoded.addEventListener = () => { added++; };
        own.watchFirstFrame(decoded, () => {});
        report({ added });
    JS);

    expect($result['added'])->toBe(0);
});
