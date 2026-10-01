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
 * A fresh upload goes through the shared uploader (<x-upload-dropzone>, docs/UPLOADS-SPEC.md): it is sent in chunks
 * as soon as it is chosen, a video measured in the browser first, and Save waits until it has arrived — then posts
 * its upload id in place of the file. An image or an ad page is given its seconds; a video has none to give — it plays
 * to its own end (owner's rule).
 */
import axios from 'axios';
import { lengthInWords } from '../core/media-file.js';
import { PlaylistItemDefaults } from '../core/playlist-defaults.js';
import { unreadableFields } from '../core/validate.js';
import { dayLabel } from '../core/clock.js';

const blankForm = () => ({ title: '', seconds: PlaylistItemDefaults.imageSeconds, starts_on: '', ends_on: '' });

// onPlaylists: how many files of this library a playlist holds, which a channel leaves out — said, not hidden.
const blankPicker = () => ({ items: [], page: 1, lastPage: 1, loading: false, search: '', library: 'platform', onPlaylists: 0 });

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

        // The file chosen in the uploader ({name, size, type}), its upload once every byte is in ({upload, meta}),
        // and whether bytes are still going (Save waits for them).
        picked: null,
        uploaded: null,
        uploading: false,
        // Cancel pressed while a file was still going up: the dialog asks before it gives the upload up.
        confirmingClose: false,
        // How full the library an upload here joins is ({used, limit}), or null for the platform's own.
        storage: null,

        init() {
            this.load();
        },

        async load() {
            this.loading = true;
            try {
                const { data } = await axios.get(`/channels/${this.channelId}/ads`);
                this.ads = data.ads;
                this.storage = data.storage ?? null;
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
            this.confirmingClose = false;
            this.clearFile();
            this.chosen = null;
            this.picker = blankPicker();
            // An edit starts from the file it has; a new ad from the library.
            this.source = ad ? 'keep' : 'library';

            this.form = ad
                ? {
                    title: ad.title,
                    seconds: ad.duration_seconds ?? PlaylistItemDefaults.imageSeconds,
                    starts_on: ad.starts_on ?? '',
                    ends_on: ad.ends_on ?? '',
                }
                : blankForm();

            this.$dispatch('open-modal', 'channel-ad-modal');
            if (PICKING.includes(this.source)) this.loadPicker();
        },

        closeAdModal(stopTheUpload = false) {
            // A long video half sent is not thrown away on one tap: the dialog asks first.
            if (this.uploading && !stopTheUpload) {
                this.confirmingClose = true;

                return;
            }

            this.confirmingClose = false;
            this.$dispatch('close-modal', 'channel-ad-modal');
            this.editingAd = null;
            this.formErrors = {};
            // A picker answer still on its way belongs to a form that is gone.
            this.pickerTicket++;
            // So does a file still going up, or one that arrived and was never saved.
            this.clearFile();
        },

        /** Escape, or a click beside the dialog, while a file is still going up: asked, as Cancel is. */
        onModalClosing(event) {
            if (event.detail !== 'channel-ad-modal' || !this.uploading) return;

            event.preventDefault();
            this.confirmingClose = true;
        },

        /** "At least 6 seconds, at most 5 minutes." — the limits, said before they are broken. */
        secondsHint() {
            return `At least ${PlaylistItemDefaults.minImageSeconds} seconds, at most ${lengthInWords(this.maxImageSeconds)}.`;
        },

        /** Switch the way the file is named. A file picked from a list is dropped; an upload is kept — a look at the
         *  library must not throw away a long video half sent — and the save sends only what the chosen way shows
         *  (the upload for Upload alone). */
        setSource(source) {
            if (this.source === source) return;

            this.source = source;
            this.chosen = null;
            this.forgetErrors('file', 'media_id');

            if (PICKING.includes(source)) {
                // The library a platform user chose stays chosen; the search starts again.
                this.picker = { ...blankPicker(), library: this.picker.library };
                this.loadPicker();
            }
        },

        /** Forget the uploaded file, and take it out of the uploader — a file still going is cancelled. */
        clearFile() {
            this.picked = null;
            this.uploaded = null;
            this.uploading = false;

            const box = this.$refs.adUpload?.querySelector('[dusk="channel-ad-dropzone"]');
            if (box) window.Alpine.$data(box).clear();
        },

        /* The uploader's events (docs/UPLOADS-SPEC.md). */
        onPicked(file) {
            this.picked = file;
            this.uploaded = null;
            this.forgetErrors('file');
        },

        onUploadReady(detail) {
            this.uploaded = detail;
        },

        onUploadCleared() {
            this.picked = null;
            this.uploaded = null;
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
                this.picker.onPlaylists = Number(data.on_playlists ?? 0);
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

        /** "3 published ads are on a playlist", or "1 file is …": what the picker leaves out, in words. */
        onPlaylistsText() {
            const count = this.picker.onPlaylists;
            const thing = this.source === 'ads' ? (count === 1 ? 'published ad is' : 'published ads are') : (count === 1 ? 'file is' : 'files are');

            return `${count} ${thing} on a playlist`;
        },

        pickerEmptyText() {
            // Not empty, but every one of them is on a playlist (owner's rule, 2026-09-26): said, with the way out.
            if (this.picker.onPlaylists > 0) {
                const wayOut = this.picker.onPlaylists === 1 ? 'Take it off its playlist' : 'Take one off its playlist';

                return `${this.onPlaylistsText()}, so not listed here: a file plays from a playlist or from a channel, never both. ${wayOut} to add it here.`;
            }

            if (this.picker.search.trim() !== '') return 'Nothing here matches that search.';

            if (this.source === 'ads') {
                // An ad publishes into its shop's library, and one made for every shop into the platform's own.
                return this.libraries.length > 0 && this.picker.library === 'platform'
                    ? 'No ad for every shop is published yet. Publish one in the Ad Builder, or choose a shop above.'
                    : 'No published ads here yet. Publish one in the Ad Builder, then choose it here.';
            }

            return 'Nothing in this library yet. Choose Upload to add a file.';
        },

        /** Does the ad run for its file's own length — a video, or an Ad Builder page whose design says how
         *  long? Then it has no seconds to set. A page published before designs had a length does not. */
        runsOwnLength() {
            if (this.isVideo()) return true;
            if (this.source === 'upload') return false;
            if (this.source === 'keep') return Number(this.editingAd?.own_length) > 0;

            return this.chosen?.type === 'html' && Number(this.chosen?.duration_seconds) > 0;
        },

        /** "Plays for the ad's own length: 8 secs, set in the Ad Builder." */
        ownLengthNote() {
            if (this.isVideo()) return 'A video plays to its own end — there is nothing to set.';

            const seconds = this.source === 'keep' ? this.editingAd?.own_length : this.chosen?.duration_seconds;

            return `Plays for the ad's own length: ${seconds} secs, set in the Ad Builder.`;
        },

        /** Is the ad in the form a video? Whatever the chosen way names decides. */
        isVideo() {
            if (this.source === 'upload') return this.picked?.type?.startsWith('video/') ?? false;
            if (this.source === 'keep') return this.editingAd?.type === 'video';

            return this.chosen?.type === 'video';
        },

        /* Mirrors ChannelAdRequest. The server still decides. */
        validateAd() {
            const errors = {};

            if (this.source === 'upload' && ! this.uploaded) {
                errors.file = [this.picked ? 'Wait until the file has finished uploading.' : 'Choose the file to upload.'];
            }

            if (PICKING.includes(this.source) && ! this.chosen) {
                errors.media_id = [this.source === 'ads' ? 'Choose one of the published ads.' : 'Choose a file from the library.'];
            }

            if (String(this.form.title ?? '').length > 255) {
                errors.title = ['Title may not be longer than 255 characters.'];
            }

            if (! this.runsOwnLength()) {
                const typed = this.form.seconds;
                const seconds = Number(typed);

                if (typed === '' || typed === null || typed === undefined) {
                    errors.seconds = ['Say how many seconds it stays on screen.'];
                } else if (! Number.isInteger(seconds)) {
                    errors.seconds = ['Give the seconds as a whole number.'];
                } else if (seconds < PlaylistItemDefaults.minImageSeconds) {
                    errors.seconds = [`A picture stays on screen for at least ${PlaylistItemDefaults.minImageSeconds} seconds.`];
                } else if (seconds > this.maxImageSeconds) {
                    errors.seconds = [`A picture stays on screen for at most ${lengthInWords(this.maxImageSeconds)}.`];
                }
            }

            if (this.form.starts_on && this.form.ends_on && this.form.ends_on < this.form.starts_on) {
                errors.ends_on = ['The end date cannot be before the start date.'];
            }

            return errors;
        },

        /** POST, with FormData, for an edit too: an edit may carry a new file, and PHP does
         *  not parse a multipart body sent as PUT. */
        async saveAd(event) {
            if (this.saving || (this.uploading && this.source === 'upload')) return;

            // A date typed only in part reads as '' — said under it, never saved as no date at all.
            const errors = { ...this.validateAd(), ...unreadableFields(event?.target) };
            if (Object.keys(errors).length > 0) {
                this.formErrors = errors;
                return;
            }

            this.formErrors = {};
            this.saving = true;
            try {
                const payload = new FormData();
                if (this.source === 'upload') payload.append('upload', this.uploaded.upload);
                if (PICKING.includes(this.source)) payload.append('media_id', this.chosen.id);

                const fields = {
                    title: this.form.title,
                    starts_on: this.form.starts_on,
                    ends_on: this.form.ends_on,
                };

                // Seconds only for a picture. A video and an ad page with its own length have none to send.
                if (! this.runsOwnLength()) fields.seconds = this.form.seconds;

                Object.entries(fields).forEach(([key, value]) => {
                    if (value !== '' && value !== null && value !== undefined) payload.append(key, value);
                });

                // What the browser measured of a video: its shape, its length and a first frame.
                if (this.source === 'upload') {
                    Object.entries(this.uploaded.meta ?? {}).forEach(([key, value]) => {
                        if (value !== null && value !== undefined) payload.append(key, value);
                    });
                }

                const url = this.editingAd
                    ? `/channels/${this.channelId}/ads/${this.editingAd.id}`
                    : `/channels/${this.channelId}/ads`;

                const { data } = await axios.post(url, payload);
                this.ads = data.ads;
                this.storage = data.storage ?? this.storage;
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
            if (ad.starts_on && ad.ends_on) return `From ${dayLabel(ad.starts_on)} to ${dayLabel(ad.ends_on)}`;

            return ad.starts_on ? `From ${dayLabel(ad.starts_on)}` : `Until ${dayLabel(ad.ends_on)}`;
        },

        /** A draft is an Ad Builder ad taken off the screens (Unpublish): off the air until it is published again. */
        statusLabel(ad) {
            return { running: 'Running', scheduled: 'Starts later', ended: 'Ended', draft: 'Draft · not playing' }[ad.status] ?? ad.status;
        },

        /** Its colour says the same as its words: playing, not yet, not playing (a draft), over. */
        statusBadge(ad) {
            return { running: 'badge-success', scheduled: 'badge-info', draft: 'badge-warning' }[ad.status] ?? 'badge-neutral';
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
