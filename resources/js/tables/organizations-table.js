/**
 * Organizations table Alpine component — every organization, from the platform's side (docs/ORGANIZATION-SPEC.md
 * rule 18). Extends the shared CRUD table with an owner invitation on create, Invite owner for an organization
 * that has none, typed-name deletion and the network-ads bulk switch. What each row allows comes from the
 * server (`can`).
 */
import axios from 'axios';
import { createCrudTable } from '../core/crud-table-base.js';
import { validate, required, maxLen, digitsOnly, emailFormat } from '../core/validate.js';

export function registerOrganizationsTable(Alpine) {
    Alpine.data('organizationsTable', (config = {}) => createCrudTable({
        fetchUrl: '/organizations/data',
        dataKey: 'organizations',
        entityLabel: 'organization',
        formModalName: 'organization-form-modal',
        deleteModalName: 'confirm-organization-deletion',
        extraState: {
            /* Deleting asks for the organization's name, typed exactly (the server checks it too). */
            deleteConfirmName: '',
            deleteErrors: {},

            /* Giving an organization without an owner an Owner. */
            ownerOrganization: null,
            ownerEmail: '',
            ownerErrors: {},
            invitingOwner: false,

            /* Network advertising — the platform owner's bulk switch. The page renders it for a super admin
             * only (@if), and the route says no to everybody else regardless. */
            selectedOrganizationIds: [],
            pendingAdsAccepts: null,   // what the confirm modal is about to do
            savingAds: false,
        },
        defaultForm: {
            name: '', street: '', suite: '', city: '',
            state: '', zip_code: '', country: 'USA', is_active: true, owner_email: '',
        },

        /* Mirrors OrganizationController's rules (the 50-state whitelist stays backend-only —
         * the state field is a fixed dropdown anyway). The owner's email is asked only
         * when the organization is created: after that, Owners come by Invite owner, or from
         * inside the organization. */
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
        /* Creating an organization emails its owner an invitation — the row cannot say so, the toast does. */
        onSaved: (data) => window.toast(data.message, data.email_sent === false ? 'error' : 'success'),
        mapItemToForm: (organization) => ({
            name: organization.name,
            street: organization.street ?? '',
            suite: organization.suite ?? '',
            city: organization.city ?? '',
            state: organization.state ?? '',
            zip_code: organization.zip_code ?? '',
            country: organization.country ?? '',
            is_active: organization.is_active,
        }),

        extraMethods: {
            /* ── Owners ────────────────────────────────────────────────── */

            openInviteOwner(organization) {
                this.ownerOrganization = organization;
                this.ownerEmail = '';
                this.ownerErrors = {};
                this.$dispatch('open-modal', 'invite-organization-owner');
            },

            async sendOwnerInvite() {
                if (!this.ownerOrganization || this.invitingOwner) return;

                const errors = validate({ email: this.ownerEmail }, { email: [required('Email'), emailFormat('Email'), maxLen('Email', 255)] });
                if (Object.keys(errors).length) {
                    this.ownerErrors = errors;
                    return;
                }

                this.invitingOwner = true;
                this.ownerErrors = {};
                try {
                    const { data } = await axios.post('/organizations/' + this.ownerOrganization.id + '/owner-invitation', { email: this.ownerEmail });
                    this.$dispatch('close-modal', 'invite-organization-owner');
                    window.toast(data.message, data.email_sent === false ? 'error' : 'success');
                    this.refreshInPlace();  // not awaited: the button is free again the moment the request is over
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

            confirmDelete(organization) {
                this.selectedItem = organization;
                this.deleteConfirmName = '';
                this.deletePassword = '';
                this.deleteErrors = {};
                this.$dispatch('open-modal', 'confirm-organization-deletion');
            },

            async deleteItem() {
                if (!this.selectedItem || this.deleting) return;

                const errors = {};
                // Trimmed, as the server reads it (TrimStrings): a stray space is not a different name.
                if (String(this.deleteConfirmName ?? '').trim() !== this.selectedItem.name) {
                    errors.confirm_name = ['Type the organization name exactly as it is shown.'];
                }
                if (!this.deletePassword) {
                    errors.password = ['Password is required.'];
                }
                if (Object.keys(errors).length) {
                    this.deleteErrors = errors;
                    return;
                }

                this.deleting = true;
                // A late answer never shuts the question about another organization (crud-table-base's deleteItem).
                const gone = this.selectedItem.id;
                const stillAsked = () => this.selectedItem?.id === gone;
                const forget = () => {
                    this.items = this.items.filter((item) => item.id !== gone);
                    if (!stillAsked()) return;
                    this.$dispatch('close-modal', 'confirm-organization-deletion');
                    this.selectedItem = null;
                };
                let said;
                try {
                    const { data } = await axios.delete('/organizations/' + gone, { data: { confirm_name: this.deleteConfirmName, password: this.deletePassword } });
                    said = data.message;
                } catch (error) {
                    if (error.response?.status !== 404) {
                        // A wrong name or password goes under its field, and so does "too many wrong passwords" (429).
                        if ([422, 429].includes(error.response?.status) && error.response.data.errors && stillAsked()) {
                            this.deleteErrors = error.response.data.errors;
                        } else {
                            window.toast(error.response?.data?.message ?? 'Could not delete the organization.');
                        }
                        return;
                    }
                    said = 'That organization is already gone.';
                } finally {
                    // Down when the request is over, not after the list is brought up to date (crud-table-base's deleteItem).
                    this.deleting = false;
                }

                forget();
                window.toast(said, 'success');
                await this.refreshInPlace();
                if (this.items.length === 0 && this.currentPage > 1) {
                    this.currentPage--;
                    await this.fetchItems();
                }
            },

            /* ── Network advertising ───────────────────────────────────── */

            /** Selection lives on the page you can see. Any refetch — a search, a page
             *  turn, a delete — empties it, so a tick you can no longer see can never
             *  be acted on by a button you can. */
            onInit() {
                this.$watch('items', () => { this.selectedOrganizationIds = []; });
            },

            isSelected(id) {
                return this.selectedOrganizationIds.includes(id);
            },

            toggleSelect(id) {
                this.selectedOrganizationIds = this.isSelected(id)
                    ? this.selectedOrganizationIds.filter((selected) => selected !== id)
                    : [...this.selectedOrganizationIds, id];
            },

            /* A METHOD, not a getter — and so is selectedScreenCount below.
             *
             * createCrudTable merges these with `...extraMethods` inside an object
             * literal, and object spread READS a getter rather than copying the
             * accessor. It would run here, at merge time, with `this` still bound to
             * this plain object: `this.items` undefined, `.length` throws, and the
             * whole organizationsTable() call dies before Alpine ever sees it — an empty
             * table and every binding on the page reporting "not defined". Getters
             * belong in crud-table-base's own literal (see endItem); never here. */
            allOnPageSelected() {
                return this.items.length > 0 && this.selectedOrganizationIds.length === this.items.length;
            },

            toggleSelectAll() {
                this.selectedOrganizationIds = this.allOnPageSelected()
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

            askOrganizationAds(accepts) {
                if (this.selectedOrganizationIds.length === 0 || this.savingAds) return;
                this.pendingAdsAccepts = accepts;
                this.$dispatch('open-modal', 'confirm-organization-ads');
            },

            async applyOrganizationAds() {
                if (this.pendingAdsAccepts === null || this.savingAds) return;

                this.savingAds = true;
                try {
                    const { data } = await axios.put('/network-ads/organizations', {
                        organization_ids: this.selectedOrganizationIds,
                        accepts: this.pendingAdsAccepts,
                    });
                    this.$dispatch('close-modal', 'confirm-organization-ads');
                    this.pendingAdsAccepts = null;
                    this.refreshInPlace();  // not awaited, clears the selection via the watcher
                    window.toast(data.message, 'success');
                } catch (error) {
                    window.toast(error.response?.data?.message ?? 'Could not change that.');
                } finally {
                    this.savingAds = false;
                }
            },

            /** Three states, never two: off, on everywhere, on with screens held back.
             *  Collapsing the third into "on" is how an organization ends up billed for adverts
             *  no television is showing. */
            adsLabel(organization) {
                if (! organization.accepts_network_ads) return 'Off';
                const total = organization.screens_count ?? 0;
                const carrying = organization.ad_screens_count ?? 0;
                if (total === 0) return 'On — no screens yet';
                if (carrying === total) return `On — all ${total}`;
                return `On — ${carrying} of ${total}`;
            },

            adsBadgeClass(organization) {
                if (! organization.accepts_network_ads) return 'badge-neutral';

                return (organization.ad_screens_count ?? 0) === (organization.screens_count ?? 0)
                    ? 'badge-success'
                    : 'badge-warning';
            },
        },
    })());
}
