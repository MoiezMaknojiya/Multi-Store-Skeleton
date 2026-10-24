/**
 * One channel's ads: add, edit, reorder and remove them — and find them, by status, month and name.
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
import { announce } from '../core/announce.js';
import { emptyTheBox, formStillAsked, giveUpUpload, notSavedWords } from '../core/form-asked.js';
import { lengthInWords } from '../core/media-file.js';
import { PlaylistItemDefaults } from '../core/playlist-defaults.js';
import { showFirstError, unreadableFields } from '../core/validate.js';
import { dayLabel, monthLabel } from '../core/clock.js';

const blankForm = () => ({ title: '', seconds: PlaylistItemDefaults.imageSeconds, starts_on: '', ends_on: '' });

// onPlaylists: how many files of this library a playlist holds, which a channel leaves out — said, not hidden.
const blankPicker = () => ({ items: [], page: 1, lastPage: 1, loading: false, failed: false, search: '', library: 'platform', onPlaylists: 0 });

/** Tiles fetched at a time; "Load more" asks for the next ones. */
const PICKER_PAGE_SIZE = 24;

/** The upload box of the Upload way, inside the dialog (channels/show.blade.php). */
const AD_BOX = '[dusk="channel-ad-dropzone"]';

/** The two ways that choose a row of the library, rather than upload one. */
const PICKING = ['library', 'ads'];

/* ── The list's filters (owner, 2026-10-23: "Starts later aur Running ka filter … ya month ka filter") ──────────
 * They choose which ads the list shows, never the order the channel plays them in: every row keeps its own place
 * number, and an arrow moves an ad past the next one shown. */

/** What ChannelAd::statusOn says, in the order the Status list offers them. */
const STATUSES = ['running', 'scheduled', 'ended', 'draft'];

const A_MONTH = /^\d{4}-(0[1-9]|1[0-2])$/;

/** The Month list holds three years at most, whatever far-off end an ad was given. */
const MOST_MONTHS = 36;

/** Does the ad run on any day of the month ("2026-11")? With no start it has run all along, with no end it runs on. */
function runsInMonth(ad, month) {
    return (! ad.starts_on || ad.starts_on <= `${month}-31`) && (! ad.ends_on || ad.ends_on >= `${month}-01`);
}

/** Every month from the first to the last that any ad's dates name ("2026-10", "2026-11" …); none when no ad has a date. */
function monthsNamed(ads) {
    const named = ads.flatMap((ad) => [ad.starts_on, ad.ends_on]).filter(Boolean).map((day) => day.slice(0, 7)).sort();
    if (named.length === 0) return [];

    const last = named[named.length - 1];
    let [year, month] = named[0].split('-').map(Number);
    const months = [];

    while (months.length < MOST_MONTHS) {
        const key = `${year}-${String(month).padStart(2, '0')}`;
        months.push(key);
        if (key >= last) break;

        month = month === 12 ? 1 : month + 1;
        if (month === 1) year += 1;
    }

    return months;
}

