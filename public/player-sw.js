/**
 * The player's service worker (docs/AD-BUILDER-SPEC.md §15): a television keeps playing when the shop's
 * internet drops.
 *
 * Plain ES5 at a fixed address — never built by Vite — so a set can update it in place. Registered by the
 * player at scope /player, so it controls the player page and the frames inside it and nothing of the
 * panel. It keeps four caches:
 *
 *   shell     the player page and the built files it names, kept together or not at all — a page whose
 *             scripts are not here would boot to nothing — so a new page replaces the kept one only once
 *             every file it names is in. Network first for the page, so a deploy reaches a set that is online
 *   manifest  the last JSON answer to /device/playlist, one per screen (keyed by a digest of the token) —
 *             network first with a short patience, the kept one when the server cannot be reached or
 *             answers with anything but JSON (a captive portal), marked with X-Signage-Cached. An answer
 *             that comes after the patience ran out is kept all the same, for the next poll that cannot wait
 *   media     every file the player names, by its versioned address (?c=checksum; the runtime's ?v=),
 *             cache first — and with no line and no copy of the version asked for, the same file's
 *             previous version rather than nothing
 *   parts     the pieces of a big file still on its way: fetched a few megabytes at a time, each kept as it
 *             lands, so a download that a reboot, a deploy or a dropped line cut short carries on where it
 *             stopped instead of starting again
 *
 * An ad page plays in a frame at /player/page?src=…, inside this worker's scope, so what the page loads —
 * its pictures, videos and scripts — comes from here too, on every browser that has a worker at all.
 *
 * Nothing else is touched: a heartbeat that cannot get out is exactly what tells the panel a screen is
 * offline, and the pairing endpoints have no business being cached. After every live manifest the player
 * names, most needed first, every file the next days may play; this worker fetches them one at a time in
 * that order — however many times it is told, and however many players it serves — and then drops what
 * is no longer named.
 */
