/**
 * What only a Samsung TV runs (owner, 2026-11-06: a change made for one kind of set must never reach another). The
 * player imports this chunk where its user agent says Tizen and nowhere else, and the page draws its look and its box
 * only there: an Android TV or a Fire TV never downloads it, so nothing here can change how they play. What a Samsung TV
 * does its own way goes into `own`, the player's list of such ways; what it adds it registers here.
 */
import { registerExitQuestion } from './exit-question.js';

/** @param {{emptyFrame: function(HTMLIFrameElement): void, oneVideoAtATime: boolean}} own */
export function startOnASamsungTv(own) {
    // One video at a time: a Samsung TV gives a web page's videos one hardware decoder (signage makers' notes on Tizen,
    // signageOS and Signagelive; a page with two videos "worked in the emulator but failed on a real TV"). On the TV 10.0
    // emulator a video made ready behind the one playing froze that one after 0.7 s, with no error to learn it from, and
    // its backstop moved on (2026-11-06): so the next video is made at the switch, the last picture held as a still.
    own.oneVideoAtATime = true;

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
