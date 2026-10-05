/**
 * Screens table Alpine component.
 *
 * Creating a screen is not a form submit — it is a pairing handshake: a TV shows
 * a six-character code, the owner types it here, and the server mints that
 * screen's token. So "create" posts to /screens/pair, in one of two modes:
 * a brand-new screen, or replacing the device behind an existing one.
 */
import axios from 'axios';
import { takeAddressFlag } from '../core/address-flag.js';
import { createCrudTable } from '../core/crud-table-base.js';
import { validate, required, maxLen, requiredMessage } from '../core/validate.js';

/** This computer's time zone, as the pairing form offers it — or nothing when the browser does not say. */
const browserTimezone = () => {
    try {
        return Intl.DateTimeFormat().resolvedOptions().timeZone ?? '';
    } catch {
        return '';
    }
};

/** How often the list refreshes itself, so a screen that stops beating turns grey without the page being
 *  reloaded. The server decides is_online (Screen::OFFLINE_AFTER_MINUTES); this is only the polling. */
const REFRESH_MS = 30000;

/** An empty Add Screen form. Above the organizations it also asks whose screen it is: chosen already when there is
 *  only one organization to choose. */
const blankPairForm = (config) => ({
    code: '',
    mode: 'new',
    name: '',
    orientation: 'landscape',
    timezone: config.defaultTimezone ?? 'America/Chicago',
    organization_id: config.organizations?.length === 1 ? String(config.organizations[0].id) : '',
    screen_id: null,
    screen_name: '',
});

