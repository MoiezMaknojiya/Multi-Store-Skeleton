/**
 * Shared playlist defaults. Mirrors PlaylistItem::DEFAULT_IMAGE_SECONDS and ::MIN_IMAGE_SECONDS so
 * the forms and the server agree on how long an image shows when nobody says, and on the least it
 * may (owner's rule, 2026-09-28: six seconds, both).
 */
export const PlaylistItemDefaults = {
    imageSeconds: 6,
    minImageSeconds: 6,
    /* Mirrors PlaylistItem::MAX_IMAGE_SECONDS: the longest one picture holds a playlist's screen (a day). */
    maxImageSeconds: 86400,

    /* Mirrors ChannelAd::UNMEASURED_VIDEO_SECONDS: the length a playlist line gives a video
     * the browser could not measure. A video runs to its own end — the player moves on at
     * `ended` — so this is only the backstop for a file that stalls, and it has to be
     * generous: the player cuts at this plus five seconds, and an image's six would stop a
     * perfectly good video at eleven on every screen. */
    unmeasuredVideoSeconds: 120,
};
