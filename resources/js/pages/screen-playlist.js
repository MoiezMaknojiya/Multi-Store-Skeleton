/**
 * The playlist builder for one screen.
 *
 * Edits are local until Save Changes: adding, removing, reordering and retiming
 * all change the same array, and the whole list is written in one PUT. That keeps
 * ordering trivially correct (position is just the array index on the server) and
 * means a half-finished rearrangement never reaches a TV.
 */
import axios from 'axios';
import { dayLabel, toAmPm, windowLabel } from '../core/clock.js';
import { PlaylistItemDefaults } from '../core/playlist-defaults.js';
import { isARepeatPress } from '../core/click-beside.js';

/** A rule as the editor holds it. day_mode and time_mode are UI ideas only — the server stores
 *  the dates, the repeat and the two times, and infers nothing from a mode. */
const blankRule = () => ({
    time_mode: 'all',
    start_time: '',
    end_time: '',
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
    time_mode: rule.start_time && rule.end_time ? 'times' : 'all',
    start_time: rule.start_time ?? '',
    end_time: rule.end_time ?? '',
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

/** …and back into what the API takes. The fields belonging to the other modes
 *  are dropped rather than sent, so a rule that used to be a date range does not
 *  quietly carry its old dates around once it repeats — nor its old hours once it is all day. */
const ruleToServer = (rule) => {
    const repeating = rule.day_mode === 'repeat';
    const ranged = rule.day_mode === 'range';
    const timed = rule.time_mode === 'times';

    return {
        start_time: timed && rule.start_time ? rule.start_time : null,
        end_time: timed && rule.end_time ? rule.end_time : null,
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

/** The day before a stored day: "2026-10-01" → "2026-09-30". */
const dayBefore = (day) => {
    const [year, month, date] = String(day).split('-').map(Number);

    return new Date(Date.UTC(year, month - 1, date - 1)).toISOString().slice(0, 10);
};

export function registerScreenPlaylist(Alpine) {
    Alpine.data('screenPlaylist', (config = {}) => ({
        screenId: config.screenId,
        /* Which way the panel is mounted — landscape or portrait — so a line the other way round can say
         * it will play with bars (docs/AD-BUILDER-SPEC.md §12). */
        screenOrientation: config.screenOrientation === 'portrait' ? 'portrait' : 'landscape',
        canEdit: config.canEdit ?? false,
        /* Fixed lists handed down by the page — see ScreenController::show. */
        weekdays: config.weekdays ?? {},

        ordinals: config.ordinals ?? {},

        items: [],
        /* The screen's own today ("2026-10-02"), as the playlist's answer says it: a schedule has ended by the
         * television's clock, not the browser's. */
        localDate: null,
        // What the server said the playlist was when this page loaded. Sent back
        // on save so the server can refuse rather than silently overwrite work
        // somebody else did in the meantime. Nothing polls it — it only travels
        // with the save that was already happening.
        version: null,
        available: [],
        /* How many files the library offers in all, whatever the search says: the number on its tab. */
        availableTotal: null,
        /* Every channel this screen could carry, and the one whose ads are unfolded. */
        channels: [],
        openChannelId: null,
        /* Which tab of the card beside the playlist is open: 'library' or 'channels' (openPickerTab). */
        pickerTab: 'library',
        /* Platform Channels locked for the organization (docs/BILLING-SPEC.md §6): its platform channels offer Unlock, not Add. */
        channelsLocked: false,
        search: '',
        availableToken: 0,
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
        /* What is wrong with each rule, by its index — said under it when OK is pressed (ruleProblems). */
        scheduleErrors: [],
        /* Why the server could not build the preview, instead of "nothing in the next 7 days". */
        previewError: '',
        preview: [],
        previewing: false,
        previewTimer: null,
        previewToken: 0,
        /* The other lines this schedule is given to as well when OK is pressed, by their row keys. */
        copyOpen: false,
        copyToKeys: [],

        /* ── Copying this playlist onto other screens ───────────────────── */
        copyTargets: [],
        copySelected: [],
        copying: false,
        copyLoading: false,
        availableLoaded: false,   // the library's first answer is in: until then it says "Loading…", not "nothing"
        leaveGuard: null,

        init() {
            this.load();
            // The library and the channels are there to add from, so the page draws them only for
            // someone who may change the playlist (screen-playlist) — the same permission their
            // endpoints ask for. Anybody else would only be refused.
            if (this.canEdit) {
                this.loadAvailable();
                this.loadChannels();
            }

            // Edits live on this page until Save Changes: leaving with some asks first, as the Ad Builder does.
            this.leaveGuard = (event) => {
                if (!this.dirty) return;
                event.preventDefault();
                event.returnValue = '';
            };
            window.addEventListener('beforeunload', this.leaveGuard);

            this.$watch('search', () => {
                if (this.searchTimer) clearTimeout(this.searchTimer);
                this.searchTimer = setTimeout(() => this.loadAvailable(), 400);
            });
        },

        destroy() {
            if (this.leaveGuard) window.removeEventListener('beforeunload', this.leaveGuard);
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
                this.localDate = data.local_date ?? null;
                this.dirty = false;
            } catch (error) {
                window.toast(error.response?.data?.message ?? 'Could not load the playlist.');
            } finally {
                this.loading = false;
            }
        },

        async loadAvailable() {
            // The newest search wins: "Pro" then "Promo" sends two, and the answer to "Pro"
            // arriving second would list files the box is no longer asking for.
            const token = ++this.availableToken;
            try {
                const { data } = await axios.get(`/screens/${this.screenId}/available-media`, {
                    params: { search: this.search },
                });
                if (token !== this.availableToken) return;
                this.available = data.media;
                this.availableTotal = data.total;
            } catch (error) {
                if (token !== this.availableToken) return;
                if (error.response?.status !== 403) {
                    window.toast('Could not load the content library.');
                }
            } finally {
                if (token === this.availableToken) this.availableLoaded = true;
            }
        },

        async loadChannels() {
            try {
                const { data } = await axios.get(`/screens/${this.screenId}/available-channels`);
                this.channels = data.channels;
                this.channelsLocked = data.platform_channels_locked === true;
            } catch (error) {
                // Someone who may only look at a playlist is not given the list, and has
                // no use for it.
                if (error.response?.status !== 403) {
                    window.toast('Could not load the channels.');
                }
            }
        },

        /* ── Editing ───────────────────────────────────────────────────── */
        addItem(media, event) {
            // Not while a save is on its way: its answer replaces the list, and this line would go with it. And once
            // for a double click: its second press is the same choice, not a second line (isARepeatPress).
            if (this.saving || isARepeatPress(event)) return;

            this.items.push({
                key: this.nextKey++,
                media_id: media.id,
                title: media.title,
                type: media.type,
                orientation: media.orientation ?? null,
                thumbnail_url: media.thumbnail_url,
                // A picture stays up for as long as the line says. A video runs to its own end — its
                // measured length, or a generous backstop for one nobody could measure (never an image's
                // six seconds, which would cut it off) — and an Ad Builder page for the length its design
                // says; a page published before designs had a length is timed like a picture.
                duration_seconds: media.type === 'video'
                    ? (media.duration_seconds || PlaylistItemDefaults.unmeasuredVideoSeconds)
                    : (media.type === 'html' && media.duration_seconds ? media.duration_seconds : PlaylistItemDefaults.imageSeconds),
                runs_own_length: media.type === 'video' || (media.type === 'html' && Number(media.duration_seconds) > 0),
                // The picker never offers an Ad Builder page taken off the screens (unpublished).
                is_draft: false,
                // No rules means "whenever the screen is on", which is what almost
                // every item wants and therefore what a new one starts as.
                rules: [],
            });
            this.dirty = true;

            // Below lg the playlist is above the library, out of sight: say where the file went.
            if (!window.matchMedia('(min-width: 1024px)').matches) {
                window.toast(`${media.title} added. Press Save Changes to send it to the screen.`, 'success');
            }
        },

        /**
         * One line that plays the whole channel, wherever it ends up in the list. It
         * carries no length of its own: it lasts as long as the ads it plays that day,
         * and new ones arrive without anybody coming back here.
         */
        addChannel(channel, event) {
            if (this.saving || channel.locked || isARepeatPress(event)) return;

            this.items.push({
                key: this.nextKey++,
                media_id: null,
                channel_id: channel.id,
                title: channel.title,
                type: 'channel',
                thumbnail_url: channel.thumbnail_url,
                channel_active: channel.channel_active,
                ads_count: channel.ads_count,
                pass_ads: channel.pass_ads,
                pass_seconds: channel.pass_seconds,
                duration_seconds: null,
                rules: [],
            });
            this.dirty = true;
        },

        // Once for a double click (isARepeatPress): the line under the pointer after the first press is the NEXT one —
        // a double press on × took two lines, and one on ↑ moved the line and then the one it swapped with back.
        removeItem(index, event) {
            if (this.saving || isARepeatPress(event)) return;

            this.items.splice(index, 1);
            this.dirty = true;
        },

        moveUp(index, event) {
            if (index === 0 || this.saving || isARepeatPress(event)) return;
            [this.items[index - 1], this.items[index]] = [this.items[index], this.items[index - 1]];
            this.dirty = true;
        },

        moveDown(index, event) {
            if (index >= this.items.length - 1 || this.saving || isARepeatPress(event)) return;
            [this.items[index + 1], this.items[index]] = [this.items[index], this.items[index + 1]];
            this.dirty = true;
        },

        async save() {
            if (this.saving || !this.dirty) return;

            // Every timed line's seconds, checked here in the server's own words before anything is sent: each line
            // with a problem gets its red border, and the first is said, with its title.
            let first = null;

            this.items.forEach((item) => {
                item.secondsError = this.secondsProblem(item);

                if (item.secondsError && first === null) first = item;
            });

            if (first) {
                window.toast(`${first.title}: ${first.secondsError}`);

                return;
            }

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
                            duration_seconds: Number(item.duration_seconds) || (item.type === 'video'
                                ? PlaylistItemDefaults.unmeasuredVideoSeconds
                                : PlaylistItemDefaults.imageSeconds),
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
                this.localDate = data.local_date ?? this.localDate;
                this.dirty = false;
                window.toast('Playlist saved. The screen shows it within 30 seconds.', 'success');
            } catch (error) {
                const errors = error.response?.data?.errors;

                // A line's own refusal goes back to that line's box, as a red border (the lines went in this order).
                Object.entries(errors ?? {}).forEach(([key, messages]) => {
                    const line = key.match(/^items\.(\d+)\.duration_seconds$/);

                    if (line && this.items[Number(line[1])]) this.items[Number(line[1])].secondsError = messages[0];
                });

                window.toast(
                    errors ? Object.values(errors)[0][0] : (error.response?.data?.message ?? 'Could not save the playlist.')
                );
            } finally {
                this.saving = false;
            }
        },

        /**
         * What is wrong with a timed line's seconds — worded as the server says it, starting small so the line's
         * title can go in front — or null. A video, an ad page with a length of its own and a channel have none.
         */
        secondsProblem(item) {
            if (!this.isTimed(item)) return null;

            const typed = item.duration_seconds;

            if (typed === '' || typed === null || typed === undefined) return 'say how many seconds it stays on screen.';

            const seconds = Number(typed);

            if (!Number.isInteger(seconds)) return 'give the seconds as a whole number.';
            if (seconds < PlaylistItemDefaults.minImageSeconds) return `a picture stays on screen for at least ${PlaylistItemDefaults.minImageSeconds} seconds.`;
            if (seconds > PlaylistItemDefaults.maxImageSeconds) return 'a picture stays on screen for at most 24 hours.';

            return null;
        },

        /* ── The schedule editor ───────────────────────────────────────── */

        openSchedule(index) {
            if (this.saving) return;

            this.scheduleIndex = index;
            // A working copy: Cancel has to leave the item exactly as it was, and the
            // rules are nested objects, so a shallow copy would not be one.
            this.scheduleRules = JSON.parse(JSON.stringify(this.items[index].rules ?? []));
            this.preview = [];
            this.copyOpen = false;
            this.copyToKeys = [];
            this.refreshPreview();
            this.$dispatch('open-modal', 'playlist-schedule-modal');
        },

        closeSchedule() {
            this.$dispatch('close-modal', 'playlist-schedule-modal');
            this.scheduleIndex = null;
            this.scheduleRules = [];
            this.preview = [];
            this.copyOpen = false;
            this.copyToKeys = [];
        },

        /** OK, not Save: the schedule is staged into the playlist and committed by
         *  the playlist's own Save Changes, in one atomic write. */
        applySchedule() {
            if (this.scheduleIndex === null) return;

            // Each rule checked as the server will check it: the window stays open, with the reason under the rule.
            const errors = this.scheduleRules.map((rule) => this.ruleProblems(rule));
            this.unreadableRuleFields().forEach(([index, field, message]) => { errors[index] = { ...errors[index], [field]: message }; });

            if (errors.some((problems) => Object.keys(problems).length > 0)) {
                this.scheduleErrors = errors;

                return;
            }

            const rules = this.scheduleRules;
            // The other lines ticked under "Copy to Other Lines" take the same schedule, each a copy of its own.
            const others = this.items.filter((item) => this.copyToKeys.includes(item.key));

            this.items[this.scheduleIndex].rules = rules;
            others.forEach((item) => { item.rules = JSON.parse(JSON.stringify(rules)); });
            this.dirty = true;
            this.closeSchedule();

            if (others.length > 0) {
                window.toast(`Schedule copied to ${others.length} other ${others.length === 1 ? 'line' : 'lines'}.`, 'success');
            }
        },

        /** The playlist's other lines, which this schedule may be copied to. */
        otherLines() {
            return this.items
                .map((item, index) => ({ key: item.key, number: index + 1, title: item.title }))
                .filter((line, index) => index !== this.scheduleIndex);
        },

        toggleCopyLine(key) {
            const at = this.copyToKeys.indexOf(key);
            if (at === -1) this.copyToKeys.push(key);
            else this.copyToKeys.splice(at, 1);
        },

        /** Every other line at once — or none, when they are all ticked already. */
        toggleEveryCopyLine() {
            const keys = this.otherLines().map((line) => line.key);

            this.copyToKeys = this.copyToKeys.length === keys.length ? [] : keys;
        },

        /** What is wrong with one rule, as {field: message} — the server's own rules and words (ruleMessages). */
        ruleProblems(rule) {
            const problems = {};
            const whole = (value, from, to) => Number.isInteger(Number(value)) && String(value).trim() !== ''
                && Number(value) >= from && Number(value) <= to;

            if (rule.day_mode === 'range' && rule.starts_on && rule.ends_on && rule.ends_on < rule.starts_on) {
                problems.ends_on = 'The end date cannot be before the start date.';
            }

            if (rule.day_mode === 'repeat') {
                if (!whole(rule.recurrence_interval, 1, 52)) problems.recurrence_interval = 'Repeat every: enter a whole number from 1 to 52.';
                if (rule.recurrence_type === 'weekly' && rule.recurrence_weekdays.length === 0) problems.recurrence_weekdays = 'Choose at least one day of the week.';
                if (rule.recurrence_type === 'monthly_day' && !whole(rule.recurrence_monthday, 1, 31)) problems.recurrence_monthday = 'On day: enter a day of the month from 1 to 31.';
                if (rule.starts_on && rule.recurrence_until && rule.recurrence_until < rule.starts_on) problems.recurrence_until = 'The repeat cannot end before the schedule starts.';
            }

            if (rule.time_mode === 'times') {
                if (!rule.start_time || !rule.end_time) {
                    problems[rule.start_time ? 'end_time' : 'start_time'] = 'Time: give both a start and an end, or choose All day.';
                } else if (rule.start_time === rule.end_time) {
                    problems.end_time = 'Time: the start and the end cannot be the same. To run past midnight, set an end earlier than the start.';
                }
            }

            return problems;
        },

        /**
         * The window's dates, times and numbers the browser could not read — typed only in part — which it hands
         * over as '' and would save as no date at all: [rule index, field, message] each (validity.badInput).
         */
        unreadableRuleFields() {
            const fields = {
                'rule-starts-on': 'starts_on', 'rule-ends-on': 'ends_on', 'rule-repeat-start': 'starts_on',
                'rule-until': 'recurrence_until', 'rule-interval': 'recurrence_interval', 'rule-monthday': 'recurrence_monthday',
                'rule-start-time': 'start_time', 'rule-end-time': 'end_time',
            };
            const messages = {
                date: 'Enter the whole date, or leave it blank.',
                time: 'Time: enter the whole time, or choose All day.',
            };

            return [...document.querySelectorAll('[dusk^="rule-"]')]
                .filter((input) => input.validity?.badInput)
                .map((input) => {
                    const [, name, index] = input.getAttribute('dusk').match(/^(rule-[a-z-]+)-(\d+)$/) ?? [];

                    return fields[name] ? [Number(index), fields[name], messages[input.type] ?? 'Enter a number.'] : null;
                })
                .filter(Boolean);
        },

        /** The rule's first problem, said under it — or ''. */
        ruleError(index) {
            return Object.values(this.scheduleErrors[index] ?? {})[0] ?? '';
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

        /** The quick picks beside the weekday buttons: Monday to Friday, or Saturday and Sunday. */
        setWeekdays(rule, days) {
            rule.recurrence_weekdays = [...days];
            this.refreshPreview();
        },

        /** An end before the start: the hours run past midnight, and the window says so. */
        crossesMidnight(rule) {
            return rule.time_mode === 'times' && !!rule.start_time && !!rule.end_time && rule.end_time < rule.start_time;
        },

        /**
         * "When would this actually play?" — answered by the server, through the very
         * same code the television is answered with. Computing it here in the browser
         * would be a second implementation of the recurrence rules, and the day the
         * two disagreed the preview would be worse than none at all.
         */
        refreshPreview() {
            if (this.previewTimer) clearTimeout(this.previewTimer);

            // A change is a new try: what OK said about the rules as they were no longer stands.
            this.scheduleErrors = [];

            // Taken when the rules change, not when the request goes: every change overtakes a
            // preview already on its way, so a slow answer for the rules as they WERE — or for the
            // item whose schedule was open before this one — never lands over the current one.
            const token = ++this.previewToken;

            // "Working…" from the change itself, not from when the request goes: otherwise the wait before it
            // reads "Nothing in the next 7 days" for a moment, about rules nobody has been asked about yet.
            this.previewing = this.scheduleIndex !== null && this.scheduleRules.length > 0;

            this.previewTimer = setTimeout(async () => {
                // This callback is the newest (a later change would have cleared its timer), so it
                // also ends the "working…" of any request it overtook, which no longer can.
                if (this.scheduleIndex === null) {
                    this.previewing = false;
                    return;
                }

                if (this.scheduleRules.length === 0) {
                    this.preview = [];
                    this.previewing = false;
                    return;
                }

                // A rule still being filled in — "Between times" with a box empty, a weekly repeat with no day yet —
                // is not asked about: the server would only refuse it. Its reason stands where the preview would.
                const unfinished = this.scheduleRules.map((rule) => Object.values(this.ruleProblems(rule))[0]).find(Boolean);

                if (unfinished) {
                    this.preview = [];
                    this.previewError = unfinished;
                    this.previewing = false;
                    return;
                }

                this.previewing = true;
                try {
                    const { data } = await axios.post(`/screens/${this.screenId}/playlist/preview`, {
                        rules: this.scheduleRules.map(ruleToServer),
                        days: 7,
                    });
                    if (token !== this.previewToken) return;
                    this.preview = data.occurrences;
                    this.previewError = '';
                } catch (error) {
                    if (token !== this.previewToken) return;
                    // Said in place of the preview — never "nothing in the next 7 days" for rules that cannot be read.
                    this.preview = [];
                    const errors = error.response?.data?.errors;
                    this.previewError = errors ? Object.values(errors)[0][0] : '';
                } finally {
                    if (token === this.previewToken) this.previewing = false;
                }
            }, 350);
        },

        /* ── Copying onto other screens ─────────────────────────────────── */

        async openCopyModal() {
            // Copying sends the SAVED playlist (PlaylistController::copy reads the rows), so with
            // changes still unsaved it would copy something other than what is on the page. The
            // button says so and is disabled; this holds the line should it be reached anyway.
            if (this.dirty || this.saving) return;

            this.copySelected = [];
            this.copyTargets = [];
            this.copyLoading = true;
            this.$dispatch('open-modal', 'playlist-copy-modal');

            try {
                const { data } = await axios.get(`/screens/${this.screenId}/playlist/copy-targets`);
                this.copyTargets = data.screens;
            } catch {
                window.toast('Could not load the other screens.');
            } finally {
                this.copyLoading = false;
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

        /** One window of the preview: "Fri 20 Mar 11:00 AM–3:00 PM". */
        slotLabel(slot) {
            return slot.start ? ` ${toAmPm(slot.start)}–${toAmPm(slot.end)}` : '';
        },

        /** One rule in plain English, so nobody has to read four inputs to know what
         *  they just said. */
        ruleSummary(rule) {
            // "11:00 AM – 3:00 PM" — built here rather than on the server, so one place decides how a clock reads.
            const when = rule.time_mode !== 'times' ? 'All day'
                : (rule.start_time && rule.end_time
                    ? windowLabel(rule.start_time, rule.end_time) + (this.crossesMidnight(rule) ? ', past midnight' : '')
                    : 'Set both times');

            if (rule.day_mode === 'always') return `Every day · ${when}`;

            if (rule.day_mode === 'range') {
                const range = rule.starts_on && rule.ends_on ? `From ${dayLabel(rule.starts_on)} to ${dayLabel(rule.ends_on)}`
                    : rule.starts_on ? `From ${dayLabel(rule.starts_on)}`
                    : rule.ends_on ? `Until ${dayLabel(rule.ends_on)}`
                    : 'Any day';
                return `${range} · ${when}`;
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
                    days = `${every}year on ${rule.starts_on ? dayLabel(rule.starts_on) : 'the start date'}`;
                    break;
                default:
                    days = `${every}day`;
            }

            const until = rule.recurrence_until ? `, until ${dayLabel(rule.recurrence_until)}` : '';

            return `${days}${until} · ${when}`;
        },

        /** The badge on a playlist row: nothing at all when the item is unscheduled. */
        scheduleBadge(item) {
            const count = (item.rules ?? []).length;
            if (count === 0) return '';
            return count === 1 ? this.ruleSummary(item.rules[0]) : `${count} schedules`;
        },

        /**
         * A line whose every schedule is over — its last day has passed on the screen's own calendar — so it no
         * longer plays at all: said on the line. Hours that run past midnight still play into the morning after
         * their last day, so such a schedule is over a day later.
         */
        scheduleEnded(item) {
            const rules = item.rules ?? [];

            if (!this.localDate || rules.length === 0) return false;

            return rules.every((rule) => {
                const last = rule.day_mode === 'range' ? rule.ends_on : (rule.day_mode === 'repeat' ? rule.recurrence_until : '');

                return !!last && last < (this.crossesMidnight(rule) ? dayBefore(this.localDate) : this.localDate);
            });
        },

        /** What a person calls a line or a file. A page published from the Ad Builder is stored as
         *  type "html", which is nobody's word for it. */
        typeLabel(item) {
            return { image: 'Image', video: 'Video', html: 'Ad page', channel: 'Channel' }[item.type] ?? item.type;
        },

        /**
         * A file the other way round from the screen plays with bars — a portrait menu board on a landscape
         * television at the sides, a landscape poster on a portrait one above and below (§12). Said on the
         * line and in the picker, never refused: it is the organization's to notice. Nothing for a file whose way is
         * unknown, one that matches, or a channel (its ads are many, each its own way).
         */
        orientationNote(item) {
            if (! item.orientation || item.orientation === this.screenOrientation) return '';

            return item.orientation === 'portrait'
                ? 'Portrait — plays with bars at the sides on this screen'
                : 'Landscape — plays with bars above and below on this screen';
        },

        /** Whether the line's seconds are set here: a picture stays up for as long as the line
         *  says; a video runs to its own end, an Ad Builder page for its design's length (one
         *  published before designs had a length is timed like a picture), a channel to its ads'. */
        isTimed(item) {
            return item.type === 'image' || (item.type === 'html' && ! item.runs_own_length);
        },

        /** How long one line holds the screen: a file its seconds, a channel about one
         *  pass of its ads (they rotate when a pass plays only some of them). */
        lineSeconds(item) {
            // A platform channel while Platform Channels are locked plays nothing (docs/BILLING-SPEC.md §6).
            if (item.locked) return 0;

            return item.type === 'channel'
                ? (Number(item.pass_seconds) || 0)
                : (Number(item.duration_seconds) || 0);
        },

        /** The lines kept on the playlist that play nothing while Platform Channels are locked. */
        lockedLines() {
            return this.items.filter((item) => item.locked);
        },

        /** "GAMA is" / "GAMA and Deals are" — the note above the lines. */
        lockedNames() {
            const names = this.lockedLines().map((item) => item.title);
            const listed = names.length <= 2 ? names.join(' and ') : `${names.slice(0, -1).join(', ')} and ${names[names.length - 1]}`;

            return `${listed} ${names.length === 1 ? 'is' : 'are'}`;
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

        /* The tab drawn open: the library whenever the screen has no channel to carry, since then there is no
         * Channels tab to be on. */
        openPickerTab() {
            return this.pickerTab === 'channels' && this.channels.length > 0 ? 'channels' : 'library';
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
            const resting = this.items.filter((item) => item.locked || item.is_draft).length;
            const playing = resting > 0 ? ` · ${this.items.length - resting} playing` : '';

            return `${count}${playing} · ${this.formatDuration(seconds)}`;
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
