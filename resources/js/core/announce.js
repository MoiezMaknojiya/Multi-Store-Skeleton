/**
 * Tells a screen reader what just changed where the keyboard is — "Lunch Specials moved to position 2 of 5." — without
 * drawing anything: the page's one polite live region (#announcer, components/toasts.blade.php). Emptied first, so the
 * same words twice (a line moved down twice) are said twice.
 */
export function announce(words) {
    const region = document.getElementById('announcer');

    if (!region) return;

    region.textContent = '';
    setTimeout(() => { region.textContent = words; }, 60);
}