(function () {
    'use strict';

    var SHELL = 'signage-shell';
    var MANIFEST = 'signage-manifest';
    var MEDIA = 'signage-media';
    var PARTS = 'signage-parts';
    var CACHES = [SHELL, MANIFEST, MEDIA, PARTS];
    var PLAYER_PATH = '/player';
    var PAGE_PATH = '/player/page';
    var MANIFEST_URL = '/device/playlist';
    var MANIFEST_TIMEOUT_MS = 8000;
    var PART_BYTES = 4 * 1024 * 1024;   // one piece of a big file
    var PART_TIMEOUT_MS = 120000;       // a piece taking longer than this has stalled
    var WHOLE_TIMEOUT_MS = 1800000;     // a server that ignores ranges sends the whole file in one answer
    var HOLD_MS = 240000;               // an event is let go before Chrome's five-minute limit ends the worker

    // What an ad page is served with from here — whenever it was compiled. The server's own copy says
    // "sandbox", which would give the frame an origin of its own, and a frame like that no worker serves.
    // The page's own policy (a meta tag, docs §15) adds which scripts may run.
    var PAGE_HEADERS = {
        'Content-Type': 'text/html; charset=utf-8',
        'X-Content-Type-Options': 'nosniff',
        'Content-Security-Policy': "frame-ancestors 'self'; base-uri 'none'; form-action 'none'; object-src 'none'; frame-src 'none'; connect-src 'none'",
    };

    function noop() {}

    function isMediaPath(path) {
        return path.indexOf('/storage/') === 0 || path.indexOf('/dusk-storage/') === 0;
    }

    function isRuntimePath(path) {
        return path.indexOf('/ad-runtime/') === 0;
    }

    function isBuildPath(path) {
        return path.indexOf('/build/') === 0;
    }

    /** An address on this origin, or null — never an error for one that is not an address at all. */
    function sameOrigin(address) {
        try {
            var url = new URL(String(address || ''), self.location.origin);

            return url.origin === self.location.origin ? url : null;
        } catch (error) {
            return null;
        }
    }

    function pathOf(address) {
        var url = new URL(address);

        return url.origin + url.pathname;
    }

    /* ── Lifecycle ─────────────────────────────────────────────────────── */

    self.addEventListener('install', function (event) {
        // The player page and its built files, fetched now: the very first visit loaded them before this
        // worker was there to keep them.
        event.waitUntil(
            fetch(PLAYER_PATH, { credentials: 'omit' })
                .then(function (response) { return response.ok ? response.text().then(keepShell) : null; })
                .catch(noop)
                .then(function () { return self.skipWaiting(); })
        );
    });

    self.addEventListener('activate', function (event) {
        event.waitUntil(
            caches.keys()
                .then(function (names) {
                    return Promise.all(names.filter(function (name) {
                        return name.indexOf('signage-') === 0 && CACHES.indexOf(name) === -1;
                    }).map(function (name) { return caches.delete(name); }));
                })
                .then(function () { return self.clients.claim(); })
        );
    });

    /* ── Requests ──────────────────────────────────────────────────────── */

    self.addEventListener('fetch', function (event) {
        var request = event.request;
        var url = sameOrigin(request.url);

        // Another origin, or anything but a plain GET (the heartbeat is a POST): the network, untouched.
        if (!url || request.method !== 'GET') return;

        var path = url.pathname;

        if (path === MANIFEST_URL) {
            event.respondWith(manifest(event));
        } else if (path.indexOf('/device/') === 0) {
            return;
        } else if (path === PAGE_PATH) {
            event.respondWith(adPage(url));
        } else if (isMediaPath(path) || isRuntimePath(path)) {
            event.respondWith(media(request));
        } else if (request.mode === 'navigate' && path === PLAYER_PATH) {
            event.respondWith(page(event));
        } else if (isBuildPath(path)) {
            event.respondWith(built(request));
        }
    });

    /**
     * The manifest: the network first, with a short patience. A refusal (401: the screen was removed) is
     * the server's word and goes through as it is; only a server that cannot answer — no network, a
     * timeout, a 5xx, a page that is not JSON — counts as unreachable, and then the last answer kept is
     * served, marked.
     */
    function manifest(event) {
        var settle = noop;

        // Held until the network has answered too, however late: that answer is kept.
        event.waitUntil(new Promise(function (resolve) { settle = resolve; }));

        // Kept per screen — by a digest of the token the request carries, never the token itself — so a set
        // paired again, or a second player on the same box, is never handed another screen's playlist.
        return manifestKey(event.request).then(function (key) {
            var network = fetch(event.request).then(function (response) {
                if (response.status >= 500) throw new Error('server ' + response.status);
                if (!response.ok) return response;

                // A captive portal or a hotel's login page answers 200 with HTML: that is no playlist, and
                // keeping it would throw away the last real one.
                if ((response.headers.get('Content-Type') || '').indexOf('json') === -1) throw new Error('not a manifest');

                var copy = response.clone();

                return caches.open(MANIFEST)
                    .then(function (cache) { return cache.put(key, copy); })
                    .then(function () { return response; }, function () { return response; });
            });

            network.then(settle, settle);

            return withTimeout(network, MANIFEST_TIMEOUT_MS).catch(function () { return remembered(key); });
        }, function (error) {
            settle();
            throw error;
        });
    }

    /** The cache key of this screen's manifest: the address plus a digest of the Authorization header. */
    function manifestKey(request) {
        var bytes = new TextEncoder().encode(request.headers.get('Authorization') || '');

        return crypto.subtle.digest('SHA-256', bytes).then(function (digest) {
            var hex = Array.prototype.map.call(new Uint8Array(digest), function (byte) {
                return ('0' + byte.toString(16)).slice(-2);
            }).join('');

            return self.location.origin + MANIFEST_URL + '?screen=' + hex.slice(0, 32);
        });
    }

    /** The last manifest kept for this screen, marked as memory — or a 503 when nothing is kept yet. */
    function remembered(key) {
        return caches.open(MANIFEST)
            .then(function (cache) { return cache.match(key); })
            .then(function (cached) {
                if (!cached) {
                    return new Response('{"message":"The server cannot be reached, and nothing is kept yet."}', {
                        status: 503,
                        headers: { 'Content-Type': 'application/json' },
                    });
                }

                return marked(cached);
            });
    }

    /** The player page: the network first, the copy kept when the network cannot answer. */
    function page(event) {
        return withTimeout(fetch(event.request), MANIFEST_TIMEOUT_MS)
            .then(function (response) {
                if (!response.ok) throw response;

                event.waitUntil(response.clone().text().then(keepShell).catch(noop));

                return response;
            })
            .catch(function (failure) {
                return caches.open(SHELL)
                    .then(function (cache) { return cache.match(PLAYER_PATH); })
                    .then(function (cached) { return cached || (failure instanceof Response ? failure : Response.error()); });
            });
    }

    /**
     * A player page and every built file it names, kept together: each file it lacks is fetched first, and
     * only when all are in does the page replace the one kept — a set that reboots with no line then always
     * has a page whose scripts are here. Built files that page no longer names go (a deploy's old ones).
     */
    function keepShell(html) {
        var assets = shellAssets(html);

        return caches.open(SHELL).then(function (cache) {
            return Promise.all(assets.map(function (url) {
                return cache.match(url, { ignoreVary: true }).then(function (hit) {
                    if (hit) return null;

                    return fetch(url, { credentials: 'omit' }).then(function (response) {
                        if (response.status !== 200) throw new Error('asset ' + response.status);

                        return cache.put(url, response);
                    });
                });
            }))
                .then(function () {
                    return cache.put(PLAYER_PATH, new Response(html, { headers: { 'Content-Type': 'text/html; charset=utf-8' } }));
                })
                .then(function () { return cache.keys(); })
                .then(function (requests) {
                    return Promise.all(requests.map(function (request) {
                        return isBuildPath(new URL(request.url).pathname) && assets.indexOf(request.url) === -1 ? cache.delete(request) : null;
                    }));
                });
        });
    }

    /** The built files a player page names — its scripts, styles and module preloads. */
    function shellAssets(html) {
        var pattern = /(?:src|href)\s*=\s*["']([^"']+)["']/gi;
        var found = [];
        var match;

        while ((match = pattern.exec(html))) {
            var url = sameOrigin(match[1]);

            if (url && isBuildPath(url.pathname) && found.indexOf(url.href) === -1) found.push(url.href);
        }

        return found;
    }

    /** A built file: the kept copy first (the shell), the network when it is not kept. */
    function built(request) {
        return caches.open(SHELL).then(function (cache) {
            return cache.match(request.url, { ignoreVary: true }).then(function (hit) {
                return hit || fetch(request);
            });
        });
    }

    /**
     * A file: the cache first, the network when it is not there — and then kept, whole. A slice the
     * browser asks for (a video's range request) is cut from the cached whole; a partial answer from the
     * network is never kept, so the warm-up is what fills the cache. With no line and no copy of this
     * version, the same file's previous version — a page republished, a file renamed — rather than nothing.
     */
    function media(request) {
        var range = request.headers.get('range');

        return caches.open(MEDIA).then(function (cache) {
            return cache.match(request.url, { ignoreVary: true }).then(function (hit) {
                if (hit) return sliced(hit, range);

                return fetch(request).then(function (response) {
                    if (response.status === 200) cache.put(request.url, response.clone()).catch(noop);

                    return response;
                }, function (error) {
                    return cache.match(request.url, { ignoreSearch: true, ignoreVary: true }).then(function (older) {
                        if (!older) throw error;

                        return sliced(older, range);
                    });
                });
            });
        });
    }

    function sliced(response, range) {
        return range && response.status === 200 ? partial(response, range) : response;
    }

    /**
     * An ad page, as the frame the player puts it in asks for it: the page's own address rides in `src` —
     * one of the media paths, and a page — and it is answered from the cache like any file, with this
     * worker's headers. A page that cannot be had is a black one, never the browser's error page.
     */
    function adPage(url) {
        var source = sameOrigin(url.searchParams.get('src'));

        if (!source || !isMediaPath(source.pathname) || !/\.html$/i.test(source.pathname)) {
            return Promise.resolve(blank(404));
        }

        return media(new Request(source.href)).then(function (response) {
            if (!response.ok) return blank(response.status);

            return response.text().then(function (html) {
                return new Response(html, { status: 200, headers: PAGE_HEADERS });
            });
        }, function () {
            return blank(504);
        });
    }

    function blank(status) {
        return new Response('<!DOCTYPE html><html><body style="margin:0;background:#000"></body></html>', {
            status: status,
            headers: PAGE_HEADERS,
        });
    }

    /** `bytes=start-end` out of a whole cached file, as a 206. */
    function partial(response, rangeHeader) {
        var match = /bytes=(\d*)-(\d*)/.exec(rangeHeader);

        if (!match) return response;

        return response.blob().then(function (blob) {
            var size = blob.size;
            var start = match[1] === '' ? Math.max(0, size - Number(match[2])) : Number(match[1]);
            var end = match[1] !== '' && match[2] !== '' ? Math.min(Number(match[2]), size - 1) : size - 1;

            if (start >= size || start > end) {
                return new Response(null, { status: 416, headers: { 'Content-Range': 'bytes */' + size } });
            }

            var headers = new Headers(response.headers);
            headers.set('Content-Range', 'bytes ' + start + '-' + end + '/' + size);
            headers.set('Content-Length', String(end - start + 1));
            headers.set('Accept-Ranges', 'bytes');

            return new Response(blob.slice(start, end + 1), { status: 206, statusText: 'Partial Content', headers: headers });
        });
    }

    /** The same answer with one more header: this came from the cache, not the server. */
    function marked(response) {
        var headers = new Headers(response.headers);
        headers.set('X-Signage-Cached', '1');

        return response.blob().then(function (body) {
            return new Response(body, { status: response.status, statusText: response.statusText, headers: headers });
        });
    }

    function withTimeout(promise, ms) {
        return new Promise(function (resolve, reject) {
            var timer = setTimeout(function () { reject(new Error('timeout')); }, ms);

            promise.then(
                function (value) { clearTimeout(timer); resolve(value); },
                function (error) { clearTimeout(timer); reject(error); }
            );
        });
    }

    function sleep(ms) {
        return new Promise(function (resolve) { setTimeout(resolve, ms); });
    }

    /* ── What the player says to keep ──────────────────────────────────── */

    self.addEventListener('message', function (event) {
        var data = event.data || {};

        if (data.type === 'warm') {
            var urls = [];
            var seen = {};

            (Array.isArray(data.urls) ? data.urls : []).forEach(function (address) {
                var url = sameOrigin(address);

                // Files and the ad runtime only: nothing else a page might name — a panel's address — is fetched.
                if (url && (isMediaPath(url.pathname) || isRuntimePath(url.pathname)) && !seen[url.href]) {
                    seen[url.href] = true;
                    urls.push(url.href);
                }
            });

            var work = warmAll(urls).then(function (missing) {
                if (event.source && event.source.postMessage) event.source.postMessage({ type: 'warmed', missing: missing });
            });

            // Held a few minutes at most: an event still open after five is the worker's end on Chrome — and the
            // warm-up carries on without it, while the player's next message holds it again.
            if (event.waitUntil) event.waitUntil(Promise.race([work, sleep(HOLD_MS)]).catch(noop));
        } else if (data.type === 'forget') {
            if (event.waitUntil) event.waitUntil(caches.delete(MANIFEST).catch(noop));
        }
    });

    var wanted = [];     // what the player named last, most needed first
    var running = null;  // the warm-up under way: every message joins it rather than starting another

    /** Fetch whatever of `urls` the cache lacks, one file at a time; resolves to how many could not be had. */
    function warmAll(urls) {
        wanted = urls;

        if (!running) {
            running = drain().then(
                function (missing) { running = null; return missing; },
                function () { running = null; return 0; }
            );
        }

        return running;
    }

    /** The warm-up: the next file named and not yet tried — the list read again after each — then the prune. */
    function drain() {
        var tried = {};
        var missing = 0;

        function next() {
            var url = null;

            for (var i = 0; i < wanted.length && url === null; i++) {
                if (!tried[wanted[i]]) url = wanted[i];
            }

            if (url === null) return Promise.resolve();

            tried[url] = true;

            return download(url).then(function (kept) {
                if (!kept) missing += 1;

                return next();
            });
        }

        return next().then(function () { return prune(wanted); }).then(function () { return missing; });
    }

    /** One file into the media cache — in pieces when the server can send pieces. True once it is there. */
    function download(url) {
        return caches.open(MEDIA).then(function (cache) {
            return cache.match(url, { ignoreVary: true }).then(function (hit) {
                if (hit) return true;

                return fetchWhole(url).then(function (whole) {
                    if (!whole) return false;

                    return cache.put(url, whole).then(function () { return dropParts(url); }).then(function () { return true; });
                });
            });
        }).catch(function () { return false; });
    }

    /**
     * A file as one whole answer, fetched PART_BYTES at a time, each piece kept in `parts` as it lands so a
     * download cut short carries on where it stopped. A server that ignores ranges answers with the whole file
     * at once, which is taken as it is. Null when a piece could not be had this time.
     */
    function fetchWhole(url) {
        return caches.open(PARTS).then(function (parts) {
            return piece(parts, url, 0, null).then(function (first) {
                if (!first) return null;
                if (first.whole) return first.whole;

                var count = Math.ceil(first.total / PART_BYTES);
                var index = 1;

                function next() {
                    if (index >= count) return assemble(parts, url, count, first.type, first.total);

                    return piece(parts, url, index, first.total).then(function (got) {
                        if (!got) return null;

                        index += 1;

                        return next();
                    });
                }

                return next();
            });
        });
    }

    /**
     * Piece `index` of a file, from `parts` when it is there, else from the network and then kept. Returns
     * the whole file's length and type — or, for the first piece, the whole file itself when the answer was
     * all of it (a server that ignores ranges, or a file no bigger than one piece).
     */
    function piece(parts, url, index, total) {
        var key = partKey(url, index);

        return parts.match(key).then(function (hit) {
            if (hit) return { total: Number(hit.headers.get('X-Signage-Total')), type: hit.headers.get('Content-Type') || '' };

            var start = index * PART_BYTES;
            var end = total === null ? start + PART_BYTES - 1 : Math.min(total, start + PART_BYTES) - 1;

            return timedFetch(url, { Range: 'bytes=' + start + '-' + end }).then(function (got) {
                var type = got.headers.get('Content-Type') || '';

                if (got.status === 200) {
                    // The whole file in one answer: only ever what the first piece may turn out to be.
                    return index === 0 ? { whole: wholeResponse(got.blob, type) } : null;
                }

                var range = /bytes\s+(\d+)-(\d+)\/(\d+)/.exec(got.headers.get('Content-Range') || '');

                if (got.status !== 206 || !range || Number(range[1]) !== start) return null;

                var size = Number(range[3]);

                // Cut short on the way: a piece with bytes missing would make a broken file.
                if (got.blob.size !== Number(range[2]) - start + 1) return null;

                // The file changed under its address between two pieces: what is kept of it is of no use.
                if (total !== null && size !== total) return dropParts(url).then(function () { return null; });

                if (index === 0 && Number(range[2]) + 1 >= size) return { whole: wholeResponse(got.blob, type) };

                return parts.put(key, new Response(got.blob, { headers: { 'Content-Type': type, 'X-Signage-Total': String(size) } }))
                    .then(function () { return { total: size, type: type }; });
            });
        }).catch(function () { return null; });
    }

    /** Every piece of a file, read back from `parts` and joined into the whole answer the cache keeps. */
    function assemble(parts, url, count, type, total) {
        var reads = [];

        for (var i = 0; i < count; i++) {
            reads.push(parts.match(partKey(url, i)).then(function (hit) {
                if (!hit) throw new Error('a piece went missing');

                return hit.blob();
            }));
        }

        return Promise.all(reads).then(function (blobs) {
            var whole = new Blob(blobs, { type: type });

            return whole.size === total ? wholeResponse(whole, type) : null;
        });
    }

    function wholeResponse(blob, type) {
        return new Response(blob, { status: 200, headers: { 'Content-Type': type, 'Content-Length': String(blob.size) } });
    }

    function partKey(url, index) {
        return url + (url.indexOf('?') === -1 ? '?' : '&') + 'signage-part=' + index;
    }

    function partOwner(key) {
        return key.replace(/[?&]signage-part=\d+$/, '');
    }

    function dropParts(url) {
        return caches.open(PARTS).then(function (parts) {
            return parts.keys().then(function (requests) {
                return Promise.all(requests.map(function (request) {
                    return partOwner(request.url) === url ? parts.delete(request) : null;
                }));
            });
        });
    }

    /** A GET with a patience: PART_TIMEOUT_MS for a piece, WHOLE_TIMEOUT_MS once the server sends the whole. */
    function timedFetch(url, headers) {
        var controller = typeof AbortController === 'function' ? new AbortController() : null;
        var timer = null;

        function arm(ms) {
            if (!controller) return;

            clearTimeout(timer);
            timer = setTimeout(function () { controller.abort(); }, ms);
        }

        arm(PART_TIMEOUT_MS);

        var init = { headers: headers, credentials: 'omit' };

        if (controller) init.signal = controller.signal;

        return fetch(url, init)
            .then(function (response) {
                if (response.status === 200) arm(WHOLE_TIMEOUT_MS);

                // The body is read inside the patience too: a stall halfway is a stall.
                return response.blob().then(function (blob) {
                    clearTimeout(timer);

                    return { status: response.status, headers: response.headers, blob: blob };
                });
            })
            .catch(function (error) {
                clearTimeout(timer);
                throw error;
            });
    }

    /**
     * Drop what the player no longer names — but keep a file's older version until the version named is here:
     * with no line, it is what plays in its place. The pieces of a file no longer named go too.
     */
    function prune(urls) {
        var named = {};
        var byPath = {};

        urls.forEach(function (url) {
            var path = pathOf(url);

            named[url] = true;
            (byPath[path] = byPath[path] || []).push(url);
        });

        return caches.open(MEDIA)
            .then(function (cache) {
                return cache.keys().then(function (requests) {
                    var held = {};

                    requests.forEach(function (request) { held[request.url] = true; });

                    return Promise.all(requests.map(function (request) {
                        if (named[request.url]) return null;

                        var versions = byPath[pathOf(request.url)] || [];
                        var replaced = versions.length === 0 || versions.some(function (url) { return held[url]; });

                        return replaced ? cache.delete(request) : null;
                    }));
                });
            })
            .then(function () { return caches.open(PARTS); })
            .then(function (parts) {
                return parts.keys().then(function (requests) {
                    return Promise.all(requests.map(function (request) {
                        return named[partOwner(request.url)] ? null : parts.delete(request);
                    }));
                });
            });
    }
})();
