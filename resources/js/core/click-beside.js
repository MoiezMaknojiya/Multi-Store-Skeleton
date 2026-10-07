/**
 * A dialog and a double click (owner, 2026-10-07: "delete ka button dabata hu toh popup khul k band ho jata ha bhoht
 * teezi mein aur kabhi kabhi toh editor mein chale jata ha"). A person's double click carries its click count on every
 * press (`detail`), and a dialog comes and goes between the two presses:
 *  - opened by the first press, the dialog is drawn over its button, and the second press lands on its backdrop —
 *    which must not shut it (isAClickBeside);
 *  - shut by the first press (Cancel, Close, Delete Ad, a font picked), the dialog is gone from under the pointer, and
 *    the second press lands on whatever lay beneath it — another row's Delete, a poster that opens the editor — which
 *    must not hear it (registerStrayClickGuard, told by noteShut).
 * Within a double click's time, the second press is the same gesture as the first: never a new one.
 */
const DOUBLE_CLICK_MS = 500;

let shutAt = -Infinity;

/** A click beside an open dialog that means it: not the rest of the multi-click that opened it, nor right after. */
export function isAClickBeside(event, openedAt) {
    return event.detail <= 1 && performance.now() - openedAt >= DOUBLE_CLICK_MS;
}

/** The second or third press of a multi-click: the first already did what the button does — Copy, Resend. */
export function isARepeatPress(event) {
    return (event?.detail ?? 0) > 1;
}

/** A dialog or an overlay has just shut (core/modal.js, the editor's overlays). */
export function noteShut() {
    shutAt = performance.now();
}

/** The rest of a multi-click that shut a dialog is dropped before anything on the page beneath hears it. */
export function registerStrayClickGuard() {
    const dropTheRest = (event) => {
        if (event.detail > 1 && performance.now() - shutAt < DOUBLE_CLICK_MS) {
            event.preventDefault();
            event.stopImmediatePropagation();
        }
    };

    document.addEventListener('click', dropTheRest, true);
    document.addEventListener('dblclick', dropTheRest, true);
}
