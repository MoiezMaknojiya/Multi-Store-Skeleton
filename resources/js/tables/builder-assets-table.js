/**
 * The Assets page of the Ad Builder: the shelf of pictures and videos the designs are made of.
 *
 * Uploading is the shared uploader's (<x-upload-dropzone>, docs/UPLOADS-SPEC.md): it sends each file in chunks,
 * measures a video in the browser and puts each on the shelf as it arrives — this page only refreshes its list and
 * its storage meter when one has (onUploaded).
 */
import { createCrudTable } from '../core/crud-table-base.js';
import { bytesInWords, storageUsedText, storagePercent } from '../core/media-file.js';

export function registerBuilderAssetsTable(Alpine) {
    Alpine.data('builderAssetsTable', (config = {}) => createCrudTable({
        fetchUrl: '/builder/assets/data',
        dataKey: 'assets',
        entityLabel: 'asset',
        deleteModalName: 'confirm-asset-deletion',

        extraState: {
            // Several files arriving together refresh the list once.
            refreshTimer: null,
            /* Above the organizations: one organization (its id), or All organizations (''), where an upload is shared with every organization. */
            filterOrganization: '',
            /* How full the organization on the shelf is ({used, limit}): its own inside an organization, the one chosen above. The page
             * brings the first answer, so the meter is there at once. */
            storage: config.storage ?? null,
        },

        extraMethods: {
            /* ── Listing filters ───────────────────────────────────────── */
            extraParams() {
                return this.filterOrganization ? { organization_id: this.filterOrganization } : {};
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
                this.refreshTimer = setTimeout(() => this.refreshInPlace(), 400);
            },

            /** A file an ad still uses cannot be deleted (the server refuses it too): said at once, with the ads that
             *  use it, rather than after a confirmation that could never succeed. */
            askToDelete(item) {
                if (item.in_use_message) {
                    window.toast(item.in_use_message);

                    return;
                }

                this.confirmDelete(item);
            },

            /** "Image · 900×900 · 18 KB": what the file is, shown over its picture on hover. */
            assetDetails(asset) {
                return [
                    asset.kind === 'video' ? 'Video' : 'Image',
                    asset.width ? `${asset.width}×${asset.height}` : null,
                    this.sizeLabel(asset),
                ].filter(Boolean).join(' · ');
            },

            /** "2.4 MB": in the words the storage meter and the uploader use, so a file reads the same size everywhere. */
            sizeLabel(asset) {
                return bytesInWords(asset.size ?? 0);
            },

            /** Named by an ad of its own shelf: an organization's file by its organization's ads, the platform's by the platform's. */
            isUsed(asset) {
                return (asset.used_by ?? []).length > 0;
            },

            /** "Used by Winter sale, Eid offer" — or nothing at all, which is why it can be deleted. */
            usageLabel(asset) {
                const used = asset.used_by ?? [];

                if (used.length === 0) return 'Not used yet';

                return `Used by ${used.length <= 2 ? used.join(', ') : `${used.slice(0, 2).join(', ')} +${used.length - 2}`}`;
            },
        },
    })());
}
