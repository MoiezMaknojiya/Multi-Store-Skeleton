/**
 * Media library Alpine component.
 *
 * Differences from the other CRUD tables:
 *  - creating a row means uploading FILES: the shared uploader on the page
 *    (<x-upload-dropzone>, docs/UPLOADS-SPEC.md) sends each in chunks, measures a
 *    video in the browser and adds each to the library as it arrives — this page only
 *    refreshes its list and its storage meter when one has (onUploaded);
 *  - above the organizations the page reads one library at a time — the platform's own
 *    or an organization's — and an upload joins the one chosen (docs/CHANNEL-CONTENT-SPEC.md);
 *  - a file a channel shows is refused before the delete is confirmed: the row
 *    carries the server's own words for it;
 *  - a file keeps its name alone (owner, 2026-10-01): Rename is the shared save of
 *    one field, and when a file plays is said on its playlist line.
 */
import { createCrudTable } from '../core/crud-table-base.js';
import { bytesInWords, storageUsedText, storagePercent } from '../core/media-file.js';
import { validate, required, maxLen } from '../core/validate.js';

export function registerMediaTable(Alpine) {
    Alpine.data('mediaTable', (config = {}) => createCrudTable({
        fetchUrl: '/media/data',
        dataKey: 'media',
        entityLabel: 'file',
        formModalName: 'media-form-modal',
        deleteModalName: 'confirm-media-deletion',

        extraState: {
            // Above the organizations, every organization [{id, name}] and the library shown: 'platform' or an organization's id.
            // Inside an organization there is only the organization's own, and no choosing (null).
            libraries: config.libraries ?? null,
            library: 'platform',
            filterType: '',
            filterOrientation: '',
            sort: 'newest',
            // How full the library on the page is ({used, limit}), or null for one with no wall (the platform's). The
            // page brings the first answer, so the meter does not appear, and push the page down, once the list is in.
            storage: config.storage ?? null,
            // Several files arriving together refresh the list once.
            refreshTimer: null,
        },

        defaultForm: { title: '' },

        mapItemToForm: (media) => ({ title: media.title }),

        validateForm: (form) => validate(form, { title: [required('Title'), maxLen('Title', 255)] }),

        extraMethods: {
            /* ── Listing filters ───────────────────────────────────────── */
            extraParams() {
                return {
                    type: this.filterType,
                    orientation: this.filterOrientation,
                    sort: this.sort,
                    ...(this.libraries !== null ? { library: this.library } : {}),
                };
            },

            applyFilters() {
                this.currentPage = 1;
                this.fetchItems();
            },

            afterFetch(data) {
                this.storage = data.storage ?? null;
            },

            storageText() {
                return storageUsedText(this.storage);
            },

            storageLevel() {
                return storagePercent(this.storage);
            },

            /* ── Upload ────────────────────────────────────────────────── */
            /** A file joined the library: its storage now, and the list once the files arriving together are in. */
            onUploaded(detail) {
                this.storage = detail?.response?.storage ?? this.storage;

                clearTimeout(this.refreshTimer);
                this.refreshTimer = setTimeout(() => {
                    this.currentPage = 1;
                    this.fetchItems();
                }, 400);
            },

            /* ── Delete ────────────────────────────────────────────────── */
            /** A file a channel shows cannot be deleted (the server refuses it too): said at once, rather
             *  than after a confirmation that could never succeed. */
            askToDelete(item) {
                if (item.in_channels_message) {
                    window.toast(item.in_channels_message);
                    return;
                }

                this.confirmDelete(item);
            },

            /* ── Display helpers ───────────────────────────────────────── */
            /** What a person calls the file: the library lists photographs and videos alone. */
            typeLabel(item) {
                return { image: 'Image', video: 'Video' }[item.type] ?? item.type;
            },

            /** In the words the storage meter and the uploader use, so a file reads the same size everywhere. */
            formatSize(bytes) {
                return bytes ? bytesInWords(bytes) : '-';
            },

            formatDuration(seconds) {
                if (!seconds) return '';
                const minutes = Math.floor(seconds / 60);
                return `${minutes}:${String(seconds % 60).padStart(2, '0')}`;
            },
        },
    })());
}
