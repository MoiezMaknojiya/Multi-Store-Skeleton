/**
 * Media library Alpine component.
 *
 * Differences from the other CRUD tables:
 *  - creating a row means uploading a FILE, so it posts FormData from its own
 *    modal instead of the base's JSON create path;
 *  - a video's duration, dimensions and poster frame are measured HERE, in the
 *    browser, because the server has no ffmpeg. The values are sent along with
 *    the upload and re-validated server-side (see StoreMediaRequest);
 *  - above the stores the page reads one library at a time — the platform's own
 *    or a shop's — and an upload joins the one chosen (docs/CHANNEL-CONTENT-SPEC.md);
 *  - a file a channel shows is refused before the delete is confirmed: the row
 *    carries the server's own words for it.
 */
import axios from 'axios';
import { createCrudTable } from '../core/crud-table-base.js';
/* Shared with the advertising campaigns page — both upload the same kinds of file
 * and both need a video measured in the browser. */
import { fileError, readVideoMeta } from '../core/media-file.js';
import { validate, required, maxLen } from '../core/validate.js';

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
            uploadForm: { title: '' },
            selectedFile: null,
            clientMeta: {},
            preparing: false,
        },

        defaultForm: { title: '', description: '', starts_at: '', expires_at: '' },

        mapItemToForm: (media) => ({
            title: media.title,
            description: media.description ?? '',
            starts_at: toLocalInput(media.starts_at),
            expires_at: toLocalInput(media.expires_at),
        }),

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

            /* ── Upload ────────────────────────────────────────────────── */
            openUploadModal() {
                this.uploadForm = { title: '' };
                this.selectedFile = null;
                this.clientMeta = {};
                this.formErrors = {};
                if (this.$refs.fileInput) this.$refs.fileInput.value = '';
                this.$dispatch('open-modal', 'media-upload-modal');
            },

            closeUploadModal() {
                this.$dispatch('close-modal', 'media-upload-modal');
                this.selectedFile = null;
                this.clientMeta = {};
                this.formErrors = {};
                this.preparing = false;
                // Clear the native input too, so reopening never shows a stale filename.
                if (this.$refs.fileInput) this.$refs.fileInput.value = '';
            },

            async onFileSelected(event) {
                const file = event.target.files?.[0] ?? null;
                this.selectedFile = file;
                this.clientMeta = {};
                this.formErrors = {};
                // Whatever an earlier pick was still measuring no longer matters.
                this.preparing = false;
                if (!file) return;

                const error = fileError(file);
                if (error) {
                    this.formErrors = { file: [error] };
                    this.selectedFile = null;
                    return;
                }

                if (file.type.startsWith('video/')) {
                    this.preparing = true;
                    try {
                        const meta = await readVideoMeta(file);
                        // Another file was chosen while this one was measured: its numbers are
                        // not that file's, and must not ride along with its upload.
                        if (this.selectedFile === file) this.clientMeta = meta;
                    } finally {
                        if (this.selectedFile === file) this.preparing = false;
                    }
                }
            },

            async uploadFile() {
                // Not while a video is still being measured: it would go without its length and poster.
                if (this.saving || this.preparing) return;

                if (!this.selectedFile) {
                    this.formErrors = { file: ['Choose a file to upload.'] };
                    return;
                }

                this.formErrors = {};
                this.saving = true;
                try {
                    const payload = new FormData();
                    payload.append('file', this.selectedFile);
                    if (this.uploadForm.title) payload.append('title', this.uploadForm.title);
                    // Above the stores: the shop chosen, or — with none — the platform's own library.
                    if (this.libraries !== null && this.library !== 'platform') payload.append('store_id', this.library);
                    Object.entries(this.clientMeta).forEach(([key, value]) => {
                        if (value !== null && value !== undefined) payload.append(key, value);
                    });

                    await axios.post('/media', payload);
                    this.closeUploadModal();
                    this.currentPage = 1;
                    await this.fetchItems();
                } catch (error) {
                    if (error.response?.status === 422 && error.response.data.errors) {
                        this.formErrors = error.response.data.errors;
                    } else {
                        window.toast(error.response?.data?.message ?? 'Upload failed. Please try again.');
                    }
                } finally {
                    this.saving = false;
                }
            },

            /* ── Edit (title, description, schedule) ───────────────────── */
            async saveItem() {
                if (this.saving || !this.editingItem) return;

                const errors = validate(this.form, {
                    title: [required('Title'), maxLen('Title', 255)],
                    description: [maxLen('Description', 2000)],
                });

                // Mirrors the backend's after:starts_at rule.
                if (this.form.starts_at && this.form.expires_at
                    && new Date(this.form.expires_at) <= new Date(this.form.starts_at)) {
                    errors.expires_at = ['The expiry date must be after the start date.'];
                }

                if (Object.keys(errors).length > 0) {
                    this.formErrors = errors;
                    return;
                }

                this.formErrors = {};
                this.saving = true;
                try {
                    await axios.put(`/media/${this.editingItem.id}`, {
                        title: this.form.title,
                        description: this.form.description,
                        starts_at: toInstant(this.form.starts_at),
                        expires_at: toInstant(this.form.expires_at),
                    });
                    this.closeFormModal();
                    await this.fetchItems();
                } catch (error) {
                    if (error.response?.status === 422 && error.response.data.errors) {
                        this.formErrors = error.response.data.errors;
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

            scheduleLabel(item) {
                if (!item.starts_at && !item.expires_at) return 'Always';
                const from = item.starts_at ? new Date(item.starts_at).toLocaleString() : 'now';
                const until = item.expires_at ? new Date(item.expires_at).toLocaleString() : 'no end';
                return `${from} - ${until}`;
            },

            isExpired(item) {
                return !!item.expires_at && new Date(item.expires_at) <= new Date();
            },
        },
    })());
}
