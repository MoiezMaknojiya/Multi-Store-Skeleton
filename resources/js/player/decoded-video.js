/*
 * <decoded-video>: a video the player decodes itself (docs/TV-ORIENTATION-SPEC.md §11, §12).
 *
 * A Fire TV's WebView takes a <video> to a hardware plane of its own a few seconds after it starts, and that plane
 * never turns with the stage: on a screen mounted another way the video came out sideways and small (Roadstar 4,
 * 2026-10-27). This element plays the file with no media element at all: it reads the MP4 in pieces (mp4box.js),
 * decodes it with WebCodecs and draws each frame on a canvas of its own, which turns with the stage like a picture.
 *
 * To the player it is a <video>: `src`, `play()` (a promise), `pause()`, `paused`, `duration`, `currentTime`, and the
 * events `loadeddata` (its first frame is drawn), `ended` (its last frame's time has passed) and `error`. It never
 * loops by itself: the player decides what comes next. It is silent, as every video the player shows.
 *
 * Loaded only where it is needed (player.js, decodesTurnedVideo), behind a check that WebCodecs is there: the
 * player's own floor is Chrome 80, and WebCodecs is Chrome 94.
 */
import MP4BoxModule from 'mp4box';

const { createFile, DataStream } = MP4BoxModule;

/** One read of the file. The worker cuts it from its cached copy when it has one (public/player-sw.js, partial). */
const PIECE_BYTES = 2 * 1024 * 1024;
/** Frames read and waiting for the decoder: about three seconds of a 30 fps video. Reading waits beyond that. */
const MAX_WAITING_CHUNKS = 90;
/** Frames handed to the decoder at once, and decoded frames held for drawing (each one is closed once drawn). */
const DECODE_AHEAD = 4;
const FRAMES_AHEAD = 6;
/** No first frame by then is a file this set cannot play: `error`, and the player moves on. */
const FIRST_FRAME_MS = 15000;
/** The canvas is never bigger than the panel: a 4K video is drawn at the size it is shown. */
const MAX_EDGE = 1920;

const wait = (ms) => new Promise((resolve) => setTimeout(resolve, ms));

/** How many degrees clockwise the file says its picture is shown turned (its track's matrix), 0, 90, 180 or 270. */
function turnOf(matrix) {
    if (!matrix || matrix.length < 4) return 0;

    const degrees = Math.round((Math.atan2(matrix[1], matrix[0]) * 180) / Math.PI);

    return ((degrees % 360) + 360) % 360;
}

class DecodedVideo extends HTMLElement {
    constructor() {
        super();
        this._src = '';
        this._paused = true;
        this._wantsToPlay = false;
        this._position = 0;
        this._clockStart = 0;
        this._duration = NaN;
        this._chunks = [];
        this._frames = [];
        this._firstTimestamp = null;
        this._framesStart = Infinity;
        this._framesEnd = -Infinity;
        this._extracted = 0;
        this._total = Infinity;
        this._readAll = false;
        this._flushed = false;
        this._ended = false;
        this._failed = false;
        this._shown = false;
        this._torn = false;
        this._turn = 0;
        this.error = null;
        // Read by tests and by a person at DevTools; never by the player.
        this.drawnFrames = 0;
        this.lateFrames = 0;
        this.reads = 0;
        this._tick = this._tick.bind(this);
    }

    // What the player sets on a <video>: accepted, and only src and autoplay mean anything here.
    set src(value) { this._src = String(value || ''); }

    set autoplay(value) { if (value) this._wantsToPlay = true; }

    set muted(value) { /* always silent */ }

    set playsInline(value) { /* always inline */ }

    set preload(value) { /* it always reads ahead */ }

    get paused() { return this._paused; }

    get duration() { return this._duration; }

    get currentTime() { return this._now(); }

    play() {
        this._wantsToPlay = true;
        if (this._shown && this._paused && !this._ended) this._runFrom(this._position);

        return Promise.resolve();
    }

    pause() {
        if (this._paused) return;
        this._position = this._now();
        this._paused = true;
    }

