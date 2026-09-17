/**
 * Dayparts table Alpine component.
 *
 * A daypart is a named window of time the shop reuses — "Deli hours 07:00–20:00".
 * Beyond the shared CRUD behaviour this adds two things: the repeating rows of
 * per-weekday exceptions, and a plain-English summary that updates as you type, so
 * nobody has to work out from four inputs what they have just told the screens to do.
 */
import { createCrudTable } from '../core/crud-table-base.js';
import { toAmPm, windowLabel as clockRange } from '../core/clock.js';
import { validate, required, maxLen } from '../core/validate.js';

/** "07:00:00" and "07:00" both arrive; the inputs want "07:00". Stored and edited in
 *  24-hour time throughout — only what is READ is turned into AM/PM. */
const clock = (value) => String(value ?? '').slice(0, 5);

/** An end at or before the start means the window runs past midnight. */
const wraps = (start, end) => !!start && !!end && clock(end) <= clock(start);

export function registerDaypartsTable(Alpine) {
    Alpine.data('daypartsTable', (config = {}) => createCrudTable({
        fetchUrl: '/dayparts/data',
        dataKey: 'dayparts',
        entityLabel: 'daypart',
        formModalName: 'daypart-form-modal',
        deleteModalName: 'confirm-daypart-deletion',

        extraState: {
            /* Creating one needs a store context — the backend refuses without it, so
             * the modal says why instead of letting the save fail. */
            hasStore: config.hasStore ?? false,
            /* { "1": "Monday", … } straight from Daypart::WEEKDAYS. */
            weekdays: config.weekdays ?? {},
        },

        defaultForm: {
            name: '',
            start_time: '09:00',
            end_time: '17:00',
            is_retired: false,
            exceptions: [],
        },

        /* Mirrors DaypartRequest. The backend stays the source of truth. */
        validateForm: (form) => validate(form, {
            name: [required('Name'), maxLen('Name', 100)],
            start_time: [required('Start time')],
            end_time: [
                required('End time'),
                (value, f) => clock(value) && clock(value) === clock(f.start_time)
                    ? 'The start and end time cannot be the same. To run past midnight, set an end time EARLIER than the start.'
                    : null,
            ],
        }),

        mapItemToForm: (daypart) => ({
            name: daypart.name,
            start_time: clock(daypart.start_time),
            end_time: clock(daypart.end_time),
            is_retired: !! daypart.is_retired,
            exceptions: (daypart.exceptions ?? []).map((row) => ({
                /* String, because the <select> option values are strings. */
                weekday: String(row.weekday),
                /* A UI idea only — the server stores two times, and no times means
                 * closed. Saying that out loud in a dropdown beats leaving somebody
                 * to work out that two empty boxes mean "shut". */
                mode: (row.start_time && row.end_time) ? 'hours' : 'closed',
                start_time: clock(row.start_time),
                end_time: clock(row.end_time),
            })),
        }),

        extraMethods: {
            /* ── Exception rows ────────────────────────────────────────── */

            /** Add a row, pre-picking the first weekday not already spoken for.
             *  It starts as CLOSED, which is both the commoner case and the one that
             *  needs nothing else typed — the row is valid the moment it appears. */
            addException() {
                const taken = this.form.exceptions.map((row) => String(row.weekday));
                const free = Object.keys(this.weekdays).find((day) => ! taken.includes(day));

                if (! free) return;   // all seven already have a row

                this.form.exceptions.push({ weekday: free, mode: 'closed', start_time: '', end_time: '' });
            },

            /**
             * Closed or different hours.
             *
             * Closed clears the times, because that is exactly what "closed" is in the
             * database. Switching the other way starts from the daypart's OWN window
             * rather than two empty boxes, so the person only changes what differs.
             */
            setExceptionMode(row) {
                if (row.mode === 'closed') {
                    row.start_time = '';
                    row.end_time = '';

                    return;
                }

                if (! row.start_time) row.start_time = this.form.start_time;
                if (! row.end_time) row.end_time = this.form.end_time;
            },

            removeException(index) {
                this.form.exceptions.splice(index, 1);
                /* Indexed error keys point at rows that have just shifted, so they
                 * would be shown against the wrong day. */
                this.clearExceptionErrors();
            },

            clearExceptionErrors() {
                for (const key of Object.keys(this.formErrors)) {
                    if (key.startsWith('exceptions.')) delete this.formErrors[key];
                }
            },

            /** Laravel reports these as "exceptions.0.start_time", which the
             *  form-field component's dot notation cannot reach. */
            exceptionError(index, field) {
                return this.formErrors[`exceptions.${index}.${field}`]?.[0] ?? '';
            },

            /** A weekday already used by another row — the select greys it out so two
             *  rows can never claim the same day. */
            weekdayTaken(day, index) {
                return this.form.exceptions.some(
                    (row, i) => i !== index && String(row.weekday) === String(day)
                );
            },

            canAddException() {
                return this.form.exceptions.length < Object.keys(this.weekdays).length;
            },

            /* ── Labels ────────────────────────────────────────────────── */

            crossesMidnight(start, end) {
                return wraps(start, end);
            },

            /** "9:00 AM – 5:00 PM" for a listing row. */
            windowLabel(item) {
                return clockRange(item.start_time, item.end_time);
            },

            /** What the modal shows underneath the inputs, live as they are typed. */
            summary() {
                const { start_time: start, end_time: end, exceptions } = this.form;

                if (! start || ! end) return '';

                const parts = [`Every day ${clockRange(start, end)}`];

                if (wraps(start, end)) parts[0] += ' (past midnight)';

                for (const row of exceptions) {
                    const day = this.weekdays[String(row.weekday)];
                    if (! day) continue;

                    parts.push(
                        row.start_time && row.end_time
                            ? `${day} ${clockRange(row.start_time, row.end_time)}`
                            : `${day} closed`
                    );
                }

                return parts.join(' · ');
            },

            /** The same sentence for a listing row, built from the saved record. */
            rowSummary(item) {
                const rows = item.exceptions ?? [];

                if (rows.length === 0) return 'Every day';

                return rows
                    .map((row) => {
                        const day = this.weekdays[String(row.weekday)] ?? '';
                        return row.start_time && row.end_time
                            ? `${day} ${toAmPm(row.start_time)}–${toAmPm(row.end_time)}`
                            : `${day} closed`;
                    })
                    .join(' · ');
            },
        },
    })());
}
