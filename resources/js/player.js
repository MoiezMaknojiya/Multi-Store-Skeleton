/**
 * The player: what a TV runs.
 *
 * It has no login and no session — it carries a token it was handed at pairing
 * and speaks only to /device/*. Two states, and the page decides which on load:
 *
 *   no token  -> register, show the six-character code, poll until claimed
 *   token     -> fetch the playlist, render it, beat every minute
 *
 * A 401 at any point means the screen was deleted or re-paired elsewhere: wipe
 * the token and fall back to the pairing code, exactly like a first boot.
 *
 * Deliberately kept as the RENDERER only. If this page is ever wrapped in a
 * native shell for offline caching, the shell injects window.SignagePlayer and
 * the item URLs below are the single place that has to consult it — nothing else
 * about this file changes.
 *
 * Offline (docs/AD-BUILDER-SPEC.md §15): where the browser has a service worker,
 * public/player-sw.js keeps the page, the last manifest and every file the
 * manifest names, and this page plays on from them when the line drops — the
 * manifest's timeline says what each moment of the next days shows; what has
 * expired since is dropped, then the holding picture, then black — and after
 * every live manifest it names to the worker, most needed first, every file the
 * next days may play. Anywhere else the player is online only, exactly as before.
 */

const TOKEN_KEY = 'signage.device.token';
const UUID_KEY = 'signage.device.uuid';
const SECRET_KEY = 'signage.device.poll_secret';
const CODE_KEY = 'signage.device.code';
const CODE_EXPIRES_KEY = 'signage.device.code_expires';
const KNOWN_KEY = 'signage.device.known';
// The gap between the server's clock and this set's, from the last live manifest (§15) — kept, so a set that
// reboots with no line still judges expiry and the schedule by the server's hours.
const CLOCK_KEY = 'signage.device.clock_offset';

// While waiting to be claimed. Deliberately as slow as the playlist poll
// (owner's decision): an unpaired player asks 2 times a minute instead of 15,
// and a page left open on a pairing code costs almost nothing. The price is
// paid at setup — after the owner types the code, the TV can take up to this
// long to notice and start playing.
const POLL_MS = 30000;
const PLAYLIST_MS = 30000;   // how often to ask what to show
const HEARTBEAT_MS = 60000;  // how often to say "alive"
const BROKEN_ITEM_PAUSE_MS = 1000;  // breathing room before skipping a file that will not play
const WORKER_UPDATE_MS = 3600000;   // a television never reloads its page, so it asks for a new worker itself
const WORKER_PATH = '/player-sw.js';
const WORKER_SCOPE = '/player';
const PAGE_PATH = '/player/page';   // where an ad page plays when the worker keeps this set (§15)
const CACHE_PARAM = 'c';            // a file's address carries its cache key, so a new version is a new address

// Read by the page's own watchdog (resources/views/player/index.blade.php): a page whose script never ran is
// reloaded, since nobody reloads a television.
window.signagePlayerStarted = true;

/* localStorage throws in some kiosk configurations; never let that kill the page. */
const store = {
    get(key) {
        try {
            return window.localStorage.getItem(key);
        } catch {
            return null;
        }
    },
    set(key, value) {
        try {
            window.localStorage.setItem(key, value);
        } catch {
            /* Nothing to do: the screen will simply pair again next boot. */
        }
    },
    remove(key) {
        try {
            window.localStorage.removeItem(key);
        } catch {
            /* ignore */
        }
    },
};

const el = (id) => document.getElementById(id);

/**
 * Which route through the panel this television should be sent down.
 *
 * A device the server already recognises belongs to a screen that exists — it
 * only lost its token. Telling that owner to "Add Screen" makes a second screen
 * and strands the real one with its whole playlist, which is a confusing mess to
 * unpick. Replace device puts the new token on the screen they already have and
 * keeps its name, orientation and playlist.
 *
 * textContent, never innerHTML: this text comes from the server.
 */
function setPairingInstruction(knownDevice) {
    // Which television is this? Only the last block of the uuid — twelve
    // characters are plenty to tell a shop's handful of sets apart, and the whole
    // thirty-six is a wall of noise nobody reads. The panel prints the same block
    // beside each screen, so the two can be matched at a glance.
    // Safe to print either way: a uuid is an identity, never a way in.
    el('pairing-uuid').textContent = (store.get(UUID_KEY) ?? '').split('-').pop();

    el('pairing-lead').textContent = knownDevice
        ? 'This screen was set up before. In your dashboard, go to'
        : 'Open your dashboard, go to';

    el('pairing-where').textContent = knownDevice
        ? 'Screens \u2192 Replace device'
        : 'Screens \u2192 Add Screen';

    // A 401 leaves behind "This screen was removed", which is the right note when
    // it really was. For a device the server still recognises it is simply false —
    // the screen is sitting there with its playlist — and two lines contradicting
    // each other is worse than either alone. Say the reassuring, true thing.
    if (knownDevice) {
        el('pairing-note').textContent =
            'Its name and playlist are safe. This only reconnects the screen.';
    }
}

