/**
 * The Ads page of the Ad Builder: a gallery of saved designs.
 *
 * Unlike the other listings there is no form modal — an ad is made in the editor, not in a dialog — so
 * this adds only the two row actions the gallery offers beside Edit: Copy (a draft to work from) and
 * Delete (a big delete, so the shared password confirmation).
 */
import axios from 'axios';
import { takeAddressFlag } from '../core/address-flag.js';
import { createCrudTable } from '../core/crud-table-base.js';

export function registerAdsTable(Alpine) {
    Alpine.data('adsTable', createCrudTable({
        fetchUrl: '/builder/data',
        dataKey: 'ads',
        entityLabel: 'ad',
        deleteModalName: 'confirm-ad-deletion',
        deleteNeedsPassword: true,

        extraState: {
            /* The card whose Copy is in flight, so only that button goes quiet. */
            busyId: null,
            /* Above the stores: one shop, or every shop (empty). */
            filterStore: '',
        },

        extraMethods: {
            /** Sent here to start a new ad (/builder/create with no shape chosen): New ad's question, at once. */
            onInit() {
                // The dialog is on the page only for somebody who may create an ad.
                if (takeAddressFlag('new')) this.$nextTick(() => this.$dispatch('open-modal', 'new-ad-orientation'));
            },

            /* ── Listing filters ───────────────────────────────────────── */
            extraParams() {
                return this.filterStore ? { store_id: this.filterStore } : {};
            },

            applyFilters() {
                this.currentPage = 1;
                this.fetchItems();
            },

            /** A design whose published page a channel shows cannot be deleted (the server refuses it too): said at
             *  once, rather than after the password. */
            askToDelete(item) {
                if (item.in_channels_message) {
                    window.toast(item.in_channels_message);

                    return;
                }

                this.confirmDelete(item);
            },

            /** A copy to work from. The server names it, so two people copying at once cannot collide. */
            async duplicate(ad) {
                if (this.busyId) return;
                this.busyId = ad.id;

                try {
                    const { data } = await axios.post(`/builder/${ad.id}/duplicate`);
                    window.toast(data.message, 'success');
                    await this.fetchItems();
                } catch (error) {
                    window.toast(error.response?.data?.message ?? 'Could not copy this ad.');
                } finally {
                    this.busyId = null;
                }
            },
        },
    }));
}
