/**
 * Media library Alpine component.
 *
 * Differences from the other CRUD tables:
 *  - creating a row means uploading FILES: the upload modal holds the shared uploader
 *    (<x-upload-dropzone>, docs/UPLOADS-SPEC.md), which sends each in chunks, measures a
 *    video in the browser and adds each to the library as it arrives — this page only
 *    refreshes its list and its storage meter when one has (onUploaded);
 *  - above the stores the page reads one library at a time — the platform's own
 *    or a shop's — and an upload joins the one chosen (docs/CHANNEL-CONTENT-SPEC.md);
 *  - a file a channel shows is refused before the delete is confirmed: the row
 *    carries the server's own words for it.
 */
import axios from 'axios';
import { takeAddressFlag } from '../core/address-flag.js';
import { createCrudTable } from '../core/crud-table-base.js';
import { storageUsedText, storagePercent } from '../core/media-file.js';
import { validate, required, maxLen, unreadableFields } from '../core/validate.js';

/** ISO instant -> the value a datetime-local input expects, in the viewer's own
 *  timezone, so a shop owner in Karachi sees the time they typed. */
function toLocalInput(iso) {
    if (!iso) return '';
    const date = new Date(iso);
    if (Number.isNaN(date.getTime())) return '';
    const pad = (n) => String(n).padStart(2, '0');
    return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}T${pad(date.getHours())}:${pad(date.getMinutes())}`;
}

/** datetime-local value -> an unambiguous instant for the server. */
function toInstant(local) {
    if (!local) return null;
    const date = new Date(local);
    return Number.isNaN(date.getTime()) ? null : date.toISOString();
}

export function registerMediaTable(Alpine) {
    Alpine.data('mediaTable', (config = {}) => createCrudTable({
        fetchUrl: '/media/data',
        dataKey: 'media',
        entityLabel: 'file',
        formModalName: 'media-form-modal',
        deleteModalName: 'confirm-media-deletion',

        extraState: {
            // Above the stores, every shop [{id, name}] and the library shown: 'platform' or a shop's id.
            // Inside a store there is only the store's own, and no choosing (null).
            libraries: config.libraries ?? null,
            library: 'platform',
            filterType: '',
            filterOrientation: '',
            sort: 'newest',
            // How full the library on the page is ({used, limit}), or null for one with no wall (the platform's).
            storage: null,
            // Several files arriving together refresh the list once.
            refreshTimer: null,
        },

        defaultForm: { title: '', description: '', starts_at: '', expires_at: '' },

        mapItemToForm: (media) => ({
            title: media.title,
            description: media.description ?? '',
            starts_at: toLocalInput(media.starts_at),
            expires_at: toLocalInput(media.expires_at),
        }),

        extraMethods: {
            /** Sent here to upload (the dashboard's Upload files): the uploader at once, for somebody who may upload. */
            onInit() {
                if (takeAddressFlag('upload') && document.querySelector('[dusk="upload-media"]')) this.$nextTick(() => this.openUploadModal());
            },

            /* ── Listing filters ───────────────────────────────────────── */
            extraParams() {
                return {
                    type: this.filterType,
                    orientation: this.filterOrientation,
                    sort: this.sort,
                    ...(this.libraries !== null ? { library: this.library } : {}),
                };
            },

            /** Where an upload from this page goes, in words. */
            libraryName() {
                if (this.library === 'platform') return 'the platform\'s library';

                const shop = (this.libraries ?? []).find((each) => String(each.id) === String(this.library));

                return shop ? `${shop.name}'s library` : 'the chosen library';
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
            /** The uploader in the modal keeps its files: open it again and they are still there, going or gone. */
            openUploadModal() {
                this.$dispatch('open-modal', 'media-upload-modal');
            },

            /** Closing it lets the uploads carry on. */
            closeUploadModal() {
                this.$dispatch('close-modal', 'media-upload-modal');
            },

            /** A file joined the library: its storage now, and the list once the files arriving together are in. */
            onUploaded(detail) {
                this.storage = detail?.response?.storage ?? this.storage;

                clearTimeout(this.refreshTimer);
                this.refreshTimer = setTimeout(() => {
                    this.currentPage = 1;
                    this.fetchItems();
                }, 400);
            },

            /* ── Edit (title, description, schedule) ───────────────────── */
            async saveItem(event) {
                if (this.saving || !this.editingItem) return;

                // A start or an expiry typed only in part reads as '' — never saved as "no expiry", which would let
                // the file play for ever (unreadableFields, as the shared save does).
                const errors = {
                    ...validate(this.form, {
                        title: [required('Title'), maxLen('Title', 255)],
                        description: [maxLen('Description', 2000)],
                    }),
                    ...unreadableFields(event?.target),
                };

                // Mirrors the backend's after:starts_at rule.
                if (this.form.starts_at && this.form.expires_at
                    && new Date(this.form.expires_at) <= new Date(this.form.starts_at)) {
                    errors.expires_at = ['The expiry date must be after the start date.'];
                }

                if (Object.keys(errors).length > 0) {
                    this.formErrors = errors;
                    this.focusFirstError();
                    return;
                }

                this.formErrors = {};
                this.saving = true;
                try {
                    const { data } = await axios.put(`/media/${this.editingItem.id}`, {
                        title: this.form.title,
                        description: this.form.description,
                        starts_at: toInstant(this.form.starts_at),
                        expires_at: toInstant(this.form.expires_at),
                    });
                    this.closeFormModal();
                    window.toast(data?.message ?? 'File saved.', 'success');
                    await this.fetchItems();
                } catch (error) {
                    if (error.response?.status === 422 && error.response.data.errors) {
                        this.formErrors = error.response.data.errors;
                        this.focusFirstError();
                    } else {
                        window.toast(error.response?.data?.message ?? 'An error occurred. Please try again.');
                    }
                } finally {
                    this.saving = false;
                }
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
            /** What a person calls the file. A page published from the Ad Builder is stored as
             *  type "html", which is nobody's word for it — and it is not an image either. */
            typeLabel(item) {
                return { image: 'Image', video: 'Video', html: 'Ad page' }[item.type] ?? item.type;
            },

            formatSize(bytes) {
                if (!bytes) return '-';
                const units = ['B', 'KB', 'MB', 'GB'];
                let value = bytes;
                let unit = 0;
                while (value >= 1024 && unit < units.length - 1) {
                    value /= 1024;
                    unit++;
                }
                return `${value < 10 && unit > 0 ? value.toFixed(1) : Math.round(value)} ${units[unit]}`;
            },

            formatDuration(seconds) {
                if (!seconds) return '';
                const minutes = Math.floor(seconds / 60);
                return `${minutes}:${String(seconds % 60).padStart(2, '0')}`;
            },

            /* "From Oct 1, 2026, 9:00 AM" / "Until …" / "From … to …" — a date and a time, no seconds. */
            scheduleLabel(item) {
                if (!item.starts_at && !item.expires_at) return 'Always';
                const when = (value) => new Date(value).toLocaleString(undefined, { dateStyle: 'medium', timeStyle: 'short' });
                if (item.starts_at && item.expires_at) return `From ${when(item.starts_at)} to ${when(item.expires_at)}`;
                return item.starts_at ? `From ${when(item.starts_at)}` : `Until ${when(item.expires_at)}`;
            },

            isExpired(item) {
                return !!item.expires_at && new Date(item.expires_at) <= new Date();
            },
        },
    })());
}