const state = {
    token: store.get(TOKEN_KEY),
    version: null,
    pollTimer: null,
    playlistTimer: null,
    heartbeatTimer: null,
    // Playback
    entries: [],      // the manifest's lines — a file, or a channel holding its ads
    items: [],        // the pass being played: `entries` with each channel laid out
    cursors: {},      // per channel line, the ad the current pass started from…
    nextCursors: {},  // …and where each will stand once this pass has been played
    index: 0,
    itemTimer: null,
    front: 'a',
    // When the current item started and how long it was given, so an interrupted
    // image can resume with the time it had LEFT rather than starting over.
    itemStartedAt: 0,
    itemDurationMs: 0,

    // Offline (§15): whether the manifest on screen came from the worker's cache, the
    // set's clock corrected by the last live manifest's server time (so expiry is judged
    // by the right hours however wrong the box's clock is), and the last live manifest —
    // warmed again when a worker takes the page over after it loaded.
    offline: false,
    clockOffset: Number(store.get(CLOCK_KEY)) || 0,
    lastLive: null,

    // Network advertising. The shop's content pauses, the advert plays over it, and
    // the shop's content carries on from where it stopped.
    ad: {
        items: [],
        everyMs: 0,
        nextAt: 0,        // when the next break is due — NOT reset by a poll
        timer: null,
        active: false,
        index: 0,
        playTimer: null,  // backstop for one advert that stalls or never ends
        deferred: false,  // this break already waited out an item's last seconds, once
        resumeMs: 0,      // what was left of the interrupted item
        contentPaused: false, // an advert has appeared and the shop's content is paused under it
        pendingData: null, // a manifest that arrived mid-break, applied afterwards
    },
};

/** How close to the end of an item is too close to interrupt it. Cutting the last
 *  three seconds off a video looks like a fault; waiting them out costs nothing. */
const AD_GRACE_MS = 3500;

/* ── Screens ───────────────────────────────────────────────────────────── */

function show(view) {
    ['view-loading', 'view-pairing', 'view-content', 'view-error'].forEach((id) => {
        el(id).hidden = id !== view;
    });

    // Only the content view is a wall nobody touches. On the pairing and error
    // screens somebody is standing at the set, and taking their mouse pointer
    // away just makes it look broken.
    document.body.classList.toggle('kiosk', view === 'view-content');
}

function clearTimers() {
    [state.pollTimer, state.playlistTimer, state.heartbeatTimer].forEach((t) => t && clearInterval(t));
    state.pollTimer = state.playlistTimer = state.heartbeatTimer = null;
    if (state.itemTimer) clearTimeout(state.itemTimer);
    state.itemTimer = null;
    // Dropped BEFORE the break is ended: ending it applies a held manifest, and one held
    // across a 401 would draw the removed screen's playlist again, behind the pairing code.
    state.ad.pendingData = null;
    endAdBreak({ resume: false });
    if (state.ad.timer) clearTimeout(state.ad.timer);
    state.ad.timer = null;
    state.ad.nextAt = 0;
}

/** Token is gone or no longer valid: forget everything and start over. */
function resetToPairing(message = null) {
    clearTimers();
    // Stop what is PLAYING too, not only the clocks: a hidden <video> still fires `ended`,
    // and that alone would go on walking the old playlist behind the pairing code.
    stopPlayback();
    state.entries = [];
    state.items = [];
    state.index = 0;
    state.cursors = {};
    state.nextCursors = {};
    store.remove(TOKEN_KEY);
    store.remove(CODE_KEY);
    store.remove(CODE_EXPIRES_KEY);
    state.token = null;
    state.version = null;
    state.lastLive = null;
    // The manifest kept for the screen this set was is nobody's now.
    tellWorker({ type: 'forget' });
    if (message) el('pairing-note').textContent = message;
    startPairing();
}

/* ── Pairing ───────────────────────────────────────────────────────────── */

/**
 * Show the pairing code.
 *
 * A code already on file and still alive is reused: reloading the page must not
 * cost a registration, both because the number on the TV should not keep
 * changing under the owner's fingers and because the endpoint is rate limited.
 */
async function startPairing() {
    show('view-loading');

    const cachedCode = store.get(CODE_KEY);
    const cachedExpiry = Number(store.get(CODE_EXPIRES_KEY) || 0);

    if (cachedCode && store.get(SECRET_KEY) && cachedExpiry > Date.now()) {
        el('pairing-code').textContent = cachedCode;
        setPairingInstruction(store.get(KNOWN_KEY) === '1');
        show('view-pairing');
        clearTimers();
        state.pollTimer = setInterval(pollPairStatus, POLL_MS);

        // Ask once straight away, not only after the first interval. This branch
        // is a REOPEN — the page is coming back with a code it already had — so
        // the owner may well have typed that code while the screen was off. The
        // token would be sitting on the server, and waiting a whole POLL_MS to
        // discover it is a blank screen for no reason. The register path below
        // needs no such call: nobody can have claimed a code minted a moment ago.
        pollPairStatus();

        return;
    }

    try {
        const response = await fetch('/device/register', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
            body: JSON.stringify({ device_uuid: store.get(UUID_KEY) }),
        });

        if (response.status === 429) {
            // Asked too often - usually a page reloaded over and over. Say so plainly
            // and wait out the window instead of hammering it every ten seconds.
            showError('Too many attempts. Waiting a minute, then trying again...');
            setTimeout(startPairing, 60000);

            return;
        }

        if (!response.ok) throw new Error('register failed');

        const data = await response.json();
        store.set(UUID_KEY, data.device_uuid);
        store.set(SECRET_KEY, data.poll_secret);
        store.set(CODE_KEY, data.code);
        store.set(CODE_EXPIRES_KEY, String(Date.parse(data.expires_at)));
        store.set(KNOWN_KEY, data.known_device ? '1' : '');

        el('pairing-code').textContent = data.code;
        setPairingInstruction(!!data.known_device);
        show('view-pairing');

        clearTimers();
        state.pollTimer = setInterval(pollPairStatus, POLL_MS);
    } catch {
        showError('Cannot reach the server. Retrying...');
        setTimeout(startPairing, 10000);
    }
}