    connectedCallback() {
        if (!this._canvas) {
            this._canvas = document.createElement('canvas');
            this._context = this._canvas.getContext('2d');
            this.appendChild(this._canvas);
        }

        if (this._started || !this._src) return;
        this._started = true;
        this._abort = new AbortController();
        this._timeout = setTimeout(() => {
            if (!this._shown) this._fail('no picture after ' + FIRST_FRAME_MS / 1000 + ' s');
        }, FIRST_FRAME_MS);
        this._read().catch((error) => this._fail(error && error.message ? error.message : String(error)));
        this._raf = requestAnimationFrame(this._tick);
    }

    disconnectedCallback() {
        this._teardown();
    }

    /** Seconds played: frozen while paused, never past the end once the end is certain (every frame read). */
    _now() {
        if (this._paused) return this._position;

        const seconds = (performance.now() - this._clockStart) / 1000;

        return this._readAll && Number.isFinite(this._duration) ? Math.min(seconds, this._duration) : seconds;
    }

    _runFrom(position) {
        this._clockStart = performance.now() - position * 1000;
        this._paused = false;
    }

    /**
     * The file, read in pieces with Range requests guided by mp4box.js: wherever its index (`moov`) is, front or
     * back, mp4box says where it needs to read next, and once the index is read, where the next frame's bytes are.
     * Reading waits while enough frames are waiting, so a five-minute video is never whole in memory. A server that
     * ignores ranges answers with the whole file once.
     */
    async _read() {
        const file = this._file = createFile();
        file.onError = (message) => this._fail('mp4: ' + message);
        file.onReady = (info) => this._ready(info);
        file.onSamples = (id, user, samples) => this._take(id, samples);

        let position = 0;
        let size = null;
        let reads = 0;
        // Smaller pieces only when a test asks (data-piece-bytes), so a small file is read in many.
        const pieceBytes = Number(this.dataset.pieceBytes) > 0 ? Number(this.dataset.pieceBytes) : PIECE_BYTES;

        while (!this._torn && !this._failed) {
            if (size !== null && position >= size) break;
            if (++reads > 10000) throw new Error('the file was read in circles');
            this.reads = reads;

            while (this._chunks.length > MAX_WAITING_CHUNKS && !this._torn) await wait(100);
            if (this._torn) return;

            const last = (size === null ? position + pieceBytes : Math.min(position + pieceBytes, size)) - 1;
            const response = await fetch(this._src, {
                headers: { Range: 'bytes=' + position + '-' + last },
                signal: this._abort.signal,
            });
            if (this._torn) return;

            if (response.status === 200) {
                const whole = await response.arrayBuffer();
                if (this._torn) return;
                whole.fileStart = 0;
                file.appendBuffer(whole);
                break;
            }
            if (response.status !== 206) throw new Error('the file answered ' + response.status);

            const total = /\/(\d+)\s*$/.exec(response.headers.get('Content-Range') || '');
            if (total) size = Number(total[1]);

            const buffer = await response.arrayBuffer();
            if (this._torn) return;
            if (buffer.byteLength === 0) break;

            buffer.fileStart = position;
            const next = file.appendBuffer(buffer);
            // The pieces whose every byte mp4box has handed out are let go.
            if (file.stream && typeof file.stream.cleanBuffers === 'function') file.stream.cleanBuffers();

            // mp4box may send the reading back only once the index is read (to the frames' bytes); before that, a file
            // that is no MP4 would have it ask for the start again and again.
            const asked = typeof next === 'number' && next !== position && (this._indexRead || next > position);
            position = asked ? next : position + buffer.byteLength;
        }

        if (this._torn || this._failed) return;
        file.flush();
        // Every frame is read: the length is the longest of what the index said and what the frames run to.
        const frames = (this._framesEnd - this._framesStart) / 1e6;
        if (frames > 0 && !(this._duration >= frames)) this._duration = frames;
        this._readAll = true;

        // Read to its end with no index: no MP4 this set can read. With one, still short of frames: a file cut short.
        if (!this._indexRead) {
            this._fail('no video index in the file');
        } else if (this._extracted < this._total) {
            this._fail('the file ended ' + (this._total - this._extracted) + ' frames early');
        }
    }

