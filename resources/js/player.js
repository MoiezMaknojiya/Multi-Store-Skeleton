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
 * manifest's timeline says what each moment of the next days shows, the holding
 * picture where nothing is due, then black — and after every live manifest it
 * names to the worker, most needed first, every file the next days may play.
 * Anywhere else the player is online only, exactly as before.
 */

import { registerExitQuestion } from './player/exit-question.js';

const TOKEN_KEY = 'signage.device.token';
const UUID_KEY = 'signage.device.uuid';
const SECRET_KEY = 'signage.device.poll_secret';
const CODE_KEY = 'signage.device.code';
const CODE_EXPIRES_KEY = 'signage.device.code_expires';
const KNOWN_KEY = 'signage.device.known';
// The gap between the server's clock and this set's, from the last live manifest (§15) — kept, so a set that
// reboots with no line still reads the timeline by the server's hours.
const CLOCK_KEY = 'signage.device.clock_offset';
// This set's own word on decoding a turned screen's videos (docs/TV-ORIENTATION-SPEC.md §12): '1' or '0' overrides the
// server's switch, set by hand at the set's DevTools to try it on one stick before everyone, and by the browser tests.
const DECODE_KEY = 'signage.decode_turned_video';

// While waiting to be claimed. Deliberately as slow as the playlist poll
// (owner's decision): an unpaired player asks 2 times a minute instead of 15,
// and a page left open on a pairing code costs almost nothing. The price is
// paid at setup — after the owner types the code, the TV can take up to this
// long to notice and start playing.
const POLL_MS = 30000;
const PLAYLIST_MS = 30000;   // how often to ask what to show
const HEARTBEAT_MS = 60000;  // how often to say "alive"
const BROKEN_ITEM_PAUSE_MS = 1000;  // breathing room before skipping a file that will not play
const DECODER_WAIT_MS = 5000;       // the most a set waits for its own video decoder before it plays without it
const PREPARE_AHEAD_MS = 3500;      // how long before the item on screen ends the next one is made ready behind it
const AD_LEAVES_MS = 5000;          // the most a break's last advert stays up for the content after it to be ready
const FADE_MS = 500;                // how long the next item takes to fade in over the one before (every screen fades)
const PAGE_VIDEO_WAIT_MS = 5000;    // the most a page made ready waits for the first frames of the videos decoded for it
// One decoder at a time (oneAtATime): a video bigger than full HD — a 4K one — is never set up beside another, which most
// sets cannot decode at once; nor is any video on a set that showed it cannot, remembered for a week.
const BIG_VIDEO_PIXELS = 1920 * 1080 * 1.5;
const ONE_DECODER_KEY = 'signage.one_decoder';    // when this set last showed it decodes one video at a time
const TRYING_KEY = 'signage.second_decoder';      // when a second video was last set up beside one on the stage
const ONE_DECODER_MS = 7 * 24 * 3600 * 1000;
const FELL_WITHIN_MS = 30000;                     // a page back this soon after TRYING_KEY: it fell setting that one up
const SHOWS_WITHIN_MS = 700;                      // the most a video set up at the switch is waited for to show (whenItShows)
// An Ad Builder page the player opens is told when it is on screen (startPage), and starts its animations then: one made
// ready behind the item before would otherwise have played its entrance where nobody saw it. The frame's name says so.
const HELD_PAGE_NAME = 'signage-held';
const PAGE_START_MESSAGE = 'signage-start';
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
    // characters are plenty to tell an organization's handful of sets apart, and the whole
    // thirty-six is a wall of noise nobody reads. The panel prints the same block
    // beside each screen, so the two can be matched at a glance.
    // Safe to print either way: a uuid is an identity, never a way in.
    el('pairing-uuid').textContent = (store.get(UUID_KEY) ?? '').split('-').pop();

    el('pairing-lead').textContent = knownDevice
        ? 'This screen was set up before. In your dashboard, go to'
        : 'Open your dashboard, go to';

    el('pairing-where').textContent = knownDevice
        ? 'Screens \u2192 Replace Device'
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
    // The next item, made ready in the layer behind the one on screen ({node, url}), and the timer that makes it
    // (prepare): every switch is then a swap.
    prepared: null,
    prepareTimer: null,
    // The element playCurrent put up last: only its own end moves the loop on.
    current: null,
    // The fade under way ({element, done, timer, endsAt}), finished at once by the next change (finishFade).
    fade: null,

    // Offline (§15): whether the manifest on screen came from the worker's cache, the
    // set's clock corrected by the last live manifest's server time (so the timeline's
    // entry is picked by the right hours however wrong the box's clock is), and the last
    // live manifest — warmed again when a worker takes the page over after it loaded.
    offline: false,
    clockOffset: Number(store.get(CLOCK_KEY)) || 0,
    lastLive: null,

    // Network advertising. A break comes between two items, never in the middle of one (owner,
    // 2026-10-23: "jese hi ad khatam ho toh hamari network ads chale"); only an item that never ends is
    // paused under it, and carries on from where it stopped.
    ad: {
        items: [],
        everyMs: 0,
        nextAt: 0,        // when the next break is due — NOT reset by a poll
        timer: null,      // the countdown to the next break, or — while one waits — its backstop
        active: false,
        index: 0,
        playTimer: null,  // backstop for one advert that stalls or never ends
        prepared: null,   // the next advert, made ready behind the one on screen ({node, url, index})…
        prepareTimer: null, // …and the timer that makes it (prepareAd)
        leaveTimer: null, // the backstop of a last advert left up for the content after it (takeTheAdDown)
        due: false,       // a break is waiting for the item on screen to end
        dueFor: 0,        // which item it waits for (when that item started)
        between: false,   // this break came between two items: the next one plays after it
        resumeMs: 0,      // what was left of the interrupted item
        contentPaused: false, // an advert has appeared and the organization's content is paused under it
        pendingData: null, // a manifest that arrived mid-break, applied afterwards
    },

    // A turned Fire TV's videos, decoded by the player itself (decodesTurnedVideo): the server's switch, and
    // whether the decoder's chunk has been loaded on this set.
    decodeTurnedVideo: false,
    decoderReady: false,

    // Whether this set decodes one video at a time (oneDecoderRemembered), and whether the next video is set up with the
    // stage's decoders let go first, to see whether it plays then (makeRoomFor).
    oneDecoder: false,
    roomTest: false,
};

state.oneDecoder = oneDecoderRemembered();

/** How long past its own end a break waits for the item on screen before it interrupts it after all: a
 *  video whose length reads wrong, or that stalls without ending, must not keep a brand off the wall. */
const BREAK_WAIT_SLACK_MS = 15000;

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

/** The code on the television, and its poll every POLL_MS. */
function showPairingCode(code, knownDevice) {
    el('pairing-code').textContent = code;
    setPairingInstruction(knownDevice);
    show('view-pairing');
    clearTimers();
    state.pollTimer = setInterval(pollPairStatus, POLL_MS);
}

/**
 * Show the pairing code.
 *
 * A code already on file and still alive is reused: reloading the page must not
 * cost a registration, both because the number on the TV should not keep
 * changing under the owner's fingers and because the endpoint is rate limited.
 *
 * `asked` says the server was asked about the code on file a moment ago (its poll said it is over), so it is not asked
 * again before a new one is fetched.
 */