export function registerChannelAds(Alpine) {
    Alpine.data('channelAds', (config = {}) => ({
        channelId: config.channelId,

        maxImageSeconds: config.maxImageSeconds ?? 300,
        adsPerPass: config.adsPerPass ?? null,
        // Above the organizations, the platform's channel may take any organization's files: [{id, name}], else empty.
        libraries: config.libraries ?? [],
        uploadsJoin: config.uploadsJoin ?? 'your media library',

        ads: [],
        loading: true,
        // The ads could not be loaded at all: said where they would be, with Try Again.
        loadFailed: false,
        // Which ads the list shows: a status of STATUSES, a month ("2026-11") and words of the title; '' is every one.
        // Kept in the page's address (status, month, search), so a reload or a link keeps them.
        filterStatus: '',
        filterMonth: '',
        search: '',
        searchTimer: null,
        saving: false,
        reordering: false,
        removing: false,

        editingAd: null,
        removingAd: null,
        form: blankForm(),
        formErrors: {},
        // Moves each time the form opens: a save's answer acts on the form it was sent from alone (saveAd).
        formTicket: 0,

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
        // The upload a save on its way carries: the box is held meanwhile (holdsTheSavedUpload), and lets go of it without
        // giving it up (clearFile).
        uploadBeingSaved: null,
        // Cancel pressed while a file was still going up: the dialog asks before it gives the upload up.
        confirmingClose: false,
        // How full the library an upload here joins is ({used, limit}), or null for the platform's own.
        storage: null,

        init() {
            this.readFilters();
            this.$watch('filterStatus', () => this.filtersChanged());
            this.$watch('filterMonth', () => this.filtersChanged());
            this.$watch('search', () => {
                clearTimeout(this.searchTimer);
                this.searchTimer = setTimeout(() => this.filtersChanged(), 300);
            });
            this.load();
        },

        /* After a change (`quiet`), the rows stay where they are until the new ones are in, with no "Loading..." row
         * pushing them down under the pointer — as crud-table-base's refreshInPlace (owner, 2026-10-07). */
        async load({ quiet = false } = {}) {
            if (!quiet) {
                this.loading = true;
                this.loadFailed = false;
            }
            try {
                const { data } = await axios.get(`/channels/${this.channelId}/ads`);
                this.ads = data.ads;
                this.storage = data.storage ?? null;
                this.loadFailed = false;
                // A month the address named that no ad runs in any more is not offered: the list shows every month.
                if (this.filterMonth !== '' && ! this.months().some((month) => month.key === this.filterMonth)) this.filterMonth = '';
            } catch (error) {
                // The first load says so where the ads would be, with Try Again; a refresh after a change keeps the
                // rows it has and says it in a toast.
                if (quiet) window.toast(error.response?.data?.message ?? 'Could not load the ads.');
                else this.loadFailed = true;
            } finally {
                this.loading = false;
            }
        },

        /* ── Add / edit ──────────────────────────────────────────────────── */

        openAdModal(ad = null) {
            this.formTicket++;
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

        /** "At least 10 seconds, at most 5 minutes." — the limits, said before they are broken. */
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

        /** Forget the uploaded file, and take it out of the uploader: a file still going is cancelled, and one that came is
         *  given up on the server — unless a save on its way carries it. The box is found on the page: a ref inside the
         *  dialog is the dialog's own, and from Add Ad or an ad's Edit, outside it, it was never found, so a dialog shut
         *  with Escape opened again saying "Uploaded" while the form had forgotten the file. */
        clearFile() {
            this.picked = null;
            this.uploaded = null;
            this.uploading = false;

            emptyTheBox(AD_BOX, this.uploadBeingSaved);
        },

        /** Is the box still showing the file a save on its way carries? Then it is held (channels/show.blade.php): its ×
         *  and a new file would give that upload up under the save. Shut with Escape meanwhile, the box has let go of it
         *  and takes the next file at once. */
        holdsTheSavedUpload() {
            return this.uploadBeingSaved !== null && this.uploaded?.upload === this.uploadBeingSaved;
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
            if (! more) this.picker.failed = false;
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

                // A refusal in words (a search too long) is a toast; a library that did not come at all is said where
                // its tiles would be, with Try Again — never "Nothing in this library yet".
                const errors = error.response?.data?.errors;
                if (errors) window.toast(Object.values(errors)[0][0]);
                else if (! more) this.picker.failed = true;
                else window.toast(error.response?.data?.message ?? 'Could not load the library.');
            } finally {
                if (ticket === this.pickerTicket) this.picker.loading = false;
            }
        },

        pick(item) {
            this.chosen = item;
            this.forgetErrors('media_id');
        },

        /** What the card beside the fields says of the tile picked: its name, or that none is yet. */
        chosenTitle() {
            if (this.chosen) return this.chosen.title;

            return this.source === 'ads' ? 'No ad chosen yet.' : 'No file chosen yet.';
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
                // An ad publishes into its organization's library, and one made for every organization into the platform's own.
                return this.libraries.length > 0 && this.picker.library === 'platform'
                    ? 'No ad for every organization is published yet. Publish one in the Ad Builder, or choose an organization above.'
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
                // On a phone the files are above the fields: what is wrong is brought into sight.
                showFirstError(event?.target);
                return;
            }

            this.formErrors = {};
            this.saving = true;
            const named = this.editingAd?.title ?? this.form.title;
            // The answer acts on this form alone: shut since, or opened again for another ad, the dialog is neither shut
            // nor marked by it (core/form-asked.js).
            const asked = formStillAsked(() => this.formTicket, event?.target);
            const sent = this.source === 'upload' ? this.uploaded.upload : null;
            this.uploadBeingSaved = sent;
            const editedId = this.editingAd?.id ?? null;
            const listed = new Set(this.ads.map((ad) => ad.id));
            try {
                const payload = new FormData();
                if (sent) payload.append('upload', sent);
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
                // Its upload is the ad's now: the box lets go of it, and nothing gives it up (uploadBeingSaved).
                if (asked()) this.closeAdModal();
                // An ad the filters leave out would seem to have gone nowhere: the toast says where it is.
                const savedId = editedId ?? this.ads.find((ad) => ! listed.has(ad.id))?.id;
                const hidden = savedId !== undefined && ! this.shownAds().some((ad) => ad.id === savedId);
                window.toast(hidden ? `${data.message}. The filters hide it.` : data.message, 'success');
            } catch (error) {
                const errors = error.response?.status === 422 ? (error.response.data?.errors ?? null) : null;

                if (! asked()) {
                    // Its dialog is gone, and the box let go of the file when it went: given up now, and the refusal said
                    // in a toast.
                    giveUpUpload(AD_BOX, sent, error);
                    window.toast(notSavedWords(errors, named) ?? error.response?.data?.message ?? 'Could not save the ad.');
                } else if (errors) {
                    this.formErrors = errors;
                    showFirstError(event?.target);
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
                this.uploadBeingSaved = null;
            }
        },

        /* ── Order ───────────────────────────────────────────────────────── */

        /** The ad shown just above (`step` -1) or below (1) this one, or null at the end of what is shown. */
        neighbourOf(ad, step) {
            const shown = this.shownAds();

            return shown[shown.findIndex((other) => other.id === ad.id) + step] ?? null;
        },

        /** Move one ad past the next one shown, and send the whole order: with no filter on, a swap with its neighbour;
         *  under a filter, the ads it hides keep their places. The arrows stay pressable while it goes — a disabled
         *  button lets go of the keyboard — and a press meanwhile is simply not taken. */
        async move(moved, step) {
            const neighbour = this.neighbourOf(moved, step);
            if (this.reordering || neighbour === null) return;

            const order = this.ads.map((ad) => ad.id).filter((id) => id !== moved.id);
            const beside = order.indexOf(neighbour.id);
            order.splice(step < 0 ? beside : beside + 1, 0, moved.id);

            this.reordering = true;
            try {
                const { data } = await axios.put(`/channels/${this.channelId}/ads/order`, { ad_ids: order });
                this.ads = data.ads;

                // Its row was taken out of the page and put back: the keyboard goes back to the same arrow of the ad,
                // or the other one once it is at the end, and where it went is said.
                const at = this.ads.findIndex((ad) => ad.id === moved.id);
                this.$nextTick(() => {
                    const direction = step < 0 ? 'up' : 'down';
                    const same = document.querySelector(`[dusk="channel-ad-${direction}-${moved.id}"]`);
                    const other = document.querySelector(`[dusk="channel-ad-${direction === 'up' ? 'down' : 'up'}-${moved.id}"]`);
                    (same && !same.disabled ? same : other)?.focus();
                });
                // Its place in the channel, which is what plays, whatever the filters show.
                if (at !== -1) announce(`${moved.title} moved to position ${at + 1} of ${this.ads.length}.`);
            } catch (error) {
                const errors = error.response?.data?.errors;
                window.toast(errors ? Object.values(errors)[0][0] : (error.response?.data?.message ?? 'Could not change the order.'));
                // Whatever went wrong, show the order as the server has it.
                await this.load({ quiet: true });
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
            // A late answer never shuts the question about another ad (crud-table-base's deleteItem says why).
            const target = this.removingAd.id;
            try {
                const { data } = await axios.delete(`/channels/${this.channelId}/ads/${target}`);
                this.ads = data.ads;
                if (this.removingAd?.id === target) {
                    this.$dispatch('close-modal', 'confirm-channel-ad-deletion');
                    this.removingAd = null;
                }
            } catch (error) {
                window.toast(error.response?.data?.message ?? 'Could not remove the ad.');
            } finally {
                this.removing = false;
            }
        },

        /* ── Filters ─────────────────────────────────────────────────────── */

        /** What the address says the list shows, read before the first load; anything it does not allow is left out. */
        readFilters() {
            const query = new URLSearchParams(window.location?.search ?? '');

            if (STATUSES.includes(query.get('status'))) this.filterStatus = query.get('status');
            if (A_MONTH.test(query.get('month') ?? '')) this.filterMonth = query.get('month');
            if (query.has('search')) this.search = query.get('search').slice(0, 255);
        },

        /** The filters written into the address in place (no new history entry), only those that are on. */
        writeFilters() {
            if (! window.location || ! window.history) return;

            const query = new URLSearchParams(window.location.search);
            const put = (param, value) => (value === '' ? query.delete(param) : query.set(param, value));
            put('status', this.filterStatus);
            put('month', this.filterMonth);
            put('search', this.search.trim());

            const next = query.toString();
            const address = window.location.pathname + (next ? `?${next}` : '') + window.location.hash;
            if (address !== window.location.pathname + window.location.search + window.location.hash) {
                window.history.replaceState(window.history.state, '', address);
            }
        },

        /** A filter changed: the address keeps it, and a screen reader hears how many ads are shown now. */
        filtersChanged() {
            this.writeFilters();
            if (this.ads.length > 0) announce(`${this.shownAds().length} of ${this.ads.length} ads shown.`);
        },

        /** Does the ad pass the filters? `except` leaves one out: a filter's own counts are read under the others. */
        passes(ad, except = null) {
            if (except !== 'status' && this.filterStatus !== '' && ad.status !== this.filterStatus) return false;
            if (except !== 'month' && this.filterMonth !== '' && ! runsInMonth(ad, this.filterMonth)) return false;

            const words = this.search.trim().toLowerCase();

            return words === '' || String(ad.title ?? '').toLowerCase().includes(words);
        },

        /** The ads the list shows, in the channel's own order. */
        shownAds() {
            return this.ads.filter((ad) => this.passes(ad));
        },

        isFiltered() {
            return this.filterStatus !== '' || this.filterMonth !== '';
        },

        /** How many ads of this status the other filters leave: the number beside it in the Status list. */
        statusCount(status) {
            return this.ads.filter((ad) => ad.status === status && this.passes(ad, 'status')).length;
        },

        /** The months any ad runs in ({key: "2026-11", label: "November 2026", count}), for the Month list. */
        months() {
            return monthsNamed(this.ads)
                .filter((key) => this.ads.some((ad) => runsInMonth(ad, key)))
                .map((key) => ({
                    key,
                    label: monthLabel(key),
                    count: this.ads.filter((ad) => runsInMonth(ad, key) && this.passes(ad, 'month')).length,
                }));
        },

        /** The Month list is there once there are two months to choose between (or the address chose one). */
        offersMonths() {
            return this.filterMonth !== '' || this.months().length > 1;
        },

        clearFilters() {
            this.filterStatus = '';
            this.filterMonth = '';
        },

        /** Where the ad plays in the channel, 1 first: shown on its row whatever the filters leave out. */
        placeOf(ad) {
            return this.ads.findIndex((other) => other.id === ad.id) + 1;
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
