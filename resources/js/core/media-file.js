/**
 * Choosing and measuring a file before it is uploaded.
 *
 * Shared by the store's media library and by the platform's advertising campaigns:
 * both accept exactly the same formats and both need a video's shape, length and a
 * poster frame — because the server has no ffmpeg, so the BROWSER measures them and
 * sends the numbers along. The server re-validates everything it is told.
 *
 * Kept in one place because the fiddly part is the canvas: a codec the browser can
 * decode but not paint throws, and every failure path here has to end with the
 * upload still going ahead. Two copies of that would eventually stop agreeing.
 */

/* Mirrors StoreMediaRequest::ALLOWED_MIMES — the server still validates. */
export const ALLOWED_EXTENSIONS = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'mp4', 'webm'];

/* Mirrors StoreMediaRequest::MAX_KILOBYTES. */
export const MAX_BYTES = 256000 * 1024;

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
        let settled = false;

        const finish = (meta) => {
            if (settled) return;
            settled = true;
            URL.revokeObjectURL(url);
            resolve(meta);
        };

        setTimeout(() => finish({}), METADATA_TIMEOUT_MS);

        video.preload = 'metadata';
        video.muted = true;
        video.onerror = () => finish({});

        video.onloadedmetadata = () => {
            const meta = {
                duration_seconds: Number.isFinite(video.duration) ? Math.round(video.duration) : null,
                width: video.videoWidth || null,
                height: video.videoHeight || null,
            };

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
                finish(meta);
            };

            // A frame a second in is more representative than black frame zero.
            video.currentTime = Math.min(1, (video.duration || 2) / 2);
        };

        video.src = url;
    });
}