async function startPairing(asked = false) {
    show('view-loading');

    const cachedCode = store.get(CODE_KEY);
    const cachedExpiry = Number(store.get(CODE_EXPIRES_KEY) || 0);
    const uuid = store.get(UUID_KEY);
    const secret = store.get(SECRET_KEY);

    if (cachedCode && secret && cachedExpiry > Date.now()) {
        showPairingCode(cachedCode, store.get(KNOWN_KEY) === '1');

        // Ask once straight away, not only after the first interval. This branch
        // is a REOPEN — the page is coming back with a code it already had — so
        // the owner may well have typed that code while the screen was off. The
        // token would be sitting on the server, and waiting a whole POLL_MS to
        // discover it is a blank screen for no reason.
        pollPairStatus();

        return;
    }

    // A code this set's own clock calls over may not be: the owner may have typed it while the set was off, its token
    // waiting on the server, or the set's clock is simply wrong. So the server is asked with the secret first, and a
    // code is replaced only once it says so (the review of 2026-10-15: registering in its place threw the waiting token
    // away, and the screen stayed paired to a token nobody held).
    if (!asked && uuid && secret) {
        const status = await askPairStatus(uuid, secret);

        if (status === 'paired') return;

        if (status === 'pending' && cachedCode) {
            showPairingCode(cachedCode, store.get(KNOWN_KEY) === '1');

            return;
        }
    }

    try {
        // The secret goes with the uuid: the server hands this set's own code back only to the set that holds it.
        const response = await fetch('/device/register', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
            body: JSON.stringify({ device_uuid: uuid, poll_secret: secret }),
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

        showPairingCode(data.code, !!data.known_device);

        // Its own code given back (the secret it sent is the one on file): the owner may have typed it already, so it is
        // asked about at once. A code minted a moment ago needs no such call: nobody can have claimed it.
        if (secret && data.poll_secret === secret) pollPairStatus();
    } catch {
        showError('No connection to the server. Check this TV’s internet: it keeps trying by itself.');
        setTimeout(startPairing, 10000);
    }
}

/**
 * Asks the server about this set's code, with its secret, and takes the token up the moment it is there. The status it
 * said — paired, pending, expired or unknown — or null when it could not be asked.
 */
async function askPairStatus(uuid, secret) {
    try {
        const query = new URLSearchParams({ device_uuid: uuid, poll_secret: secret });
        const response = await fetch(`/device/pair-status?${query}`, { headers: { Accept: 'application/json' } });
        if (!response.ok) return null;

        const data = await response.json();

        if (data.status === 'paired' && data.token) {
            store.set(TOKEN_KEY, data.token);
            state.token = data.token;
            store.remove(SECRET_KEY);
            store.remove(CODE_KEY);
            store.remove(CODE_EXPIRES_KEY);
            clearTimers();
            startPlayback();
        }

        return data.status ?? null;
    } catch {
        /* Offline for a moment: whoever asked tries again. */
        return null;
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

    const status = await askPairStatus(uuid, secret);

    // An unclaimed code eventually dies; fetch a fresh one so the TV never
    // shows a number that no longer works — the server has just been asked.
    if (status === 'expired' || status === 'unknown') {
        store.remove(CODE_KEY);
        store.remove(CODE_EXPIRES_KEY);
        startPairing(true);
    }
}

/* ── Playback ──────────────────────────────────────────────────────────── */

function startPlayback() {
    show('view-loading');
    // A set that may decode its own videos has the decoder ready before its first one comes.
    if (decoderWanted()) loadDecoder();
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
        state.decodeTurnedVideo = data.screen?.decode_turned_video === true;
        // The decoder is in before the first video is made: made without it, that video was a <video> for its whole
        // length, sideways on a turned Fire TV after every start (2026-10-28). A chunk that never comes holds the
        // screen DECODER_WAIT_MS at most, and its videos play as <video> until it does.
        if (decoderWanted() && !state.decoderReady) {
            await Promise.race([loadDecoder(), new Promise((resolve) => setTimeout(resolve, DECODER_WAIT_MS))]);
            if (token !== state.token) return;
        }

        // The worker marks an answer it served from its cache because the server could
        // not be reached (§15): the set is offline, and plays its timeline's entry for now.
        applyManifest(data, response.headers.get('X-Signage-Cached') === '1');
    } catch {
        /* Keep showing the last thing that worked. */
    }
}

/**
 * A manifest, live or from memory. A live one resets the clock offset and warms the cache
 * with everything the screen may need; a cached one plays its timeline's entry for now.
 */
function applyManifest(data, fromCache) {
    state.offline = fromCache;
    document.body.dataset.source = fromCache ? 'cache' : 'live';

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

    // Which entry of the timeline a cached manifest is playing — nothing on the glass says so (the owner's
    // rule); it is on the page for whoever checks a set.
    if (fromCache) document.body.dataset.entry = String(shown.entry);

    // Nothing changed: leave whatever is on screen alone rather than
    // re-rendering and making the TV flicker every poll. What is compared is
    // what would be SHOWN, whether it came live or from memory: the line dropping
    // — or coming back — with the same things due restarts nothing (a video is
    // not cut, a channel's rotation is not sent back to its start), while a new
    // entry of the timeline (lunch has begun) does.
    const key = JSON.stringify([
        shown.screen?.orientation ?? null,
        // The switch turned on or off plays the playlist again, the other way.
        shown.screen?.decode_turned_video === true,
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
 * online, the holding picture wherever nothing else is due — so its entry for this moment is taken (past its
 * end, the last one).
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

    return { ...data, items: base.items, blank: base.blank, ad_break: { ...(data.ad_break ?? {}), items: base.ads }, entry };
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
    // off by the organization's own content reappearing behind it. Hold the manifest and
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
    // no line, fails to: played from memory, black is better than yesterday's price. (Online, the next item
    // is a moment away, and cutting to black for that moment would be a flash.)
    const current = frontNode();

    if (current && state.offline && !state.items.some((item) => assetUrl(item) === current.dataset.item)) {
        finishFade();
        if (isVideoNode(current)) current.pause();
        layer(state.front).hidden = true;
        empty(layer(state.front));
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

/** A layer emptied: nothing in it, and nothing laid out in it unseen. */
function empty(target) {
    target.querySelectorAll('iframe').forEach(releasePageVideos);
    target.innerHTML = '';
    target.removeAttribute('data-page');
}

/**
 * An item put in the layer behind the one on screen. A page is laid out there at its full size, unseen (the player's
 * own style, `data-page`): its fonts, pictures and stage are in place when it comes on. Hidden like the rest, its frame
 * has no size at all, and its stage was scaled to nothing until the moment it came on (the owner's Fire TV Stick,
 * 2026-11-03).
 */
function putBehind(target, node) {
    empty(target);
    if (node.tagName === 'IFRAME') target.setAttribute('data-page', '');
    target.appendChild(node);
}

/**
 * Every switch fades (owner, 2026-11-04 — "by default sub per fade hi chiya tha aur woo field hata deni thi"): `element`
 * goes from `from` to `to` opacity in FADE_MS, steadily, over what was on screen, and `done` tidies up after it — then,
 * or at once when anything changes the screen before it is over (finishFade): the next switch never waits for this one,
 * and nothing depends on a `transitionend` that may never come. The swap itself happened already: the fade only shows
 * it gently.
 */
function fade(element, from, to, done) {
    finishFade();

    element.style.transition = 'none';
    element.style.opacity = String(from);
    // The starting opacity is drawn before the change, or there is nothing to fade from.
    void element.offsetWidth;
    element.style.transition = 'opacity ' + FADE_MS + 'ms linear';
    element.style.opacity = String(to);

    state.fade = { element, done, timer: setTimeout(finishFade, FADE_MS), endsAt: Date.now() + FADE_MS };
}

/** The fade under way, if any, ended where it was going, and tidied up after. */
function finishFade() {
    const under = state.fade;
    if (!under) return;

    state.fade = null;
    clearTimeout(under.timer);
    under.element.style.transition = '';
    under.element.style.opacity = '';
    under.element.style.zIndex = '';
    under.done();
}

function stopPlayback() {
    if (state.itemTimer) clearTimeout(state.itemTimer);
    state.itemTimer = null;
    state.itemDurationMs = 0;
    state.current = null;
    finishFade();
    clearPrepared();
    // An advert must not be left hanging over content that is no longer there.
    // The hourly countdown keeps running, so it comes back when the content does.
    endAdBreak({ resume: false });
    ['a', 'b'].forEach((which) => {
        empty(layer(which));
        layer(which).hidden = true;
    });
}

function buildElement(item) {
    const node = buildNode(item);

    // Which file it is: render() takes something that is no longer on the list off the glass by it.
    node.dataset.item = assetUrl(item);
    if (item.type === 'video') {
        node.signagePixels = itemPixels(item);
        // A second video set up beside one on the stage: should the page fall now, the set decodes one at a time.
        if (decodersOnStage(node).length) markTrying(node);
    }
    // Asked at once, so none of the events it waits for is missed, however late the element is put up.
    readiness(node, item);

    return node;
}

/**
 * When an element can come on with nothing more to wait for: a picture loaded and decoded (decoded on the glass, it
 * was a black frame there now and then), a video's first frame drawn, a page loaded. True then, false for a file that
 * will not play. Kept on the element, so one made ready ahead and one made at the switch are waited for alike.
 */
function readiness(node, item) {
    if (node.signageReady) return node.signageReady;

    node.signageReady = new Promise((resolve) => {
        const fail = () => resolve(false);

        if (node.error) {
            fail();

            return;
        }

        if (item.type === 'video') {
            if (node.readyState >= 2) {
                resolve(true);

                return;
            }
            node.addEventListener('loadeddata', () => resolve(true), { once: true });
            node.addEventListener('error', fail, { once: true });

            return;
        }

        if (item.type === 'html') {
            // A frame fires `load` for the empty document it starts with, before its page is even asked for: only the
            // page's own load counts.
            const loaded = () => {
                if (!node.getAttribute('src')) return;

                node.removeEventListener('load', loaded);
                watchPageVideos(node);
                decodePageVideos(node).then(() => resolve(true));
            };

            node.addEventListener('load', loaded);
            node.addEventListener('error', fail, { once: true });

            return;
        }

        // A picture the browser cannot decode still comes on as before: decode() is the head start, never the gate.
        const decoded = () => node.decode().then(() => resolve(true), () => resolve(true));

        if (node.complete) {
            if (node.naturalWidth > 0) decoded();
            else fail();

            return;
        }
        node.addEventListener('load', decoded, { once: true });
        node.addEventListener('error', fail, { once: true });
    });

    return node.signageReady;
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
        // It waits to be told it is on screen (startPage) before its animations start.
        frame.name = HELD_PAGE_NAME;
        frame.setAttribute('scrolling', 'no');
        // Never the keyboard's: a remote's keys belong to the player (the Samsung app's RETURN, player/exit-question.js).
        frame.tabIndex = -1;
        frame.setAttribute('sandbox', kept ? 'allow-scripts allow-same-origin' : 'allow-scripts');

        // Beside a video that keeps the set's one decoder its own videos come without their files (holdsPageVideos).
        const hold = kept && holdsPageVideos();

        fetch(url)
            .then((response) => {
                if (!response.ok) throw new Error('page ' + response.status);

                frame.src = kept ? PAGE_PATH + '?src=' + encodeURIComponent(url) + (hold ? '&hold=1' : '') : url;
            })
            .catch(() => frame.dispatchEvent(new Event('error')));

        return frame;
    }

    if (item.type === 'video') {
        // A turned Fire TV's video is decoded by the player itself, which draws it where the stage's turn reaches.
        const video = document.createElement(decodesTurnedVideo(item) ? 'decoded-video' : 'video');
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

/** A video on the stage, played by a <video> or by the player's own decoder: both are paused, timed and ended alike. */
function isVideoNode(node) {
    return Boolean(node) && (node.tagName === 'VIDEO' || node.tagName === 'DECODED-VIDEO');
}

/**
 * Whether this set may decode a turned screen's videos itself (docs/TV-ORIENTATION-SPEC.md §11, §12): a Fire TV,
 * whose WebView puts a <video> on a hardware plane the stage's turn never reaches, with WebCodecs (Chrome 94; the
 * player's floor is Chrome 79, so it is asked first), and the server's switch on — or this set's own override
 * (DECODE_KEY), which also lets a browser test try it anywhere.
 */
function decoderWanted() {
    const own = store.get(DECODE_KEY);

    if (own === '0') return false;
    if (typeof window.VideoDecoder !== 'function' || typeof window.EncodedVideoChunk !== 'function') return false;
    if (own === '1') return true;

    return state.decodeTurnedVideo && /\bAFT/.test(navigator.userAgent);
}

/** The decoder's own chunk (mp4box.js and <decoded-video>), loaded once, and only on a set that may use it. */
let decoderLoading = null;

function loadDecoder() {
    if (decoderLoading) return decoderLoading;

    decoderLoading = import('./player/decoded-video.js')
        .then((module) => {
            module.registerDecodedVideo();
            state.decoderReady = true;
        })
        .catch(() => {
            // Asked for again on the next poll; meanwhile every video plays as a <video>.
            decoderLoading = null;
        });

    return decoderLoading;
}

/** Whether this set decodes its videos itself now: on a screen not landscape, once the decoder is in (decoderWanted). */
function decodesOnThisScreen() {
    const orientation = el('stage').dataset.orientation || 'landscape';

    return state.decoderReady && orientation !== 'landscape' && decoderWanted();
}

/** A video this set decodes itself: an MP4 (mp4box.js reads MP4 alone), where the set decodes (decodesOnThisScreen). */
function decodesTurnedVideo(item) {
    return String(item.mime || '').toLowerCase() === 'video/mp4' && decodesOnThisScreen();
}

/**
 * An Ad Builder page's own videos on a set that decodes them (docs/TV-ORIENTATION-SPEC.md §12.16): every MP4 <video>
 * the page holds — a background layer's, one on its stage — is played by the player's decoder instead, a
 * <decoded-video> put in its place inside the page, looping as the page's video did, and it turns with the page's
 * stage like a picture. Left a <video>, the Fire TV took it to its hardware plane: sideways on a portrait screen, and
 * black on a flipped one (the owner's Fire TV Stick, 2026-11-03). Only where the frame is the player's own origin (the
 * worker keeps the set, §15); anywhere else the page plays as it is. Done as the page is made ready, so its first frames
 * are drawn by the time it comes on (at most PAGE_VIDEO_WAIT_MS is waited), and they move when it does (startPage). A
 * video the decoder cannot play is given back to the page (putBackPageVideo).
 */
function decodePageVideos(frame) {
    let page = null;
    try {
        page = frame.contentDocument;
    } catch {
        page = null;
    }
    if (!page || !page.defaultView || !decodesOnThisScreen()) return Promise.resolve();

    const swaps = [];
    page.querySelectorAll('video').forEach((video) => {
        const src = video.getAttribute('src') || '';
        if (!/\.mp4(?:[?#]|$)/i.test(src)) return;

        // Read now: a style read once the video is out of the page is the defaults' (fill), which stretched the picture.
        const look = page.defaultView.getComputedStyle(video);
        const fit = 'object-fit:' + look.objectFit + ';object-position:' + look.objectPosition + ';';
        const decoded = document.createElement('decoded-video');
        decoded.className = video.className;
        // The page's frame, shadow, filter and flip stay on it; a rounded corner clips the picture as the video's did.
        decoded.setAttribute('style', (video.getAttribute('style') || '') + ';overflow:hidden;');
        decoded.loop = true;
        decoded.src = src;
        // The page's own <video> lets its decoder go, so only one is ever busy with the file.
        video.pause();
        video.removeAttribute('src');
        video.load();
        video.replaceWith(decoded);
        // Its canvas, made as it went in, fills it the way the video was fitted.
        if (decoded.firstElementChild) {
            decoded.firstElementChild.setAttribute('style', 'display:block;width:100%;height:100%;' + fit);
        }
        swaps.push({ decoded, video, src });
    });

    // Added to: the videos a page was given back once the stage let its decoders go are decoded then (giveBackPageVideos).
    frame.signageVideos = (frame.signageVideos || []).concat(swaps);
    if (swaps.length === 0) return Promise.resolve();

    const firstFrames = swaps.map((swap) => new Promise((resolve) => {
        swap.decoded.addEventListener('loadeddata', resolve, { once: true });
        swap.decoded.addEventListener('error', () => {
            putBackPageVideo(frame, swap);
            resolve();
        }, { once: true });
    }));

    return Promise.race([Promise.all(firstFrames), new Promise((resolve) => setTimeout(resolve, PAGE_VIDEO_WAIT_MS))]);
}

/** A page video the decoder could not play, given back to the page as its own <video>: it plays with the page. */
function putBackPageVideo(frame, swap) {
    if (!swap.decoded.isConnected) return;

    swap.decoded.replaceWith(swap.video);
    swap.video.setAttribute('src', swap.src);
    if (frame.signageStarted) swap.video.play().catch(() => {});
}

/**
 * A page going (emptied out of its layer, let go for a break): its decoded videos let go of their decoders. Its document
 * going would not tell them: the elements inside a frame that goes are never told they left.
 */
function releasePageVideos(frame) {
    (frame.signageVideos || []).forEach(({ decoded }) => decoded.remove());
    frame.signageVideos = [];
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
    // A fade still under way ends now: the layer it was fading from is the one this item goes into.
    finishFade();

    const item = state.items[state.index % state.items.length];

    // The same video again — a playlist of one, the same file twice running — plays again in its own element: a second
    // copy beside it took a second decoder, which a set with one cannot give (a 4K video alone on the owner's FB 24 Deli
    // TVs: its last picture frozen, then black, every fifty seconds, 2026-11-05).
    const again = sameVideoOnScreen(item);
    if (again) {
        replay(again, item);

        return;
    }

    const target = state.front === 'a' ? 'b' : 'a';
    const incoming = layer(target);
    const prepared = state.prepared;
    let node;
    let madeAtTheSwitch = false;

    // The next item made ready behind this one (prepare): it comes on the moment it is asked for. Anything else made
    // ready — the playlist changed, a channel's next pass took another ad — goes.
    if (prepared && prepared.url === assetUrl(item) && prepared.node.parentElement === incoming) {
        state.prepared = null;
        if (state.prepareTimer) clearTimeout(state.prepareTimer);
        state.prepareTimer = null;
        node = prepared.node;
    } else {
        clearPrepared();
        madeAtTheSwitch = true;
        const testing = makeRoomFor(item);
        node = buildElement(item);
        if (testing) passTheRoomTest(node);
        putBehind(incoming, node);
    }
    state.current = node;

    // How long it is on screen, counted from when it came on: a first frame or a page slow to come took its seconds
    // from the end of it (2026-11-02). Counted from now too, until then, as the backstop of one that never comes.
    const seconds = item.type === 'video' ? item.duration + 5 : Math.max(1, item.duration);

    const reveal = () => {
        // A node no longer on the page — replaced by the next item before it finished
        // loading, or torn down with its playlist — can still finish loading. Swapping the
        // layers for it would hide and empty the layer the CURRENT item was just put in: a
        // black screen until the next item.
        if (!node.isConnected) return;
        finishFade();

        // A page whose own videos waited for the stage's decoders (watchPageVideos): the stage lets them go, its last
        // pictures held, and the page has them.
        if (node.signageHeld && node.signageHeld.length) {
            decodersOnStage(node).forEach(holdStill);
            giveBackPageVideos(node);
        }

        const outgoing = layer(state.front);
        const previous = outgoing.hidden ? null : outgoing.firstElementChild;
        const adUp = !state.ad.active && !el('layer-ad').hidden;

        // Whatever was on screen is now behind the new item. A hidden <video> that is
        // still playing goes on DECODING — for the whole length of whatever replaced
        // it — which on a cheap television is heat, fan noise and a stutter in the
        // picture people can actually see. Stop it, then let it go.
        if (isVideoNode(previous)) previous.pause();

        const letGo = () => {
            outgoing.hidden = true;
            empty(outgoing);
        };

        incoming.hidden = false;
        state.front = target;

        // The item after this one is made ready behind it once the switch is over, the item before gone (prepareNext).
        // Made while that was still on the glass (its fade a moment from its end, or this video, set up at the switch,
        // still waiting for its first frame), it went into the layer still showing it, which the switch then emptied;
        // or it found the video faded from still on the stage, the very file it was when two videos take turns, and
        // was made only at its own switch, as was every switch after (2026-11-06).
        const prepareTheNext = () => {
            if (state.current === node && node.isConnected) prepareNext(node);
        };

        const present = () => {
            if (adUp) {
                // A break's last advert stayed up until the item after it was ready: it goes in the same moment, or
                // fades away over it, the swap underneath it unseen.
                letGo();
                letTheAdGo();
                prepareTheNext();
            } else if (previous) {
                // Over the item before, which goes once the new one is fully in (the first item has nothing to fade from).
                incoming.style.zIndex = '1';
                fade(incoming, 0, 1, () => {
                    letGo();
                    prepareTheNext();
                });
            } else {
                letGo();
                prepareTheNext();
            }
        };

        // A video set up at the switch can take a moment on screen to show its first picture (a 4K one after a break on
        // the owner's Fire TV Stick: the advert went over black for a third of a second, 2026-11-05). What is on the glass
        // stays until it does, never longer than SHOWS_WITHIN_MS.
        if (madeAtTheSwitch && node.tagName === 'VIDEO' && (adUp || previous)) {
            if (!adUp) {
                incoming.style.zIndex = '1';
                incoming.style.opacity = '0.01';
            }
            whenItShows(node).then(() => {
                if (state.current === node && node.isConnected) present();
                else incoming.style.opacity = '';
            });
        } else {
            present();
        }
        if (node.tagName === 'IFRAME') startPage(node);
        startItemTimer(seconds * 1000);
        preloadNext();
    };

    // The same for a file that fails after it was replaced: skipping then would cut short
    // whatever is playing now, which is not the file that broke.
    let failed = false;
    const broken = () => {
        if (failed || !node.isConnected) return;
        failed = true;

        // A video that found no decoder beside the stage's is set up once more with them let go (makeRoomFor): a file
        // that then plays says the set decodes one at a time; one that fails again is a file that will not play.
        if (couldHaveNoDecoder(node)) {
            state.roomTest = true;
            playCurrent();

            return;
        }
        skipBroken();
    };

    if (item.type === 'video') {
        // A video advances when it actually ends; the duration is only a backstop
        // for a file that stalls or has no end event. A video the backstop has already moved on from — still on the
        // glass while the next one loads — ends without moving the loop on again: that skipped the next item, over
        // and over (a first frame 6 s late, 2026-11-02).
        node.addEventListener('ended', () => {
            if (state.current === node) advance();
        }, { once: true });
        node.addEventListener('error', broken, { once: true });
        node.play().catch(() => {});
    }
    startItemTimer(seconds * 1000);

    // Whatever is on screen stays there until this can take its place: a hold, never a black screen.
    readiness(node, item).then((ready) => (ready ? reveal() : broken()));
}

/** An Ad Builder page on screen now: its animations and its videos start (the compiler's boot and hold scripts wait for
 *  this word), and so do the videos the player decodes for it (decodePageVideos). */
function startPage(frame) {
    frame.signageStarted = true;
    (frame.signageVideos || []).forEach(({ decoded }) => {
        if (decoded.isConnected) decoded.play();
    });

    try {
        if (frame.contentWindow) frame.contentWindow.postMessage(PAGE_START_MESSAGE, '*');
    } catch {
        /* A frame torn down meanwhile has nothing to start. */
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

/** Warm the browser cache for the next picture at once, so it is in by the time it is made ready (prepare). */
function preloadNext() {
    if (state.items.length < 2) return;
    const next = state.items[(state.index + 1) % state.items.length];
    // Videos stream and ad pages fetch their own pieces; only a plain picture is worth warming.
    if (next.type === 'video' || next.type === 'html') return;
    const warm = new Image();
    warm.src = assetUrl(next);
}

/**
 * The next item, made ready in the layer behind this one PREPARE_AHEAD_MS before this one ends, so the switch is a
 * swap (playCurrent): a picture loaded and decoded, a page loaded and laid out (it starts when it comes on, startPage),
 * a video's file read, its decoder set up and its first frame drawn, paused. Made only at the switch, the screen held
 * the item before for as long as that took, or went black (the owner's Fire TV Stick, 2026-11-02: 0.53 to 0.62 s
 * between two turned videos, docs/TV-ORIENTATION-SPEC.md §12.14; and on every kind of switch, §12.15). The same item
 * again — a one-item playlist — is made ready the same way.
 *
 * A break due when this item ends plays first, and the item after it is made ready during its last advert
 * (prepareAfterTheBreak). Called with the item on screen, once its switch is over and the item before has gone
 * (playCurrent), as it plays again (replay) or as it comes back after a break (resumeContent).
 */
function prepareNext(node) {
    if (state.prepareTimer) clearTimeout(state.prepareTimer);
    state.prepareTimer = null;
    if (state.items.length === 0) return;

    const item = state.items[state.index % state.items.length];
    const next = state.items[(state.index + 1) % state.items.length];

    prepare(next, timeLeftOf(node, item) - PREPARE_AHEAD_MS,
        () => !state.ad.due && !state.ad.active && state.current === node && node.isConnected);
}

/**
 * How long the item on screen still has, in ms: a video's own length less what it has played (a fragmented file
 * says its length only once read through: the server's then); anything else, what its countdown has left.
 */
function timeLeftOf(node, item) {
    if (isVideoNode(node)) {
        const length = Number.isFinite(node.duration) && node.duration > 0 ? node.duration : item.duration;

        return (length - (node.currentTime || 0)) * 1000;
    }

    return state.itemDurationMs - (Date.now() - state.itemStartedAt);
}

/** `item`, made ready in the layer behind the one on screen in `inMs`, if `stillWanted()` says so then. */
function prepare(item, inMs, stillWanted) {
    if (state.prepareTimer) clearTimeout(state.prepareTimer);

    // Never into a layer still fading away: the item made ready would go with it.
    const fadeLeft = state.fade ? state.fade.endsAt - Date.now() : 0;

    state.prepareTimer = setTimeout(() => {
        state.prepareTimer = null;
        if (state.prepared || !stillWanted()) return;
        // The fade this waited for ends now, before the stage is read: a moment from its end, the item it fades from is
        // still on the stage until then, and may be the very video to make ready (two videos taking turns).
        finishFade();
        // A video that cannot have a decoder beside the stage's is made at the switch instead (playCurrent).
        if (!mayMakeReadyBeside(item)) return;

        const ready = buildElement(item);
        // Paused before its first frame: it waits on that frame, unplayed, until its turn.
        if (isVideoNode(ready)) ready.pause();
        ready.addEventListener('error', () => {
            // No decoder beside the stage's: at the switch it is set up with theirs let go (makeRoomFor).
            if (couldHaveNoDecoder(ready)) state.roomTest = true;
            if (state.prepared && state.prepared.node === ready) clearPrepared();
        }, { once: true });
        putBehind(layer(state.front === 'a' ? 'b' : 'a'), ready);
        state.prepared = { node: ready, url: assetUrl(item) };
    }, Math.max(0, inMs, fadeLeft));
}

/** Let an item made ready for later go, and the timer that would make one. */
function clearPrepared() {
    if (state.prepareTimer) clearTimeout(state.prepareTimer);
    state.prepareTimer = null;

    const prepared = state.prepared;
    state.prepared = null;
    const behind = prepared && prepared.node.parentElement;
    if (behind && behind !== layer(state.front)) empty(behind);
}

/* ── One decoder at a time ─────────────────────────────────────────────────
 *
 * A video made ready behind the one on screen (prepare) takes a second decoder while the first still plays, and a set
 * may have only one — for a 4K video, most have one. The owner's FB 24 Deli TVs (an ELC Android TV, 2026-11-05) fell
 * every fifty seconds, black, from the first day they ran that: a second 4K decoder took their WebView down; the
 * Philips TV in the office said "video decoder reinitialization failed" and froze on the last picture for sixteen
 * seconds. So: the same video again plays again in its own element (replay); a video is set up beside another only
 * when the set can decode both — neither bigger than full HD, and the set never showed otherwise — else at the switch,
 * the stage's decoders let go first, their last pictures held as stills (holdStill); and a page's own videos wait the
 * same way (watchPageVideos). A set learns it from a video that plays only once the stage has let go (passTheRoomTest),
 * or from its page falling while a second one was being set up (TRYING_KEY), and keeps it for a week.
 */

/** Whether this set decodes one video at a time: learned within the week, or its page just fell setting a second one up. */
function oneDecoderRemembered() {
    const tried = Number(store.get(TRYING_KEY)) || 0;
    store.remove(TRYING_KEY);
    if (tried && Date.now() - tried < FELL_WITHIN_MS) store.set(ONE_DECODER_KEY, String(Date.now()));

    const at = Number(store.get(ONE_DECODER_KEY)) || 0;

    return at > 0 && Date.now() - at < ONE_DECODER_MS;
}

function rememberOneDecoder() {
    state.oneDecoder = true;
    store.set(ONE_DECODER_KEY, String(Date.now()));
}

/** A second video being set up beside one on the stage, said until it has its first frame or has failed. */
function markTrying(node) {
    store.set(TRYING_KEY, String(Date.now()));
    const done = () => store.remove(TRYING_KEY);
    node.addEventListener('loadeddata', done, { once: true });
    node.addEventListener('error', done, { once: true });
}

/** The pixels of the video an item plays, as the server measured the file: 0 when it does not say, or for no video. */
function itemPixels(item) {
    return item && item.type === 'video' ? (Number(item.width) || 0) * (Number(item.height) || 0) : 0;
}

/** A page's own <video> elements that hold a file, when the page is the player's to read (the worker keeps the set). */
function pageVideos(frame) {
    try {
        const page = frame.contentDocument;

        return page ? Array.from(page.querySelectorAll('video')).filter((video) => video.getAttribute('src')) : [];
    } catch {
        return [];
    }
}

/** Whether an element on the stage holds a video decoder now: a video with its file, a page with videos of its own. */
function holdsADecoder(node) {
    if (node.tagName === 'VIDEO') return !node.error && Boolean(node.getAttribute('src'));
    if (node.tagName === 'DECODED-VIDEO') return !node.released;
    if (node.tagName === 'IFRAME') return pageVideos(node).length > 0 || (node.signageVideos || []).some(({ decoded }) => !decoded.released);

    return false;
}

/** Every element on the stage — both content layers and the advert's — that holds a decoder, but `except`. */
function decodersOnStage(except) {
    const held = [];
    ['layer-a', 'layer-b', 'layer-ad'].forEach((id) => {
        const holder = el(id);
        if (!holder) return;
        Array.from(holder.children).forEach((node) => {
            if (node !== except && holdsADecoder(node)) held.push(node);
        });
    });

    return held;
}

/** The pixels of the biggest video an element on the stage decodes. */
function pixelsOf(node) {
    if (node.tagName === 'VIDEO') return Math.max(node.videoWidth * node.videoHeight || 0, node.signagePixels || 0);
    if (node.tagName === 'DECODED-VIDEO') return node.signagePixels || 0;
    if (node.tagName === 'IFRAME') return Math.max(0, ...pageVideos(node).map((video) => video.videoWidth * video.videoHeight));

    return 0;
}

/** Whether `item` (a video, or none for a page's own) must wait for `onStage` to let its decoders go. */
function oneAtATime(item, onStage) {
    return state.oneDecoder || itemPixels(item) > BIG_VIDEO_PIXELS || onStage.some((node) => pixelsOf(node) > BIG_VIDEO_PIXELS);
}

/** Whether `item` may be made ready behind the stage now (prepare, prepareAd): anything but a video waiting its turn. */
function mayMakeReadyBeside(item) {
    if (item.type !== 'video') return true;

    const onStage = decodersOnStage();
    if (onStage.length === 0) return true;
    // The same file again plays again in its own element (replay), never as a copy beside it.
    if (onStage.some((node) => node.tagName === 'VIDEO' && node.dataset.item === assetUrl(item))) return false;

    return !state.roomTest && !oneAtATime(item, onStage);
}

/**
 * Before `item` is set up at the switch: on a set that decodes one at a time, everything on the stage lets its decoder
 * go, its last picture held. True when this is the test of a video that found no decoder beside them (roomTest).
 */
function makeRoomFor(item) {
    const testing = state.roomTest;
    state.roomTest = false;
    if (item.type !== 'video') return false;

    const onStage = decodersOnStage();
    if (onStage.length === 0) return false;
    if (testing || oneAtATime(item, onStage)) onStage.forEach(holdStill);

    return testing;
}

/** A video set up with the stage's decoders let go, after it found none beside them: playing now, the set has one. */
function passTheRoomTest(node) {
    node.signageRoomTest = true;
    node.addEventListener('loadeddata', rememberOneDecoder, { once: true });
}

/** Whether a video failed for want of a decoder beside those on the stage, and has not been set up without them yet. */
function couldHaveNoDecoder(node) {
    if (!isVideoNode(node) || node.signageRoomTest || !node.error) return false;
    // A decoder that would not start (MEDIA_ERR_DECODE), or the player's own decoder's refusal; a file the set cannot
    // read at all says so otherwise.
    const failedToDecode = node.tagName === 'DECODED-VIDEO' ? /^decoder/.test(node.error.message || '') : node.error.code === 3;

    return failedToDecode && decodersOnStage(node).length > 0;
}

/**
 * The element lets its decoder go and keeps its picture: a <video>'s last frame drawn on a canvas put in its place, a
 * decoded video's own canvas kept as it is, a page's own videos stopped (its background stays). The next video then
 * has the set's decoder, and the glass is never black while it starts.
 */
function holdStill(node) {
    if (node.tagName === 'VIDEO') {
        stillInPlace(node, 'display:block;width:100%;height:100%;object-fit:contain;background:#000;');

        return;
    }

    if (node.tagName === 'DECODED-VIDEO') {
        node.letGo();

        return;
    }

    if (node.tagName === 'IFRAME') {
        // A page's own videos the same way, in the page: their pictures stay where they were, fitted as they were.
        (node.signageVideos || []).forEach(({ decoded }) => decoded.isConnected && decoded.letGo());
        pageVideos(node).forEach((video) => {
            const look = video.ownerDocument.defaultView.getComputedStyle(video);
            stillInPlace(video, (video.getAttribute('style') || '') + ';object-fit:' + look.objectFit + ';object-position:' + look.objectPosition + ';');
        });
    }
}

/** A <video> replaced by a canvas of its last frame, styled `style`, and let go of its file and its decoder. */
function stillInPlace(video, style) {
    const still = video.ownerDocument.createElement('canvas');
    const scale = Math.min(1, 1920 / Math.max(video.videoWidth || 1, video.videoHeight || 1));
    still.width = Math.max(1, Math.round((video.videoWidth || 16) * scale));
    still.height = Math.max(1, Math.round((video.videoHeight || 9) * scale));
    still.className = video.className;
    still.setAttribute('style', style);
    still.dataset.item = video.dataset.item || '';
    still.dataset.still = '1';
    try {
        const context = still.getContext('2d');
        context.drawImage(video, 0, 0, still.width, still.height);
        // One pixel read back makes the canvas draw now, while the video still has its picture: left for the next
        // paint, the draw found the video already let go, and the still was black (the Philips TV, 2026-11-05).
        context.getImageData(0, 0, 1, 1);
    } catch {
        /* A picture that cannot be read back: the still is black for the moment the next video takes. */
    }
    video.pause();
    video.replaceWith(still);
    video.removeAttribute('src');
    video.load();
}

/**
 * A page made ready while videos on the stage keep their decoders: on a set that decodes one at a time its own videos
 * wait, their files taken out, and get them back when it comes on (playCurrent, giveBackPageVideos); elsewhere they are
 * set up beside them, and one that finds no decoder waits the same way.
 */
function watchPageVideos(frame) {
    // The videos the worker sent without their files (holdsPageVideos) wait for theirs.
    try {
        const page = frame.contentDocument;
        (page ? Array.from(page.querySelectorAll('video[data-signage-src]')) : []).forEach((video) => {
            (frame.signageHeld = frame.signageHeld || []).push({ video, src: video.getAttribute('data-signage-src'), failed: false });
            video.removeAttribute('data-signage-src');
        });
    } catch {
        /* A page that is not the player's to read plays as it is. */
    }

    const videos = pageVideos(frame);
    if (videos.length === 0) return;

    const onStage = decodersOnStage(frame);
    if (onStage.length === 0) return;

    const hold = (video) => {
        (frame.signageHeld = frame.signageHeld || []).push({ video, src: video.getAttribute('src'), failed: Boolean(video.error) });
        video.pause();
        video.removeAttribute('src');
        video.load();
    };

    if (oneAtATime(null, onStage)) {
        videos.forEach(hold);

        return;
    }

    videos.forEach((video) => {
        markTrying(video);
        video.addEventListener('error', () => {
            if (video.error && video.error.code === 3 && decodersOnStage(frame).length) hold(video);
        }, { once: true });
    });
}

/** Whether a page made ready now has its videos sent without their files: beside a video that keeps the one decoder. */
function holdsPageVideos() {
    const onStage = decodersOnStage();

    return onStage.length > 0 && oneAtATime(null, onStage);
}

/** A page's own videos that waited for the stage, given their files back (and decoded where the set decodes them). */
function giveBackPageVideos(frame) {
    const held = frame.signageHeld || [];
    frame.signageHeld = [];

    held.forEach(({ video, src, failed }) => {
        // One that had found no decoder plays now: the set decodes one at a time.
        if (failed) video.addEventListener('loadeddata', rememberOneDecoder, { once: true });
        // Its file given back, it loads; the page's own start plays it (startPage, after this).
        video.setAttribute('src', src);
        if (frame.signageStarted) video.play().catch(() => {});
    });

    if (held.length) {
        decodePageVideos(frame).then(() => {
            if (frame.signageStarted) (frame.signageVideos || []).forEach(({ decoded }) => decoded.isConnected && decoded.play());
        });
    }
}

/**
 * When a video put up shows its first picture: its first frame handed to the screen (requestVideoFrameCallback, Chrome
 * 83), or at once where the set cannot say — and SHOWS_WITHIN_MS at the most.
 */
function whenItShows(node) {
    if (typeof node.requestVideoFrameCallback !== 'function') return Promise.resolve();

    return new Promise((resolve) => {
        const timer = setTimeout(resolve, SHOWS_WITHIN_MS);
        node.requestVideoFrameCallback(() => {
            clearTimeout(timer);
            resolve();
        });
    });
}

/** The video on screen, when it is `item`'s own file and can simply play again (replay). */
function sameVideoOnScreen(item) {
    if (item.type !== 'video' || layer(state.front).hidden) return null;

    const front = frontNode();
    if (!front || front.tagName !== 'VIDEO' || front.error || !front.getAttribute('src')) return null;

    return front.dataset.item === assetUrl(item) ? front : null;
}

/** The video on screen, from its start once more, as the item it is again: no second copy, no second decoder. */
function replay(node, item) {
    clearPrepared();
    state.current = node;
    // A break's last advert stayed up for the item after it: this is it.
    if (!state.ad.active && !el('layer-ad').hidden) letTheAdGo();

    node.addEventListener('ended', () => {
        if (state.current === node) advance();
    }, { once: true });
    try {
        node.currentTime = 0;
    } catch {
        /* A file that cannot seek plays on from where it is. */
    }
    node.play().catch(() => {});
    startItemTimer((item.duration + 5) * 1000);
    preloadNext();
    prepareNext(node);
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

    // A break that came due while the item before was on screen starts now, between the two; the next item
    // plays after it, from its start.
    if (state.ad.due) {
        breakBetweenItems();

        return;
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
 * Every so often the brand's adverts play between two of the organization's items: a break that comes due
 * while an ad, a picture or a video is on screen waits for it to end (owner, 2026-10-23), so nothing is cut
 * in the middle — at most it waits one item, five minutes. Only an item that never ends — a video whose
 * length reads wrong, one that stalls — is paused under the break once its time is well past, and carries
 * on from exactly where it stopped.
 *
 * The clock is kept here rather than on the server because the server has no way to
 * reach a television between polls. It still decides everything that matters: which
 * adverts, how often, and whether at all. The player only counts — the same as it
 * already counts an image's ten seconds.
 *
 * Three things this is careful about, because an organization's wall must not misbehave:
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

    if (items.length === 0 || everyMs === 0) {
        if (state.ad.timer) clearTimeout(state.ad.timer);
        state.ad.timer = null;
        state.ad.due = false;
        state.ad.nextAt = 0;
        clearPreparedAd();

        return;
    }

    // A break waiting for the item on screen to end keeps its backstop: the poll changes nothing of it.
    if (state.ad.due) return;

    if (state.ad.timer) clearTimeout(state.ad.timer);
    state.ad.timer = null;

    // Warm the still adverts NOW rather than when the break starts. An advert that
    // has not arrived by its moment lets the whole break pass in silence — the
    // backstop timer ends it and the organization's content simply carries on — which on a
    // slow organization connection is a brand paying for nothing. Videos are left to stream.
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

    if (isVideoNode(node) && Number.isFinite(node.duration) && node.duration > 0) {
        return Math.max(0, (node.duration - node.currentTime) * 1000);
    }

    if (! state.itemDurationMs) return 0;

    return Math.max(0, state.itemDurationMs - (Date.now() - state.itemStartedAt));
}

/** Is the organization's content on the wall? Not a dark screen, an empty playlist, or a set still pairing. */
function contentOnTheWall() {
    return ! el('view-content').hidden
        && ! document.body.classList.contains('closed')
        && state.items.length > 0
        && frontNode() !== null;
}

function startAdBreak() {
    state.ad.timer = null;

    // Nothing to interrupt: skip this break rather than advertising to nobody, and wait for the next one.
    if (state.ad.active || state.ad.items.length === 0 || ! contentOnTheWall()) {
        scheduleNextBreak();

        return;
    }

    // Nothing is cut in the middle: the break waits for the item on screen to end, and starts between it
    // and the next one (advance).
    state.ad.due = true;
    waitForTheItemOnScreen();
}

/**
 * The backstop of a break waiting for the item on screen: once that item is well past its own end, it is
 * interrupted after all. A video's reported length is not always the truth — a streamed file can report one
 * that grows as it downloads, and a stalled one never ends — and a break that waited for ever would be a brand
 * paying for nothing, on a television that looked perfectly healthy. An item that came on meanwhile (a new
 * manifest started the loop again) is waited for in its turn.
 *
 * Its first advert is made ready meanwhile, behind the item on screen, so the break starts with a swap.
 */
function waitForTheItemOnScreen() {
    if (state.ad.timer) clearTimeout(state.ad.timer);

    const remaining = remainingOnScreen();
    prepareAd(0, remaining - PREPARE_AHEAD_MS, () => state.ad.due && ! state.ad.active);

    state.ad.dueFor = state.itemStartedAt;
    state.ad.timer = setTimeout(() => {
        state.ad.timer = null;
        if (! state.ad.due) return;

        if (state.itemStartedAt !== state.ad.dueFor) {
            waitForTheItemOnScreen();

            return;
        }

        state.ad.due = false;

        // The screen went dark or empty while the break waited: it waits for the next one.
        if (! contentOnTheWall()) {
            scheduleNextBreak();

            return;
        }

        state.ad.active = true;
        state.ad.index = 0;
        playAd();
    }, remaining + BREAK_WAIT_SLACK_MS);
}

/** The break that waited: the item before it has ended, and the next one plays once it is over (endAdBreak). */
function breakBetweenItems() {
    if (state.ad.timer) clearTimeout(state.ad.timer);
    state.ad.timer = null;
    // The item before has ended: its own backstop (a video's) must not move the loop on under the break.
    if (state.itemTimer) clearTimeout(state.itemTimer);
    state.itemTimer = null;
    // Nothing waits behind the whole break with a decoder of its own: the next item is made ready during its last advert.
    clearPrepared();
    state.ad.due = false;
    state.ad.between = true;
    state.ad.active = true;
    state.ad.index = 0;
    playAd();
}

/**
 * Build the advert first, pause the organization's content only once it is ready to show.
 *
 * The other order — pause, then load — leaves a frozen frame on the wall for as long
 * as the file takes to arrive. And the advert before it stays up until then: emptied first, the layer was black
 * between two adverts for 0.23 to 0.35 s (the owner's Fire TV Stick, 2026-11-02). The next one is made ready behind
 * it (prepareAd), so it is usually ready at once.
 */
function playAd() {
    const item = state.ad.items[state.ad.index];

    if (! item) {
        endAdBreak({ resume: true });

        return;
    }

    const stage = el('layer-ad');
    const prepared = state.ad.prepared;
    let node;

    if (prepared && prepared.index === state.ad.index && prepared.url === assetUrl(item) && prepared.node.parentElement === stage) {
        state.ad.prepared = null;
        if (state.ad.prepareTimer) clearTimeout(state.ad.prepareTimer);
        state.ad.prepareTimer = null;
        node = prepared.node;
    } else {
        clearPreparedAd();
        const testing = makeRoomFor(item);
        node = buildElement(item);
        if (testing) passTheRoomTest(node);
        node.hidden = true;
        stage.appendChild(node);
    }

    let done = false;
    const finish = () => {
        // Once per advert, and never for one no longer on the stage: an error, or a refused
        // play(), can arrive after the break has ended and emptied the layer, and moving on
        // from it then would start an advert outside any break.
        if (done || ! node.isConnected) return;
        done = true;

        // An advert that found no decoder beside the stage's is set up once more with them let go (makeRoomFor).
        if (couldHaveNoDecoder(node)) {
            state.roomTest = true;
            node.remove();
            playAd();

            return;
        }
        state.ad.index += 1;
        playAd();
    };

    // Its time, counted from when it came on — and from now, until then, as the backstop of one that never comes.
    const seconds = item.type === 'video' ? item.duration + 5 : Math.max(1, item.duration);

    const reveal = () => {
        // An advert that finishes loading after its time was up, or after the break ended,
        // must not show itself: un-hiding the emptied layer is a black screen over the organization's
        // content, and pausing that content would freeze it with nothing to resume it.
        if (done || ! node.isConnected) return;

        finishFade();
        pauseContent();
        // The advert before goes only once this one has faded in over it.
        const before = Array.from(stage.children).filter((child) => child !== node);
        before.forEach((child) => {
            if (isVideoNode(child)) child.pause();
        });
        const letGo = () => before.forEach((child) => child.remove());
        const appearing = stage.hidden;
        node.hidden = false;
        stage.hidden = false;

        if (appearing) {
            // The break begins: the advert fades in over the organization's item, paused under it.
            letGo();
            fade(stage, 0, 1, () => {});
        } else {
            fade(node, 0, 1, letGo);
        }

        if (item.type === 'video') node.play().catch(finish);
        if (state.ad.playTimer) clearTimeout(state.ad.playTimer);
        state.ad.playTimer = setTimeout(finish, seconds * 1000);

        // What comes after it is made ready behind it: the next advert, or after the last one the organization's item.
        const left = isVideoNode(node) ? timeLeftOf(node, item) : seconds * 1000;
        if (state.ad.index + 1 < state.ad.items.length) {
            prepareAd(state.ad.index + 1, left - PREPARE_AHEAD_MS, () => state.ad.active && node.isConnected);
        } else {
            prepareAfterTheBreak(node, left);
        }
    };

    if (item.type === 'video') {
        node.addEventListener('ended', finish, { once: true });
        // A file that stalls must never hold the wall hostage.
        node.addEventListener('error', finish, { once: true });
    }
    if (state.ad.playTimer) clearTimeout(state.ad.playTimer);
    state.ad.playTimer = setTimeout(finish, seconds * 1000);

    readiness(node, item).then((ready) => (ready ? reveal() : finish()));
}

/** The advert at `index`, made ready in the advert layer, hidden, in `inMs`, if `stillWanted()` says so then. */
function prepareAd(index, inMs, stillWanted) {
    if (state.ad.prepareTimer) clearTimeout(state.ad.prepareTimer);

    state.ad.prepareTimer = setTimeout(() => {
        state.ad.prepareTimer = null;
        const item = state.ad.items[index];
        if (! item || state.ad.prepared || ! stillWanted()) return;
        // A video that cannot have a decoder beside the stage's is made when its turn comes instead (playAd).
        if (! mayMakeReadyBeside(item)) return;

        const node = buildElement(item);
        node.hidden = true;
        if (isVideoNode(node)) node.pause();
        node.addEventListener('error', () => {
            if (couldHaveNoDecoder(node)) state.roomTest = true;
            if (state.ad.prepared && state.ad.prepared.node === node) clearPreparedAd();
        }, { once: true });
        el('layer-ad').appendChild(node);
        state.ad.prepared = { node, index, url: assetUrl(item) };
    }, Math.max(0, inMs));
}

/** Let an advert made ready for later go, and the timer that would make one. */
function clearPreparedAd() {
    if (state.ad.prepareTimer) clearTimeout(state.ad.prepareTimer);
    state.ad.prepareTimer = null;

    const prepared = state.ad.prepared;
    state.ad.prepared = null;
    if (prepared && prepared.node.isConnected) prepared.node.remove();
}

/**
 * The organization's item that comes on after the break, made ready behind its content during the last advert: the
 * next one (a break between two items), or a page let go for the break (pauseContent), which comes back from its
 * start. A video or a picture interrupted under the break carries on where it was, and needs nothing made.
 * The advert stays up until that item is ready (playCurrent): taken down first, the item before the break came
 * back for a moment, or black where a page had been let go (the owner's Fire TV Stick, 2026-11-02).
 */
function prepareAfterTheBreak(adNode, leftMs) {
    if (state.items.length === 0) return;

    const front = frontNode();
    if (! state.ad.between && ! (front && front.tagName === 'IFRAME')) return;

    prepare(state.items[state.index % state.items.length], leftMs - PREPARE_AHEAD_MS,
        () => state.ad.active && adNode.isConnected && ! state.ad.pendingData);
}

/** The advert layer down and emptied: a hidden <video> keeps its decoder and its buffer otherwise. */
function takeTheAdDown() {
    if (state.ad.leaveTimer) clearTimeout(state.ad.leaveTimer);
    state.ad.leaveTimer = null;

    const stage = el('layer-ad');
    // A fade of the advert layer, or inside it, is over: there is nothing left to fade.
    if (state.fade && stage && stage.contains(state.fade.element)) finishFade();

    if (stage) {
        stage.hidden = true;
        stage.innerHTML = '';
    }
}

/** The advert layer goes, fading away over the organization's item. */
function letTheAdGo() {
    if (! el('layer-ad').hidden) fade(el('layer-ad'), 1, 0, takeTheAdDown);
    else takeTheAdDown();
}

/** Stop the organization's content where it stands, remembering what it had left — once per break. */
function pauseContent() {
    // Every advert in a break comes here as it appears, but only the first may measure. An
    // image's time left is counted from when it STARTED, so measured again at the second
    // advert it has lost the first advert's seconds as well — nothing left — and the picture
    // came back for half a second before moving on.
    if (state.ad.contentPaused) return;
    state.ad.contentPaused = true;

    if (state.itemTimer) clearTimeout(state.itemTimer);
    state.itemTimer = null;
    clearPrepared();
    state.ad.resumeMs = remainingOnScreen();

    const node = frontNode();

    // pause(), never a seek: the element keeps its position and its buffer, so
    // resuming a two-hour video costs nothing and cannot stutter.
    if (isVideoNode(node)) node.pause();

    // An Ad Builder page cannot be paused from here — its runtime is its own — and left running
    // under the break it came back past its exit, its video decoding behind the advert all along
    // (the brute-force round, 2026-09-29). It is let go, hidden so nothing white shows, and shown
    // again from the start when the break is over (resumeContent).
    // Once the advert has faded in over it: let go at once, the page left black under the advert coming in (the owner's
    // Fire TV Stick, 2026-11-05).
    if (node && node.tagName === 'IFRAME') {
        setTimeout(() => {
            if (! state.ad.contentPaused || frontNode() !== node) return;
            releasePageVideos(node);
            node.style.visibility = 'hidden';
            emptyFrame(node);
        }, FADE_MS);
    }
}

/**
 * A frame emptied in place, its page REPLACED: setting its src wrote a history entry at every break, so the Samsung app's
 * Exit (history back) only went back inside the frame, and after a few days of breaks the browser's 50 entries no longer
 * held the app's own page at all. Location.replace may be called on a frame of another origin too.
 */
function emptyFrame(frame) {
    try {
        frame.contentWindow.location.replace('about:blank');
    } catch {
        frame.src = 'about:blank';
    }
}

/** Put the organization's content back exactly where it was. */
function resumeContent() {
    const node = frontNode();

    if (! node) {
        takeTheAdDown();

        return;
    }

    // A page let go for the break: shown again from the start, for its whole length, as if it had
    // just come on — never the rest of a run whose exit already played. The advert stays up until it is
    // (playCurrent). So is a video that let its decoder go for an advert (holdStill): its picture is a still.
    if (node.tagName === 'IFRAME' || node.dataset.still) {
        playCurrent();

        return;
    }

    letTheAdGo();

    if (isVideoNode(node)) {
        node.play().catch(() => {});
        // The backstop is rearmed from what was left, not from the whole duration — with the
        // same five seconds of slack playCurrent gives a video. Exactly the time left would
        // fire a moment before `ended` (starting again takes a moment) and cut its last frames.
        startItemTimer(Math.max(1000, state.ad.resumeMs || 1000) + 5000);
        prepareNext(node);

        return;
    }

    // An image that had four of its ten seconds used gets the remaining six.
    startItemTimer(Math.max(500, state.ad.resumeMs || 500));
    prepareNext(node);
}

function endAdBreak({ resume }) {
    if (state.ad.playTimer) clearTimeout(state.ad.playTimer);
    state.ad.playTimer = null;
    clearPreparedAd();

    // Whether an advert ever actually appeared. If none did, nothing was paused: the content
    // kept playing on its own clock, and "resuming" it would re-arm that clock from a stale
    // measurement — cutting off a video that had been playing all along.
    const contentWasPaused = state.ad.contentPaused;
    state.ad.contentPaused = false;
    // A break between two items has no item to put back: the next one comes on, from its start.
    const between = state.ad.between;
    state.ad.between = false;

    // With no advert on screen, or no content to come back, the layer goes at once. Emptied, not just hidden: a
    // hidden <video> still holds its decoder and its buffer, and a set left running for weeks would collect one
    // every hour.
    if (! state.ad.active || ! resume || el('layer-ad').hidden) {
        takeTheAdDown();
        if (! state.ad.active) return;
    }

    state.ad.active = false;
    state.ad.index = 0;

    scheduleNextBreak();

    // The last advert stays up until the organization's item after it is on screen (playCurrent takes it down), and
    // never longer than this, so a file that will not come on cannot keep the brand's last picture on the wall.
    if (! el('layer-ad').hidden) {
        state.ad.leaveTimer = setTimeout(takeTheAdDown, AD_LEAVES_MS);
    }

    // A manifest that arrived mid-break is applied now, and replaces the resume:
    // there is no point putting back content the server has already changed.
    if (state.ad.pendingData) {
        const data = state.ad.pendingData;
        state.ad.pendingData = null;
        render(data);

        return;
    }

    if (between && resume) {
        playCurrent();

        return;
    }

    if (resume && contentWasPaused) {
        resumeContent();

        return;
    }

    takeTheAdDown();
}

function scheduleNextBreak() {
    if (state.ad.timer) clearTimeout(state.ad.timer);
    state.ad.timer = null;
    state.ad.due = false;
    // An advert made ready for a break that is not coming goes.
    clearPreparedAd();

    if (state.ad.everyMs === 0 || state.ad.items.length === 0) return;

    state.ad.nextAt = Date.now() + state.ad.everyMs;
    state.ad.timer = setTimeout(startAdBreak, state.ad.everyMs);
}

function showError(message) {
    el('error-message').textContent = message;
    show('view-error');
}

/* ── Boot ──────────────────────────────────────────────────────────────── */

// The Samsung app's RETURN asks before it closes the app (player/exit-question.js).
registerExitQuestion(store);
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

// Best effort on TVs that honour it; the organization also disables sleep on the set.
if ('wakeLock' in navigator) {
    navigator.wakeLock.request('screen').catch(() => {});
}
