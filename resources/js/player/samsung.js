/**
 * What only a Samsung TV runs (owner, 2026-11-06: a change made for one kind of set must never reach another). The
 * player imports this chunk where its user agent says Tizen and nowhere else, and the page draws its look and its box
 * only there: an Android TV or a Fire TV never downloads it, so nothing here can change how they play. What a Samsung TV
 * does its own way goes into `own`, the player's list of such ways; what it adds it registers here.
 */
import { registerExitQuestion } from './exit-question.js';

/** @param {{emptyFrame: function(HTMLIFrameElement): void}} own */
export function startOnASamsungTv(own) {
    // A page's frame emptied for a break by REPLACING its page: setting its src wrote a history entry at every break,
    // so the app's Exit (history back) only went back inside the frame, and after a few days of breaks the browser's
    // 50 entries no longer held the app's own page at all. Location.replace may be called on a frame of another origin.
    own.emptyFrame = (frame) => {
        try {
            frame.contentWindow.location.replace('about:blank');
        } catch {
            frame.src = 'about:blank';
        }
    };

    registerExitQuestion();
}
