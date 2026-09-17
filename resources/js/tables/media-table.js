/**
 * Media library Alpine component.
 *
 * Two differences from the other CRUD tables:
 *  - creating a row means uploading a FILE, so it posts FormData from its own
 *    modal instead of the base's JSON create path;
 *  - a video's duration, dimensions and poster frame are measured HERE, in the
 *    browser, because the server has no ffmpeg. The values are sent along with
 *    the upload and re-validated server-side (see StoreMediaRequest).
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
            // Uploads need a store context; the banner in the modal uses this.
            hasStore: config.hasStore ?? false,
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
                };
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
                        this.clientMeta = await readVideoMeta(file);
                    } finally {
                        this.preparing = false;
                    }
                }
            },



            async uploadFile() {
                if (this.saving) return;

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

            /* ── Display helpers ───────────────────────────────────────── */
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
