/**
 * Text put on the clipboard: true once it is there. The Clipboard API first; where it is missing or refuses (a page that
 * is not https, or a browser that wants the press itself when a request came between), the field showing the text is
 * selected and the browser's own copy is asked. A refusal leaves the field selected, for the person to copy by hand.
 */
export async function copyText(text, field = null) {
    try {
        if (navigator.clipboard?.writeText) {
            await navigator.clipboard.writeText(text);

            return true;
        }
    } catch {
        // The field below.
    }

    if (!field) return false;

    field.focus();
    field.select();

    try {
        return document.execCommand('copy');
    } catch {
        return false;
    }
}
