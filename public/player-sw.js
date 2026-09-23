/**
 * The player's service worker (docs/AD-BUILDER-SPEC.md §15): a television keeps playing when the shop's
 * internet drops.
 *
 * Plain ES5 at a fixed address — never built by Vite — so a set can update it in place. Registered by the
 * player at scope /player, so it controls the player page and the frames inside it and nothing of the
 * panel. It keeps three caches:
 *
 *   shell     the player page and its built assets — network first, so a deploy reaches a set that is
 *             online, and the cache when the set reboots with no line
 *   manifest  the last answer to /device/playlist — network first with a short patience, the cached one
 *             when the server cannot be reached, marked with X-Signage-Cached so the player knows
 *   media     every file the manifest names, by versioned address (?c=checksum), cache first — and the
 *             ad runtime, matched ignoring its version query
 *
 * Nothing else is touched: a heartbeat that cannot get out is exactly what tells the panel a screen is
 * offline, and the pairing endpoints have no business being cached. The player says what to warm and
 * what to keep after every live manifest; a video is warmed whole, and a slice of it is cut from the
 * cache when the browser asks for a range.
 */
(function () {
    'use strict';

    var VERSION = 'signage-1';
    var SHELL = VERSION + '-shell';
    var MANIFEST = VERSION + '-manifest';
    var MEDIA = VERSION + '-media';
    var PLAYER_PATH = '/player';
    var MANIFEST_URL = '/device/playlist';
    var MANIFEST_TIMEOUT_MS = 8000;

    function isMediaPath(path) {
        return path.indexOf('/storage/') === 0 || path.indexOf('/dusk-storage/') === 0;
    }

    function isRuntimePath(path) {
        return path.indexOf('/ad-runtime/') === 0;
    }

    function isBuildPath(path) {
        return path.indexOf('/build/') === 0;
    }

    /* ── Lifecycle ─────────────────────────────────────────────────────── */

    self.addEventListener('install', function (event) {
        event.waitUntil(
            caches.open(SHELL)
                .then(function (cache) { return cache.add(PLAYER_PATH).catch(function () {}); })
                .then(function () { return self.skipWaiting(); })
        );
    });

    self.addEventListener('activate', function (event) {
        event.waitUntil(
            caches.keys()
                .then(function (names) {
                    return Promise.all(names.filter(function (name) {
                        return name.indexOf('signage-') === 0 && [SHELL, MANIFEST, MEDIA].indexOf(name) === -1;
                    }).map(function (name) { return caches.delete(name); }));
                })
                .then(function () { return self.clients.claim(); })
        );
    });

    /* ── Requests ──────────────────────────────────────────────────────── */

    self.addEventListener('fetch', function (event) {
        var request = event.request;
        var url;

        try {
            url = new URL(request.url);
        } catch (error) {
            return;
        }

        // Another origin, or anything but a plain GET (the heartbeat is a POST): the network, untouched.
        if (url.origin !== self.location.origin || request.method !== 'GET') return;

        var path = url.pathname;

        if (path === MANIFEST_URL) {
            event.respondWith(manifest(request));
        } else if (path.indexOf('/device/') === 0) {
            return;
        } else if (isMediaPath(path)) {
            event.respondWith(cacheFirst(MEDIA, request, false));
        } else if (isRuntimePath(path)) {
            event.respondWith(cacheFirst(MEDIA, request, true));
        } else if (request.mode === 'navigate' && path === PLAYER_PATH) {
            event.respondWith(page(request));
        } else if (isBuildPath(path)) {
            event.respondWith(cacheFirst(SHELL, request, false));
        }
    });

    /**
     * The manifest: the network first, with a short patience. A refusal (401: the screen was removed) is
     * the server's word and goes through as it is; only a server that cannot answer — no network, a
     * timeout, a 5xx — counts as unreachable, and then the last answer kept is served, marked.
     */
    function manifest(request) {
        return withTimeout(fetch(request), MANIFEST_TIMEOUT_MS)
            .then(function (response) {
                if (response.status >= 500) throw new Error('server ' + response.status);
                if (!response.ok) return response;

                var copy = response.clone();

                return caches.open(MANIFEST)
                    .then(function (cache) { return cache.put(MANIFEST_URL, copy); })
                    .then(function () { return response; }, function () { return response; });
            })
            .catch(function () {
                return caches.open(MANIFEST)
                    .then(function (cache) { return cache.match(MANIFEST_URL); })
                    .then(function (cached) {
                        if (!cached) {
                            return new Response('{"message":"The server cannot be reached, and nothing is kept yet."}', {
                                status: 503,
                                headers: { 'Content-Type': 'application/json' },
                            });
                        }

                        return marked(cached);
                    });
            });
    }

    /** The player page: the network first, the copy kept when the network cannot answer. */
    function page(request) {
        var fromNetwork = null;

        return withTimeout(fetch(request), MANIFEST_TIMEOUT_MS)
            .then(function (response) {
                fromNetwork = response;

                if (!response.ok) throw new Error('page ' + response.status);

                var copy = response.clone();

                caches.open(SHELL).then(function (cache) { return cache.put(PLAYER_PATH, copy); }).catch(function () {});

                return response;
            })
            .catch(function () {
                return caches.open(SHELL)
                    .then(function (cache) { return cache.match(PLAYER_PATH); })
                    .then(function (cached) { return cached || fromNetwork || Response.error(); });
            });
    }

    /**
     * A file: the cache first, the network when it is not there — and then kept, whole. A slice the
     * browser asks for (a video's range request) is cut from the cached whole; a partial answer from the
     * network is never kept, so the warm-up's plain fetch is what fills the cache.
     */
    function cacheFirst(cacheName, request, ignoreSearch) {
        var range = request.headers.get('range');

        return caches.open(cacheName).then(function (cache) {
            return cache.match(request, { ignoreSearch: ignoreSearch }).then(function (hit) {
                if (hit) return range && hit.status === 200 ? partial(hit, range) : hit;

                return fetch(request).then(function (response) {
                    if (response.ok && response.status === 200) {
                        cache.put(request, response.clone()).catch(function () {});
                    }

                    return response;
                });
            });
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

    /* ── What the player says to keep ──────────────────────────────────── */

    self.addEventListener('message', function (event) {
        var data = event.data || {};
        var work = null;

        if (data.type === 'warm') {
            work = warm(data.urls || []).then(function (missing) {
                if (event.source && event.source.postMessage) event.source.postMessage({ type: 'warmed', missing: missing });
            });
        } else if (data.type === 'keep') {
            work = prune(MEDIA, data.urls || [], function (path) { return isMediaPath(path); });
        } else if (data.type === 'shell') {
            work = prune(SHELL, (data.urls || []).concat([self.location.origin + PLAYER_PATH]), function (path) { return isBuildPath(path); });
        } else if (data.type === 'forget') {
            work = caches.delete(MANIFEST);
        }

        if (work && event.waitUntil) event.waitUntil(work.catch(function () {}));
    });

    /** Fetch, one after another, whatever the cache lacks. Returns how many could not be fetched. */
    function warm(urls) {
        return caches.open(MEDIA).then(function (cache) {
            var missing = 0;

            return urls.reduce(function (chain, url) {
                return chain.then(function () {
                    var runtime = isRuntimePath(new URL(url, self.location.origin).pathname);

                    return cache.match(url, { ignoreSearch: runtime }).then(function (hit) {
                        if (hit) return;

                        return fetch(url).then(function (response) {
                            if (response.ok && response.status === 200) return cache.put(url, response);

                            missing += 1;
                        }).catch(function () {
                            missing += 1;
                        });
                    });
                });
            }, Promise.resolve()).then(function () { return missing; });
        });
    }

    /** Drop every entry of a kind that the player no longer names. */
    function prune(cacheName, keepUrls, ofKind) {
        var keep = {};

        keepUrls.forEach(function (url) {
            try {
                keep[new URL(url, self.location.origin).href] = true;
            } catch (error) {
                // A malformed address keeps nothing.
            }
        });

        return caches.open(cacheName).then(function (cache) {
            return cache.keys().then(function (requests) {
                return Promise.all(requests.map(function (request) {
                    var path = new URL(request.url).pathname;

                    if (ofKind(path) && !keep[request.url]) return cache.delete(request);

                    return null;
                }));
            });
        });
    }
})();
