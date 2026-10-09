/**
 * A dialog and a double click (owner, 2026-10-07: "delete ka button dabata hu toh popup khul k band ho jata ha bhoht
 * teezi mein aur kabhi kabhi toh editor mein chale jata ha"). A person's double click carries its click count on every
 * press (`detail`), and a dialog comes and goes between the two presses:
 *  - opened by the first press, the dialog is drawn over its button, and the second press lands on its backdrop —
 *    which must not shut it (isAClickBeside);
 *  - shut by the first press (Cancel, Close, Delete Ad, a font picked), the dialog is gone from under the pointer, and
 *    the second press lands on whatever lay beneath it — another row's Delete, a poster that opens the editor — which
 *    must not hear it (registerStrayClickGuard, told by noteShut);
 *  - on a switch, a toggle or a menu button the second press turns it straight back (owner, 2026-10-08: the Billing
 *    switch went on and off at one press of a mouse that sends two), so it is dropped too (registerStrayClickGuard);
 *  - a select's list, or a date's, a time's or a colour's picker, is the browser's own: the first press opens it and the
 *    second shuts it, before any page can stop it (owner, 2026-10-09: "single click per dropdown ... khul k band ho jata
 *    ha"), so one the first press opened is opened again at once (registerStrayClickGuard).
 * Within a double click's time, the second press is the same gesture as the first: never a new one.
 */
const DOUBLE_CLICK_MS = 500;

/** What one press turns: a switch, a toggle and a menu or disclosure button. */
const TOGGLES = '[role="switch"], [aria-pressed], [aria-expanded]';

/** What the browser opens a picker of its own for. */
const PICKERS = 'select, input[type="date"], input[type="time"], input[type="datetime-local"], input[type="month"], input[type="week"], input[type="color"]';

/** Whether a picker is open now — false in a browser that cannot say (no :open). */
function pickerIsOpen(element) {
    try {
        return element.matches(':open');
    } catch {
        return false;
    }
}

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

/**
 * The rest of a multi-click that shut a dialog is dropped before anything on the page beneath hears it, and so is the rest
 * of one on a toggle — its click only: its dblclick still goes through (a layer's name is renamed by one).
 */
export function registerStrayClickGuard() {
    const dropTheRest = (event) => {
        if (event.detail > 1 && performance.now() - shutAt < DOUBLE_CLICK_MS) {
            event.preventDefault();
            event.stopImmediatePropagation();
        }
    };
    const turnOnce = (event) => {
        if (event.detail > 1 && event.target.closest?.(TOGGLES)) {
            event.preventDefault();
            event.stopImmediatePropagation();
        }
    };
    // The first press says whether it opened the picker (read once the browser has done it); the rest of the multi-click
    // opens one it shut again. A first press that opened nothing — a day picked out of a date's text — leaves the rest alone.
    let openedByTheFirstPress = null;
    const keepThePickerOpen = (event) => {
        const picker = event.target.closest?.(PICKERS);

        if (!picker) return;

        if (event.detail <= 1) {
            openedByTheFirstPress = null;
            setTimeout(() => { openedByTheFirstPress = pickerIsOpen(picker) ? picker : null; }, 0);

            return;
        }

        if (picker === openedByTheFirstPress && !pickerIsOpen(picker) && typeof picker.showPicker === 'function') {
            try {
                picker.showPicker();
            } catch {
                // Not allowed here: the browser's own way stands.
            }
        }
    };

    document.addEventListener('click', dropTheRest, true);
    document.addEventListener('click', turnOnce, true);
    document.addEventListener('click', keepThePickerOpen, true);
    document.addEventListener('dblclick', dropTheRest, true);
}
