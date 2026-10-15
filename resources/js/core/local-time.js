/**
 * The exact moment beside a relative one (owner, 2026-10-15, the dashboard's Recent activity): a
 * `<time datetime="2026-10-13T20:42:00+00:00" data-local-time>1 hour ago</time>` reads "1 hour ago (Oct 13, 8:42 PM)", in the
 * reader's own timezone and language. The server cannot know the reader's clock, so the browser writes it, as the Activity Log
 * does; the year is said only when it is not this one. Written once: a page the browser brings back from its cache keeps it.
 */
export function registerLocalTimes() {
    const write = () => document.querySelectorAll('time[data-local-time]:not([data-local-written])').forEach((time) => {
        const at = new Date(time.getAttribute('datetime'));
        if (Number.isNaN(at.getTime())) return;

        const exact = at.toLocaleString(undefined, {
            month: 'short', day: 'numeric', hour: 'numeric', minute: '2-digit',
            ...(at.getFullYear() === new Date().getFullYear() ? {} : { year: 'numeric' }),
        });

        time.textContent = `${time.textContent.trim()} (${exact})`;
        time.dataset.localWritten = 'true';
    });

    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', write);
    else write();
}
