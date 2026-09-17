/**
 * The playlist builder for one screen.
 *
 * Edits are local until Save Changes: adding, removing, reordering and retiming
 * all change the same array, and the whole list is written in one PUT. That keeps
 * ordering trivially correct (position is just the array index on the server) and
 * means a half-finished rearrangement never reaches a TV.
 */
import axios from 'axios';
import { toAmPm, windowLabel } from '../core/clock.js';
import { PlaylistItemDefaults } from '../core/playlist-defaults.js';

/** A rule as the editor holds it. day_mode is a UI idea only — the server stores
 *  the dates and the repeat, and infers nothing from a mode. */
const blankRule = () => ({
    daypart_id: '',
    day_mode: 'always',
    starts_on: '',
    ends_on: '',
    recurrence_type: 'weekly',
    recurrence_interval: 1,
    recurrence_weekdays: [],
    recurrence_monthday: 1,
    recurrence_ordinal: 1,
    recurrence_weekday: 1,
    recurrence_until: '',
});

/** Turn a saved rule back into the shape the editor edits. */
const ruleFromServer = (rule) => ({
    daypart_id: rule.daypart_id ?? '',
    day_mode: rule.recurrence_type ? 'repeat' : ((rule.starts_on || rule.ends_on) ? 'range' : 'always'),
    starts_on: rule.starts_on ?? '',
    ends_on: rule.ends_on ?? '',
    recurrence_type: rule.recurrence_type ?? 'weekly',
    recurrence_interval: rule.recurrence_interval ?? 1,
    recurrence_weekdays: rule.recurrence_weekdays ?? [],
    recurrence_monthday: rule.recurrence_monthday ?? 1,
    recurrence_ordinal: rule.recurrence_ordinal ?? 1,
    recurrence_weekday: rule.recurrence_weekday ?? 1,
    recurrence_until: rule.recurrence_until ?? '',
});

/** …and back into what the API takes. The fields belonging to the other day modes
 *  are dropped rather than sent, so a rule that used to be a date range does not
 *  quietly carry its old dates around once it repeats. */
const ruleToServer = (rule) => {
    const repeating = rule.day_mode === 'repeat';
    const ranged = rule.day_mode === 'range';

    return {
        daypart_id: rule.daypart_id === '' ? null : Number(rule.daypart_id),
        starts_on: (ranged || repeating) && rule.starts_on ? rule.starts_on : null,
        ends_on: ranged && rule.ends_on ? rule.ends_on : null,
        recurrence_type: repeating ? rule.recurrence_type : null,
        recurrence_interval: repeating ? Math.max(1, Number(rule.recurrence_interval) || 1) : 1,
        recurrence_weekdays: repeating && rule.recurrence_type === 'weekly'
            ? rule.recurrence_weekdays.map(Number)
            : [],
        recurrence_monthday: repeating && rule.recurrence_type === 'monthly_day'
            ? Number(rule.recurrence_monthday) : null,
        recurrence_ordinal: repeating && rule.recurrence_type === 'monthly_weekday'
            ? Number(rule.recurrence_ordinal) : null,
        recurrence_weekday: repeating && rule.recurrence_type === 'monthly_weekday'
            ? Number(rule.recurrence_weekday) : null,
        recurrence_until: repeating && rule.recurrence_until ? rule.recurrence_until : null,
    };
};

