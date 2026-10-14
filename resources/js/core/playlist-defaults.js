/**
 * Shared playlist defaults. Mirrors PlaylistItem::DEFAULT_IMAGE_SECONDS and ::MIN_IMAGE_SECONDS so
 * the forms and the server agree on how long an image shows when nobody says, and on the least it
 * may (owner's rule, 2026-10-14: ten seconds, both).
 */
export const PlaylistItemDefaults = {
    imageSeconds: 10,
    minImageSeconds: 10,
    /* Mirrors PlaylistItem::MAX_IMAGE_SECONDS: the longest one picture holds a screen, on a playlist as in a channel
     * (five minutes; owner, 2026-10-14). */
    maxImageSeconds: 300,

    /* Mirrors ChannelAd::UNMEASURED_VIDEO_SECONDS: the length a playlist line gives a video
     * the browser could not measure. A video runs to its own end — the player moves on at
     * `ended` — so this is only the backstop for a file that stalls, and it has to be
     * generous: the player cuts at this plus five seconds, and an image's six would stop a
     * perfectly good video at eleven on every screen. */
    unmeasuredVideoSeconds: 120,
};