export function registerScreensTable(Alpine) {
    Alpine.data('screensTable', (config = {}) => createCrudTable({
        fetchUrl: '/screens/data',
        dataKey: 'screens',
        entityLabel: 'screen',
        formModalName: 'screen-form-modal',
        deleteModalName: 'confirm-screen-deletion',

        extraState: {
            /* Above the organizations only (null inside one): the organizations a new screen may be paired for. */
            organizations: config.organizations ?? null,
            /* The model's words for the four ways (Screen::ORIENTATIONS), so the list and the dialogs agree. */
            orientations: config.orientations ?? {},
            browserTimezone: browserTimezone(),
            pairForm: blankPairForm(config),
            refreshTimer: null,
            /* The default-media picker's options, fetched when the modal opens: the
             * library can be long and most edits never touch it. */
            mediaOptions: [],
            loadingMedia: false,
            mediaOptionsToken: 0,

            /* Network advertising. The panel below is rendered only when the gate
             * passes, so these are meaningless — and unreachable — to an organization member. */

            organizationAcceptsAds: config.organizationAcceptsAds ?? false,
            savingAds: false,
        },

        defaultForm: {
            name: '',
            orientation: 'landscape',
            timezone: config.defaultTimezone ?? 'America/Chicago',
            default_media_id: '',
        },

        mapItemToForm: (screen) => ({
            name: screen.name,
            orientation: screen.orientation ?? 'landscape',
            timezone: screen.timezone ?? config.defaultTimezone ?? 'America/Chicago',
            /* '' rather than null, because that is what an unselected <option> is —
             * and the ConvertEmptyStringsToNull middleware turns it back on the way in. */
            default_media_id: screen.default_media_id ?? '',
        }),

        validateForm: (form) => validate(form, {
            name: [required('Screen name'), maxLen('Screen name', 255)],
            orientation: [required('Orientation')],
        }),

        extraMethods: {
            /* Keep the Online/Offline chips honest without a manual refresh. Quietly: the rows
             * stay on the page while the new ones are fetched, rather than the whole list
             * blinking to "Loading..." every thirty seconds, and a failure is said rather than
             * leaving stale chips looking current. Not over a fetch somebody asked for. */
            onInit() {
                this.refreshTimer = setInterval(() => {
                    if (!this.saving && !this.deleting && !this.loading) this.fetchItems({ quiet: true });
                }, REFRESH_MS);

                /* The holding-picture list is fetched only when an edit modal opens:
                 * the library can be long, and most edits never touch it. */
                this.$watch('editingItem', (item) => {
                    if (item) this.loadMediaOptions(item.id);
                });

                /* Sent here to pair a screen (the dashboard's Pair a screen): the code's dialog at once — for somebody
                 * the page offers Add Screen to. */
                if (takeAddressFlag('pair') && document.querySelector('[dusk="add-screen"]')) this.$nextTick(() => this.openPairModal());
            },

            async loadMediaOptions(screenId) {
                /* Edit on one screen, then quickly on another: only the list asked for last may
                 * land, or the picker would offer the first screen's answer to the second. */
                const token = ++this.mediaOptionsToken;
                this.loadingMedia = true;
                try {
                    const { data } = await axios.get(`/screens/${screenId}/media-options`);
                    if (token !== this.mediaOptionsToken) return;
                    this.mediaOptions = data.media;
                } catch {
                    if (token !== this.mediaOptionsToken) return;
                    /* Without it the picker is just empty — not worth a banner. */
                    this.mediaOptions = [];
                } finally {
                    if (token === this.mediaOptionsToken) this.loadingMedia = false;
                }
            },

            destroy() {
                if (this.refreshTimer) clearInterval(this.refreshTimer);
            },

            /* ── Pair / replace ────────────────────────────────────────── */
            openPairModal() {
                this.pairForm = blankPairForm(config);
                this.formErrors = {};
                this.$dispatch('open-modal', 'screen-pair-modal');
            },

            openReplaceModal(screen) {
                this.pairForm = {
                    code: '',
                    mode: 'replace',
                    name: '',
                    orientation: screen.orientation,
                    timezone: screen.timezone,
                    organization_id: '',
                    screen_id: screen.id,
                    screen_name: screen.name,
                };
                this.formErrors = {};
                this.$dispatch('open-modal', 'screen-pair-modal');
            },

            closePairModal() {
                this.$dispatch('close-modal', 'screen-pair-modal');
                this.formErrors = {};
            },

            async pairScreen() {
                if (this.saving) return;

                const isNew = this.pairForm.mode === 'new';
                const forAnOrganization = isNew && this.organizations !== null;
                const rules = { code: [required('Pairing code')] };
                if (isNew) {
                    // In the server's own words (ScreenController::pair).
                    rules.name = [requiredMessage('Give the screen a name.'), maxLen('Screen name', 255)];
                    rules.orientation = [requiredMessage('Choose how the screen is mounted.')];
                }
                if (forAnOrganization) {
                    rules.organization_id = [requiredMessage('Choose the organization this screen belongs to.')];
                }

                const errors = validate(this.pairForm, rules);
                // Mirrors the backend's size:6 rule.
                if (!errors.code && this.pairForm.code.length !== 6) {
                    errors.code = ['A pairing code is exactly 6 characters.'];
                }

                if (Object.keys(errors).length > 0) {
                    this.formErrors = errors;
                    return;
                }

                this.formErrors = {};
                this.saving = true;
                try {
                    await axios.post('/screens/pair', {
                        code: this.pairForm.code,
                        mode: this.pairForm.mode,
                        name: isNew ? this.pairForm.name : null,
                        orientation: isNew ? this.pairForm.orientation : null,
                        timezone: isNew ? this.pairForm.timezone : null,
                        organization_id: forAnOrganization ? this.pairForm.organization_id : null,
                        screen_id: isNew ? null : this.pairForm.screen_id,
                    });
                    this.closePairModal();
                    // The television notices within half a minute, and the next step is its playlist.
                    window.toast(isNew
                        ? 'Screen paired. The TV starts within 30 seconds: open its Playlist to choose what it shows.'
                        : 'Device replaced. The new TV starts within 30 seconds, with this screen\'s playlist.', 'success');
                    this.currentPage = 1;
                    await this.fetchItems();
                } catch (error) {
                    if (error.response?.status === 422 && error.response.data.errors) {
                        this.formErrors = error.response.data.errors;
                    } else {
                        window.toast(error.response?.data?.message ?? 'Pairing failed. Please try again.');
                    }
                } finally {
                    this.saving = false;
                }
            },

            /* ── Display helpers ───────────────────────────────────────── */
            playlistLabel(screen) {
                const count = screen.playlist_items_count ?? 0;
                if (count === 0) return 'Nothing to play yet';

                return count === 1 ? '1 item in playlist' : `${count} items in playlist`;
            },

            /* This computer's zone, offered only when it differs and the list has it (a browser may name an old alias). */
            offersBrowserTimezone() {
                if (!this.browserTimezone || this.browserTimezone === this.pairForm.timezone) return false;

                return !!document.querySelector(`[dusk="screen-pair-timezone"] option[value="${CSS.escape(this.browserTimezone)}"]`);
            },

            orientationLabel(value) {
                return this.orientations[value] ?? value;
            },

            /** Which way the screen in the form is: its four settings are two shapes. */
            formShape() {
                return String(this.form.orientation ?? '').startsWith('portrait') ? 'portrait' : 'landscape';
            },

            /** A holding picture's name in the list — saying so when it is the other way round from the screen. */
            holdingLabel(media) {
                if (!media.orientation || media.orientation === this.formShape()) return media.title;

                return media.title + (media.orientation === 'portrait' ? ' (portrait)' : ' (landscape)');
            },

            /** Under the list: what the chosen holding picture will look like on this screen, when it is the other way. */
            holdingNote() {
                const chosen = (this.mediaOptions ?? []).find((media) => String(media.id) === String(this.form.default_media_id));

                if (!chosen?.orientation || chosen.orientation === this.formShape()) return '';

                return chosen.orientation === 'portrait'
                    ? 'Portrait — it holds this screen with bars at the sides.'
                    : 'Landscape — it holds this screen with bars above and below.';
            },

            /* ── Network advertising ───────────────────────────────────── */

            /** Does this organization carry advertising at all? Nothing runs until it does. */
            async toggleOrganizationAds() {
                if (this.savingAds) return;

                this.savingAds = true;
                try {
                    const { data } = await axios.put('/network-ads/organization', { accepts: ! this.organizationAcceptsAds });
                    this.organizationAcceptsAds = data.accepts_network_ads;
                    window.toast(data.message, 'success');
                } catch (error) {
                    window.toast(error.response?.data?.message ?? 'Could not change that.');
                } finally {
                    this.savingAds = false;
                }
            },

            /** One television, or all of them — the same call either way, so the bulk
             *  switch is not a second endpoint with its own rules to keep in step. */
            async setScreenAds(ids, accepts) {
                if (this.savingAds || ids.length === 0) return;

                this.savingAds = true;
                try {
                    await axios.put('/network-ads/screens', { screen_ids: ids, accepts });
                    await this.fetchItems();
                } catch (error) {
                    window.toast(error.response?.data?.message ?? 'Could not change that.');
                } finally {
                    this.savingAds = false;
                }
            },

            allScreenAds(accepts) {
                this.setScreenAds(this.items.map((item) => item.id), accepts);
            },

            adsLabel(screen) {
                if (! this.organizationAcceptsAds) return 'Organization has not agreed';

                return screen.accepts_network_ads ? 'Carries adverts' : 'Kept clear';
            },

            lastSeenLabel(screen) {
                if (!screen.last_seen_at) return 'Never seen';
                const seconds = Math.max(0, Math.round((Date.now() - new Date(screen.last_seen_at)) / 1000));
                if (seconds < 60) return 'Seen just now';
                const minutes = Math.floor(seconds / 60);
                if (minutes < 60) return `Seen ${minutes} min ago`;
                const hours = Math.floor(minutes / 60);
                if (hours < 24) return `Seen ${hours} hr ago`;
                return `Seen ${Math.floor(hours / 24)} d ago`;
            },
        },
    })());
}
