/**
 * A file dropped anywhere but on an upload box is not opened by the browser in place of the panel — which threw away
 * the page and any form half filled in around the box (a channel's Add Ad, a campaign). The boxes take their own drop
 * first (components/upload-dropzone.blade.php, `@drop.prevent`), and anything else that takes a drag (the editor's
 * layers) carries no files, so this answers only what nobody else did: the drop goes nowhere, and the pointer says so.
 */
const carriesFiles = (event) => Array.from(event.dataTransfer?.types ?? []).includes('Files');

export function registerDropGuard() {
    window.addEventListener('dragover', (event) => {
        if (event.defaultPrevented || !carriesFiles(event)) return;
        event.preventDefault();
        event.dataTransfer.dropEffect = 'none';
    });

    window.addEventListener('drop', (event) => {
        if (event.defaultPrevented || !carriesFiles(event)) return;
        event.preventDefault();
    });
}