async function pollPairStatus() {
    const uuid = store.get(UUID_KEY);
    const secret = store.get(SECRET_KEY);

    if (!uuid || !secret) {
        // The secret is cleared by whoever completes the pairing, and storage is
        // shared across tabs — so if a token is now on file, another tab of this
        // same player just finished the job. Take it up rather than calling
        // resetToPairing(), which would delete that token and send a TV that is
        // already paired back to asking for a code. Two tabs is not exotic: the
        // panel's Open Player button opens one every time it is clicked.
        const token = store.get(TOKEN_KEY);

        if (token) {
            state.token = token;
            clearTimers();
            startPlayback();

            return;
        }

        return resetToPairing();
    }

    try {
        const query = new URLSearchParams({ device_uuid: uuid, poll_secret: secret });
        const response = await fetch(`/device/pair-status?${query}`, { headers: { Accept: 'application/json' } });
        if (!response.ok) return;

        const data = await response.json();

        if (data.status === 'paired' && data.token) {
            store.set(TOKEN_KEY, data.token);
            state.token = data.token;
            store.remove(SECRET_KEY);
            store.remove(CODE_KEY);
            store.remove(CODE_EXPIRES_KEY);
            clearTimers();
            startPlayback();
            return;
        }

        // An unclaimed code eventually dies; fetch a fresh one so the TV never
        // shows a number that no longer works.
        if (data.status === 'expired' || data.status === 'unknown') {
            store.remove(CODE_KEY);
            store.remove(CODE_EXPIRES_KEY);
            startPairing();
        }
    } catch {
        /* Offline for a moment: the next tick tries again. */
    }
}

/* ── Playback ──────────────────────────────────────────────────────────── */

function startPlayback() {
    show('view-loading');
    fetchPlaylist();
    state.playlistTimer = setInterval(fetchPlaylist, PLAYLIST_MS);
    state.heartbeatTimer = setInterval(sendHeartbeat, HEARTBEAT_MS);
    sendHeartbeat();
}

function deviceHeaders() {
    return { Accept: 'application/json', Authorization: `Bearer ${state.token}` };
}

async function fetchPlaylist() {
    // The token this request speaks for. An answer that arrives after it was dropped — the
    // heartbeat's 401 got there first, or the screen was paired again — belongs to a screen
    // this page no longer is: a second 401 would start a second pairing, and a late playlist
    // would be drawn over the pairing code.
    const token = state.token;

    try {
        const response = await fetch('/device/playlist', { headers: deviceHeaders() });
        if (token !== state.token) return;

        if (response.status === 401) {
            return resetToPairing('This screen was removed. Pair it again to continue.');
        }
        if (!response.ok) return;

        const data = await response.json();
        if (token !== state.token) return;
        applyOrientation(data.screen?.orientation);

        // The worker marks an answer it served from its cache because the server could
        // not be reached (§15): the set is offline, and judges expiry for itself.
        applyManifest(data, response.headers.get('X-Signage-Cached') === '1');
    } catch {
        /* Keep showing the last thing that worked. */
    }
}

/**
 * A manifest, live or from memory. A live one resets the clock offset and warms the cache
 * with everything the screen may need; a cached one is played the way the owner decided —
 * what has not expired plays on, and once everything has, the holding picture or black.
 */
function applyManifest(data, fromCache) {
    state.offline = fromCache;
    document.body.dataset.source = fromCache ? 'cache' : 'live';
    // Which items a cached manifest has dropped as expired — nothing on the glass says so (the owner's
    // rule); it is on the page for whoever checks a set.
    document.body.dataset.dropped = '';

    if (!fromCache) {
        const serverTime = Date.parse(data.server_time);

        if (Number.isFinite(serverTime)) {
            state.clockOffset = serverTime - Date.now();
            store.set(CLOCK_KEY, String(state.clockOffset));
        }

        state.lastLive = data;
        warmCache(data);
    }

    const shown = fromCache ? fromMemory(data) : data;

    if (fromCache) {
        document.body.dataset.dropped = shown.dropped;
        document.body.dataset.entry = String(shown.entry);
    }

    // Nothing changed: leave whatever is on screen alone rather than
    // re-rendering and making the TV flicker every poll. What is compared is
    // what would be SHOWN, whether it came live or from memory: the line dropping
    // — or coming back — with the same things due restarts nothing (a video is
    // not cut, a channel's rotation is not sent back to its start), while a new
    // entry of the timeline (lunch has begun) or a file expiring mid-outage does.
    const key = JSON.stringify([
        shown.screen?.orientation ?? null,
        shown.blank === true,
        shown.items ?? [],
        shown.ad_break?.items ?? [],
        shown.ad_break?.every_seconds ?? 0,
    ]);

    if (key === state.version) return;
    state.version = key;

    render(shown);
}

/**
 * What a cached manifest says the screen should show NOW (§15). The timeline carries the server's own
 * answer for every moment it changes over the next few days — worked out by the very resolver that answers
 * online — so its entry for this moment is taken (past its end, the last one); then any file that has
 * expired since is dropped, and when nothing is left the holding picture, then black.
 *
 * "Now" is this set's clock corrected by the last live server time, and never earlier than the moment the
 * manifest was made: a box whose clock went back to 2020 after a power cut keeps playing the right day.
 */
