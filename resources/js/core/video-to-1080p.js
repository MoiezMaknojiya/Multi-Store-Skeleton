/*
 * A video bigger than 1080p made 1080p in the browser before it is uploaded (owner, 2026-11-05 — "agar 4K ki video aye toh
 * usko optimize kar k 1080p kardeve", "awaaz chiya hi nahi"): most televisions decode one 4K video at a time and show
 * 1080p anyway (player.js, "One decoder at a time"), and the file is a fraction of the size. The server has no ffmpeg and
 * one processor, so the work is the uploading computer's: the MP4 is read with mp4box.js, decoded with WebCodecs, drawn
 * at its new size (turned upright where the file says it is shown turned), encoded as H.264 and written as an MP4 with
 * its index first and no sound — a signage video is always silent.
 *
 * Loaded only when such a video is dropped (upload-dropzone.js). A browser without WebCodecs, a WebM, or anything that
 * goes wrong on the way: the caller uploads the file as it came, and the server takes it as before.
 */
import MP4BoxModule from 'mp4box';

const { createFile, DataStream } = MP4BoxModule;

/** One read of the file. */
const READ_BYTES = 4 * 1024 * 1024;
/** Frames read and waiting for the decoder: reading waits beyond that, so a five-minute file is never whole in memory. */
const WAITING_CHUNKS = 120;
/** Frames inside the decoder or the encoder at once. */
const IN_FLIGHT = 6;
/** A key frame every two seconds, so a television can start and seek quickly. */
const KEY_EVERY_US = 2_000_000;
/** The bitrate of the new file: half the old one's, at least 2.5 and at most 8 Mbit/s — and never more than the old one's. */
const MIN_BITRATE = 2_500_000;
const MAX_BITRATE = 8_000_000;
const LEAST_BITRATE = 500_000;
const TIMESCALE = 90000;

const wait = (ms) => new Promise((resolve) => setTimeout(resolve, ms));

/** How many degrees clockwise the file says its picture is shown turned (its track's matrix): 0, 90, 180 or 270. */
function turnOf(matrix) {
    if (!matrix || matrix.length < 4) return 0;

    const degrees = Math.round((Math.atan2(matrix[1], matrix[0]) * 180) / Math.PI);

    return ((degrees % 360) + 360) % 360;
}

/** The first encoder setting this browser takes for the size: hardware first, Baseline H.264 (no reordered frames). */
async function encoderConfig(width, height, bitrate, framerate) {
    for (const hardwareAcceleration of ['prefer-hardware', 'no-preference']) {
        for (const codec of ['avc1.42002A', 'avc1.42E02A', 'avc1.420028']) {
            const config = { codec, width, height, bitrate, framerate, hardwareAcceleration, latencyMode: 'quality', avc: { format: 'avc' } };
            const answer = await VideoEncoder.isConfigSupported(config).catch(() => null);
            if (answer && answer.supported) return config;
        }
    }

    throw new Error('this browser cannot encode H.264 at ' + width + ' × ' + height);
}

/**
 * `file` within `longEdge` × `shortEdge` (media-file.js, LONG_EDGE and SHORT_EDGE) with no sound, as a new File — or an
 * error, and the caller uploads `file` as it is. `onProgress` hears 0 to 1 as the frames are done; `signal` stops it.
 */
