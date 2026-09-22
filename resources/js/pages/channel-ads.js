/**
 * One channel's ads: add, edit, reorder and remove them.
 *
 * Every action is its own request, and each answer brings the whole list back — so the
 * page never has to guess what the server did. An upload, a reorder and a removal all
 * end the same way: with the list as it now stands.
 *
 * An ad is a file of a media library (docs/CHANNEL-CONTENT-SPEC.md), and the form offers
 * three ways to name it: a file already in the library, one of the Ad Builder's published
 * ads (they live in the same library), or a fresh upload — which joins the library first.
 * An edit may also keep the file it has. Only the way chosen is sent.
 *
 * Uploading posts FormData and measures a video in the browser first, exactly like the
 * media library and the campaigns (there is no ffmpeg on the server). An image or an ad
 * page is given its seconds; a video has none to give — it plays to its own end (owner's rule).
 */
import axios from 'axios';
import { fileError, readVideoMeta } from '../core/media-file.js';

const blankForm = () => ({ title: '', seconds: 10, starts_on: '', ends_on: '' });

const blankPicker = () => ({ items: [], page: 1, lastPage: 1, loading: false, search: '', library: 'platform' });

/** Tiles fetched at a time; "Load more" asks for the next ones. */
const PICKER_PAGE_SIZE = 24;

/** The two ways that choose a row of the library, rather than upload one. */
const PICKING = ['library', 'ads'];

