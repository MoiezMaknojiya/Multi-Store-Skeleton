/**
 * The Assets tab of the Ad Builder: the shelf of pictures and videos the designs are made of.
 *
 * Uploading measures a video in the BROWSER before sending it (there is no ffmpeg on the server), exactly
 * the way the media library does — the same helpers, so a format added in one place is added in both.
 */
import axios from 'axios';
import { createCrudTable } from '../core/crud-table-base.js';
import { fileError, readVideoMeta } from '../core/media-file.js';

export function registerBuilderAssetsTable(Alpine) {
    Alpine.data('builderAssetsTable', createCrudTable({
        fetchUrl: '/builder/assets/data',
        dataKey: 'assets',
        entityLabel: 'asset',
        deleteModalName: 'confirm-asset-deletion',

        extraState: {
            uploading: false,
            /* Above the stores: one shop, or every shop (empty). */
            filterStore: '',
        },

        extraMethods: {
            /* ── Listing filters ───────────────────────────────────────── */
            extraParams() {
                return this.filterStore ? { store_id: this.filterStore } : {};
            },

            applyFilters() {
                this.currentPage = 1;
                this.fetchItems();
            },

            /** The file picker's change handler: check, measure, send. */
            async upload(event) {
                const file = event.target.files?.[0];
                event.target.value = '';           // so picking the same file twice still fires

                if (!file || this.uploading) return;

                const problem = fileError(file);

                if (problem) {
                    window.toast(problem);

                    return;
                }

                this.uploading = true;

                try {
                    const form = new FormData();
                    form.append('file', file);

                    // Above the stores the file goes to the shop chosen in the Shop list; the server
                    // refuses "All shops" with a message saying so. A store's own people have no list.
                    if (this.filterStore) form.append('store_id', this.filterStore);

                    // A video's length, size and first frame are measured here, because the server cannot.
                    if (file.type.startsWith('video/')) {
                        const meta = await readVideoMeta(file);

                        if (meta.duration_seconds) form.append('duration_seconds', meta.duration_seconds);
                        if (meta.width) form.append('width', meta.width);
                        if (meta.height) form.append('height', meta.height);
                        if (meta.poster) form.append('poster', meta.poster);
                    }

                    const { data } = await axios.post('/builder/assets', form);
                    window.toast(data.message, 'success');
                    await this.fetchItems();
                } catch (error) {
                    const message = error.response?.data?.errors?.file?.[0]
                        ?? error.response?.data?.message
                        ?? 'Could not upload this file.';
                    window.toast(message);
                } finally {
                    this.uploading = false;
                }
            },

            /** "2.4 MB" — a size a person reads, not a number of bytes. */
            sizeLabel(asset) {
                const mb = (asset.size ?? 0) / (1024 * 1024);

                return mb >= 1 ? `${mb.toFixed(1)} MB` : `${Math.max(1, Math.round((asset.size ?? 0) / 1024))} KB`;
            },

            /** "Used by Winter sale, Eid offer" — or nothing at all, which is why it can be deleted. */
            usageLabel(asset) {
                const used = asset.used_by ?? [];

                if (used.length === 0) return 'Not used yet';

                return used.length <= 2
                    ? `Used by ${used.join(', ')}`
                    : `Used by ${used.slice(0, 2).join(', ')} +${used.length - 2}`;
            },
        },
    }));
}