    /** The file's index is read: the decoder is set up for its video track. */
    _ready(info) {
        this._indexRead = true;
        const track = info.videoTracks && info.videoTracks[0];
        if (!track) return this._fail('no video track');

        const entry = this._file.getTrackById(track.id).mdia.minf.stbl.stsd.entries[0];
        const box = entry.avcC || entry.hvcC || entry.vpcC || entry.av1C;
        const config = { codec: track.codec, codedWidth: track.video.width, codedHeight: track.video.height };

        if (box) {
            const stream = new DataStream(undefined, 0, DataStream.BIG_ENDIAN);
            box.write(stream);
            // The box without its own eight-byte header is what WebCodecs calls the description.
            config.description = new Uint8Array(stream.buffer, 8);
        }

        // A fragmented file (what a browser or OBS records) may say 0 here, its length in a mehd box or nowhere: then
        // its frames' own, known once every frame is read (_read). Capped at 0, its clock never moved off the first frame.
        const said = info.timescale
            ? Math.max(info.duration || 0, info.fragment_duration || 0) / info.timescale
            : track.duration / track.timescale;
        this._duration = said > 0 ? said : NaN;
        this._total = track.nb_samples;
        this._trackId = track.id;
        this._turn = turnOf(track.matrix);

        // The canvas: the picture's own shape as shown (turned when the file says so), never bigger than the panel.
        const width = track.track_width || track.video.width;
        const height = track.track_height || track.video.height;
        const shownWidth = this._turn % 180 === 0 ? width : height;
        const shownHeight = this._turn % 180 === 0 ? height : width;
        const scale = Math.min(1, MAX_EDGE / Math.max(shownWidth, shownHeight));
        this._canvas.width = Math.max(1, Math.round(shownWidth * scale));
        this._canvas.height = Math.max(1, Math.round(shownHeight * scale));

        // Frames are taken out at once, while this very read is going on: mp4box then says where the first frame's
        // bytes are, which is back at the start when the index came last. The decoder is set up meanwhile, and the
        // frames wait for it (_feed).
        this._file.setExtractionOptions(track.id, null, { nbSamples: 30 });
        this._file.start();

        this._configure(config).catch((error) => this._fail(error && error.message ? error.message : String(error)));
    }

    /** The hardware decoder when the set has one for this file, else whatever it has. */
    async _configure(config) {
        let chosen = null;

        for (const acceleration of ['prefer-hardware', 'no-preference']) {
            const candidate = Object.assign({}, config, { hardwareAcceleration: acceleration });
            const answer = await VideoDecoder.isConfigSupported(candidate).catch(() => null);
            if (answer && answer.supported) {
                chosen = candidate;
                break;
            }
        }
        if (!chosen) throw new Error('this set cannot decode ' + config.codec);
        if (this._torn) return;

        this._decoder = new VideoDecoder({
            output: (frame) => {
                if (this._torn) {
                    frame.close();

                    return;
                }
                this._frames.push(frame);
            },
            error: (error) => this._fail('decoder: ' + (error && error.message ? error.message : String(error))),
        });
        this._decoder.configure(chosen);
    }

    /** Frames mp4box has read: kept as chunks for the decoder, and their bytes let go in mp4box. */
    _take(id, samples) {
        for (const sample of samples) {
            const timestamp = (1e6 * sample.cts) / sample.timescale;
            const duration = (1e6 * sample.duration) / sample.timescale;
            this._framesStart = Math.min(this._framesStart, timestamp);
            this._framesEnd = Math.max(this._framesEnd, timestamp + duration);
            this._chunks.push(new EncodedVideoChunk({
                type: sample.is_sync ? 'key' : 'delta',
                timestamp: timestamp,
                duration: duration,
                data: sample.data,
            }));
        }
        this._extracted += samples.length;
        if (samples.length) this._file.releaseUsedSamples(id, samples[samples.length - 1].number + 1);
    }

