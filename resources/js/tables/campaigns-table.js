/**
 * Network advertising campaigns — the platform's own content, sold to a brand.
 *
 * Like the media library, creating one means uploading a FILE, so it posts FormData
 * rather than JSON and measures a video in the browser (there is no ffmpeg on the
 * server). Unlike anything else in the panel, it belongs to no store: the screens it
 * runs on are chosen from every shop at once.
 */
import axios from 'axios';
import { toAmPm } from '../core/clock.js';
import { createCrudTable } from '../core/crud-table-base.js';
import { fileError, readVideoMeta } from '../core/media-file.js';
import { validate, required, maxLen } from '../core/validate.js';

const blankForm = () => ({
    name: '',
    advertiser_name: '',
    duration_seconds: 15,
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
            breakEverySeconds: config.breakEverySeconds ?? 3600,
            maxBreakSeconds: config.maxBreakSeconds ?? 60,
            /* The screen picker, fetched once when a modal first opens. */
            allScreens: [],
            loadingScreens: false,
            selectedFile: null,
            clientMeta: {},
            preparing: false,
        },

        defaultForm: blankForm(),

        mapItemToForm: (campaign) => ({
            name: campaign.name,
            advertiser_name: campaign.advertiser_name ?? '',
            duration_seconds: campaign.duration_seconds ?? 15,
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
                this.selectedFile = null;
                this.clientMeta = {};
                this.preparing = false;
                if (this.$refs.fileInput) this.$refs.fileInput.value = '';
                this.openFormModal(item);
                // A modal opened before the list arrived would show no screens at all.
                if (this.allScreens.length === 0 && ! this.loadingScreens) this.loadScreens();
            },

            async onFileSelected(event) {
                const file = event.target.files?.[0] ?? null;
                this.selectedFile = file;
                this.clientMeta = {};
                this.formErrors = {};
                if (! file) return;

                const error = fileError(file);
                if (error) {
                    this.formErrors = { file: [error] };
                    this.selectedFile = null;
                    return;
                }

                if (file.type.startsWith('video/')) {
                    this.preparing = true;
                    try {
                        this.clientMeta = await readVideoMeta(file);
                    } finally {
                        this.preparing = false;
                    }
                }
            },

            /** Is the advert in the form a video? The newly chosen file decides when there
             *  is one; otherwise the advert the campaign already has. A video has no
             *  seconds to set — it runs to its own end — so its field is not shown. */
            isVideoAd() {
                if (this.selectedFile) return this.selectedFile.type.startsWith('video/');

                return this.editingItem?.type === 'video';
            },

            /**
             * FormData, and no method spoofing: an edit may carry a replacement file,
             * PHP does not parse multipart bodies on PUT, and _method=PUT would turn
             * the request INTO a PUT and miss the route entirely.
             */
            async saveCampaign() {
                if (this.saving) return;

                const errors = validate(this.form, {
                    name: [required('Name'), maxLen('Name', 120)],
                    advertiser_name: [maxLen('Advertiser', 120)],
                    // Only an image has seconds; a video's field is not even shown.
                    ...(this.isVideoAd() ? {} : { duration_seconds: [required('Seconds')] }),
                });

                if (! this.editingItem && ! this.selectedFile) {
                    errors.file = ['Choose the advert to upload.'];
                }

                // Mirrors the backend: both ends of the window, or neither.
                if (!! this.form.start_time !== !! this.form.end_time) {
                    errors.end_time = ['Give both a start and an end time, or leave both blank to run all day.'];
                } else if (this.form.start_time && this.form.start_time === this.form.end_time) {
                    errors.end_time = ['The start and end time cannot be the same. To run past midnight, set an end time EARLIER than the start.'];
                }

                if (this.form.starts_on && this.form.ends_on && this.form.ends_on < this.form.starts_on) {
                    errors.ends_on = ['The end date cannot be before the start date.'];
                }

                if (Object.keys(errors).length > 0) {
                    this.formErrors = errors;
                    return;
                }

                this.formErrors = {};
                this.saving = true;
                try {
                    const payload = new FormData();

                    if (this.selectedFile) payload.append('file', this.selectedFile);

                    Object.entries({
                        name: this.form.name,
                        advertiser_name: this.form.advertiser_name,
                        // A video has no typed seconds to send; the length the browser
                        // measured goes with the rest of clientMeta below.
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

                    Object.entries(this.clientMeta).forEach(([key, value]) => {
                        if (value !== null && value !== undefined) payload.append(key, value);
                    });

                    const url = this.editingItem ? `/campaigns/${this.editingItem.id}` : '/campaigns';
                    await axios.post(url, payload);

                    this.closeFormModal();
                    this.currentPage = 1;
                    await this.fetchItems();
                    await this.loadScreens();   // the booked seconds have moved
                } catch (error) {
                    if (error.response?.status === 422 && error.response.data.errors) {
                        this.formErrors = error.response.data.errors;
                    } else {
                        window.toast(error.response?.data?.message ?? 'Could not save the campaign.');
                    }
                } finally {
                    this.saving = false;
                }
            },

            /* ── The screen picker ─────────────────────────────────────── */

            /** Screens grouped under the shop they stand in. */
            screensByStore() {
                const groups = new Map();

                this.allScreens.forEach((screen) => {
                    if (! groups.has(screen.store_id)) {
                        groups.set(screen.store_id, { name: screen.store_name, screens: [] });
                    }
                    groups.get(screen.store_id).screens.push(screen);
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

            /** Every screen in one shop that CAN carry advertising, in one click. */
            toggleStore(group) {
                const eligible = group.screens.filter((screen) => screen.carries_ads).map((s) => s.id);
                const allChosen = eligible.length > 0 && eligible.every((id) => this.isChosen(id));

                eligible.forEach((id) => {
                    const chosen = this.isChosen(id);
                    if (allChosen && chosen) this.toggleScreen(id);
                    if (! allChosen && ! chosen) this.toggleScreen(id);
                });
            },

            /** Why this screen cannot be chosen, in words. */
            blockedReason(screen) {
                if (! screen.store_accepts) return 'this shop has not agreed to advertising';
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
                return Number(this.clientMeta.duration_seconds) || Number(this.form.duration_seconds) || 0;
            },

            /* ── Labels ────────────────────────────────────────────────── */

            windowLabel(campaign) {
                if (! campaign.start_time || ! campaign.end_time) return 'All day';

                return `${toAmPm(campaign.start_time)} – ${toAmPm(campaign.end_time)}`;
            },

            datesLabel(campaign) {
                if (! campaign.starts_on && ! campaign.ends_on) return 'No end date';

                const from = campaign.starts_on ? String(campaign.starts_on).slice(0, 10) : 'always';
                const to = campaign.ends_on ? String(campaign.ends_on).slice(0, 10) : 'forever';

                return `${from} → ${to}`;
            },

            screensLabel(campaign) {
                const count = campaign.screens_count ?? 0;

                if (count === 0) return 'No screens chosen';

                return count === 1 ? '1 screen' : `${count} screens`;
            },
        },
    })());
}
