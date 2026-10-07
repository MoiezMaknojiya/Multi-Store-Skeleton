/**
 * Network advertising campaigns — the platform's own content, sold to a brand.
 *
 * Like the media library, creating one means uploading a FILE, so it posts FormData
 * rather than JSON and measures a video in the browser (there is no ffmpeg on the
 * server). Unlike anything else in the panel, it belongs to no organization: the screens it
 * runs on are chosen from every organization at once.
 */
import axios from 'axios';
import { dayLabel, windowLabel as clockRange } from '../core/clock.js';
import { createCrudTable } from '../core/crud-table-base.js';

import { validate, required, maxLen, maxNumber, minNumber, requiredMessage, unreadableFields, wholeNumber } from '../core/validate.js';
import { PlaylistItemDefaults } from '../core/playlist-defaults.js';

const blankForm = () => ({
    name: '',
    advertiser_name: '',
    duration_seconds: PlaylistItemDefaults.imageSeconds,
    starts_on: '',
    ends_on: '',
    start_time: '',
    end_time: '',
    is_active: true,
    screen_ids: [],
});

export function registerCampaignsTable(Alpine) {
    Alpine.data('campaignsTable', (config = {}) => createCrudTable({
        fetchUrl: '/campaigns/data',
        dataKey: 'campaigns',
        entityLabel: 'campaign',
        formModalName: 'campaign-form-modal',
        deleteModalName: 'confirm-campaign-deletion',
        deleteNeedsPassword: true,

        extraState: {
            maxBreakSeconds: config.maxBreakSeconds ?? 60,
            /* The screen picker, fetched once when a modal first opens. */
            allScreens: [],
            loadingScreens: false,
            /* The advert chosen in the uploader ({name, size, type, meta}), its upload once every byte is in
             * ({upload, meta}), and whether bytes are still going: Save waits for them (docs/UPLOADS-SPEC.md). */
            picked: null,
            uploaded: null,
            uploading: false,
            // Cancel pressed while the advert was still going up: the dialog asks before it gives the upload up.
            confirmingClose: false,
        },

        defaultForm: blankForm(),

        mapItemToForm: (campaign) => ({
            name: campaign.name,
            advertiser_name: campaign.advertiser_name ?? '',
            // A picture saved before the six-second rule, or before the one-break ceiling, opens as its row says and
            // a screen plays it — never at a number the form would refuse on any change at all (Campaign::play_seconds).
            duration_seconds: campaign.type === 'image'
                ? (campaign.play_seconds ?? PlaylistItemDefaults.imageSeconds)
                : (campaign.duration_seconds ?? PlaylistItemDefaults.imageSeconds),
            starts_on: campaign.starts_on ? String(campaign.starts_on).slice(0, 10) : '',
            ends_on: campaign.ends_on ? String(campaign.ends_on).slice(0, 10) : '',
            start_time: (campaign.start_time ?? '').slice(0, 5),
            end_time: (campaign.end_time ?? '').slice(0, 5),
            is_active: !! campaign.is_active,
            screen_ids: (campaign.screens ?? []).map((screen) => screen.id),
        }),

        extraMethods: {
            onInit() {
                // The picker is wanted the moment any modal opens, and the list is
                // small — one fetch on load beats a wait every time.
                this.loadScreens();
            },

            async loadScreens() {
                this.loadingScreens = true;
                try {
                    const { data } = await axios.get('/campaigns/screens');
                    this.allScreens = data.screens;
                    this.maxBreakSeconds = data.max_break_seconds ?? this.maxBreakSeconds;
                } catch {
                    this.allScreens = [];
                } finally {
                    this.loadingScreens = false;
                }
            },

            /* ── The form ──────────────────────────────────────────────── */

            openCampaignModal(item = null) {
                this.confirmingClose = false;
                this.clearFile();
                this.openFormModal(item);
                // A modal opened before the list arrived would show no screens at all.
                if (this.allScreens.length === 0 && ! this.loadingScreens) this.loadScreens();
            },

            /** Closing the form gives up an advert still going up — asked first — or one that arrived and was never
             *  saved. */
            closeCampaignModal(stopTheUpload = false) {
                if (this.uploading && ! stopTheUpload) {
                    this.confirmingClose = true;

                    return;
                }

                this.confirmingClose = false;
                this.clearFile();
                this.closeFormModal();
            },

            /** Escape, or a click beside the dialog, while the advert is still going up: asked, as Cancel is. */
            onModalClosing(event) {
                if (event.detail !== 'campaign-form-modal' || ! this.uploading) return;

                event.preventDefault();
                this.confirmingClose = true;
            },

            /** "At least 6 seconds, at most 60: one break." — the limits, said before they are broken. */
            secondsHint() {
                return `At least ${PlaylistItemDefaults.minImageSeconds} seconds, at most ${this.maxBreakSeconds}: one break.`;
            },

            /** Forget the uploaded advert, and take it out of the uploader — one still going is cancelled. */
            clearFile() {
                this.picked = null;
                this.uploaded = null;
                this.uploading = false;

                const box = this.$refs.advertUpload?.querySelector('[dusk="campaign-dropzone"]');
                if (box) window.Alpine.$data(box).clear();
            },

            /* The uploader's events (docs/UPLOADS-SPEC.md). */
            onPicked(file) {
                this.picked = file;
                this.uploaded = null;
                const { file: _gone, ...rest } = this.formErrors ?? {};
                this.formErrors = rest;
            },

            onUploadReady(detail) {
                this.uploaded = detail;
            },

            onUploadCleared() {
                this.picked = null;
                this.uploaded = null;
            },

            /** Is the advert in the form a video? The newly chosen file decides when there
             *  is one; otherwise the advert the campaign already has. A video has no
             *  seconds to set — it runs to its own end — so its field is not shown. */
            isVideoAd() {
                if (this.picked) return this.picked.type.startsWith('video/');

                return this.editingItem?.type === 'video';
            },

            /**
             * FormData, and no method spoofing: an edit may carry a replacement file,
             * PHP does not parse multipart bodies on PUT, and _method=PUT would turn
             * the request INTO a PUT and miss the route entirely.
             */
            /** Why the chosen screens were refused (a screen deleted meanwhile, say), or '' — shown under the list. */
            screensError() {
                const key = Object.keys(this.formErrors ?? {}).find((field) => field === 'screen_ids' || field.startsWith('screen_ids.'));

                return key ? this.formErrors[key][0] : '';
            },

            async saveCampaign(event) {
                // Not while the advert is still going up: Save sends its upload once every byte is in.
                if (this.saving || this.uploading) return;

                const errors = validate(this.form, {
                    name: [required('Campaign name'), maxLen('Campaign name', 120)],
                    advertiser_name: [maxLen('Advertiser', 120)],
                    // Only an image has seconds; a video's field is not even shown.
                    ...(this.isVideoAd() ? {} : {
                        duration_seconds: [
                            requiredMessage('Say how many seconds it stays on screen.'),
                            wholeNumber('Give the seconds as a whole number.'),
                            minNumber(`An advert stays on screen for at least ${PlaylistItemDefaults.minImageSeconds} seconds.`, PlaylistItemDefaults.minImageSeconds),
                            maxNumber(`An advert stays on screen for at most ${this.maxBreakSeconds} seconds: one break.`, this.maxBreakSeconds),
                        ],
                    }),
                });

                if (this.picked && ! this.uploaded) {
                    errors.file = ['Wait until the advert has finished uploading.'];
                } else if (! this.editingItem && ! this.uploaded) {
                    errors.file = ['Choose the advert to upload.'];
                }

                // Mirrors the backend: both ends of the window, or neither.
                if (!! this.form.start_time !== !! this.form.end_time) {
                    // Under the one left empty, as the server says it.
                    errors[this.form.start_time ? 'end_time' : 'start_time'] = ['Give both a start and an end time, or leave both blank to run all day.'];
                } else if (this.form.start_time && this.form.start_time === this.form.end_time) {
                    errors.end_time = ['The start and end time cannot be the same. To run past midnight, set an end time EARLIER than the start.'];
                }

                if (this.form.starts_on && this.form.ends_on && this.form.ends_on < this.form.starts_on) {
                    errors.ends_on = ['The end date cannot be before the start date.'];
                }

                // A date or a time typed only in part reads as '' — said under it, never saved as no date at all.
                Object.assign(errors, unreadableFields(event?.target));

                if (Object.keys(errors).length > 0) {
                    this.formErrors = errors;
                    return;
                }

                this.formErrors = {};
                this.saving = true;
                try {
                    const payload = new FormData();

                    if (this.uploaded) payload.append('upload', this.uploaded.upload);

                    Object.entries({
                        name: this.form.name,
                        advertiser_name: this.form.advertiser_name,
                        // A video has no typed seconds to send; the length the browser
                        // measured goes with the rest of the upload's meta below.
                        duration_seconds: this.isVideoAd() ? '' : this.form.duration_seconds,
                        starts_on: this.form.starts_on,
                        ends_on: this.form.ends_on,
                        start_time: this.form.start_time,
                        end_time: this.form.end_time,
                    }).forEach(([key, value]) => {
                        if (value !== '' && value !== null && value !== undefined) payload.append(key, value);
                    });

                    payload.append('is_active', this.form.is_active ? '1' : '0');

                    // An empty list still has to reach the server, or "no screens" and
                    // "field missing" become the same request.
                    if (this.form.screen_ids.length === 0) {
                        payload.append('screen_ids', '');
                    } else {
                        this.form.screen_ids.forEach((id) => payload.append('screen_ids[]', id));
                    }

                    // What the browser measured of a video: its shape, its length and a first frame.
                    Object.entries(this.uploaded?.meta ?? {}).forEach(([key, value]) => {
                        if (value !== null && value !== undefined) payload.append(key, value);
                    });

                    const url = this.editingItem ? `/campaigns/${this.editingItem.id}` : '/campaigns';
                    await axios.post(url, payload);

                    this.closeCampaignModal();
                    this.currentPage = 1;
                    this.refreshInPlace();  // not awaited: the button is free again the moment the request is over
                    this.loadScreens();  // not awaited, the booked seconds have moved
                } catch (error) {
                    if (error.response?.status === 422 && error.response.data.errors) {
                        this.formErrors = error.response.data.errors;

                        // A refusal the form has no place for (a video's measurements) would otherwise say nothing at
                        // all; the screens' own refusal is said under the list (screensError).
                        const shown = ['name', 'advertiser_name', 'file', 'starts_on', 'ends_on', 'start_time', 'end_time',
                            ...(this.isVideoAd() ? [] : ['duration_seconds'])];
                        const unseen = Object.keys(this.formErrors)
                            .find((field) => ! shown.includes(field) && ! field.startsWith('screen_ids'));

                        if (unseen) window.toast(this.formErrors[unseen][0]);
                    } else {
                        window.toast(error.response?.data?.message ?? 'Could not save the campaign.');
                    }
                } finally {
                    this.saving = false;
                }
            },

            /* ── The screen picker ─────────────────────────────────────── */

            /** Screens grouped under the organization they stand in. */
            screensByOrganization() {
                const groups = new Map();

                this.allScreens.forEach((screen) => {
                    if (! groups.has(screen.organization_id)) {
                        groups.set(screen.organization_id, { name: screen.organization_name, screens: [] });
                    }
                    groups.get(screen.organization_id).screens.push(screen);
                });

                return [...groups.entries()].map(([id, group]) => ({ id, ...group }));
            },

            toggleScreen(id) {
                const at = this.form.screen_ids.indexOf(id);
                if (at === -1) this.form.screen_ids.push(id);
                else this.form.screen_ids.splice(at, 1);
            },

            isChosen(id) {
                return this.form.screen_ids.includes(id);
            },

            /** Is every screen of the organization that can carry adverts chosen? Then its button clears them. */
            organizationAllChosen(group) {
                const eligible = group.screens.filter((screen) => screen.carries_ads);

                return eligible.length > 0 && eligible.every((screen) => this.isChosen(screen.id));
            },

            /** Every screen in one organization that CAN carry advertising, in one click. */
            toggleOrganization(group) {
                const eligible = group.screens.filter((screen) => screen.carries_ads).map((s) => s.id);
                const allChosen = this.organizationAllChosen(group);

                eligible.forEach((id) => {
                    const chosen = this.isChosen(id);
                    if (allChosen && chosen) this.toggleScreen(id);
                    if (! allChosen && ! chosen) this.toggleScreen(id);
                });
            },

            /** Why this screen cannot be chosen, in words. */
            blockedReason(screen) {
                if (! screen.organization_accepts) return 'this organization has not agreed to advertising';
                if (! screen.screen_accepts) return 'this screen is kept clear of advertising';

                return '';
            },

            /**
             * What choosing this screen would do to its break.
             *
             * Overselling is a commercial mistake, and the place to notice it is here —
             * not at a lunch counter watching a three-minute advert break.
             */
            bookedLabel(screen) {
                const mine = this.editingItem ? 0 : this.thisAdSeconds();
                const total = screen.booked_seconds + (this.isChosen(screen.id) ? mine : 0);

                return `${total}s of ${this.maxBreakSeconds}s booked`;
            },

            isOversold(screen) {
                const mine = this.editingItem ? 0 : this.thisAdSeconds();

                return screen.booked_seconds + (this.isChosen(screen.id) ? mine : 0) > this.maxBreakSeconds;
            },

            /** A video runs to its own length; an image to the typed seconds. */
            thisAdSeconds() {
                return Number(this.picked?.meta?.duration_seconds) || Number(this.form.duration_seconds) || 0;
            },

            /* ── Labels ────────────────────────────────────────────────── */

            windowLabel(campaign) {
                if (! campaign.start_time || ! campaign.end_time) return 'All day';

                // One place decides how a window of time reads (core/clock.js), as on a playlist's schedules.
                return clockRange(campaign.start_time, campaign.end_time);
            },

            datesLabel(campaign) {
                if (! campaign.starts_on && ! campaign.ends_on) return 'No end date';
                if (campaign.starts_on && campaign.ends_on) return `From ${dayLabel(campaign.starts_on)} to ${dayLabel(campaign.ends_on)}`;

                return campaign.starts_on ? `From ${dayLabel(campaign.starts_on)}` : `Until ${dayLabel(campaign.ends_on)}`;
            },

            /**
             * Where the campaign stands today, in words and a colour: paused, over, not yet started, on no screen, or
             * running. Read on this computer's calendar — each screen reads the dates on its own, so a screen in
             * another time zone may be a day either side of it.
             */
            campaignStatus(campaign) {
                const now = new Date();
                const today = `${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, '0')}-${String(now.getDate()).padStart(2, '0')}`;
                const starts = campaign.starts_on ? String(campaign.starts_on).slice(0, 10) : null;
                const ends = campaign.ends_on ? String(campaign.ends_on).slice(0, 10) : null;

                if (! campaign.is_active) return { label: 'Paused', badge: 'badge-neutral' };
                if (ends && ends < today) return { label: 'Ended', badge: 'badge-neutral' };
                if (starts && starts > today) return { label: `Starts ${dayLabel(starts)}`, badge: 'badge-info' };
                if ((campaign.screens_count ?? 0) === 0) return { label: 'On no screen', badge: 'badge-warning' };

                return { label: 'Running', badge: 'badge-success' };
            },

            screensLabel(campaign) {
                const count = campaign.screens_count ?? 0;

                if (count === 0) return 'No screens chosen';

                return count === 1 ? '1 screen' : `${count} screens`;
            },
        },
    })());
}
