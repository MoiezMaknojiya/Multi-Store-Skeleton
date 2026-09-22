/**
 * Stores table Alpine component — every store, from the platform's side (docs/STORE-ORGANIZATION-SPEC.md
 * rule 18). Extends the shared CRUD table with an owner invitation on create, Invite owner for a store
 * that has none, typed-name deletion and the network-ads bulk switch. What each row allows comes from the
 * server (`can`).
 */
import axios from 'axios';
import { createCrudTable } from '../core/crud-table-base.js';
import { validate, required, maxLen, digitsOnly, emailFormat } from '../core/validate.js';

export function registerStoresTable(Alpine) {
    Alpine.data('storesTable', (config = {}) => createCrudTable({
        fetchUrl: '/stores/data',
        dataKey: 'stores',
        entityLabel: 'store',
        formModalName: 'store-form-modal',
        deleteModalName: 'confirm-store-deletion',
        extraState: {
            /* Deleting asks for the store's name, typed exactly (the server checks it too). */
            deleteConfirmName: '',
            deleteErrors: {},

            /* Giving a store without an owner an Owner. */
            ownerStore: null,
            ownerEmail: '',
            ownerErrors: {},
            invitingOwner: false,

            /* Network advertising — the platform owner's bulk switch. The page renders it for a super admin
             * only (@if), and the route says no to everybody else regardless. */
            selectedStoreIds: [],
            pendingAdsAccepts: null,   // what the confirm modal is about to do
            savingAds: false,
        },
        defaultForm: {
            name: '', street: '', suite: '', city: '',
            state: '', zip_code: '', country: 'USA', is_active: true, owner_email: '',
        },

        /* Mirrors StoreController's rules (the 50-state whitelist stays backend-only —
         * the state field is a fixed dropdown anyway). The owner's email is asked only
         * when the store is created: after that, Owners come by Invite owner, or from
         * inside the store. */
        validateForm: (form, editingItem) => validate(form, {
            name: [required('Name'), maxLen('Name', 255)],
            street: [required('Street'), maxLen('Street', 255)],
            suite: [maxLen('Suite', 100)],
            city: [required('City'), maxLen('City', 100)],
            state: [required('State')],
            zip_code: [required('Zip code'), digitsOnly('Zip code'), maxLen('Zip code', 10)],
            country: [required('Country'), maxLen('Country', 100)],
            ...(editingItem ? {} : { owner_email: [required('Owner email'), emailFormat('Owner email'), maxLen('Owner email', 255)] }),
        }),
        /* Creating a store emails its owner an invitation — the row cannot say so, the toast does. */
        onSaved: (data) => window.toast(data.message, data.email_sent === false ? 'error' : 'success'),
        mapItemToForm: (store) => ({
            name: store.name,
            street: store.street ?? '',
            suite: store.suite ?? '',
            city: store.city ?? '',
            state: store.state ?? '',
            zip_code: store.zip_code ?? '',
            country: store.country ?? '',
            is_active: store.is_active,
        }),

        extraMethods: {
            /* ── Owners ────────────────────────────────────────────────── */

            openInviteOwner(store) {
                this.ownerStore = store;
                this.ownerEmail = '';
                this.ownerErrors = {};
                this.$dispatch('open-modal', 'invite-store-owner');
            },

            async sendOwnerInvite() {
                if (!this.ownerStore || this.invitingOwner) return;

                const errors = validate({ email: this.ownerEmail }, { email: [required('Email'), emailFormat('Email')] });
                if (Object.keys(errors).length) {
                    this.ownerErrors = errors;
                    return;
                }

                this.invitingOwner = true;
                this.ownerErrors = {};
                try {
                    const { data } = await axios.post('/stores/' + this.ownerStore.id + '/owner-invitation', { email: this.ownerEmail });
                    this.$dispatch('close-modal', 'invite-store-owner');
                    window.toast(data.message, data.email_sent === false ? 'error' : 'success');
                    await this.fetchItems();
                } catch (error) {
                    if (error.response?.status === 422 && error.response.data.errors) {
                        this.ownerErrors = error.response.data.errors;
                    } else {
                        window.toast(error.response?.data?.message ?? 'Could not send the invitation.');
                    }
                } finally {
                    this.invitingOwner = false;
                }
            },

            /* ── Delete (typed name) ───────────────────────────────────── */

            confirmDelete(store) {
                this.selectedItem = store;
                this.deleteConfirmName = '';
                this.deletePassword = '';
                this.deleteErrors = {};
                this.$dispatch('open-modal', 'confirm-store-deletion');
            },

            async deleteItem() {
                if (!this.selectedItem || this.deleting) return;

                const errors = {};
                // Trimmed, as the server reads it (TrimStrings): a stray space is not a different name.
                if (String(this.deleteConfirmName ?? '').trim() !== this.selectedItem.name) {
                    errors.confirm_name = ['Type the store name exactly as it is shown.'];
                }
                if (!this.deletePassword) {
                    errors.password = ['Password is required.'];
                }
                if (Object.keys(errors).length) {
                    this.deleteErrors = errors;
                    return;
                }

                this.deleting = true;
                try {
                    const { data } = await axios.delete('/stores/' + this.selectedItem.id, { data: { confirm_name: this.deleteConfirmName, password: this.deletePassword } });
                    this.$dispatch('close-modal', 'confirm-store-deletion');
                    this.selectedItem = null;
                    window.toast(data.message, 'success');
                    await this.fetchItems();
                    if (this.items.length === 0 && this.currentPage > 1) {
                        this.currentPage--;
                        await this.fetchItems();
                    }
                } catch (error) {
                    if (error.response?.status === 422 && error.response.data.errors) {
                        this.deleteErrors = error.response.data.errors;
                    } else {
                        window.toast(error.response?.data?.message ?? 'Could not delete the store.');
                    }
                } finally {
                    this.deleting = false;
                }
            },

            /* ── Network advertising ───────────────────────────────────── */

            /** Selection lives on the page you can see. Any refetch — a search, a page
             *  turn, a delete — empties it, so a tick you can no longer see can never
             *  be acted on by a button you can. */
            onInit() {
                this.$watch('items', () => { this.selectedStoreIds = []; });
            },

            isSelected(id) {
                return this.selectedStoreIds.includes(id);
            },

            toggleSelect(id) {
                this.selectedStoreIds = this.isSelected(id)
                    ? this.selectedStoreIds.filter((selected) => selected !== id)
                    : [...this.selectedStoreIds, id];
            },

            /* A METHOD, not a getter — and so is selectedScreenCount below.
             *
             * createCrudTable merges these with `...extraMethods` inside an object
             * literal, and object spread READS a getter rather than copying the
             * accessor. It would run here, at merge time, with `this` still bound to
             * this plain object: `this.items` undefined, `.length` throws, and the
             * whole storesTable() call dies before Alpine ever sees it — an empty
             * table and every binding on the page reporting "not defined". Getters
             * belong in crud-table-base's own literal (see endItem); never here. */
            allOnPageSelected() {
                return this.items.length > 0 && this.selectedStoreIds.length === this.items.length;
            },

            toggleSelectAll() {
                this.selectedStoreIds = this.allOnPageSelected()
                    ? []
                    : this.items.map((item) => item.id);
            },

            /** How many televisions the pending switch would actually touch — the
             *  number that makes the confirmation mean something. */
            selectedScreenCount() {
                return this.items
                    .filter((item) => this.isSelected(item.id))
                    .reduce((sum, item) => sum + (item.screens_count ?? 0), 0);
            },

            askStoreAds(accepts) {
                if (this.selectedStoreIds.length === 0 || this.savingAds) return;
                this.pendingAdsAccepts = accepts;
                this.$dispatch('open-modal', 'confirm-store-ads');
            },

            async applyStoreAds() {
                if (this.pendingAdsAccepts === null || this.savingAds) return;

                this.savingAds = true;
                try {
                    const { data } = await axios.put('/network-ads/stores', {
                        store_ids: this.selectedStoreIds,
                        accepts: this.pendingAdsAccepts,
                    });
                    this.$dispatch('close-modal', 'confirm-store-ads');
                    this.pendingAdsAccepts = null;
                    await this.fetchItems();   // clears the selection via the watcher
                    window.toast(data.message, 'success');
                } catch (error) {
                    window.toast(error.response?.data?.message ?? 'Could not change that.');
                } finally {
                    this.savingAds = false;
                }
            },

            /** Three states, never two: off, on everywhere, on with screens held back.
             *  Collapsing the third into "on" is how a shop ends up billed for adverts
             *  no television is showing. */
            adsLabel(store) {
                if (! store.accepts_network_ads) return 'Off';
                const total = store.screens_count ?? 0;
                const carrying = store.ad_screens_count ?? 0;
                if (total === 0) return 'On — no screens yet';
                if (carrying === total) return `On — all ${total}`;
                return `On — ${carrying} of ${total}`;
            },

            adsBadgeClass(store) {
                if (! store.accepts_network_ads) return 'badge-neutral';

                return (store.ad_screens_count ?? 0) === (store.screens_count ?? 0)
                    ? 'badge-success'
                    : 'badge-warning';
            },

            /** Restricts the zip code input to digits only, max 10 characters */
            restrictZipInput(event) {
                if (event.type === 'keydown') {
                    const key = event.key;
                    if (event.ctrlKey || event.metaKey) return; // allow Ctrl/Cmd shortcuts (paste, etc.)
                    const allowed = ['Backspace', 'Delete', 'Tab', 'Escape', 'Enter',
                        'ArrowLeft', 'ArrowRight', 'ArrowUp', 'ArrowDown', 'Home', 'End'];
                    if (allowed.includes(key) || /^\d$/.test(key)) return;
                    event.preventDefault();
                    return;
                }
                const digits = event.target.value.replace(/\D/g, '').slice(0, 10);
                if (this.form.zip_code !== digits) this.form.zip_code = digits;
            },
        },
    })());
}
