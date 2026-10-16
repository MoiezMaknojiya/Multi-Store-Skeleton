/**
 * Organizations table Alpine component — every organization, from the platform's side (docs/ORGANIZATION-SPEC.md
 * rule 18). Extends the shared CRUD table with an owner invitation on create, Invite owner for an organization
 * that has none, typed-name deletion and the network-ads bulk switch. What each row allows comes from the
 * server (`can`).
 */
import axios from 'axios';
import { createCrudTable } from '../core/crud-table-base.js';
import { formStillAsked, notSavedWords } from '../core/form-asked.js';
import { validate, required, maxLen, digitsOnly, emailFormat, requiredMessage, wholeNumber, minNumber, maxNumber, unreadableFields, showFirstError } from '../core/validate.js';
import { bytesInWords } from '../core/media-file.js';

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

            /* Billing of one organization (docs/BILLING-SPEC.md §4): its summary, and the two switches and the extra storage (§8) as
             * they stand in the dialog. */
            billingOrganization: null,
            billing: null,
            billingForm: { premium_templates_unlocked: true, platform_channels_unlocked: true, extra_storage_gb: 0 },
            billingErrors: {},
            billingLoading: false,
            billingFailed: false,
            billingAsked: 0,
            savingBilling: false,
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
            /* ── Billing ───────────────────────────────────────────────── */

            openBilling(organization) {
                this.formTicket++;
                this.billingOrganization = organization;
                this.billing = null;
                this.billingErrors = {};
                this.$dispatch('open-modal', 'organization-billing');
                this.loadBilling();
            },

            /** The organization's summary — the newest asked for alone, should another row's Billing be pressed meanwhile. */
            async loadBilling() {
                const asked = ++this.billingAsked;
                const organization = this.billingOrganization;
                this.billingLoading = true;
                this.billingFailed = false;

                try {
                    const { data } = await axios.get(`/organizations/${organization.id}/billing`);
                    if (asked !== this.billingAsked) return;
                    this.showBilling(data.billing);
                } catch {
                    if (asked !== this.billingAsked) return;
                    this.billingFailed = true;
                } finally {
                    if (asked === this.billingAsked) this.billingLoading = false;
                }
            },

            showBilling(billing) {
                this.billing = billing;
                this.billingForm = {
                    premium_templates_unlocked: billing.features.premium_templates.unlocked,
                    platform_channels_unlocked: billing.features.platform_channels.unlocked,
                    extra_storage_gb: billing.storage.extra_gb,
                };
            },

            /** The wall as the dialog stands: 512 MB and the extra typed, or null while the field holds no number Save would send — left
             *  empty or half typed (x-model.number reads it as null), or past 0 to 50 — so nothing is said of a wall that cannot be. */
            billingLimit() {
                const storage = this.billing?.storage;
                const extra = this.billingForm.extra_storage_gb;
                if (!storage || typeof extra !== 'number' || !Number.isInteger(extra) || extra < 0 || extra > storage.max_extra_gb) return null;

                return storage.included + extra * 1024 ** 3;
            },

            /** "Uses 300 MB of 1.5 GB: 512 MB included and 1 GB extra, free for now" */
            storageLine() {
                const storage = this.billing?.storage;
                if (!storage) return '';

                const limit = this.billingLimit();
                const included = bytesInWords(storage.included);
                if (limit === null) return `Uses ${bytesInWords(storage.used)}: ${included} included, and 0 to ${storage.max_extra_gb} GB extra`;

                const extra = this.billingForm.extra_storage_gb;

                return `Uses ${bytesInWords(storage.used)} of ${bytesInWords(limit)}: `
                    + (extra > 0 ? `${included} included and ${extra} GB extra, free for now` : `${included} included`);
            },

            /** Said while the wall in the dialog is below what the organization already holds: nothing is deleted, uploads stop. */
            storageWarning() {
                const storage = this.billing?.storage;
                const limit = this.billingLimit();
                if (!storage || limit === null || storage.used <= limit) return '';

                return `${this.billingOrganization?.name ?? 'This organization'} holds ${bytesInWords(storage.used)}, more than ${bytesInWords(limit)}. `
                    + 'Nothing is deleted, but it cannot upload until it deletes files to fit.';
            },

            /** "4 screens: the first is free, then $5 a month each" */
            screensLine() {
                const count = this.billing?.screen_count ?? 0;
                if (count === 0) return 'No screens yet: the first one is free';

                return `${count} ${count === 1 ? 'screen' : 'screens'}: the first is free, then $${this.billing.screen_price} a month each`;
            },

            /** The month as the switches stand in the dialog: the screens, and each feature while it is unlocked ($10 a month each). */
            monthlyTotal() {
                const features = this.billing?.features;
                if (!features) return 0;

                return (this.billing.screens_monthly ?? 0)
                    + (this.billingForm.premium_templates_unlocked ? features.premium_templates.price : 0)
                    + (this.billingForm.platform_channels_unlocked ? features.platform_channels.price : 0);
            },

            /** What the switch says the organization may do, as it stands in the dialog. */
            featureLine(feature) {
                const name = this.billingOrganization?.name ?? '';
                const unlocked = this.billingForm[`${feature}_unlocked`];

                return feature === 'premium_templates'
                    ? (unlocked ? `Unlocked: ${name} can use every template` : `Locked: ${name} sees the templates and cannot use them`)
                    : (unlocked ? `Unlocked: ${name} can put every platform channel on its screens` : `Locked: ${name} sees the platform channels and cannot add them`);
            },

            billingChanged() {
                const features = this.billing?.features;

                return !!features && (this.billingForm.premium_templates_unlocked !== features.premium_templates.unlocked
                    || this.billingForm.platform_channels_unlocked !== features.platform_channels.unlocked
                    || String(this.billingForm.extra_storage_gb) !== String(this.billing.storage.extra_gb));
            },

            async saveBilling() {
                if (this.savingBilling || !this.billingChanged()) return;

                // The extra storage as the server takes it, said under its field; letters or half a number in the box reach the
                // form as '' (unreadableFields), and are refused in the same words.
                const form = document.querySelector('[dusk="organization-billing"]');
                const most = this.billing.storage.max_extra_gb;
                const message = `Extra storage is a whole number of GB, from 0 to ${most}.`;
                const errors = validate(this.billingForm, {
                    extra_storage_gb: [requiredMessage(message), wholeNumber(message), minNumber(message, 0), maxNumber(message, most)],
                });
                if (unreadableFields(form).extra_storage_gb) errors.extra_storage_gb = [message];

                this.billingErrors = errors;
                if (Object.keys(errors).length) {
                    showFirstError(form);
                    return;
                }

                this.savingBilling = true;
                const organization = this.billingOrganization;
                // The answer acts on this dialog alone: shut since, or opened again for another organization (or this one),
                // it is neither shut nor marked by it — nor are the switches turned there since put back.
                const asked = formStillAsked(() => this.formTicket, form);

                try {
                    const { data } = await axios.put(`/organizations/${organization.id}/billing`,
                        { ...this.billingForm, extra_storage_gb: Number(this.billingForm.extra_storage_gb) });
                    window.toast(data.message, 'success');
                    if (asked()) {
                        this.showBilling(data.billing);
                        this.$dispatch('close-modal', 'organization-billing');
                    } else if (this.billingOrganization?.id === organization.id && !this.billingChanged()) {
                        // Opened again for the same organization, nothing changed there yet: it shows what was saved, never a
                        // summary its loading may have read before the save (saved again, that would undo it).
                        this.billingAsked++;
                        this.billingLoading = false;
                        this.billingFailed = false;
                        this.showBilling(data.billing);
                    }
                } catch (error) {
                    const refused = error.response?.status === 422 ? (error.response.data?.errors ?? null) : null;
                    if (refused?.extra_storage_gb && asked()) {
                        this.billingErrors = refused;
                        showFirstError(form);
                    } else {
                        window.toast((asked() ? null : notSavedWords(refused, `${organization.name}'s billing`))
                            ?? error.response?.data?.message ?? 'Could not save the billing.');
                    }
                } finally {
                    this.savingBilling = false;
                }
            },

            /* ── Owners ────────────────────────────────────────────────── */

            openInviteOwner(organization) {
                this.formTicket++;
                this.ownerOrganization = organization;
                this.ownerEmail = '';
                this.ownerErrors = {};
                this.$dispatch('open-modal', 'invite-organization-owner');
            },

            async sendOwnerInvite(event = null) {
                if (!this.ownerOrganization || this.invitingOwner) return;

                const errors = validate({ email: this.ownerEmail }, { email: [required('Email'), emailFormat('Email'), maxLen('Email', 255)] });
                if (Object.keys(errors).length) {
                    this.ownerErrors = errors;
                    return;
                }

                this.invitingOwner = true;
                this.ownerErrors = {};
                // The answer acts on this form alone: shut since, or opened again for another organization, it is neither
                // shut nor marked by it.
                const asked = formStillAsked(() => this.formTicket, event?.target);
                const named = `The invitation to ${this.ownerEmail}`;
                try {
                    const { data } = await axios.post('/organizations/' + this.ownerOrganization.id + '/owner-invitation', { email: this.ownerEmail });
                    if (asked()) this.$dispatch('close-modal', 'invite-organization-owner');
                    window.toast(data.message, data.email_sent === false ? 'error' : 'success');
                    this.refreshInPlace();  // not awaited: the button is free again the moment the request is over
                } catch (error) {
                    const refused = error.response?.status === 422 ? (error.response.data?.errors ?? null) : null;
                    if (refused && asked()) {
                        this.ownerErrors = refused;
                    } else {
                        window.toast(notSavedWords(refused, named, 'sent') ?? error.response?.data?.message ?? 'Could not send the invitation.');
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
