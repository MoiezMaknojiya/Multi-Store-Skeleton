/**
 * How a time is READ, as opposed to how it is stored.
 *
 * Every clock time in this app is kept as 24-hour "HH:MM" — in the database, in the
 * API, and inside the `<input type="time">` elements, which accept nothing else. That
 * never changes. This is only the last step before a person sees it.
 *
 * Formatted explicitly rather than through Intl, because the shop asked for AM/PM and
 * a locale-driven format would quietly show 24-hour time to somebody in another
 * country — a reading, not a preference to be guessed at.
 */

/** "07:00" → "7:00 AM"  ·  "20:00" → "8:00 PM"  ·  "00:30" → "12:30 AM" */
export function toAmPm(value) {
    const raw = String(value ?? '').slice(0, 5);
    const [hours, minutes] = raw.split(':');

    if (minutes === undefined || minutes.length !== 2) return raw;

    const hour = Number(hours);

    if (!Number.isInteger(hour) || hour < 0 || hour > 23) return raw;

    // Midnight and noon are both "12" — 0 o'clock is written 12 AM, not 0 AM.
    return `${hour % 12 === 0 ? 12 : hour % 12}:${minutes} ${hour < 12 ? 'AM' : 'PM'}`;
}

/** "7:00 AM – 8:00 PM" from a pair of stored times. */
export function windowLabel(start, end) {
    return `${toAmPm(start)} – ${toAmPm(end)}`;
}
