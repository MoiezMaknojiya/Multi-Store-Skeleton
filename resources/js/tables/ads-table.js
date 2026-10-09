/**
 * The Ads page of the Ad Builder: a gallery of saved designs.
 *
 * Unlike the other listings there is no form modal — an ad is made in the editor, not in a dialog — so
 * this adds only the two row actions the gallery offers beside Edit: Copy (a draft to work from) and
 * Delete (a big delete, so the shared password confirmation). And Create Ad's questions: which way the screen is, and,
 * inside an organization, how to start — Create Your Own or a Premium Template (owner, 2026-10-07).
 */
import axios from 'axios';
import { takeAddressFlag } from '../core/address-flag.js';
import { isARepeatPress } from '../core/click-beside.js';
import { createCrudTable } from '../core/crud-table-base.js';

export function registerAdsTable(Alpine) {
    Alpine.data('adsTable', (config = {}) => createCrudTable({
        fetchUrl: '/builder/data',
        dataKey: 'ads',
        entityLabel: 'ad',
        deleteModalName: 'confirm-ad-deletion',
        deleteNeedsPassword: true,

        extraState: {
            /* The card whose Copy is in flight, so only that button goes quiet. */
            busyId: null,
            /* Above the organizations the Owner list: the platform's ('platform', where the page opens), one organization (its id), or
             * all of them (empty). */
            filterOrganization: config.owner ?? '',

            /* ── Create Ad ──────────────────────────────────────────────── */
            /* 'orientation', then inside an organization 'start' (Create Your Own or Premium Template). */
            newAdStep: 'orientation',
            newAdOrientation: null,
            /* Above the organizations whose new ad it is (owner, 2026-10-09): 'platform', or an organization's id. */
            newAdOwner: 'platform',
            /* The Premium Templates of the shape chosen, and where their list stands: idle | loading | ready | failed. */
            templates: [],
            templatesState: 'idle',
            /* Premium Templates locked for the organization (docs/BILLING-SPEC.md §6): every template shown, Contact Us to Unlock on each. */
            templatesLocked: false,
            templateSearch: '',
            templatesAsked: 0,
            /* The template being copied: every Use This Template waits for it, and the page leaves for the editor. */
            usingTemplateId: null,
        },

        extraMethods: {
            /** Sent here to start a new ad (/builder/create with no shape chosen): New ad's question, at once. */
            onInit() {
                // The dialog is on the page only for somebody who may create an ad.
                if (takeAddressFlag('new')) this.$nextTick(() => this.startNewAd());

                // Back from the editor a template opened, the browser may show this page as it was left: the button free again.
                window.addEventListener('pageshow', (event) => {
                    if (event.persisted) this.usingTemplateId = null;
                });
            },

            /* ── Listing filters ───────────────────────────────────────── */
            extraParams() {
                return this.filterOrganization ? { organization_id: this.filterOrganization } : {};
            },

            applyFilters() {
                this.currentPage = 1;
                this.fetchItems();
            },

            /** "Copied from a Premium Template · by Ali Raza": where the ad came from, and who changed it last. */
            byline(ad) {
                return [ad.from_template ? 'Copied from a Premium Template' : null, ad.updated_by_name ? `by ${ad.updated_by_name}` : null]
                    .filter(Boolean).join(' · ');
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
            async duplicate(ad, event) {
                if (this.busyId || isARepeatPress(event)) return;
                this.busyId = ad.id;

                try {
                    const { data } = await axios.post(`/builder/${ad.id}/duplicate`);
                    window.toast(data.message, 'success');
                    this.refreshInPlace();  // not awaited: the button is free again the moment the request is over
                } catch (error) {
                    window.toast(error.response?.data?.message ?? 'Could not copy this ad.');
                } finally {
                    this.busyId = null;
                }
            },

            /* ── Create Ad ─────────────────────────────────────────────── */

            /** Create Ad's first question, asked afresh every time — for the organization the Owner list shows, if it shows one. */
            startNewAd() {
                this.newAdStep = 'orientation';
                this.newAdOrientation = null;
                this.newAdOwner = /^[1-9][0-9]*$/.test(String(this.filterOrganization)) ? String(this.filterOrganization) : 'platform';
                this.$dispatch('open-modal', 'new-ad-orientation');
            },

            /** The editor's address names the organization chosen; the platform's ad names none. */
            newAdOwnerQuery() {
                return this.newAdOwner !== 'platform' ? `&organization_id=${encodeURIComponent(this.newAdOwner)}` : '';
            },

            /** Inside an organization the shape leads to the second question: how to start — where the keyboard goes too. */
            chooseOrientation(orientation) {
                this.newAdOrientation = orientation;
                this.newAdStep = 'start';
                this.$nextTick(() => document.getElementById('new-ad-own')?.focus());
            },

            /** Back to the shape, the keyboard on the one chosen. */
            backToOrientation() {
                this.newAdStep = 'orientation';
                this.$nextTick(() => document.getElementById(`new-ad-${this.newAdOrientation ?? 'landscape'}`)?.focus());
            },

            /**
             * The rest of the double click that chose the shape lands on what took the shape's place — Create Your Own, Premium
             * Template — and must not choose it as well; nor may a double click on Preview open two tabs.
             */
            ignoreRepeatPress(event) {
                if (isARepeatPress(event)) event.preventDefault();
            },

            /** Premium Template: the gallery of the shape chosen, in a dialog as wide as the window. */
            openTemplates(event) {
                if (isARepeatPress(event)) return;
                this.templates = [];
                this.templateSearch = '';
                this.$dispatch('close-modal', 'new-ad-orientation');
                this.$dispatch('open-modal', 'premium-templates');
                this.loadTemplates();
            },

            /** Back to how to start, with the shape kept. */
            backToStart() {
                if (this.usingTemplateId !== null) return;
                this.$dispatch('close-modal', 'premium-templates');
                this.newAdStep = 'start';
                this.$dispatch('open-modal', 'new-ad-orientation');
            },

            /** The templates of the shape chosen — the newest search's answer alone, however the answers arrive. */
            async loadTemplates() {
                const asked = ++this.templatesAsked;
                this.templatesState = 'loading';

                try {
                    const { data } = await axios.get('/builder/templates', {
                        params: { orientation: this.newAdOrientation, search: this.templateSearch || undefined },
                    });
                    if (asked !== this.templatesAsked) return;
                    this.templates = data.templates;
                    this.templatesLocked = data.locked === true;
                    this.templatesState = 'ready';
                } catch {
                    if (asked !== this.templatesAsked) return;
                    this.templates = [];
                    this.templatesState = 'failed';
                }
            },

            /** Use This Template: the organization's own ad, files and all, opened in the editor. Once for a double click. */
            async useTemplate(template, event) {
                if (this.usingTemplateId !== null || isARepeatPress(event)) return;
                this.usingTemplateId = template.id;

                try {
                    const { data } = await axios.post(`/builder/templates/${template.id}`);
                    window.location.assign(data.redirect);
                } catch (error) {
                    this.usingTemplateId = null;
                    window.toast(error.response?.data?.message ?? 'Could not use this template.');
                }
            },
        },
    })());
}