function fromMemory(data) {
    const made = Date.parse(data.server_time);
    const now = Math.max(Date.now() + state.clockOffset, Number.isFinite(made) ? made : 0);
    const entries = Array.isArray(data.timeline?.entries) ? data.timeline.entries : [];
    const lines = data.timeline?.lines ?? {};
    let entry = -1;
    let base = { items: data.items ?? [], blank: data.blank === true, ads: data.ad_break?.items ?? [] };

    entries.forEach((candidate, index) => {
        if (Date.parse(candidate.at) <= now) entry = index;
    });

    if (entries.length > 0) {
        entry = Math.max(0, entry);

        const named = (keys) => (Array.isArray(keys) ? keys.map((key) => lines[key]).filter(Boolean) : []);

        base = { items: named(entries[entry].items), blank: entries[entry].blank === true, ads: named(entries[entry].ads) };
    }

    const expired = (item) => item.type !== 'channel' && item.expires_at && Date.parse(item.expires_at) <= now;
    const items = base.items.filter((item) => !expired(item));
    const dropped = base.items.filter(expired).map((item) => item.id).join(',');
    const adBreak = { ...(data.ad_break ?? {}), items: base.ads };

    if (items.length > 0 || base.items.length === 0) {
        return { ...data, items, blank: base.blank, ad_break: adBreak, dropped, entry };
    }

    // Everything in it has expired: the holding picture if there is one — unless that too has
    // expired — and otherwise black, which is what the server would have answered.
    const fallback = data.fallback && !expired(data.fallback) ? [data.fallback] : [];

    return { ...data, items: fallback, blank: fallback.length === 0, ad_break: adBreak, dropped, entry };
}

/* ── The worker and its cache (§15) ─────────────────────────────────────── */

function registerWorker() {
    if (!('serviceWorker' in navigator)) return;

    // Ask the browser not to clear this set's cache when its disk runs low: a television that has lost its
    // line and then its cache has nothing left to play. A browser may say no; nothing else changes.
    navigator.storage?.persist?.().catch(() => {});

    navigator.serviceWorker.register(WORKER_PATH, { scope: WORKER_SCOPE })
        .then((registration) => {
            setInterval(() => registration.update().catch(() => {}), WORKER_UPDATE_MS);
        })
        .catch(() => {
            /* A browser without one, or a page not on https: online only, as before. */
        });

    // A worker that took the page over after it loaded missed the manifest the page fetched on its own:
    // ask again through the worker, so it keeps a copy — and warms the files — before any line drops.
    navigator.serviceWorker.addEventListener('controllerchange', () => {
        if (state.token) fetchPlaylist();
    });

    // The worker says when a warm-up is done, and how many files it could not fetch. Kept on the
    // page for whoever checks a set (nothing on the glass says so — the owner's rule).
    navigator.serviceWorker.addEventListener('message', (event) => {
        if (event.data?.type === 'warmed') {
            document.body.dataset.warmed = String(Date.now());
            document.body.dataset.warmMissing = String(event.data.missing ?? 0);
        }
    });
}

function tellWorker(message) {
    try {
        navigator.serviceWorker?.controller?.postMessage(message);
    } catch {
        /* No worker: nothing to tell. */
    }
}

/**
 * Everything this screen may play over the next days, named to the worker most needed first — it
 * fetches one file at a time in this order, and drops whatever is no longer named once it is done:
 * what is due now (with what those ad pages load), the holding picture, the network adverts, and then
 * every line the timeline brings later on. The page of an ad is read once per version for what it loads.
 */
async function warmCache(data) {
    if (!navigator.serviceWorker?.controller) return;

    const lines = data.timeline?.lines ?? {};
    const later = (data.timeline?.entries ?? [])
        .flatMap((entry) => [...(entry.items ?? []), ...(entry.ads ?? [])])
        .map((key) => lines[key]);
    const filesOf = (items) => (items ?? [])
        .flatMap((item) => (item?.type === 'channel' ? item.ads ?? [] : [item]))
        .filter((file) => file && file.url);

    const urls = new Set();
    const pages = new Set();

    for (const group of [filesOf(data.items), filesOf(data.fallback ? [data.fallback] : []), filesOf(data.ad_break?.items), filesOf(later)]) {
        group.forEach((file) => urls.add(assetUrl(file)));

        for (const file of group.filter((candidate) => candidate.type === 'html')) {
            const page = assetUrl(file);

            pages.add(page);
            (await pageSubresources(page)).forEach((address) => urls.add(address));
        }
    }

    // A page no longer named is read no more.
    [...pageScans.keys()].filter((page) => !pages.has(page)).forEach((page) => pageScans.delete(page));

    tellWorker({ type: 'warm', urls: [...urls] });
}

// What each ad page loads, by the page's address — which carries its version, so a page is read once.
const pageScans = new Map();

/**
 * The addresses on this origin that an ad page loads. The page is fetched through the worker, which keeps
 * it; one that cannot be had now is asked about again next time.
 */
async function pageSubresources(page) {
    if (!pageScans.has(page)) {
        try {
            const response = await fetch(page);

            if (!response.ok) return [];

            const found = subresourcesOf(await response.text())
                .map((address) => {
                    try {
                        return new URL(address, window.location.href);
                    } catch {
                        return null;
                    }
                })
                .filter((address) => address && address.origin === window.location.origin)
                .map((address) => address.href);

            pageScans.set(page, found);
        } catch {
            return [];
        }
    }

    return pageScans.get(page);
}

