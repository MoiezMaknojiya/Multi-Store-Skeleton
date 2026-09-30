/**
 * A field that takes digits only — a phone number, a ZIP code: `data-digits="10"` on the input keeps whatever is
 * typed or pasted to its digits, and to that many of them (owner, 2026-09-29: "phone number ki jitni bhi field ha us
 * mein 10 se ziyada likhne hi naah do"). A letter never appears, an eleventh digit never appears, and "(555) 123-4567"
 * pasted becomes 5551234567.
 *
 * One listener for every page, in the capture phase: it cleans the value before the field's own listeners hear the
 * event, so Alpine's x-model only ever reads the clean value. Such a field carries no `maxlength`: the browser would cut
 * a pasted "(555) 123-4567" to its first ten characters before the digits were picked out. The server still decides
 * (`digits:10`, `regex:/^[0-9]+$/`).
 */
export function registerDigitsOnly() {
    document.addEventListener('input', (event) => {
        const field = event.target;

        if (!(field instanceof HTMLInputElement) || !field.dataset.digits) return;

        const most = Number.parseInt(field.dataset.digits, 10);
        const clean = field.value.replace(/\D/g, '').slice(0, most > 0 ? most : undefined);

        if (clean === field.value) return;

        // The caret stays after the digit it was after.
        const caret = field.selectionStart ?? field.value.length;
        const at = Math.min(field.value.slice(0, caret).replace(/\D/g, '').length, clean.length);

        field.value = clean;

        try {
            field.setSelectionRange(at, at);
        } catch {
            /* A field with no caret to place. */
        }
    }, true);
}
