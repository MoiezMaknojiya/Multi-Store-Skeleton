/**
 * What only a Fire TV runs (owner, 2026-11-06: a change made for one kind of set never reaches another). The player
 * imports this chunk where its user agent says AFT and nowhere else, so an Android TV or a Samsung TV never downloads
 * it. What a Fire TV does its own way goes into `own`, the player's list of such ways.
 *
 * A video stopped before its first frame (the owner's Fire TV Stick 4K Max, 2026-11-06; docs/TV-ORIENTATION-SPEC.md
 * §12.21): for an hour the first video after the player started had its metadata and nothing more, every time and on the
 * live player too: no first frame, no error, nothing loading, until its backstop moved on after its length and five
 * seconds of an empty glass. The next video played, and it was never seen again after that hour. So a video in that state
 * is set up once more, and one still in it is given up: the next item comes on within a second.
 */
const FIRST_FRAME_WAIT_MS = 3000;

/** @param {{watchFirstFrame: function(HTMLVideoElement, function(): void): void}} own */
export function startOnAFireTv(own) {
    own.watchFirstFrame = (video, giveUp) => {
        // The player's own decoder (<decoded-video>) says for itself what it does.
        if (video.tagName !== 'VIDEO') return;

        let timer = null;
        let setUpAgain = false;
        const stop = () => {
            if (timer) clearTimeout(timer);
            timer = null;
        };
        const look = () => {
            timer = null;
            if (! video.isConnected || video.readyState >= 2 || video.error) return;
            // Still loading: a slow line, not a stop.
            if (video.networkState !== 1) {
                arm();

                return;
            }
            if (setUpAgain) {
                giveUp();

                return;
            }
            setUpAgain = true;
            // load() leaves it paused: one that was playing plays again, one made ready behind stays paused.
            const playing = ! video.paused;
            video.load();
            if (playing) video.play().catch(() => {});
            else video.pause();
        };
        const arm = () => {
            stop();
            timer = setTimeout(look, FIRST_FRAME_WAIT_MS);
        };

        // Counted from its metadata, and again once it is set up once more: what comes before is the line's.
        video.addEventListener('loadedmetadata', arm);
        video.addEventListener('loadeddata', stop);
        video.addEventListener('error', stop);
        if (video.readyState === 1) arm();
    };
}
