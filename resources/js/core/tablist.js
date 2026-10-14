/**
 * The keys every tab list on the panel answers (the WAI-ARIA tabs pattern): ← and → to the tab beside, Home and End
 * to the first and the last, each opened as it is reached. Only the open tab is a stop for Tab, so Tab goes on to the
 * open panel instead of through every tab (roving tabindex, kept by the tabs' own aria-selected).
 */
const tabsOf = (list) => [...list.querySelectorAll('[role="tab"]')].filter((tab) => tab.getClientRects().length > 0 && !tab.disabled);

function rove() {
    document.querySelectorAll('[role="tablist"]').forEach((list) => {
        list.querySelectorAll('[role="tab"]').forEach((tab) => {
            tab.tabIndex = tab.getAttribute('aria-selected') === 'true' ? 0 : -1;
        });
    });
}

export function registerTabLists() {
    document.addEventListener('keydown', (event) => {
        const tab = event.target.closest?.('[role="tab"]');
        const list = tab?.closest('[role="tablist"]');

        // Alt+← is the browser's Back and Ctrl or ⌘ with an arrow is the system's own: never taken for the tabs.
        if (!list || event.altKey || event.ctrlKey || event.metaKey || !['ArrowLeft', 'ArrowRight', 'Home', 'End'].includes(event.key)) return;

        const tabs = tabsOf(list);
        const at = tabs.indexOf(tab);
        const next = {
            ArrowLeft: tabs[(at - 1 + tabs.length) % tabs.length],
            ArrowRight: tabs[(at + 1) % tabs.length],
            Home: tabs[0],
            End: tabs[tabs.length - 1],
        }[event.key];

        if (!next || next === tab) return;

        event.preventDefault();
        next.focus();
        next.click();
    });

    new MutationObserver(rove).observe(document.documentElement, { subtree: true, attributes: true, attributeFilter: ['aria-selected'] });
    document.addEventListener('alpine:initialized', rove);
}