export function registerScreenPlaylist(Alpine) {
    Alpine.data('screenPlaylist', (config = {}) => ({
        screenId: config.screenId,
        canEdit: config.canEdit ?? false,
        /* Fixed lists handed down by the page — see ScreenController::show. */
        dayparts: config.dayparts ?? [],
        weekdays: config.weekdays ?? {},

        ordinals: config.ordinals ?? {},

        items: [],
        // What the server said the playlist was when this page loaded. Sent back
        // on save so the server can refuse rather than silently overwrite work
        // somebody else did in the meantime. Nothing polls it — it only travels
        // with the save that was already happening.
        version: null,
        available: [],
        /* Every channel this screen could carry, and the one whose ads are unfolded. */
        channels: [],
        openChannelId: null,
        search: '',
        loading: true,
        saving: false,
        dirty: false,
        searchTimer: null,
        /* Rows need a stable key that survives reordering, and a brand-new row has
         * no id yet — so the key is issued here, not taken from the server. */
        nextKey: 1,

        /* ── The schedule editor, open over one item at a time ──────────── */
        scheduleIndex: null,
        scheduleRules: [],
        preview: [],
        previewing: false,
        previewTimer: null,

        /* ── Copying this playlist onto other screens ───────────────────── */
        copyTargets: [],
        copySelected: [],
        copying: false,

        init() {
            this.load();
            this.loadAvailable();
            this.loadChannels();
            this.$watch('search', () => {
                if (this.searchTimer) clearTimeout(this.searchTimer);
                this.searchTimer = setTimeout(() => this.loadAvailable(), 400);
            });
        },

        async load() {
            this.loading = true;
            try {
                const { data } = await axios.get(`/screens/${this.screenId}/playlist`);
                this.items = data.items.map((item) => ({
                    ...item,
                    key: this.nextKey++,
                    rules: (item.rules ?? []).map(ruleFromServer),
                }));
                this.version = data.version;
                this.dirty = false;
            } catch (error) {
                window.toast(error.response?.data?.message ?? 'Could not load the playlist.');
            } finally {
                this.loading = false;
            }
        },

        async loadAvailable() {
            try {
                const { data } = await axios.get(`/screens/${this.screenId}/available-media`, {
                    params: { search: this.search },
                });
                this.available = data.media;
            } catch (error) {
                if (error.response?.status !== 403) {
                    window.toast('Could not load the content library.');
                }
            }
        },

        async loadChannels() {
            try {
                const { data } = await axios.get(`/screens/${this.screenId}/available-channels`);
                this.channels = data.channels;
            } catch (error) {
                // Someone who may only look at a playlist is not given the list, and has
                // no use for it.
                if (error.response?.status !== 403) {
                    window.toast('Could not load the channels.');
                }
            }
        },

        /* ── Editing ───────────────────────────────────────────────────── */
        addItem(media) {
            this.items.push({
                key: this.nextKey++,
                media_id: media.id,
                title: media.title,
                type: media.type,
                orientation: media.orientation,
                thumbnail_url: media.thumbnail_url,
                media_duration: media.duration_seconds,
                // A video runs to its own end; an image needs a time to be told.
                duration_seconds: media.type === 'video'
                    ? (media.duration_seconds || PlaylistItemDefaults.imageSeconds)
                    : PlaylistItemDefaults.imageSeconds,
                expires_at: null,
                // No rules means "whenever the screen is on", which is what almost
                // every item wants and therefore what a new one starts as.
                rules: [],
            });
            this.dirty = true;
        },

        /**
         * One line that plays the whole channel, wherever it ends up in the list. It
         * carries no length of its own: it lasts as long as the ads it plays that day,
         * and new ones arrive without anybody coming back here.
         */
        addChannel(channel) {
            this.items.push({
                key: this.nextKey++,
                media_id: null,
                channel_id: channel.id,
                title: channel.title,
                type: 'channel',
                thumbnail_url: channel.thumbnail_url,
                channel_active: channel.channel_active,
                ads_count: channel.ads_count,
                ads_per_pass: channel.ads_per_pass,
                pass_ads: channel.pass_ads,
                pass_seconds: channel.pass_seconds,
                duration_seconds: null,
                expires_at: null,
                rules: [],
            });
            this.dirty = true;
        },

        removeItem(index) {
            this.items.splice(index, 1);
            this.dirty = true;
        },

        moveUp(index) {
            if (index === 0) return;
            [this.items[index - 1], this.items[index]] = [this.items[index], this.items[index - 1]];
            this.dirty = true;
        },

        moveDown(index) {
            if (index >= this.items.length - 1) return;
            [this.items[index + 1], this.items[index]] = [this.items[index], this.items[index + 1]];
            this.dirty = true;
        },

        async save() {
            if (this.saving || !this.dirty) return;

            this.saving = true;
            try {
                const { data } = await axios.put(`/screens/${this.screenId}/playlist`, {
                    // The schedules travel WITH the save. Stored separately they would be
                    // wiped by the cascade every time somebody merely reordered the list.
                    items: this.items.map((item) => (item.type === 'channel'
                        // A channel line has no length to send — it lasts as long as its ads.
                        ? { channel_id: item.channel_id, rules: (item.rules ?? []).map(ruleToServer) }
                        : {
                            media_id: item.media_id,
                            duration_seconds: Number(item.duration_seconds) || PlaylistItemDefaults.imageSeconds,
                            rules: (item.rules ?? []).map(ruleToServer),
                        })),
                    version: this.version,
                });
                this.items = data.items.map((item) => ({
                    ...item,
                    key: this.nextKey++,
                    rules: (item.rules ?? []).map(ruleFromServer),
                }));
                this.version = data.version;
                this.dirty = false;
                window.toast('Playlist saved', 'success');
            } catch (error) {
                const errors = error.response?.data?.errors;
                window.toast(
                    errors ? Object.values(errors)[0][0] : (error.response?.data?.message ?? 'Could not save the playlist.')
                );
            } finally {
                this.saving = false;
            }
        },

        /* ── The schedule editor ───────────────────────────────────────── */

        openSchedule(index) {
            this.scheduleIndex = index;
            // A working copy: Cancel has to leave the item exactly as it was, and the
            // rules are nested objects, so a shallow copy would not be one.
            this.scheduleRules = JSON.parse(JSON.stringify(this.items[index].rules ?? []));
            this.preview = [];
            this.refreshPreview();
            this.$dispatch('open-modal', 'playlist-schedule-modal');
        },

        closeSchedule() {
            this.$dispatch('close-modal', 'playlist-schedule-modal');
            this.scheduleIndex = null;
            this.scheduleRules = [];
            this.preview = [];
        },

        /** OK, not Save: the schedule is staged into the playlist and committed by
         *  the playlist's own Save Changes, in one atomic write. */
        applySchedule() {
            if (this.scheduleIndex === null) return;
            this.items[this.scheduleIndex].rules = this.scheduleRules;
            this.dirty = true;
            this.closeSchedule();
        },

        addRule() {
            this.scheduleRules.push(blankRule());
            this.refreshPreview();
        },

        removeRule(index) {
            this.scheduleRules.splice(index, 1);
            this.refreshPreview();
        },

        /** The weekday checkboxes for a weekly repeat. */
        toggleWeekday(rule, day) {
            const value = Number(day);
            const at = rule.recurrence_weekdays.indexOf(value);
            if (at === -1) rule.recurrence_weekdays.push(value);
            else rule.recurrence_weekdays.splice(at, 1);
            this.refreshPreview();
        },

        hasWeekday(rule, day) {
            return rule.recurrence_weekdays.includes(Number(day));
        },

        /**
         * "When would this actually play?" — answered by the server, through the very
         * same code the television is answered with. Computing it here in the browser
         * would be a second implementation of the recurrence rules, and the day the
         * two disagreed the preview would be worse than none at all.
         */
        refreshPreview() {
            if (this.previewTimer) clearTimeout(this.previewTimer);

            this.previewTimer = setTimeout(async () => {
                if (this.scheduleIndex === null) return;

                if (this.scheduleRules.length === 0) {
                    this.preview = [];
                    return;
                }

                this.previewing = true;
                try {
                    const { data } = await axios.post(`/screens/${this.screenId}/playlist/preview`, {
                        rules: this.scheduleRules.map(ruleToServer),
                        days: 7,
                    });
                    this.preview = data.occurrences;
                } catch {
                    // A preview that cannot be built is not worth an error banner —
                    // the save itself will say what is wrong with the rule.
                    this.preview = [];
                } finally {
                    this.previewing = false;
                }
            }, 350);
        },

        /* ── Copying onto other screens ─────────────────────────────────── */

        async openCopyModal() {
            this.copySelected = [];
            this.$dispatch('open-modal', 'playlist-copy-modal');

            try {
                const { data } = await axios.get(`/screens/${this.screenId}/playlist/copy-targets`);
                this.copyTargets = data.screens;
            } catch {
                window.toast('Could not load the other screens.');
            }
        },

        toggleCopyTarget(id) {
            const at = this.copySelected.indexOf(id);
            if (at === -1) this.copySelected.push(id);
            else this.copySelected.splice(at, 1);
        },

        /** What the person is about to overwrite, counted before they press the
         *  button rather than discovered afterwards. */
        copyWillReplace() {
            return this.copyTargets
                .filter((screen) => this.copySelected.includes(screen.id))
                .reduce((total, screen) => total + screen.playlist_items_count, 0);
        },

        async doCopy() {
            if (this.copying || this.copySelected.length === 0) return;

            this.copying = true;
            try {
                const { data } = await axios.post(`/screens/${this.screenId}/playlist/copy`, {
                    target_screen_ids: this.copySelected,
                });
                this.$dispatch('close-modal', 'playlist-copy-modal');
                window.toast(data.message, 'success');
            } catch (error) {
                const errors = error.response?.data?.errors;
                window.toast(
                    errors ? Object.values(errors)[0][0] : (error.response?.data?.message ?? 'Could not copy the playlist.')
                );
            } finally {
                this.copying = false;
            }
        },

        /* ── Display ───────────────────────────────────────────────────── */

        /** "Lunch (11:00 AM – 3:00 PM)" — built here rather than on the server, so one
         *  place decides how a clock reads. */
        daypartLabel(daypart) {
            return `${daypart.name} (${windowLabel(daypart.start_time, daypart.end_time)})`;
        },

        /** One window of the preview: "Fri 20 Mar 11:00 AM–3:00 PM". */
        slotLabel(slot) {
            return slot.start ? ` ${toAmPm(slot.start)}–${toAmPm(slot.end)}` : '';
        },

        /** One rule in plain English, so nobody has to read four inputs to know what
         *  they just said. */
        ruleSummary(rule) {
            const daypart = this.dayparts.find((d) => String(d.id) === String(rule.daypart_id));
            const when = daypart ? this.daypartLabel(daypart) : 'All day';

            if (rule.day_mode === 'always') return `Every day · ${when}`;

            if (rule.day_mode === 'range') {
                const from = rule.starts_on || 'always';
                const to = rule.ends_on || 'forever';
                return `${from} → ${to} · ${when}`;
            }

            const every = Number(rule.recurrence_interval) > 1
                ? `every ${rule.recurrence_interval} ` : 'every ';

            let days;
            switch (rule.recurrence_type) {
                case 'weekly':
                    days = rule.recurrence_weekdays.length
                        ? `${every}week on ` + rule.recurrence_weekdays
                            .map((d) => this.weekdays[String(d)]).filter(Boolean).join(', ')
                        : `${every}week (pick a day)`;
                    break;
                case 'monthly_day':
                    days = `${every}month on day ${rule.recurrence_monthday}`;
                    break;
                case 'monthly_weekday':
                    days = `${every}month on the ${this.ordinals[String(rule.recurrence_ordinal)] ?? ''} `
                        + (this.weekdays[String(rule.recurrence_weekday)] ?? '');
                    break;
                case 'yearly':
                    days = `${every}year on ${rule.starts_on || 'the start date'}`;
                    break;
                default:
                    days = `${every}day`;
            }

            const until = rule.recurrence_until ? `, until ${rule.recurrence_until}` : '';

            return `${days}${until} · ${when}`;
        },

        /** The badge on a playlist row: nothing at all when the item is unscheduled. */
        scheduleBadge(item) {
            const count = (item.rules ?? []).length;
            if (count === 0) return '';
            return count === 1 ? this.ruleSummary(item.rules[0]) : `${count} schedules`;
        },

        /** How long one line holds the screen: a file its seconds, a channel about one
         *  pass of its ads (they rotate when a pass plays only some of them). */
        lineSeconds(item) {
            return item.type === 'channel'
                ? (Number(item.pass_seconds) || 0)
                : (Number(item.duration_seconds) || 0);
        },

        /** "5 ads · 2 each time, about 20 secs" — or, plainly, why it will play nothing.
         *  Used for a playlist line and for a channel in the box alike: both carry the
         *  same fields. */
        channelInfo(channel) {
            if (! channel.channel_active) return 'Paused — plays nothing until it is switched back on';
            if (! channel.ads_count) return 'No ads running today';

            const ads = `${channel.ads_count} ${channel.ads_count === 1 ? 'ad' : 'ads'}`;
            const each = channel.pass_ads < channel.ads_count ? `${channel.pass_ads} each time, ` : '';

            return `${ads} · ${each}about ${this.formatDuration(channel.pass_seconds)}`;
        },

        channelWarning(channel) {
            return ! channel.channel_active || ! channel.ads_count;
        },

        toggleChannelAds(id) {
            this.openChannelId = this.openChannelId === id ? null : id;
        },

        /** One ad in the unfolded channel: its length, and its last day if it has one. */
        adLine(ad) {
            const length = ad.type === 'video'
                ? `${this.formatDuration(ad.play_seconds)} video`
                : this.formatDuration(ad.play_seconds);

            return ad.ends_on ? `${length} · until ${ad.ends_on}` : length;
        },

        summary() {
            const seconds = this.items.reduce((total, item) => total + this.lineSeconds(item), 0);
            const count = `${this.items.length} ${this.items.length === 1 ? 'item' : 'items'}`;
            return `${count} · ${this.formatDuration(seconds)}`;
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