export function registerChannelAds(Alpine) {
    Alpine.data('channelAds', (config = {}) => ({
        channelId: config.channelId,

        maxImageSeconds: config.maxImageSeconds ?? 300,
        adsPerPass: config.adsPerPass ?? null,
        // Above the stores, the platform's channel may take any shop's files: [{id, name}], else empty.
        libraries: config.libraries ?? [],
        uploadsJoin: config.uploadsJoin ?? 'your media library',

        ads: [],
        loading: true,
        saving: false,
        reordering: false,
        removing: false,

        editingAd: null,
        removingAd: null,
        form: blankForm(),
        formErrors: {},

        // Where the ad's file comes from: 'keep' (an edit only), 'library', 'ads' or 'upload'.
        source: 'library',
        // The library row picked with 'library' or 'ads'.
        chosen: null,
        picker: blankPicker(),
        // Numbers each picker request, so an answer overtaken by a newer one is dropped.
        pickerTicket: 0,

        selectedFile: null,
        clientMeta: {},
        preparing: false,

        init() {
            this.load();
        },

        async load() {
            this.loading = true;
            try {
                const { data } = await axios.get(`/channels/${this.channelId}/ads`);
                this.ads = data.ads;
            } catch (error) {
                window.toast(error.response?.data?.message ?? 'Could not load the ads.');
            } finally {
                this.loading = false;
            }
        },

        /* ── Add / edit ──────────────────────────────────────────────────── */

        openAdModal(ad = null) {
            this.editingAd = ad;
            this.formErrors = {};
            this.clearFile();
            this.chosen = null;
            this.picker = blankPicker();
            // An edit starts from the file it has; a new ad from the library.
            this.source = ad ? 'keep' : 'library';

            this.form = ad
                ? {
                    title: ad.title,
                    seconds: ad.duration_seconds ?? 10,
                    starts_on: ad.starts_on ?? '',
                    ends_on: ad.ends_on ?? '',
                }
                : blankForm();

            this.$dispatch('open-modal', 'channel-ad-modal');
            if (PICKING.includes(this.source)) this.loadPicker();
        },

        closeAdModal() {
            this.$dispatch('close-modal', 'channel-ad-modal');
            this.editingAd = null;
            this.formErrors = {};
            // A picker answer still on its way belongs to a form that is gone.
            this.pickerTicket++;
        },

        /** Switch the way the file is named. What was picked or chosen the other way is
         *  dropped, so the form only ever sends what it shows. */
        setSource(source) {
            if (this.source === source) return;

            this.source = source;
            this.chosen = null;
            this.clearFile();
            this.forgetErrors('file', 'media_id');

            if (PICKING.includes(source)) {
                // The library a platform user chose stays chosen; the search starts again.
                this.picker = { ...blankPicker(), library: this.picker.library };
                this.loadPicker();
            }
        },

        clearFile() {
            this.selectedFile = null;
            this.clientMeta = {};
            this.preparing = false;
            if (this.$refs.fileInput) this.$refs.fileInput.value = '';
        },

        forgetErrors(...fields) {
            this.formErrors = Object.fromEntries(
                Object.entries(this.formErrors).filter(([field]) => ! fields.includes(field)),
            );
        },

        /** The field the chosen way is refused under, or null when the ad keeps its file. */
        sourceField() {
            if (this.source === 'upload') return 'file';

            return PICKING.includes(this.source) ? 'media_id' : null;
        },

        /* ── The picker ──────────────────────────────────────────────────── */

        /** The first page for the current way, search and library — or, with `more`, the next one. */
        async loadPicker({ more = false } = {}) {
            if (! PICKING.includes(this.source)) return;

            const ticket = ++this.pickerTicket;
            const params = {
                // The Ad Builder's published ads are the library's ad pages; the library, its pictures and videos.
                type: this.source === 'ads' ? 'html' : 'files',
                page: more ? this.picker.page + 1 : 1,
                per_page: PICKER_PAGE_SIZE,
            };
            const search = this.picker.search.trim();
            if (search !== '') params.search = search;
            if (this.libraries.length > 0) params.library = this.picker.library;

            this.picker.loading = true;
            try {
                const { data } = await axios.get(`/channels/${this.channelId}/library`, { params });
                if (ticket !== this.pickerTicket) return;

                // A file uploaded between two pages pushes the next page along by one; the id it
                // repeats would give two tiles the same key.
                const shown = new Set(more ? this.picker.items.map((item) => item.id) : []);
                const fresh = data.media.filter((item) => ! shown.has(item.id));

                this.picker.items = more ? [...this.picker.items, ...fresh] : fresh;
                this.picker.page = data.currentPage;
                this.picker.lastPage = data.lastPage;
            } catch (error) {
                if (ticket !== this.pickerTicket) return;

                const errors = error.response?.data?.errors;
                window.toast(errors ? Object.values(errors)[0][0] : (error.response?.data?.message ?? 'Could not load the library.'));
            } finally {
                if (ticket === this.pickerTicket) this.picker.loading = false;
            }
        },

        pick(item) {
            this.chosen = item;
            this.forgetErrors('media_id');
        },

        pickerEmptyText() {
            if (this.picker.search.trim() !== '') return 'Nothing here matches that search.';

            if (this.source === 'ads') {
                // The Ad Builder works inside a shop and publishes into that shop's library.
                return this.libraries.length > 0 && this.picker.library === 'platform'
                    ? 'Ad Builder ads are published into a shop\'s library — choose the shop above.'
                    : 'No published ads here yet. Publish one in the Ad Builder, then choose it here.';
            }

            return 'Nothing in this library yet. Choose Upload to add a file.';
        },

        /** Is the ad in the form a video? Whatever the chosen way names decides. */
        isVideo() {
            if (this.source === 'upload') return this.selectedFile?.type.startsWith('video/') ?? false;
            if (this.source === 'keep') return this.editingAd?.type === 'video';

            return this.chosen?.type === 'video';
        },

        async onFileSelected(event) {
            const file = event.target.files?.[0] ?? null;
            this.selectedFile = file;
            this.clientMeta = {};
            this.forgetErrors('file');
            // Whatever an earlier pick was still measuring no longer matters.
            this.preparing = false;
            if (! file) return;

            const error = fileError(file);
            if (error) {
                this.formErrors = { ...this.formErrors, file: [error] };
                this.selectedFile = null;
                return;
            }

            if (file.type.startsWith('video/')) {
                this.preparing = true;
                try {
                    const meta = await readVideoMeta(file);
                    // Another file was chosen while this one was measured: its numbers are
                    // not that file's, and must not ride along with its upload.
                    if (this.selectedFile === file) this.clientMeta = meta;
                } finally {
                    if (this.selectedFile === file) this.preparing = false;
                }
            }
        },

        /* Mirrors ChannelAdRequest. The server still decides. */
        validateAd() {
            const errors = {};

            if (this.source === 'upload' && ! this.selectedFile) {
                errors.file = ['Choose the file to upload.'];
            }

            if (PICKING.includes(this.source) && ! this.chosen) {
                errors.media_id = [this.source === 'ads' ? 'Choose one of the published ads.' : 'Choose a file from the library.'];
            }

            if (String(this.form.title ?? '').length > 255) {
                errors.title = ['The title may not be longer than 255 characters.'];
            }

            if (! this.isVideo()) {
                const seconds = Number(this.form.seconds);

                if (! Number.isInteger(seconds) || seconds < 1) {
                    errors.seconds = ['Say how many seconds it stays on screen.'];
                } else if (seconds > this.maxImageSeconds) {
                    errors.seconds = [`It may not stay up longer than ${this.maxImageSeconds} seconds.`];
                }
            }

            if (this.form.starts_on && this.form.ends_on && this.form.ends_on < this.form.starts_on) {
                errors.ends_on = ['The end date cannot be before the start date.'];
            }

            return errors;
        },

        /** POST, with FormData, for an edit too: an edit may carry a new file, and PHP does
         *  not parse a multipart body sent as PUT. */
        async saveAd() {
            if (this.saving || this.preparing) return;

            const errors = this.validateAd();
            if (Object.keys(errors).length > 0) {
                this.formErrors = errors;
                return;
            }

            this.formErrors = {};
            this.saving = true;
            try {
                const payload = new FormData();
                if (this.source === 'upload') payload.append('file', this.selectedFile);
                if (PICKING.includes(this.source)) payload.append('media_id', this.chosen.id);

                const fields = {
                    title: this.form.title,
                    starts_on: this.form.starts_on,
                    ends_on: this.form.ends_on,
                };

                // Seconds only for an image or an ad page. A video has none to send.
                if (! this.isVideo()) fields.seconds = this.form.seconds;

                Object.entries(fields).forEach(([key, value]) => {
                    if (value !== '' && value !== null && value !== undefined) payload.append(key, value);
                });

                if (this.source === 'upload') {
                    Object.entries(this.clientMeta).forEach(([key, value]) => {
                        if (value !== null && value !== undefined) payload.append(key, value);
                    });
                }

                const url = this.editingAd
                    ? `/channels/${this.channelId}/ads/${this.editingAd.id}`
                    : `/channels/${this.channelId}/ads`;

                const { data } = await axios.post(url, payload);
                this.ads = data.ads;
                this.closeAdModal();
                window.toast(data.message, 'success');
            } catch (error) {
                const errors = error.response?.status === 422 ? error.response.data.errors : null;

                if (errors) {
                    this.formErrors = errors;
                    // A refusal the form has no place for (a video's measurements, the other way's
                    // field) would otherwise say nothing at all.
                    const shown = ['title', 'seconds', 'starts_on', 'ends_on', this.sourceField()];
                    const unseen = Object.keys(errors).find((field) => ! shown.includes(field));
                    if (unseen) window.toast(errors[unseen][0]);
                } else {
                    window.toast(error.response?.data?.message ?? 'Could not save the ad.');
                }
            } finally {
                this.saving = false;
            }
        },

        /* ── Order ───────────────────────────────────────────────────────── */

        /** Swap one ad with its neighbour, and send the whole order. */
        async move(index, step) {
            const target = index + step;
            if (this.reordering || target < 0 || target >= this.ads.length) return;

            const order = this.ads.map((ad) => ad.id);
            [order[index], order[target]] = [order[target], order[index]];

            this.reordering = true;
            try {
                const { data } = await axios.put(`/channels/${this.channelId}/ads/order`, { ad_ids: order });
                this.ads = data.ads;
            } catch (error) {
                const errors = error.response?.data?.errors;
                window.toast(errors ? Object.values(errors)[0][0] : (error.response?.data?.message ?? 'Could not change the order.'));
                // Whatever went wrong, show the order as the server has it.
                await this.load();
            } finally {
                this.reordering = false;
            }
        },

        /* ── Remove ──────────────────────────────────────────────────────── */

        confirmRemove(ad) {
            this.removingAd = ad;
            this.$dispatch('open-modal', 'confirm-channel-ad-deletion');
        },

        async removeAd() {
            if (! this.removingAd || this.removing) return;

            this.removing = true;
            try {
                const { data } = await axios.delete(`/channels/${this.channelId}/ads/${this.removingAd.id}`);
                this.ads = data.ads;
                this.$dispatch('close-modal', 'confirm-channel-ad-deletion');
                this.removingAd = null;
            } catch (error) {
                window.toast(error.response?.data?.message ?? 'Could not remove the ad.');
            } finally {
                this.removing = false;
            }
        },

        /* ── Display ─────────────────────────────────────────────────────── */

        summary() {
            if (this.ads.length === 0) return 'No ads yet';

            const running = this.ads.filter((ad) => ad.status === 'running');
            const seconds = running.reduce((total, ad) => total + (Number(ad.play_seconds) || 0), 0);
            const each = this.adsPerPass ? `${this.adsPerPass} each time` : 'every ad each time';

            return `${running.length} of ${this.ads.length} running today · ${this.formatDuration(seconds)} in all · ${each}`;
        },

        /** The word the media library uses: "html" is nobody's word for an Ad Builder page. */
        typeLabel(type) {
            return { image: 'Image', video: 'Video', html: 'Ad page' }[type] ?? type;
        },

        datesLabel(ad) {
            if (! ad.starts_on && ! ad.ends_on) return 'No end date';

            return `${ad.starts_on ?? 'now'} → ${ad.ends_on ?? 'no end'}`;
        },

        /** A draft is an Ad Builder ad taken off the screens (Unpublish): off the air until it is published again. */
        statusLabel(ad) {
            return { running: 'Running', scheduled: 'Starts later', ended: 'Ended', draft: 'Draft · not playing' }[ad.status] ?? ad.status;
        },

        lengthLabel(ad) {
            return ad.type === 'video'
                ? `${this.formatDuration(ad.play_seconds)} · plays to its end`
                : this.formatDuration(ad.play_seconds);
        },

        formatDuration(seconds) {
            const total = Number(seconds) || 0;
            if (total < 60) return `${total} secs`;

            const minutes = Math.floor(total / 60);
            const rest = total % 60;

            return rest ? `${minutes} min ${rest} secs` : `${minutes} min`;
        },
    }));
}
