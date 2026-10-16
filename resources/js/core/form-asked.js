/**
 * A save's answer is about its own question alone (the review of 2026-10-15). A slow save leaves the person free to shut
 * its dialog — Escape, then Discard Changes — and open it again for another row; the first answer then shut the second
 * dialog, the typing in it lost, or put its refusal under the second one's fields. The deletes keep to their own row the
 * same way (crud-table-base's deleteItem). And a form that carries an upload lets go of its box the same way: the upload
 * a save on its way carries is never given up under it — the page holds the box meanwhile (its holdsTheSavedUpload, bound
 * to `inert` around the box), so neither the box's × nor a new file can give it up either.
 */

/**
 * Whether the form a save was sent from is still the one asked: not opened again since (`ticket` reads the number the page
 * moves each time it opens the form) and still on the screen (`form`, the element sent from — its dialog may have been
 * shut; null when the page has none to hand, and the number alone decides).
 */
export function formStillAsked(ticket, form = null) {
    const asked = ticket();
    const onScreen = () => !form || (form.isConnected && form.getClientRects().length > 0);

    return () => ticket() === asked && onScreen();
}

/** A refusal whose form is no longer asked, in a toast's words: what was not saved (or sent, added …: `verb`) and the
 *  first reason — or null, when the answer names no reason under a field. */
export function notSavedWords(errors, name = '', verb = 'saved') {
    const reason = Object.values(errors ?? {}).flat().find((words) => typeof words === 'string' && words !== '');

    if (!reason) return null;

    const named = String(name ?? '').trim();

    return named ? `${named} was not ${verb}: ${reason}` : `Not ${verb}: ${reason}`;
}

/**
 * A form's upload box (<x-upload-dropzone mode="form">, found on the page by `selector`) emptied: a file still going is
 * cancelled and one that came is given up on the server, as the box's own × does — but not `keep`, the upload a save on its
 * way carries: given up then, the save would find it gone, and once saved the server has forgotten it already.
 */
export function emptyTheBox(selector, keep = null) {
    const box = document.querySelector(selector);

    if (!box) return;

    const dropzone = window.Alpine.$data(box);

    if (keep) dropzone.uploads.filter((row) => row.uploadId === keep).forEach((row) => { row.uploadId = null; });
    dropzone.clear();
}

/** An upload nothing will use now — its form shut while the save carrying it was on its way, and the save refused — given
 *  up on the server by the box's own give-up, unless the refusal says the server gave it up itself (upload_given_up). */
export function giveUpUpload(selector, id, refusal = null) {
    const box = document.querySelector(selector);

    if (box && id && !refusal?.response?.data?.upload_given_up) window.Alpine.$data(box).giveUp(id);
}