export async function shrinkVideo(file, { longEdge, shortEdge, onProgress = () => {}, signal } = {}) {
    const stopped = () => signal && signal.aborted;
    const chunks = [];
    const encoded = [];
    let failure = null;
    let decoder = null;
    let encoder = null;
    let info = null;
    let total = Infinity;
    let extracted = 0;
    let avcC = null;
    let lastKey = -Infinity;
    let done = 0;

    const fail = (error) => {
        if (!failure) failure = error instanceof Error ? error : new Error(String(error));
    };

    const mp4 = createFile();
    mp4.onError = (message) => fail(new Error('mp4: ' + message));
    mp4.onSamples = (id, user, samples) => {
        for (const sample of samples) {
            chunks.push(new EncodedVideoChunk({
                type: sample.is_sync ? 'key' : 'delta',
                timestamp: (1e6 * sample.cts) / sample.timescale,
                duration: (1e6 * sample.duration) / sample.timescale,
                data: sample.data,
            }));
        }
        extracted += samples.length;
        if (samples.length) mp4.releaseUsedSamples(id, samples[samples.length - 1].number + 1);
    };

    const ready = new Promise((resolve) => {
        mp4.onReady = (fileInfo) => {
            info = fileInfo;
            // Frames are taken out while this very read goes on: mp4box.js then says where their bytes are.
            const video = fileInfo.videoTracks && fileInfo.videoTracks[0];
            if (video) {
                mp4.setExtractionOptions(video.id, null, { nbSamples: 100 });
                mp4.start();
            }
            resolve();
        };
    });

    // The file, read in pieces wherever its index is: mp4box.js says where to read next.
    const reading = (async () => {
        let position = 0;
        let reads = 0;

        while (!failure && !stopped() && position < file.size) {
            if (++reads > 100000) throw new Error('the file was read in circles');
            while (chunks.length > WAITING_CHUNKS && !failure && !stopped()) await wait(20);

            const buffer = await file.slice(position, Math.min(file.size, position + READ_BYTES)).arrayBuffer();
            buffer.fileStart = position;
            const next = mp4.appendBuffer(buffer);
            position = typeof next === 'number' && next !== position && (info || next > position) ? next : position + buffer.byteLength;
            if (info && extracted >= total) break;
        }
        mp4.flush();
    })().catch(fail);

    await Promise.race([ready, reading]);
    if (failure) throw failure;
    if (!info) throw new Error('no video index in the file');

    const track = info.videoTracks && info.videoTracks[0];
    if (!track) throw new Error('no video track');
    total = track.nb_samples;

    const entry = mp4.getTrackById(track.id).mdia.minf.stbl.stsd.entries[0];
    const box = entry.avcC || entry.hvcC || entry.vpcC || entry.av1C;
    const decoderConfig = { codec: track.codec, codedWidth: track.video.width, codedHeight: track.video.height };
    if (box) {
        const stream = new DataStream(undefined, 0, DataStream.BIG_ENDIAN);
        box.write(stream);
        decoderConfig.description = new Uint8Array(stream.buffer, 8);
    }
    const decodable = await VideoDecoder.isConfigSupported(decoderConfig).catch(() => null);
    if (!decodable || !decodable.supported) throw new Error('this browser cannot decode ' + track.codec);

    // The new size: the picture as it is shown (turned where the file says so), within 1920 × 1080, in even numbers.
    const turn = turnOf(track.matrix);
    const width = track.track_width || track.video.width;
    const height = track.track_height || track.video.height;
    const shownWidth = turn % 180 === 0 ? width : height;
    const shownHeight = turn % 180 === 0 ? height : width;
    const scale = Math.min(longEdge / Math.max(shownWidth, shownHeight), shortEdge / Math.min(shownWidth, shownHeight), 1);
    const outWidth = Math.max(2, Math.round((shownWidth * scale) / 2) * 2);
    const outHeight = Math.max(2, Math.round((shownHeight * scale) / 2) * 2);

    const seconds = track.duration / track.timescale || info.duration / info.timescale || 0;
    const framerate = seconds > 0 ? Math.min(60, Math.max(1, total / seconds)) : 30;
    const sourceBitrate = seconds > 0 ? (file.size * 8) / seconds : MAX_BITRATE;
    const bitrate = Math.round(Math.max(LEAST_BITRATE, Math.min(MAX_BITRATE, sourceBitrate, Math.max(MIN_BITRATE, sourceBitrate * 0.5))));
    const lengthUs = seconds * 1e6;

    const canvas = new OffscreenCanvas(outWidth, outHeight);
    const context = canvas.getContext('2d');

    encoder = new VideoEncoder({
        output: (chunk, metadata) => {
            const description = metadata && metadata.decoderConfig && metadata.decoderConfig.description;
            if (description && !avcC) {
                avcC = description instanceof ArrayBuffer
                    ? new Uint8Array(description.slice(0))
                    : new Uint8Array(description.buffer.slice(description.byteOffset, description.byteOffset + description.byteLength));
            }
            const data = new Uint8Array(chunk.byteLength);
            chunk.copyTo(data);
            encoded.push({ data, timestamp: chunk.timestamp, duration: chunk.duration, key: chunk.type === 'key' });
        },
        error: fail,
    });
    encoder.configure(await encoderConfig(outWidth, outHeight, bitrate, framerate));

    decoder = new VideoDecoder({
        output: (frame) => {
            try {
                if (failure || stopped()) return;
                if (turn === 0) {
                    context.drawImage(frame, 0, 0, outWidth, outHeight);
                } else {
                    context.save();
                    context.translate(outWidth / 2, outHeight / 2);
                    context.rotate((turn * Math.PI) / 180);
                    const drawWidth = turn % 180 === 0 ? outWidth : outHeight;
                    const drawHeight = turn % 180 === 0 ? outHeight : outWidth;
                    context.drawImage(frame, -drawWidth / 2, -drawHeight / 2, drawWidth, drawHeight);
                    context.restore();
                }
                const out = new VideoFrame(canvas, { timestamp: frame.timestamp, duration: frame.duration || undefined });
                const keyFrame = frame.timestamp - lastKey >= KEY_EVERY_US;
                if (keyFrame) lastKey = frame.timestamp;
                encoder.encode(out, { keyFrame });
                out.close();
                done += 1;
                onProgress(lengthUs > 0 ? Math.min(1, frame.timestamp / lengthUs) : done / total);
            } catch (error) {
                fail(error);
            } finally {
                frame.close();
            }
        },
        error: fail,
    });
    decoder.configure(decoderConfig);

    // Stopped (× on its row): the decoder and the encoder let go at once, so the computer's work ends there too.
    const letGo = () => {
        for (const coder of [decoder, encoder]) {
            try { if (coder.state !== 'closed') coder.close(); } catch { /* already closed */ }
        }
    };
    if (signal) signal.addEventListener('abort', letGo, { once: true });

    try {
        // Fed a few at a time, as fast as the decoder and the encoder take them.
        let fed = 0;
        while (fed < total) {
            if (failure) throw failure;
            if (stopped()) throw new DOMException('Stopped', 'AbortError');

            if (chunks.length && decoder.decodeQueueSize < IN_FLIGHT && encoder.encodeQueueSize < IN_FLIGHT) {
                decoder.decode(chunks.shift());
                fed += 1;
            } else {
                await wait(chunks.length ? 4 : 15);
                if (!chunks.length && extracted >= total) break;
            }
        }
        await reading;
        await decoder.flush();
        await encoder.flush();
        if (failure) throw failure;
        if (stopped()) throw new DOMException('Stopped', 'AbortError');
        if (encoded.length === 0 || !avcC) throw new Error('nothing came out of the encoder');
    } finally {
        if (signal) signal.removeEventListener('abort', letGo);
        letGo();
        try { mp4.stop(); } catch { /* nothing to stop */ }
    }

    onProgress(1);
    const name = file.name.replace(/\.[^.]+$/, '') + '.mp4';

    return new File([writeMp4(outWidth, outHeight, avcC, encoded)], name, { type: 'video/mp4', lastModified: Date.now() });
}

