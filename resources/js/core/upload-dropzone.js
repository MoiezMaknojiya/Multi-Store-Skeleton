/**
 * The uploader every page that takes a file uses (docs/UPLOADS-SPEC.md, owner 2026-09-29): a box a file is dropped on
 * or chosen with, and a row per file with its preview, its progress, its speed and the time left, and Pause, Resume,
 * Cancel or Try again. The bytes travel by the tus protocol, 5 MB at a time, through Uppy's engine — @uppy/core and
 * @uppy/tus, loaded only once a file is chosen — to the app's own /uploads: a dropped connection retries by itself, an
 * offline browser waits for the network, a connection that hangs without a word is noticed and sent again, and the same
 * file chosen again after a reload carries on where it stopped.
 *
 * What a person sees is the panel's own (components/upload-dropzone.blade.php). Two modes:
 *  - `add` (the Media page, the Ad Builder's shelf): each file that arrives is posted to its door (`addUrl`) at once,
 *    and `upload-added` is dispatched with the server's answer, so the page refreshes its list and its storage. Its
 *    "Added" row then goes by itself after a few seconds, as a notification does.
 *  - `form` (a channel's Upload, a campaign's advert): one file. `upload-picked` when it is accepted, `upload-ready`
 *    with its upload id and what the browser measured once every byte is in, `upload-cleared` when it is taken away,
 *    and `upload-busy` while bytes are still going, so the form can hold its Save.
 *
 * `context` is kept up to date by the page (the component's x-effect): where the file goes (library, store, channel),
 * the fields its door needs besides, and the shop's storage ({used, limit}) for the check before a byte is sent.
 */
import axios from 'axios';
import { bytesInWords, clock, fileError, MAX_VIDEO_SECONDS, readVideoMeta, storageError, videoLengthError } from './media-file.js';

/* Mirrors App\Services\ChunkedUploads::CHUNK_BYTES. */
const CHUNK_BYTES = 5 * 1024 * 1024;

/* Files sent at the same time; the rest wait their turn. */
const AT_ONCE = 3;

/* How long a chunk that failed is tried again before the row says so: at once, after 1, 3, 5, 10, 20 and 30 seconds,
 * then every minute — some 25 minutes in all, long enough for a router to come back. A chunk that gets through starts
 * the count again, and while the browser is offline the files not yet started wait for the network. */
const RETRY_DELAYS = [0, 1000, 3000, 5000, 10000, 20000, 30000, ...Array(24).fill(60000)];

/* An upload that has sent nothing for this long says it is having trouble… */
const QUIET_SAYS_MS = 6000;

/* …and after this long its request is given up and sent again from what the server kept: a connection that died
 * without a word would otherwise hold the upload for as long as the system's own network timeout. */
const QUIET_RESTARTS_MS = 30000;

/* What an upload tells the server about itself (Upload-Metadata). */
const META_FIELDS = ['name', 'type', 'purpose', 'library', 'store', 'channel'];

/* How long an "Added" row stays (`add`) before it fades away by itself, as a notification does (owner, 2026-09-30);
 * a refused or failed row stays, for its reason and its Try again. */
const ADDED_STAYS_MS = 8000;

/* How long its fading takes: the row's `duration-300`. */
const FADE_MS = 300;

/* Rows still doing something: the page asks before it is left while any is. */
const BUSY = ['checking', 'waiting', 'uploading', 'paused', 'adding'];

/* Rows whose bytes are not counted in the shop's storage yet. */
const PENDING = [...BUSY, 'ready'];

let engine = null;

/** Uppy's core and its tus uploader, fetched once, when the first file is chosen. */
function loadEngine() {
    engine ??= Promise.all([import('@uppy/core'), import('@uppy/tus')])
        .then(([core, tus]) => ({ Uppy: core.default, Tus: tus.default }));

    return engine;
}

/** The server's own words from a refused answer's body, or null. */
function wordsFrom(body) {
    try {
        const data = typeof body === 'string' ? JSON.parse(body) : body;

        return data?.errors?.file?.[0] ?? Object.values(data?.errors ?? {})[0]?.[0] ?? data?.message ?? null;
    } catch {
        return null;
    }
}

let rows = 0;

