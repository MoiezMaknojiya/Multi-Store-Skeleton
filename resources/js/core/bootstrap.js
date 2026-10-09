import axios from 'axios';
window.axios = axios;

window.axios.defaults.headers.common['X-Requested-With'] = 'XMLHttpRequest';

/* Whose this page is (the layout's session-context): sent with every request, so a tab left open while the session changed in
   another — an organization switched, Log In As — is refused instead of acting as the new session (RefuseAStaleTab). */
const sessionContext = document.querySelector('meta[name="session-context"]')?.content ?? '';
if (sessionContext) window.axios.defaults.headers.common['X-Session-Context'] = sessionContext;

/* Refused as out of date: said once, in the server's words, and the page is loaded again as the session now is. */
let pageWasStale = false;

/* A session that ended while the page stayed open (signed out elsewhere, a long break, a password reset): every
   request then answers 401 or 419, and a page would toast "Unauthenticated." or "CSRF token mismatch." and show
   empty lists. Said once, in words, and the page is loaded again — which signs the person in or takes them to
   sign in. */
let sessionEnded = false;

/* An organization paused while one of its pages was open (EnsureOrganizationIsActive): every request answers 403 with `paused`. Said
   once, in the server's words, and the dashboard — which says why and what still works — is opened. */
let organizationPaused = false;

window.axios.interceptors.response.use((response) => response, (error) => {
    if ([401, 419].includes(error.response?.status) && !sessionEnded) {
        sessionEnded = true;
        window.toast?.('Your session has ended. Sign in again to carry on.');
        setTimeout(() => window.location.reload(), 1500);
    }

    if (error.response?.status === 409 && error.response.data?.stale_tab === true && !pageWasStale) {
        pageWasStale = true;
        window.toast?.(error.response.data.message);
        setTimeout(() => window.location.reload(), 2500);
    }

    if (error.response?.status === 403 && error.response.data?.paused === true && !organizationPaused) {
        organizationPaused = true;
        window.toast?.(error.response.data.message);
        setTimeout(() => window.location.assign('/dashboard'), 1500);
    }

    return Promise.reject(error);
});
