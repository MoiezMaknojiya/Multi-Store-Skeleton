/**
 * Shared playlist defaults. Mirrors PlaylistItem::DEFAULT_IMAGE_SECONDS so the
 * builder and the server agree on how long an image shows when nobody says.
 */
export const PlaylistItemDefaults = {
    imageSeconds: 10,

    /* Mirrors ChannelAd::UNMEASURED_VIDEO_SECONDS: the length a playlist line gives a video
     * the browser could not measure. A video runs to its own end — the player moves on at
     * `ended` — so this is only the backstop for a file that stalls, and it has to be
     * generous: the player cuts at this plus five seconds, and an image's ten would stop a
     * perfectly good video at fifteen on every screen. */
    unmeasuredVideoSeconds: 120,
};