export function registerUploadDropzone(Alpine) {
    Alpine.data('uploadDropzone', (config = {}) => {
        // Uppy is kept out of Alpine's reactive data: wrapped in its Proxy, its private fields (#…) cannot be read.
        let uppy = null;
        // The watch on uploads that have gone quiet.
        let watch = null;
        // The "Added" rows waiting to fade away.
        const leaving = new Set();

        return {
            purpose: config.purpose,
            mode: config.mode ?? 'add',
            multiple: !! config.multiple,
            addUrl: config.addUrl ?? null,
            maxVideoSeconds: config.maxVideoSeconds ?? MAX_VIDEO_SECONDS,
            videoNoun: config.videoNoun ?? 'A video',

            /* Set by the page: {library|store|channel, fields, storage}. */
            context: {},

            // The rows. Not `items`: a page calling its own methods from inside the box would write its list here.
            uploads: [],
            dragging: false,
            offline: typeof navigator !== 'undefined' && navigator.onLine === false,
            wasBusy: false,
            // "Added" rows that have gone from the list, still counted in "3 of 5 added" until the batch is over.
            addedAndGone: 0,
            // What a screen reader is told: a file's turn — uploaded, added, refused, failed — never every percent.
            said: '',

            init() {
                this.onLeave = (event) => {
                    if (! this.busy()) return;
                    event.preventDefault();
                    event.returnValue = '';
                };
                this.onOnline = () => {
                    this.offline = false;
                    // Back: what the connection stopped carries on now, not at its next retry.
                    this.uploads.filter((item) => item.status === 'uploading').forEach((item) => this.sendAgain(item));
                };
                this.onOffline = () => { this.offline = true; };

                window.addEventListener('beforeunload', this.onLeave);
                window.addEventListener('online', this.onOnline);
                window.addEventListener('offline', this.onOffline);
                watch = setInterval(() => this.checkQuiet(), 1000);
            },

            destroy() {
                window.removeEventListener('beforeunload', this.onLeave);
                window.removeEventListener('online', this.onOnline);
                window.removeEventListener('offline', this.onOffline);
                clearInterval(watch);
                leaving.forEach((timer) => clearTimeout(timer));
                this.uploads.forEach((item) => this.forgetPreview(item));
                uppy?.destroy();
            },

            /* ── Choosing ─────────────────────────────────────────────────── */

            /** The box's drop, and its chooser's change. */
            async addFiles(list) {
                this.dragging = false;

                const files = Array.from(list ?? []);

                if (files.length === 0) return;

                // One file where only one is taken: a new pick replaces the old.
                if (! this.multiple) this.clear();

                for (const file of (this.multiple ? files : files.slice(0, 1))) {
                    await this.accept(file);
                }
            },

            /** A drag that leaves the box, not one of its children. */
            leaveBox(event) {
                if (! event.currentTarget.contains(event.relatedTarget)) this.dragging = false;
            },

            /** Check what can be checked before a byte is sent — then send it. */
            async accept(file) {
                this.uploads.push({
                    key: `upload-${++rows}`,
                    fileId: null,
                    name: file.name,
                    size: file.size,
                    type: file.type,
                    isVideo: file.type.startsWith('video/'),
                    preview: null,
                    duration: null,
                    meta: {},
                    status: 'checking',
                    percent: 0,
                    speed: 0,
                    eta: null,
                    tick: null,
                    lastSentAt: null,
                    stalled: false,
                    error: '',
                    uploadId: null,
                    fading: false,
                });

                // The reactive row, as Alpine keeps it.
                const item = this.uploads[this.uploads.length - 1];
                this.announceBusy();

                const refused = fileError(file) ?? storageError(file, this.roomLeft(item));

                if (refused) return this.refuse(item, refused);

                if (file.type.startsWith('image/')) {
                    item.preview = URL.createObjectURL(file);
                } else if (item.isVideo) {
                    // The length, the shape and a first frame are measured here: the server has no ffmpeg.
                    item.meta = await readVideoMeta(file);
                    item.preview = item.meta.poster ?? null;
                    item.duration = item.meta.duration_seconds ?? null;

                    const tooLong = videoLengthError(item.meta, this.maxVideoSeconds, this.videoNoun);

                    if (tooLong) return this.refuse(item, tooLong);
                }

                // Taken away while it was being measured.
                if (! this.uploads.includes(item)) return;

                // The form learns the file at once — a video's measured length too — before its bytes are in.
                if (this.mode === 'form') this.$dispatch('upload-picked', { name: item.name, size: item.size, type: item.type, meta: item.meta });

                try {
                    const { Uppy, Tus } = await loadEngine();
                    this.startEngine(Uppy, Tus);

                    item.fileId = uppy.addFile({ name: file.name, type: file.type, data: file, meta: this.metaFor() });
                    item.status = 'waiting';
                    uppy.upload().catch(() => {});
                } catch (error) {
                    this.refuse(item, String(error?.message ?? '').includes('duplicate')
                        ? 'This file is on its way already.'
                        : 'This file could not be sent. Try again.');
                }
            },

            /** The shop's storage as it will be once the rows before this one are in. */
            roomLeft(item) {
                const storage = this.context.storage;

                if (! storage) return null;

                const pending = this.uploads
                    .filter((each) => each !== item && PENDING.includes(each.status))
                    .reduce((sum, each) => sum + each.size, 0);

                return { used: storage.used + pending, limit: storage.limit };
            },

            /** What the upload tells the server: what it is for, and where it goes. */
            metaFor() {
                const meta = { purpose: this.purpose };

                // Every key, empty when there is none: the engine sends each allowed field whatever the file holds, and
                // one the file lacked reached the server as the word "undefined" — a shop nobody has (a shared upload
                // on the Ad Builder's shelf was refused with "That shop no longer exists").
                ['library', 'store', 'channel'].forEach((key) => {
                    const value = this.context[key];
                    meta[key] = value === null || value === undefined ? '' : String(value);
                });

                return meta;
            },

            refuse(item, message) {
                item.status = 'refused';
                item.error = message;
                this.say(`${item.name}: ${message}`);
                this.announceBusy();
            },

            /** Tell a screen reader once. Emptied first, so the same words said twice are heard twice. */
            say(words) {
                this.said = '';
                this.$nextTick(() => { this.said = words; });
            },

            /* ── The engine ───────────────────────────────────────────────── */

            startEngine(Uppy, Tus) {
                if (uppy) return;

                uppy = new Uppy({ autoProceed: false, allowMultipleUploadBatches: true })
                    .use(Tus, {
                        endpoint: '/uploads',
                        chunkSize: CHUNK_BYTES,
                        limit: AT_ONCE,
                        retryDelays: RETRY_DELAYS,
                        removeFingerprintOnSuccess: true,
                        allowedMetaFields: META_FIELDS,
                        headers: {
                            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content ?? '',
                            'X-Requested-With': 'XMLHttpRequest',
                            Accept: 'application/json',
                        },
                    });

                uppy.on('upload-progress', (file, progress) => this.onProgress(file, progress));
                uppy.on('upload-success', (file, response) => this.onSent(file, response));
                uppy.on('upload-error', (file, error, response) => this.onFailed(file, error, response));
            },

            rowOf(file) {
                return file ? this.uploads.find((item) => item.fileId === file.id) ?? null : null;
            },

            onProgress(file, progress) {
                const item = this.rowOf(file);

                if (! item || ! progress.bytesTotal) return;

                const now = performance.now();
                const sent = progress.bytesUploaded;

                item.lastSentAt = now;
                item.stalled = false;

                // The speed is smoothed over half-second steps, so the time left does not jump about. A chunk sent again
                // (after a pause or a lost connection) starts from what the server kept: measured afresh from there.
                if (! item.tick || sent < item.tick.bytes) {
                    item.tick = { time: now, bytes: sent };
                } else if (now - item.tick.time >= 500) {
                    const instant = (sent - item.tick.bytes) / ((now - item.tick.time) / 1000);
                    item.speed = item.speed ? item.speed * 0.7 + instant * 0.3 : instant;
                    item.tick = { time: now, bytes: sent };
                }

                // The bar never goes back: a chunk sent again is caught up with before it moves on.
                item.percent = Math.max(item.percent, Math.min(99, Math.floor((sent / progress.bytesTotal) * 100)));
                item.eta = item.speed > 0 ? (progress.bytesTotal - sent) / item.speed : null;
                if (item.status !== 'paused') item.status = 'uploading';
                this.announceBusy();
            },

            /** Every byte is in: add it to its place (`add`), or hand it to the form (`form`). */
            onSent(file, response) {
                const item = this.rowOf(file);

                if (! item) return;

                item.uploadId = String(response?.uploadURL ?? '').split('/').pop();
                item.percent = 100;
                item.eta = null;

                // Uppy lets go of the file, so the same file can be chosen again later; the row stays. Not at once: while
                // this event is being told, the uploader still listens for the file's removal and would give the finished
                // upload up on the server — a moment later it has let go.
                const id = file.id;
                item.fileId = null;
                setTimeout(() => {
                    try {
                        uppy?.removeFile(id);
                    } catch {
                        /* Already gone. */
                    }
                }, 0);

                if (this.mode === 'add') {
                    this.addToPlace(item);

                    return;
                }

                item.status = 'ready';
                this.say(`${item.name} is uploaded. It is added when you save.`);
                this.announceBusy();
                this.$dispatch('upload-ready', {
                    upload: item.uploadId,
                    meta: item.meta,
                    file: { name: item.name, size: item.size, type: item.type },
                });
            },

            onFailed(file, error, response) {
                const item = this.rowOf(file);

                if (! item) return;

                item.status = 'failed';
                item.error = wordsFrom(response?.body?.xhr?.responseText)
                    ?? (error?.isNetworkError ? 'The connection kept dropping. Try again.' : 'This file could not be sent. Try again.');
                this.say(`${item.name}: ${item.error}`);
                this.announceBusy();
            },

            /** `add`: the door the page's form would have posted to, with the upload in place of the file. */
            async addToPlace(item) {
                item.status = 'adding';
                item.error = '';
                this.announceBusy();

                const form = new FormData();
                form.append('upload', item.uploadId);

                Object.entries({ ...(this.context.fields ?? {}), ...item.meta }).forEach(([key, value]) => {
                    if (value !== null && value !== undefined && value !== '') form.append(key, value);
                });

                try {
                    const { data } = await axios.post(this.addUrl, form);

                    item.status = 'done';
                    this.say(`${item.name} is added.`);
                    this.$dispatch('upload-added', { response: data, name: item.name });
                    this.fadeAway(item);
                } catch (error) {
                    item.status = 'failed';
                    item.error = wordsFrom(error.response?.data) ?? 'This file could not be added. Try again.';
                    this.say(`${item.name}: ${item.error}`);
                } finally {
                    this.announceBusy();
                }
            },

            /* ── The row's buttons ────────────────────────────────────────── */

            togglePause(item) {
                if (! item.fileId || ! uppy) return;

                const paused = uppy.pauseResume(item.fileId);
                item.status = paused ? 'paused' : 'uploading';
                item.speed = 0;
                item.tick = null;
                item.lastSentAt = performance.now();
                item.stalled = false;
                this.announceBusy();
            },

            /** Give the upload's request up and send it again from what the server kept. */
            sendAgain(item) {
                if (! item.fileId || ! uppy) return;

                item.lastSentAt = performance.now();
                uppy.pauseResume(item.fileId);
                uppy.pauseResume(item.fileId);
            },

            /** An upload that has sent nothing for a while says so — then its request is sent again. */
            checkQuiet() {
                if (! uppy || this.offline) return;

                const now = performance.now();

                this.uploads.filter((item) => item.status === 'uploading' && item.lastSentAt !== null).forEach((item) => {
                    const quiet = now - item.lastSentAt;

                    if (item.stalled !== (quiet >= QUIET_SAYS_MS)) item.stalled = quiet >= QUIET_SAYS_MS;
                    if (quiet >= QUIET_RESTARTS_MS) this.sendAgain(item);
                });
            },

            retry(item) {
                item.error = '';

                // The bytes are in and only adding it failed: add it again.
                if (item.uploadId && this.mode === 'add') {
                    this.addToPlace(item);

                    return;
                }

                if (item.fileId && uppy) {
                    item.status = 'waiting';
                    uppy.retryUpload(item.fileId).catch(() => {});
                }

                this.announceBusy();
            },

            /** An "Added" row goes by itself: it fades, then leaves the list — unless it was taken off first. */
            fadeAway(item) {
                const stay = setTimeout(() => {
                    leaving.delete(stay);

                    if (! this.uploads.includes(item)) return;

                    item.fading = true;

                    const fade = setTimeout(() => {
                        leaving.delete(fade);
                        if (this.uploads.includes(item)) this.remove(item);
                    }, FADE_MS);
                    leaving.add(fade);
                }, ADDED_STAYS_MS);
                leaving.add(stay);
            },

            /** Cancel a file on its way, or take a finished or refused one off the list. */
            remove(item) {
                if (item.fileId && uppy) {
                    // Uppy gives the upload up on the server too (tus termination).
                    uppy.removeFile(item.fileId);
                } else if (item.uploadId && item.status !== 'done') {
                    // Sent but not added: nothing will use it now.
                    this.giveUp(item.uploadId);
                }

                this.forgetPreview(item);
                if (item.status === 'done') this.addedAndGone++;
                this.uploads = this.uploads.filter((each) => each !== item);
                // A batch is over once nothing in the list is going or done: a refused row left behind does not carry its
                // count into the next files dropped.
                if (this.uploads.every((each) => each.status === 'refused')) this.addedAndGone = 0;

                if (this.mode === 'form') this.$dispatch('upload-cleared');

                this.announceBusy();
            },

            /** Take every row away (a form closed, a new file chosen where one is taken). */
            clear() {
                [...this.uploads].forEach((item) => this.remove(item));
            },

            giveUp(id) {
                fetch(`/uploads/${id}`, {
                    method: 'DELETE',
                    headers: {
                        'Tus-Resumable': '1.0.0',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content ?? '',
                        Accept: 'application/json',
                    },
                }).catch(() => {});
            },

            forgetPreview(item) {
                if (item.preview?.startsWith('blob:')) URL.revokeObjectURL(item.preview);
            },

            /* ── What the rows say ────────────────────────────────────────── */

            busy() {
                return this.uploads.some((item) => BUSY.includes(item.status));
            },

            announceBusy() {
                const busy = this.busy();

                if (busy !== this.wasBusy) {
                    this.wasBusy = busy;
                    this.$dispatch('upload-busy', { busy });
                }
            },

            /** "12.4 MB · 0:45" */
            details(item) {
                return [bytesInWords(item.size), item.duration ? clock(item.duration) : null].filter(Boolean).join(' · ');
            },

            statusText(item) {
                if (this.offline && ['waiting', 'uploading'].includes(item.status)) {
                    return 'Connection lost. Waiting for the internet…';
                }

                if (item.status === 'uploading' && item.stalled) {
                    return `Connection trouble at ${item.percent}%. Trying again…`;
                }

                switch (item.status) {
                    case 'checking': return 'Checking…';
                    case 'waiting': return 'Waiting…';
                    case 'uploading': return [`Uploading ${item.percent}%`, this.speedText(item)].filter(Boolean).join(' · ');
                    case 'paused': return `Paused at ${item.percent}%`;
                    case 'adding': return 'Adding…';
                    case 'ready': return 'Uploaded. It is added when you save.';
                    case 'done': return 'Added';
                    default: return '';
                }
            },

            /** "2.4 MB/s · 1 min left" */
            speedText(item) {
                if (! item.speed) return '';

                const eta = item.eta;
                const left = eta === null ? '' : eta < 60 ? `${Math.max(1, Math.round(eta))} s left` : `${Math.round(eta / 60)} min left`;

                return [`${bytesInWords(item.speed)}/s`, left].filter(Boolean).join(' · ');
            },

            showsBar(item) {
                return ['waiting', 'uploading', 'paused', 'adding'].includes(item.status);
            },

            canPause(item) {
                return !! item.fileId && ['uploading', 'paused'].includes(item.status);
            },

            canRetry(item) {
                return item.status === 'failed';
            },

            /** "3 of 5 added" — for a list of several, the rows that have faded away still counted until the batch is over. */
            summary() {
                const counted = this.uploads.filter((item) => item.status !== 'refused');
                const done = counted.filter((item) => ['done', 'ready'].includes(item.status)).length + this.addedAndGone;
                const total = counted.length + this.addedAndGone;

                return counted.length > 0 && total > 1 ? `${done} of ${total} ${this.mode === 'add' ? 'added' : 'uploaded'}` : '';
            },
        };
    });
}