/* ── The MP4: ftyp, moov (one H.264 track) and mdat, its index first ─────────────────────────── */

function bytes(...values) {
    return new Uint8Array(values);
}

function u16(value) {
    return bytes((value >>> 8) & 255, value & 255);
}

function u32(value) {
    return bytes((value >>> 24) & 255, (value >>> 16) & 255, (value >>> 8) & 255, value & 255);
}

function ascii(text) {
    return new Uint8Array([...text].map((character) => character.charCodeAt(0)));
}

function join(parts) {
    const length = parts.reduce((sum, part) => sum + part.length, 0);
    const out = new Uint8Array(length);
    let at = 0;
    for (const part of parts) {
        out.set(part, at);
        at += part.length;
    }

    return out;
}

function box(type, ...parts) {
    const body = join(parts);

    return join([u32(body.length + 8), ascii(type), body]);
}

function fullBox(type, version, flags, ...parts) {
    return box(type, bytes(version, (flags >>> 16) & 255, (flags >>> 8) & 255, flags & 255), ...parts);
}

const MATRIX = [0x00010000, 0, 0, 0, 0x00010000, 0, 0, 0, 0x40000000];

/** The file: what the encoder gave, in its order (Baseline H.264 has no reordered frames), timed from nought. */
function writeMp4(width, height, avcC, samples) {
    const first = samples[0].timestamp;
    const ticks = samples.map((sample, i) => {
        const next = samples[i + 1];
        const us = next ? next.timestamp - sample.timestamp : (sample.duration || (samples.length > 1 ? (sample.timestamp - first) / (samples.length - 1) : 33333));

        return Math.max(1, Math.round((us * TIMESCALE) / 1e6));
    });
    const mediaTicks = ticks.reduce((sum, tick) => sum + tick, 0);
    const movieMs = Math.round((mediaTicks * 1000) / TIMESCALE);

    const runs = [];
    for (const tick of ticks) {
        if (runs.length && runs[runs.length - 1][1] === tick) runs[runs.length - 1][0] += 1;
        else runs.push([1, tick]);
    }
    const keys = samples.map((sample, i) => (sample.key ? i + 1 : 0)).filter(Boolean);

    const avc1 = box('avc1',
        bytes(0, 0, 0, 0, 0, 0), u16(1),
        u16(0), u16(0), u32(0), u32(0), u32(0),
        u16(width), u16(height),
        u32(0x00480000), u32(0x00480000), u32(0), u16(1),
        new Uint8Array(32), u16(0x0018), u16(0xffff),
        box('avcC', avcC));

    const stbl = (offset) => box('stbl',
        fullBox('stsd', 0, 0, u32(1), avc1),
        fullBox('stts', 0, 0, u32(runs.length), ...runs.flatMap(([count, delta]) => [u32(count), u32(delta)])),
        fullBox('stss', 0, 0, u32(keys.length), ...keys.map(u32)),
        fullBox('stsc', 0, 0, u32(1), u32(1), u32(samples.length), u32(1)),
        fullBox('stsz', 0, 0, u32(0), u32(samples.length), ...samples.map((sample) => u32(sample.data.length))),
        fullBox('stco', 0, 0, u32(1), u32(offset)));

    const moov = (offset) => box('moov',
        fullBox('mvhd', 0, 0, u32(0), u32(0), u32(1000), u32(movieMs), u32(0x00010000), u16(0x0100), u16(0), u32(0), u32(0),
            ...MATRIX.map(u32), u32(0), u32(0), u32(0), u32(0), u32(0), u32(0), u32(2)),
        box('trak',
            fullBox('tkhd', 0, 3, u32(0), u32(0), u32(1), u32(0), u32(movieMs), u32(0), u32(0), u16(0), u16(0), u16(0), u16(0),
                ...MATRIX.map(u32), u32(width * 65536), u32(height * 65536)),
            box('mdia',
                fullBox('mdhd', 0, 0, u32(0), u32(0), u32(TIMESCALE), u32(mediaTicks), u16(0x55c4), u16(0)),
                fullBox('hdlr', 0, 0, u32(0), ascii('vide'), u32(0), u32(0), u32(0), ascii('VideoHandler'), bytes(0)),
                box('minf',
                    fullBox('vmhd', 0, 1, u16(0), u16(0), u16(0), u16(0)),
                    box('dinf', fullBox('dref', 0, 0, u32(1), fullBox('url ', 0, 1))),
                    stbl(offset)))));

    const ftyp = box('ftyp', ascii('isom'), u32(0x200), ascii('isom'), ascii('iso2'), ascii('avc1'), ascii('mp41'));
    const dataLength = samples.reduce((sum, sample) => sum + sample.data.length, 0);
    const mdatHeader = join([u32(dataLength + 8), ascii('mdat')]);
    // The index's own length does not depend on the offset it carries: written once to learn it, then for real.
    const index = moov(ftyp.length + moov(0).length + mdatHeader.length);

    return new Blob([ftyp, index, mdatHeader, ...samples.map((sample) => sample.data)], { type: 'video/mp4' });
}
