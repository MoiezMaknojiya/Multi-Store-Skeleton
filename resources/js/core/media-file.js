/**
 * Choosing and measuring a file before it is uploaded.
 *
 * Shared by the store's media library, the platform's advertising campaigns, a
 * channel's ads (channel-ads.js) and the Ad Builder's asset shelf
 * (builder-assets-table.js): all of them accept exactly the same formats and all need
 * a video's shape, length and a poster frame — because the server has no ffmpeg, so
 * the BROWSER measures them and sends the numbers along. The server re-validates
 * everything it is told.
 *
 * Kept in one place because the fiddly part is the canvas: a codec the browser can
 * decode but not paint throws, and every failure path here has to end with the
 * upload still going ahead. Two copies of that would eventually stop agreeing.
 */

/* Mirrors StoreMediaRequest::ALLOWED_MIMES — the server still validates. Read through
 * fileError() below, which every upload form uses, so it is not exported. */
const ALLOWED_EXTENSIONS = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'mp4', 'webm'];

/* Mirrors StoreMediaRequest::MAX_KILOBYTES. */
const MAX_BYTES = 256000 * 1024;

/* Mirrors Media::MAX_VIDEO_SECONDS: the longest video a library or a channel takes. The server measures the
 * file itself and decides; this only spares an upload it would refuse. */
export const MAX_VIDEO_SECONDS = 300;

/* Mirrors BuilderAsset::MAX_VIDEO_SECONDS: the longest video on the Ad Builder's shelf, where it repeats for as
 * long as the ad is on screen. */
export const BUILDER_VIDEO_SECONDS = 30;

const POSTER_MAX_EDGE = 480;
const METADATA_TIMEOUT_MS = 8000;

/** Why this file cannot be uploaded, or null if it can. */
export function fileError(file) {
    const extension = (file.name.split('.').pop() ?? '').toLowerCase();

    if (! ALLOWED_EXTENSIONS.includes(extension)) {
        return 'Only images (JPG, PNG, GIF, WEBP) and videos (MP4, WEBM) can be uploaded.';
    }

    if (file.size > MAX_BYTES) {
        return 'The file may not be larger than 250 MB.';
    }

    return null;
}

/** 432 as "7:12", 3600 as "1:00:00" — as App\Rules\VideoLength::clock says it. */
export function clock(seconds) {
    const total = Math.max(0, Math.round(seconds));
    const hours = Math.floor(total / 3600);
    const minutes = Math.floor((total % 3600) / 60);
    const rest = String(total % 60).padStart(2, '0');

    return hours > 0 ? `${hours}:${String(minutes).padStart(2, '0')}:${rest}` : `${minutes}:${rest}`;
}

/** 300 as "5 minutes", 60 as "60 seconds" — as App\Rules\VideoLength::inWords says it. */
export function lengthInWords(seconds) {
    if (seconds <= 60) return `${seconds} ${seconds === 1 ? 'second' : 'seconds'}`;

    const minutes = Math.floor(seconds / 60);
    const rest = seconds % 60;

    return `${minutes} ${minutes === 1 ? 'minute' : 'minutes'}` + (rest > 0 ? ` ${rest} ${rest === 1 ? 'second' : 'seconds'}` : '');
}

/** Why a measured video is too long, or null — in the server's own words. */
export function videoLengthError(meta, maxSeconds = MAX_VIDEO_SECONDS, noun = 'A video') {
    // Nothing measured is the server's to judge (it reads the file itself), never a length of nought.
    if (meta?.duration_seconds === null || meta?.duration_seconds === undefined || meta?.duration_seconds === '') return null;

    const seconds = Number(meta.duration_seconds);

    if (! Number.isFinite(seconds)) return null;

    // Under half a second rounds to nothing — App\Rules\VideoLength refuses it in the same words.
    if (seconds < 1) return `${noun} must be at least 1 second long.`;

    if (seconds <= maxSeconds) return null;

    return `${noun} may be at most ${lengthInWords(maxSeconds)} long. This one is ${clock(seconds)}.`;
}

/** 1536 as "2 KB", 126 353 408 as "120.5 MB", 536 870 912 as "512 MB" — as App\Services\StoreStorage says it. */
export function bytesInWords(bytes) {
    if (bytes <= 0) return '0 KB';
    if (bytes < 1024 * 1024) return `${Math.max(1, Math.ceil(bytes / 1024))} KB`;

    const megabytes = bytes / (1024 * 1024);

    return `${Number.isInteger(megabytes) ? megabytes : megabytes.toFixed(1)} MB`;
}

/**
 * Why a file will not fit its shop's storage, or null. `storage` is what the server said — {used, limit} —
 * or null for a library with no wall (the platform's own). Only the file is counted here; the server counts
 * its preview too, and decides.
 */
export function storageError(file, storage) {
    if (! storage || ! file) return null;

    const left = Math.max(0, storage.limit - storage.used);

    if (file.size <= left) return null;

    return `Not enough storage: this needs ${bytesInWords(file.size)}, and this shop has `
        + (left > 0 ? `${bytesInWords(left)} left` : 'no space left')
        + ` of its ${bytesInWords(storage.limit)}. Delete files you no longer use to make room.`;
}

/** "120.5 MB of 512 MB used", for the storage meter. */
export function storageUsedText(storage) {
    return storage ? `${bytesInWords(storage.used)} of ${bytesInWords(storage.limit)} used` : '';
}

/** How full, from 0 to 100, for the storage meter's bar. */
export function storagePercent(storage) {
    return storage && storage.limit > 0 ? Math.min(100, Math.round((storage.used / storage.limit) * 100)) : 0;
}

/**
 * Load the video just far enough to read its shape and grab one frame.
 *
 * Every failure path resolves with whatever it has: a missing poster costs a
 * thumbnail, never the upload.
 */
export function readVideoMeta(file) {
    return new Promise((resolve) => {
        const url = URL.createObjectURL(file);
        const video = document.createElement('video');
        /* What has been measured so far. Every way out resolves with it, so a poster frame
         * that never comes — a seek that hangs past the timeout, an error after the
         * metadata — still leaves the length and the size, which matter far more to the
         * upload than a thumbnail does. */
        const meta = {};
        let settled = false;

        const finish = () => {
            if (settled) return;
            settled = true;
            URL.revokeObjectURL(url);
            // A copy: a handler that fires after this must not change what the caller was given.
            resolve({ ...meta });
        };

        setTimeout(finish, METADATA_TIMEOUT_MS);

        video.preload = 'metadata';
        video.muted = true;
        video.onerror = finish;

        video.onloadedmetadata = () => {
            meta.duration_seconds = Number.isFinite(video.duration) ? Math.round(video.duration) : null;
            meta.width = video.videoWidth || null;
            meta.height = video.videoHeight || null;

            video.onseeked = () => {
                try {
                    const scale = Math.min(1, POSTER_MAX_EDGE / Math.max(video.videoWidth, video.videoHeight));
                    const canvas = document.createElement('canvas');
                    canvas.width = Math.max(1, Math.round(video.videoWidth * scale));
                    canvas.height = Math.max(1, Math.round(video.videoHeight * scale));
                    canvas.getContext('2d').drawImage(video, 0, 0, canvas.width, canvas.height);
                    meta.poster = canvas.toDataURL('image/jpeg', 0.8);
                } catch {
                    /* Codec the browser can decode but not paint: skip the poster. */
                }
                finish();
            };

            // A frame a second in is more representative than black frame zero.
            video.currentTime = Math.min(1, (video.duration || 2) / 2);
        };

        video.src = url;
    });
}
