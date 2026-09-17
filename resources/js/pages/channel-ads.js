/**
 * One channel's ads: add, edit, reorder and remove them.
 *
 * Every action is its own request, and each answer brings the whole list back — so the
 * page never has to guess what the server did. An upload, a reorder and a removal all
 * end the same way: with the list as it now stands.
 *
 * Uploading posts FormData and measures a video in the browser first, exactly like the
 * media library and the campaigns (there is no ffmpeg on the server). An image is given
 * its seconds; a video has none to give — it plays to its own end (owner's rule).
 */
import axios from 'axios';
import { fileError, readVideoMeta } from '../core/media-file.js';

const blankForm = () => ({ title: '', seconds: 10, starts_on: '', ends_on: '' });

export function registerChannelAds(Alpine) {
    Alpine.data('channelAds', (config = {}) => ({
        channelId: config.channelId,

        maxImageSeconds: config.maxImageSeconds ?? 300,
        adsPerPass: config.adsPerPass ?? null,

        ads: [],
        loading: true,
        saving: false,
        reordering: false,
        removing: false,

        editingAd: null,
        removingAd: null,
        form: blankForm(),
        formErrors: {},
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
            this.selectedFile = null;
            this.clientMeta = {};
            this.preparing = false;
            if (this.$refs.fileInput) this.$refs.fileInput.value = '';

            this.form = ad
                ? {
                    title: ad.title,
                    seconds: ad.duration_seconds ?? 10,
                    starts_on: ad.starts_on ?? '',
                    ends_on: ad.ends_on ?? '',
                }
                : blankForm();

            this.$dispatch('open-modal', 'channel-ad-modal');
        },

        closeAdModal() {
            this.$dispatch('close-modal', 'channel-ad-modal');
            this.editingAd = null;
            this.formErrors = {};
        },

        /** Is the ad in the form a video? The newly chosen file decides when there is
         *  one; otherwise the file the ad already has. */
        isVideo() {
            if (this.selectedFile) return this.selectedFile.type.startsWith('video/');

            return this.editingAd?.type === 'video';
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

        /* Mirrors ChannelAdRequest. The server still decides. */
        validateAd() {
            const errors = {};

            if (! this.editingAd && ! this.selectedFile) {
                errors.file = ['Choose the ad to upload.'];
            }

            if (String(this.form.title ?? '').length > 255) {
                errors.title = ['The title may not be longer than 255 characters.'];
            }

            if (! this.isVideo()) {
                const seconds = Number(this.form.seconds);

                if (! Number.isInteger(seconds) || seconds < 1) {
                    errors.seconds = ['Say how many seconds the image stays on screen.'];
                } else if (seconds > this.maxImageSeconds) {
                    errors.seconds = [`An image may not stay up longer than ${this.maxImageSeconds} seconds.`];
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
                if (this.selectedFile) payload.append('file', this.selectedFile);

                const fields = {
                    title: this.form.title,
                    starts_on: this.form.starts_on,
                    ends_on: this.form.ends_on,
                };

                // Seconds only for an image. A video has none to send.
                if (! this.isVideo()) fields.seconds = this.form.seconds;

                Object.entries(fields).forEach(([key, value]) => {
                    if (value !== '' && value !== null && value !== undefined) payload.append(key, value);
                });

                Object.entries(this.clientMeta).forEach(([key, value]) => {
                    if (value !== null && value !== undefined) payload.append(key, value);
                });

                const url = this.editingAd
                    ? `/channels/${this.channelId}/ads/${this.editingAd.id}`
                    : `/channels/${this.channelId}/ads`;

                const { data } = await axios.post(url, payload);
                this.ads = data.ads;
                this.closeAdModal();
                window.toast(data.message, 'success');
            } catch (error) {
                if (error.response?.status === 422 && error.response.data.errors) {
                    this.formErrors = error.response.data.errors;
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

        datesLabel(ad) {
            if (! ad.starts_on && ! ad.ends_on) return 'No end date';

            return `${ad.starts_on ?? 'now'} → ${ad.ends_on ?? 'no end'}`;
        },

        statusLabel(ad) {
            return { running: 'Running', scheduled: 'Starts later', ended: 'Ended' }[ad.status] ?? ad.status;
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
