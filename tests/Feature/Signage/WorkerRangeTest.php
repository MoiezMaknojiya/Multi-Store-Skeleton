<?php

use Tests\Support\PageScript;

/*
|--------------------------------------------------------------------------
| The player's worker answers a video's slice with that slice (public/player-sw.js)
|--------------------------------------------------------------------------
|
| A file the worker had not kept yet went to the server as the media element asked for it, the very request handed on.
| On a WebView 79 (the Philips TV in the office, 2026-11-05) that request reached the server with no Range, the whole
| file answered a slice from the middle — an MP4's index, at its end — and the video failed, "data source error", its
| address refused for half a minute: a video alone on a playlist was black that long. The worker now asks for the slice
| itself, and cuts a whole answer to it. And a page made ready beside a video that keeps a set's one decoder comes
| without its videos' files (player.js, holdsPageVideos), which the player gives them once it is on screen.
|
| Run under Node with the worker's own file, its world stood in for: a fetch that, like that WebView's, drops the Range
| of a request handed on whole.
|
*/

function runTheWorker(string $then): mixed
{
    return PageScript::run(<<<JS
        import fs from 'node:fs';
        import vm from 'node:vm';

        const ORIGIN = 'http://tv.test';
        const FILE = new Uint8Array(1000).map((_, i) => i % 251);
        const PAGE = '<!DOCTYPE html><body><video class="ad-layer" src="/storage/builder/1/assets/clip.mp4" autoplay muted loop playsinline style="object-fit:cover;"></video><video class="ad-content" src="/storage/builder/1/assets/two.mp4" autoplay></video></body>';
        const asked = [];
        let ignoresRanges = false;

        const cut = (range) => {
            const match = /bytes=(\\d*)-(\\d*)/.exec(range);
            const start = Number(match[1]);
            const end = match[2] === '' ? FILE.length - 1 : Math.min(Number(match[2]), FILE.length - 1);

            return new Response(FILE.slice(start, end + 1), { status: 206, headers: { 'Content-Range': 'bytes ' + start + '-' + end + '/' + FILE.length, 'Content-Type': 'video/mp4' } });
        };
        // The network as that WebView's worker met it: a request handed on whole lost its Range on the way out.
        const network = async (input, init) => {
            const handedOn = input instanceof Request;
            const url = handedOn ? input.url : String(input);
            const range = handedOn ? null : (init && init.headers ? (init.headers.Range || init.headers.range || null) : null);
            asked.push({ url, range, handedOn });
            if (url.endsWith('.html') || url.includes('.html?')) return new Response(PAGE, { status: 200, headers: { 'Content-Type': 'text/html' } });
            const answer = range && !ignoresRanges ? cut(range) : new Response(FILE, { status: 200, headers: { 'Content-Type': 'video/mp4', 'Content-Length': String(FILE.length) } });
            answer.native = 'server';

            return answer;
        };

        const stores = new Map();
        const keyless = (url) => String(url).split('?')[0];
        const caches = {
            open: async (name) => {
                if (!stores.has(name)) stores.set(name, new Map());
                const store = stores.get(name);

                return {
                    match: async (url, options = {}) => {
                        const hit = options.ignoreSearch ? [...store.entries()].find(([key]) => keyless(key) === keyless(url)) : [null, store.get(String(url))];
                        if (!hit || !hit[1]) return undefined;
                        const copy = hit[1].clone();
                        copy.native = 'cache';

                        return copy;
                    },
                    put: async (url, response) => { store.set(String(url), response); },
                    keys: async () => [...store.keys()].map((url) => new Request(url)),
                    delete: async (request) => store.delete(String(request.url || request)),
                };
            },
            keys: async () => [...stores.keys()],
            delete: async (name) => stores.delete(name),
        };
        const listeners = {};
        const self = {
            location: { origin: ORIGIN },
            addEventListener: (type, listener) => { listeners[type] = listener; },
            skipWaiting: async () => {},
            clients: { claim: async () => {} },
        };
        const world = vm.createContext({
            self, caches, fetch: network, Request, Response, Headers, Blob, URL, AbortController,
            setTimeout, clearTimeout, Promise, console,
        });
        vm.runInContext(fs.readFileSync('public/player-sw.js', 'utf8'), world);

        const ask = async (url, headers = {}) => {
            let answer = null;
            listeners.fetch({ request: new Request(url, { headers }), respondWith: (promise) => { answer = promise; } });
            const response = await answer;

            return { status: response.status, range: response.headers.get('Content-Range'), native: response.native || null, body: new Uint8Array(await response.arrayBuffer()) };
        };

        {$then}
        JS);
}

