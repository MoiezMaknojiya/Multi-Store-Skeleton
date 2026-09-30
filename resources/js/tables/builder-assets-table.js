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
            /* Above the stores: one shop (its id), the files shared with every shop ('shared'), or everything (''). */
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

            /** Named by any ad — this shop's, or another shop's for a file shared with every shop. */
            isUsed(asset) {
                return (asset.used_by ?? []).length > 0 || Number(asset.used_elsewhere ?? 0) > 0;
            },

            /**
             * "Used by Winter sale, Eid offer" — or nothing at all, which is why it can be deleted. Another shop's ads
             * using a shared file are counted, never named.
             */
            usageLabel(asset) {
                const used = asset.used_by ?? [];
                const elsewhere = Number(asset.used_elsewhere ?? 0);
                const others = elsewhere > 0 ? `${elsewhere} ${elsewhere === 1 ? 'ad' : 'ads'} of other shops` : '';

                if (used.length === 0) return others ? `Used by ${others}` : 'Not used yet';

                const named = used.length <= 2 ? used.join(', ') : `${used.slice(0, 2).join(', ')} +${used.length - 2}`;

                return others ? `Used by ${named} and ${others}` : `Used by ${named}`;
            },
        },
    }));
}
