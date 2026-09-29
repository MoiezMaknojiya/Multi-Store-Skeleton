/**
 * The Assets tab of the Ad Builder: the shelf of pictures and videos the designs are made of.
 *
 * Uploading is the shared uploader's (<x-upload-dropzone>, docs/UPLOADS-SPEC.md): it sends each file in chunks,
 * measures a video in the browser and puts each on the shelf as it arrives — this page only refreshes its list and
 * its storage meter when one has (onUploaded).
 */
import { createCrudTable } from '../core/crud-table-base.js';
import { storageUsedText, storagePercent } from '../core/media-file.js';

export function registerBuilderAssetsTable(Alpine) {
    Alpine.data('builderAssetsTable', createCrudTable({
        fetchUrl: '/builder/assets/data',
        dataKey: 'assets',
        entityLabel: 'asset',
        deleteModalName: 'confirm-asset-deletion',

        extraState: {
            // Several files arriving together refresh the list once.
            refreshTimer: null,
            /* Above the stores: one shop, or every shop (empty). */
            filterStore: '',
            /* How full the shop on the shelf is ({used, limit}): its own inside a store, the one chosen above. */
            storage: null,
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

            afterFetch(data) {
                this.storage = data.storage ?? null;
            },

            storageText() {
                return storageUsedText(this.storage);
            },

            storageLevel() {
                return storagePercent(this.storage);
            },

            /** A file went onto the shelf: its storage now, and the list once the files arriving together are in. */
            onUploaded(detail) {
                this.storage = detail?.response?.storage ?? this.storage;

                clearTimeout(this.refreshTimer);
                this.refreshTimer = setTimeout(() => this.fetchItems(), 400);
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