test('a slice of a file the worker has not kept is asked of the server as that slice, and answered with it', function () {
    $result = runTheWorker(<<<'JS'
        const answer = await ask(ORIGIN + '/storage/media/1/menu.mp4?c=a', { Range: 'bytes=900-' });
        report({ status: answer.status, range: answer.range, first: answer.body[0], length: answer.body.length, asked });
        JS);

    expect($result['status'])->toBe(206)
        ->and($result['range'])->toBe('bytes 900-999/1000')
        ->and($result['length'])->toBe(100)
        ->and($result['first'])->toBe(900 % 251)
        ->and($result['asked'][0])->toMatchArray(['range' => 'bytes=900-', 'handedOn' => false]);
});

test('a server that answers a slice with the whole file has it cut to the slice, and kept whole', function () {
    $result = runTheWorker(<<<'JS'
        ignoresRanges = true;
        const answer = await ask(ORIGIN + '/storage/media/1/menu.mp4?c=b', { Range: 'bytes=100-199' });
        // Asked again: from the copy kept, with nothing more from the server.
        const again = await ask(ORIGIN + '/storage/media/1/menu.mp4?c=b', { Range: 'bytes=500-509' });
        report({ status: answer.status, range: answer.range, length: answer.body.length, again: [again.status, again.range, again.body[0]], asked: asked.length });
        JS);

    expect($result['status'])->toBe(206)
        ->and($result['range'])->toBe('bytes 100-199/1000')
        ->and($result['length'])->toBe(100)
        ->and($result['again'])->toBe([206, 'bytes 500-509/1000', 500 % 251])
        ->and($result['asked'])->toBe(1);
});

test('a page made ready beside a video that keeps the one decoder comes without its videos\' files', function () {
    $result = runTheWorker(<<<'JS'
        const page = ORIGIN + '/storage/builder/1/ads/key/index.html?v=1';
        const held = await ask(ORIGIN + '/player/page?src=' + encodeURIComponent(page) + '&hold=1');
        const plain = await ask(ORIGIN + '/player/page?src=' + encodeURIComponent(page));
        const text = (answer) => new TextDecoder().decode(answer.body);
        report({ held: text(held), plain: text(plain) });
        JS);

    expect($result['held'])->not->toContain(' src="')
        ->and($result['held'])->toContain('<video class="ad-layer" data-signage-src="/storage/builder/1/assets/clip.mp4"')
        ->and($result['held'])->toContain('<video class="ad-content" data-signage-src="/storage/builder/1/assets/two.mp4"')
        ->and($result['plain'])->toContain('<video class="ad-layer" src="/storage/builder/1/assets/clip.mp4"');
});

test("every answer to a file is one the worker made, never the server's or the cache's own, so a video never mixes them", function () {
    // Chromium refuses a video whose pieces mix the server's own answers with made ones: a video begun from the server
    // while its file was being kept, its next slice from the kept copy, failed with a "data source error".
    $result = runTheWorker(<<<'JS'
        const url = ORIGIN + '/storage/media/1/menu.mp4?c=d';
        const first = await ask(url, { Range: 'bytes=0-' });
        await (await caches.open('signage-media')).put(url, new Response(FILE, { status: 200, headers: { 'Content-Type': 'video/mp4' } }));
        const next = await ask(url, { Range: 'bytes=600-' });
        const whole = await ask(url);
        const missed = await ask(ORIGIN + '/storage/media/1/other.mp4?c=e');
        report({ first: [first.status, first.native, first.body.length], next: [next.status, next.native, next.body[0]], whole: [whole.status, whole.native, whole.body.length], missed: [missed.status, missed.native] });
        JS);

    expect($result['first'])->toBe([206, null, 1000])
        ->and($result['next'])->toBe([206, null, 600 % 251])
        ->and($result['whole'])->toBe([200, null, 1000])
        ->and($result['missed'])->toBe([200, null]);
});