    /** Feed the decoder a few frames ahead; once every frame is in, flush it for the last ones. */
    _feed() {
        const decoder = this._decoder;
        if (!decoder || decoder.state !== 'configured') return;

        while (this._chunks.length && decoder.decodeQueueSize < DECODE_AHEAD && this._frames.length < FRAMES_AHEAD) {
            decoder.decode(this._chunks.shift());
        }

        if (!this._flushed && this._readAll && this._chunks.length === 0 && this._extracted >= this._total) {
            this._flushed = true;
            decoder.flush().catch(() => { /* torn down meanwhile */ });
        }
    }

    _draw(frame) {
        const canvas = this._canvas;
        const context = this._context;

        if (this._turn === 0) {
            context.drawImage(frame, 0, 0, canvas.width, canvas.height);
        } else {
            context.save();
            context.translate(canvas.width / 2, canvas.height / 2);
            context.rotate((this._turn * Math.PI) / 180);
            const width = this._turn % 180 === 0 ? canvas.width : canvas.height;
            const height = this._turn % 180 === 0 ? canvas.height : canvas.width;
            context.drawImage(frame, -width / 2, -height / 2, width, height);
            context.restore();
        }
        this.drawnFrames += 1;
    }

    _tick() {
        if (this._torn || this._failed) return;
        this._feed();
        this._frames.sort((a, b) => a.timestamp - b.timestamp);

        // The first frame is shown as soon as it is decoded, playing or not: `loadeddata`, as a <video> says it.
        if (!this._shown && this._frames.length) {
            const first = this._frames.shift();
            this._firstTimestamp = first.timestamp;
            this._draw(first);
            first.close();
            this._shown = true;
            clearTimeout(this._timeout);
            if (this._wantsToPlay) this._runFrom(0);
            this.dispatchEvent(new Event('loadeddata'));
        } else if (this._shown && !this._paused) {
            const due = this._firstTimestamp + this._now() * 1e6;
            let frame = null;

            while (this._frames.length && this._frames[0].timestamp <= due) {
                if (frame) {
                    frame.close();
                    this.lateFrames += 1;
                }
                frame = this._frames.shift();
            }
            if (frame) {
                this._draw(frame);
                frame.close();
            }

            const decoder = this._decoder;
            const nothingLeft = this._flushed && this._frames.length === 0 && this._chunks.length === 0
                && decoder && decoder.decodeQueueSize === 0;

            if (nothingLeft && (!Number.isFinite(this._duration) || this._now() >= this._duration - 0.05)) {
                this._finish();

                return;
            }
        }

        this._raf = requestAnimationFrame(this._tick);
    }

    _finish() {
        this._position = this._now();
        this._paused = true;
        this._ended = true;
        this.dispatchEvent(new Event('ended'));
    }

    _fail(message) {
        if (this._failed || this._torn) return;
        this._failed = true;
        this.error = { message: message };
        this._release();
        this.dispatchEvent(new Event('error'));
    }

    /** Everything that holds memory or the decoder: the reads, the frames, the decoder. */
    _release() {
        if (this._raf) cancelAnimationFrame(this._raf);
        clearTimeout(this._timeout);
        if (this._abort) this._abort.abort();
        this._frames.splice(0).forEach((frame) => frame.close());
        this._chunks = [];
        if (this._decoder && this._decoder.state !== 'closed') {
            try { this._decoder.close(); } catch (error) { /* already closed */ }
        }
        if (this._file) {
            try { this._file.stop(); } catch (error) { /* nothing to stop */ }
        }
        this._file = null;
    }

    /** Taken off the page: let go of everything, the canvas's pixels too. */
    _teardown() {
        this._torn = true;
        this._release();
        if (this._canvas) this._canvas.width = this._canvas.height = 0;
    }
}

/** The element, defined once. */
export function registerDecodedVideo() {
    if (!window.customElements.get('decoded-video')) window.customElements.define('decoded-video', DecodedVideo);
}
