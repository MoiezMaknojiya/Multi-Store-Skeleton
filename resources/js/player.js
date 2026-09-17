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
 */

const TOKEN_KEY = 'signage.device.token';
const UUID_KEY = 'signage.device.uuid';
const SECRET_KEY = 'signage.device.poll_secret';
const CODE_KEY = 'signage.device.code';
const CODE_EXPIRES_KEY = 'signage.device.code_expires';
const KNOWN_KEY = 'signage.device.known';

// While waiting to be claimed. Deliberately as slow as the playlist poll
// (owner's decision): an unpaired player asks 2 times a minute instead of 15,
// and a page left open on a pairing code costs almost nothing. The price is
// paid at setup — after the owner types the code, the TV can take up to this
// long to notice and start playing.
const POLL_MS = 30000;
const PLAYLIST_MS = 30000;   // how often to ask what to show
const HEARTBEAT_MS = 60000;  // how often to say "alive"
const BROKEN_ITEM_PAUSE_MS = 1000;  // breathing room before skipping a file that will not play

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
    endAdBreak({ resume: false });
    if (state.ad.timer) clearTimeout(state.ad.timer);
    state.ad.timer = null;
    state.ad.nextAt = 0;
}

/** Token is gone or no longer valid: forget everything and start over. */
function resetToPairing(message = null) {
    clearTimers();
    store.remove(TOKEN_KEY);
    store.remove(CODE_KEY);
    store.remove(CODE_EXPIRES_KEY);
    state.token = null;
    state.version = null;
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
    try {
        const response = await fetch('/device/playlist', { headers: deviceHeaders() });

        if (response.status === 401) {
            return resetToPairing('This screen was removed. Pair it again to continue.');
        }
        if (!response.ok) return;

        const data = await response.json();
        applyOrientation(data.screen?.orientation);

        // Nothing changed: leave whatever is on screen alone rather than
        // re-rendering and making the TV flicker every poll.
        if (data.version === state.version) return;
        state.version = data.version;

        render(data);
    } catch {
        /* Keep showing the last thing that worked. */
    }
}

async function sendHeartbeat() {
    try {
        const response = await fetch('/device/heartbeat', { method: 'POST', headers: deviceHeaders() });
        if (response.status === 401) resetToPairing('This screen was removed. Pair it again to continue.');
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
 * with no shell present this is just the server URL.
 */
function assetUrl(item) {
    const shell = window.SignagePlayer;
    if (shell && typeof shell.localUrl === 'function') {
        return shell.localUrl(item.url, item.checksum) || item.url;
    }
    return item.url;
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

    if (item.type === 'video') {
        node.addEventListener('loadeddata', reveal, { once: true });
        // A video advances when it actually ends; the duration is only a backstop
        // for a file that stalls or has no end event.
        node.addEventListener('ended', advance, { once: true });
        node.addEventListener('error', skipBroken, { once: true });
        startItemTimer((item.duration + 5) * 1000);
        node.play().catch(() => {});
    } else {
        node.addEventListener('load', reveal, { once: true });
        node.addEventListener('error', skipBroken, { once: true });
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
    if (next.type === 'video') return;   // videos stream; only images are worth warming
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
        if (done) return;
        done = true;
        state.ad.index += 1;
        playAd();
    };

    const reveal = () => {
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

/** Stop the shop's content where it stands, remembering what it had left. */
function pauseContent() {
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
        // The backstop is rearmed from what was left, not from the whole duration.
        startItemTimer(Math.max(1000, state.ad.resumeMs || 1000));

        return;
    }

    // An image that had four of its ten seconds used gets the remaining six.
    startItemTimer(Math.max(500, state.ad.resumeMs || 500));
}

function endAdBreak({ resume }) {
    if (state.ad.playTimer) clearTimeout(state.ad.playTimer);
    state.ad.playTimer = null;

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

    if (resume) resumeContent();
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

if (state.token) {
    startPlayback();
} else {
    startPairing();
}

// Best effort on TVs that honour it; the shop also disables sleep on the set.
if ('wakeLock' in navigator) {
    navigator.wakeLock.request('screen').catch(() => {});
}