/**
 * The addresses an ad page loads — pictures, videos and scripts by their attributes, background pictures
 * by the url() in its styles — read by the browser's own parser, so every attribute arrives decoded
 * exactly as the page will use it. Parsing runs no script and loads nothing. Data URIs (the fonts) are
 * inside the page already.
 */
function subresourcesOf(html) {
    const page = new DOMParser().parseFromString(html, 'text/html');
    const urls = /url\(\s*(['"]?)([^'")]+)\1\s*\)/g;
    const found = [
        ...[...page.querySelectorAll('[src]')].map((node) => node.getAttribute('src')),
        ...[...page.querySelectorAll('link[href]')].map((node) => node.getAttribute('href')),
    ];

    for (const node of page.querySelectorAll('[style], style')) {
        const css = node.tagName === 'STYLE' ? node.textContent : node.getAttribute('style');

        for (const match of (css ?? '').matchAll(urls)) found.push(match[2]);
    }

    return found.filter((url) => url && !url.trim().startsWith('data:'));
}

async function sendHeartbeat() {
    const token = state.token;   // see fetchPlaylist: only an answer for the current token counts

    try {
        const response = await fetch('/device/heartbeat', { method: 'POST', headers: deviceHeaders() });
        if (response.status === 401 && token === state.token) {
            resetToPairing('This screen was removed. Pair it again to continue.');
        }
    } catch {
        /* ignore */
    }
}

/** The panel is always landscape; a portrait screen is the content turned. */
function applyOrientation(orientation) {
    const stage = el('stage');
    stage.dataset.orientation = orientation ?? 'landscape';
}

function render(data) {
    // Never tear the screen down mid-advert. A poll landing during a break would
    // otherwise restart the playlist underneath, and the brand's advert would be cut
    // off by the shop's own content reappearing behind it. Hold the manifest and
    // apply it the moment the break ends.
    if (state.ad.active) {
        state.ad.pendingData = data;

        return;
    }

    el('screen-name').textContent = data.screen?.name ?? '';
    // A channel line becomes several items — and different ones on different passes
    // when it plays only some of its ads each time. buildPass lays out this pass.
    state.entries = data.items ?? [];
    buildPass();
    show('view-content');

    // What is on the glass and no longer on the list must not stay up while the next item loads — or, with
    // no line, fails to: played from memory, or once it has expired, black is better than yesterday's price.
    // (Online, the next item is a moment away, and cutting to black for that moment would be a flash.)
    const current = frontNode();

    if (current && (state.offline || hasExpired(current)) && !state.items.some((item) => assetUrl(item) === current.dataset.item)) {
        if (current.tagName === 'VIDEO') current.pause();
        layer(state.front).hidden = true;
        layer(state.front).innerHTML = '';
    }

    armAdBreak(data.ad_break);

    // This screen has a playlist, but none of it is due right now. Stop the loop and
    // hide everything rather than showing "No content", which reads as a fault at
    // three in the morning. Nothing is torn down, so the next poll after the
    // schedule opens brings it straight back.
    document.body.classList.toggle('closed', data.blank === true);

    if (data.blank === true) {
        stopPlayback();
        el('no-content').hidden = true;
        return;
    }

    if (state.items.length === 0) {
        stopPlayback();
        el('no-content').hidden = false;
        return;
    }

    el('no-content').hidden = true;
    // A changed playlist restarts from the top rather than trying to hold a
    // position that may no longer exist.
    state.index = 0;
    playCurrent();
}

/* ── Rendering the playlist ────────────────────────────────────────────── */

/**
 * Two stacked layers: one on screen, one being prepared. Swapping them is what
 * keeps the TV from flashing black between items.
 */
function layer(which) {
    return el(which === 'a' ? 'layer-a' : 'layer-b');
}

function stopPlayback() {
    if (state.itemTimer) clearTimeout(state.itemTimer);
    state.itemTimer = null;
    state.itemDurationMs = 0;
    // An advert must not be left hanging over content that is no longer there.
    // The hourly countdown keeps running, so it comes back when the content does.
    endAdBreak({ resume: false });
    ['a', 'b'].forEach((which) => {
        layer(which).innerHTML = '';
        layer(which).hidden = true;
    });
}

function buildElement(item) {
    const node = buildNode(item);

    // Which file it is and when it stops being current: render() takes something that is no longer on the
    // list off the glass by these.
    node.dataset.item = assetUrl(item);
    if (item.expires_at) node.dataset.expires = item.expires_at;

    return node;
}

/** Has what this node shows expired, by the server's clock? */
function hasExpired(node) {
    const expires = Date.parse(node.dataset.expires ?? '');

    return Number.isFinite(expires) && expires <= Date.now() + state.clockOffset;
}

function buildNode(item) {
    /* An ad built in the Ad Builder is a whole page: it brings its own layout, its own fonts and its
     * own animations, so it gets a frame rather than a tag.
     *
     * Where the worker keeps this set (§15) the frame opens the page at PAGE_PATH, inside the worker's
     * scope: the frame is then one of the worker's own pages, and the pictures, videos and scripts it
     * loads come from the cache with no line — on every browser that has a worker at all (a `srcdoc`
     * frame is one only from Chrome 135, which leaves most televisions out). A frame with an origin of
     * its own is served by no worker, so this one keeps the player's (`allow-same-origin`): the page is
     * our own compiled output with nothing a person typed written as code, and it carries a policy that
     * lets no other script run, reach the network or submit anything (docs §9, §15). Without a worker
     * the page plays straight from the server in an origin of its own, as it always has.
     *
     * The page is asked for first either way, so one that is not there — no line and no copy — is
     * skipped like any broken file instead of putting the browser's error page on the glass. */
    if (item.type === 'html') {
        const frame = document.createElement('iframe');
        const url = assetUrl(item);
        const kept = Boolean(navigator.serviceWorker?.controller);

        // The page's own address, whichever way it is opened — so a set can be asked what is in each frame.
        frame.dataset.src = url;
        frame.setAttribute('scrolling', 'no');
        frame.setAttribute('sandbox', kept ? 'allow-scripts allow-same-origin' : 'allow-scripts');

        fetch(url)
            .then((response) => {
                if (!response.ok) throw new Error('page ' + response.status);

                frame.src = kept ? PAGE_PATH + '?src=' + encodeURIComponent(url) : url;
            })
            .catch(() => frame.dispatchEvent(new Event('error')));

        return frame;
    }

    if (item.type === 'video') {
        const video = document.createElement('video');
        video.src = assetUrl(item);
        video.muted = true;          // signage is silent, and muted always autoplays
        video.autoplay = true;
        video.playsInline = true;
        video.preload = 'auto';
        return video;
    }

    const image = document.createElement('img');
    image.src = assetUrl(item);
    image.alt = '';
    return image;
}

/**
 * The single place that decides where a file comes from. A native shell that
 * caches assets injects window.SignagePlayer and answers with a local path;
 * with no shell present this is the server URL carrying the file's cache key
 * (§15): the worker files each copy under it, so a republished page or a
 * replaced file is a new address and the old copy is never shown stale.
 */
function assetUrl(item) {
    const shell = window.SignagePlayer;
    if (shell && typeof shell.localUrl === 'function') {
        return shell.localUrl(item.url, item.checksum) || item.url;
    }
    if (!item.checksum) return item.url;

    return item.url + (item.url.includes('?') ? '&' : '?') + CACHE_PARAM + '=' + encodeURIComponent(item.checksum);
}

function playCurrent() {
    if (state.items.length === 0) return;
    if (state.itemTimer) clearTimeout(state.itemTimer);

    const item = state.items[state.index % state.items.length];
    const target = state.front === 'a' ? 'b' : 'a';
    const incoming = layer(target);

    incoming.innerHTML = '';
    const node = buildElement(item);
    incoming.appendChild(node);

    const reveal = () => {
        // A node no longer on the page — replaced by the next item before it finished
        // loading, or torn down with its playlist — can still finish loading. Swapping the
        // layers for it would hide and empty the layer the CURRENT item was just put in: a
        // black screen until the next item.
        if (!node.isConnected) return;

        const outgoing = layer(state.front);
        const previous = outgoing.firstElementChild;

        // Whatever was on screen is now behind the new item. A hidden <video> that is
        // still playing goes on DECODING — for the whole length of whatever replaced
        // it — which on a cheap television is heat, fan noise and a stutter in the
        // picture people can actually see. Stop it, then let it go.
        if (previous && previous.tagName === 'VIDEO') previous.pause();

        outgoing.hidden = true;
        outgoing.innerHTML = '';

        incoming.hidden = false;
        state.front = target;
        preloadNext();
    };

    // The same for a file that fails after it was replaced: skipping then would cut short
    // whatever is playing now, which is not the file that broke.
    const broken = () => {
        if (node.isConnected) skipBroken();
    };

    if (item.type === 'video') {
        node.addEventListener('loadeddata', reveal, { once: true });
        // A video advances when it actually ends; the duration is only a backstop
        // for a file that stalls or has no end event.
        node.addEventListener('ended', advance, { once: true });
        node.addEventListener('error', broken, { once: true });
        startItemTimer((item.duration + 5) * 1000);
        node.play().catch(() => {});
    } else if (item.type === 'html') {
        // A frame fires `load` for the empty document it starts with, before its page is even asked for:
        // showing that would blank the screen while the page is on its way. Only the page's own load reveals it.
        const loaded = () => {
            if (!node.getAttribute('src')) return;

            node.removeEventListener('load', loaded);
            reveal();
        };

        node.addEventListener('load', loaded);
        node.addEventListener('error', broken, { once: true });
        startItemTimer(Math.max(1, item.duration) * 1000);
    } else {
        node.addEventListener('load', reveal, { once: true });
        node.addEventListener('error', broken, { once: true });
        startItemTimer(Math.max(1, item.duration) * 1000);
    }
}

/** Start the countdown for the current item, remembering when and for how long —
 *  an item interrupted by an advert resumes with what it had LEFT. */
function startItemTimer(ms) {
    if (state.itemTimer) clearTimeout(state.itemTimer);
    state.itemStartedAt = Date.now();
    state.itemDurationMs = ms;
    state.itemTimer = setTimeout(advance, ms);
}

/** The element currently on screen, or null. */
function frontNode() {
    return layer(state.front).firstElementChild;
}

/** Warm the browser cache for whatever comes next, so the swap is instant. */
function preloadNext() {
    if (state.items.length < 2) return;
    const next = state.items[(state.index + 1) % state.items.length];
    // Videos stream and ad pages fetch their own pieces; only a plain picture is worth warming.
    if (next.type === 'video' || next.type === 'html') return;
    const warm = new Image();
    warm.src = assetUrl(next);
}

/**
 * Lay out one pass of the loop.
 *
 * A playlist line is either one file or a CHANNEL, and a channel may play only some of
 * its ads each time round (`per_pass`), taking the next ones the time after. So the list
 * the screen actually walks through is built here, one pass at a time.
 *
 * Where each channel had got to is kept in `cursors`, keyed by its line, and only moved
 * on once a pass has really been played to its end (advance). A manifest arriving in the
 * middle of a pass therefore replays that pass's ads instead of skipping ones nobody saw,
 * and a change to some other line does not send a channel back to its first ad.
 */
function buildPass() {
    const flat = [];
    const next = {};

    state.entries.forEach((entry) => {
        if (entry.type !== 'channel') {
            flat.push(entry);

            return;
        }

        const ads = entry.ads ?? [];
        if (ads.length === 0) return;

        const take = Math.min(ads.length, entry.per_pass || ads.length);
        let at = (state.cursors[entry.id] ?? 0) % ads.length;

        for (let i = 0; i < take; i += 1) {
            flat.push(ads[at]);
            at = (at + 1) % ads.length;
        }

        next[entry.id] = at;
    });

    state.items = flat;
    state.nextCursors = next;
}

function advance() {
    if (state.items.length === 0) return;

    state.index += 1;

    // The end of a pass. The channels in it really were played, so each moves on to
    // its next ads, and the next pass is laid out.
    if (state.index >= state.items.length) {
        state.index = 0;
        state.cursors = state.nextCursors;
        buildPass();
    }

    playCurrent();
}

/**
 * A file the device cannot render — corrupt, or a codec this TV lacks. Move on,
 * but never instantly: a single broken item in a one-item playlist would spin
 * error -> next -> same item -> error as fast as the browser could manage.
 */
function skipBroken() {
    if (state.itemTimer) clearTimeout(state.itemTimer);
    state.itemTimer = setTimeout(advance, BROKEN_ITEM_PAUSE_MS);
}

/* ── Network advertising ───────────────────────────────────────────────────
 *
 * Every so often the shop's content PAUSES, the brand's advert plays over it, and
 * the shop's content carries on from exactly where it stopped — a two-hour video
 * resumes at 1:00:00, not at the beginning.
 *
 * The clock is kept here rather than on the server because the server has no way to
 * reach a television between polls. It still decides everything that matters: which
 * adverts, how often, and whether at all. The player only counts — the same as it
 * already counts an image's ten seconds.
 *
 * Three things this is careful about, because a shop's wall must not misbehave:
 *   · the countdown is NOT restarted by a poll, or an hourly break would never fire
 *   · only one video ever decodes at a time — the content is paused before the
 *     advert plays, and the advert's element is destroyed after
 *   · a manifest arriving mid-break is held, not applied
 */

/** Take the break configuration from a manifest without disturbing a countdown
 *  that is already running. */
function armAdBreak(config) {
    const items = config?.items ?? [];
    const everyMs = Math.max(0, (config?.every_seconds ?? 0) * 1000);

    state.ad.items = items;

    // A changed interval is a new arrangement; anything else leaves the clock alone.
    if (everyMs !== state.ad.everyMs) {
        state.ad.everyMs = everyMs;
        state.ad.nextAt = 0;
    }

    if (state.ad.timer) clearTimeout(state.ad.timer);
    state.ad.timer = null;

    if (items.length === 0 || everyMs === 0) {
        state.ad.nextAt = 0;

        return;
    }

    // Warm the still adverts NOW rather than when the break starts. An advert that
    // has not arrived by its moment lets the whole break pass in silence — the
    // backstop timer ends it and the shop's content simply carries on — which on a
    // slow shop connection is a brand paying for nothing. Videos are left to stream.
    items.filter((item) => item.type !== 'video').forEach((item) => {
        const warm = new Image();
        warm.src = assetUrl(item);
    });

    // Counted from when the player started, not from the clock: set once, and every
    // later poll only re-hangs the timeout on the same deadline.
    if (! state.ad.nextAt) state.ad.nextAt = Date.now() + everyMs;

    state.ad.timer = setTimeout(startAdBreak, Math.max(0, state.ad.nextAt - Date.now()));
}

/** How much of the item on screen is still to run. */
function remainingOnScreen() {
    const node = frontNode();

    if (node && node.tagName === 'VIDEO' && Number.isFinite(node.duration) && node.duration > 0) {
        return Math.max(0, (node.duration - node.currentTime) * 1000);
    }

    if (! state.itemDurationMs) return 0;

    return Math.max(0, state.itemDurationMs - (Date.now() - state.itemStartedAt));
}

function startAdBreak() {
    state.ad.timer = null;

    // Nothing to interrupt: a dark screen, an empty playlist, or a set still pairing.
    // Skip this break rather than advertising to nobody, and wait for the next one.
    const playable = ! el('view-content').hidden
        && ! document.body.classList.contains('closed')
        && state.items.length > 0
        && frontNode() !== null;

    if (state.ad.active || state.ad.items.length === 0 || ! playable) {
        scheduleNextBreak();

        return;
    }

    // Do not cut the last few seconds off something — it looks like a fault. Wait
    // for it to finish, then break.
    //
    // But wait ONCE, and only once. A video's reported duration is not always the
    // truth: a streamed file can report a length that grows as it downloads, so it
    // looks permanently about to end. Deferring every time it did would mean the
    // break never happened at all — a brand paying for nothing, on a television that
    // looked perfectly healthy.
    const left = remainingOnScreen();

    if (! state.ad.deferred && left > 0 && left < AD_GRACE_MS) {
        state.ad.deferred = true;
        state.ad.timer = setTimeout(startAdBreak, left + 150);

        return;
    }

    state.ad.deferred = false;
    state.ad.active = true;
    state.ad.index = 0;
    playAd();
}

/**
 * Build the advert first, pause the shop's content only once it is ready to show.
 *
 * The other order — pause, then load — leaves a frozen frame on the wall for as long
 * as the file takes to arrive.
 */
function playAd() {
    const item = state.ad.items[state.ad.index];

    if (! item) {
        endAdBreak({ resume: true });

        return;
    }

    const stage = el('layer-ad');
    stage.innerHTML = '';

    const node = buildElement(item);
    stage.appendChild(node);

    let done = false;
    const finish = () => {
        // Once per advert, and never for one no longer on the stage: an error, or a refused
        // play(), can arrive after the break has ended and emptied the layer, and moving on
        // from it then would start an advert outside any break.
        if (done || ! node.isConnected) return;
        done = true;
        state.ad.index += 1;
        playAd();
    };

    const reveal = () => {
        // An advert that finishes loading after its time was up, or after the break ended,
        // must not show itself: un-hiding the emptied layer is a black screen over the shop's
        // content, and pausing that content would freeze it with nothing to resume it.
        if (done || ! node.isConnected) return;

        pauseContent();
        stage.hidden = false;

        if (item.type === 'video') node.play().catch(finish);
    };

    if (item.type === 'video') {
        node.addEventListener('loadeddata', reveal, { once: true });
        node.addEventListener('ended', finish, { once: true });
        // A file that stalls must never hold the wall hostage.
        node.addEventListener('error', finish, { once: true });
        state.ad.playTimer = setTimeout(finish, (item.duration + 5) * 1000);
    } else {
        node.addEventListener('load', reveal, { once: true });
        node.addEventListener('error', finish, { once: true });
        state.ad.playTimer = setTimeout(finish, Math.max(1, item.duration) * 1000);
    }
}

/** Stop the shop's content where it stands, remembering what it had left — once per break. */
function pauseContent() {
    // Every advert in a break comes here as it appears, but only the first may measure. An
    // image's time left is counted from when it STARTED, so measured again at the second
    // advert it has lost the first advert's seconds as well — nothing left — and the picture
    // came back for half a second before moving on.
    if (state.ad.contentPaused) return;
    state.ad.contentPaused = true;

    if (state.itemTimer) clearTimeout(state.itemTimer);
    state.itemTimer = null;
    state.ad.resumeMs = remainingOnScreen();

    const node = frontNode();

    // pause(), never a seek: the element keeps its position and its buffer, so
    // resuming a two-hour video costs nothing and cannot stutter.
    if (node && node.tagName === 'VIDEO') node.pause();
}

/** Put the shop's content back exactly where it was. */
function resumeContent() {
    const node = frontNode();

    if (! node) return;

    if (node.tagName === 'VIDEO') {
        node.play().catch(() => {});
        // The backstop is rearmed from what was left, not from the whole duration — with the
        // same five seconds of slack playCurrent gives a video. Exactly the time left would
        // fire a moment before `ended` (starting again takes a moment) and cut its last frames.
        startItemTimer(Math.max(1000, state.ad.resumeMs || 1000) + 5000);

        return;
    }

    // An image that had four of its ten seconds used gets the remaining six.
    startItemTimer(Math.max(500, state.ad.resumeMs || 500));
}

function endAdBreak({ resume }) {
    if (state.ad.playTimer) clearTimeout(state.ad.playTimer);
    state.ad.playTimer = null;

    // Whether an advert ever actually appeared. If none did, nothing was paused: the content
    // kept playing on its own clock, and "resuming" it would re-arm that clock from a stale
    // measurement — cutting off a video that had been playing all along.
    const contentWasPaused = state.ad.contentPaused;
    state.ad.contentPaused = false;

    const stage = el('layer-ad');

    if (stage) {
        stage.hidden = true;
        // Emptied, not just hidden: a hidden <video> still holds its decoder and its
        // buffer, and a set left running for weeks would collect one every hour.
        stage.innerHTML = '';
    }

    if (! state.ad.active) return;

    state.ad.active = false;
    state.ad.index = 0;

    scheduleNextBreak();

    // A manifest that arrived mid-break is applied now, and replaces the resume:
    // there is no point putting back content the server has already changed.
    if (state.ad.pendingData) {
        const data = state.ad.pendingData;
        state.ad.pendingData = null;
        render(data);

        return;
    }

    if (resume && contentWasPaused) resumeContent();
}

function scheduleNextBreak() {
    if (state.ad.timer) clearTimeout(state.ad.timer);
    state.ad.timer = null;
    // A fresh break gets its own one chance to wait out an item's last seconds.
    state.ad.deferred = false;

    if (state.ad.everyMs === 0 || state.ad.items.length === 0) return;

    state.ad.nextAt = Date.now() + state.ad.everyMs;
    state.ad.timer = setTimeout(startAdBreak, state.ad.everyMs);
}

function showError(message) {
    el('error-message').textContent = message;
    show('view-error');
}

/* ── Boot ──────────────────────────────────────────────────────────────── */

registerWorker();

if (state.token) {
    startPlayback();
} else {
    startPairing();
}

// The line is back: ask at once rather than at the next poll.
window.addEventListener('online', () => {
    if (state.token) fetchPlaylist();
});

// Best effort on TVs that honour it; the shop also disables sleep on the set.
if ('wakeLock' in navigator) {
    navigator.wakeLock.request('screen').catch(() => {});
}
