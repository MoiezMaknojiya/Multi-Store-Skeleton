/**
 * A page opened to do one thing at once — `/screens?pair=1` (the dashboard's first step), `/builder?new=1` (Create Ad
 * sent back to choose a shape) — reads its flag here, and only once: the flag leaves the address at the same moment,
 * so a refresh or the back button does not open the dialog again. The page still decides whether it may open it (its
 * own button must be on the page, which is how the permission shows).
 */
export function takeAddressFlag(name) {
    const params = new URLSearchParams(window.location.search);

    if (params.get(name) !== '1') return false;

    params.delete(name);
    const query = params.toString();
    window.history.replaceState(window.history.state, '', window.location.pathname + (query ? `?${query}` : '') + window.location.hash);

    return true;
}
