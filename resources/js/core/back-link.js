/**
 * A way back to a list (`<a data-back href="/screens">`) that returns to the list as it was left — its filter, search,
 * page and sort, which the list keeps in its address (crud-table-base's writeAddress) — when the person came from that
 * list: the page they left, opened afresh, so a row made or changed since is there and no dialog left open comes back
 * with it (the browser's own Back would bring the old page out of its cache). The link's own words in its address (the
 * Ads page's `owner`) must agree with where the person came from; anything else, or a person who came from elsewhere,
 * follows the link as it is.
 */
export function registerBackLinks() {
    document.addEventListener('click', (event) => {
        const link = event.target.closest?.('a[data-back]');

        if (!link || event.defaultPrevented || event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return;

        let from = null;
        try {
            from = document.referrer ? new URL(document.referrer) : null;
        } catch {
            from = null;
        }

        const to = new URL(link.href);

        if (!from || from.origin !== window.location.origin || from.pathname !== to.pathname) return;
        if ([...to.searchParams].some(([key, value]) => from.searchParams.get(key) !== value)) return;

        event.preventDefault();
        window.location.assign(from.href);
    });
}
