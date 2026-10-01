import axios from 'axios';
window.axios = axios;

window.axios.defaults.headers.common['X-Requested-With'] = 'XMLHttpRequest';

/* A session that ended while the page stayed open (signed out elsewhere, a long break, a password reset): every
   request then answers 401 or 419, and a page would toast "Unauthenticated." or "CSRF token mismatch." and show
   empty lists. Said once, in words, and the page is loaded again — which signs the person in or takes them to
   sign in. */
let sessionEnded = false;

/* A store paused while one of its pages was open (EnsureStoreIsActive): every request answers 403 with `paused`. Said
   once, in the server's words, and the dashboard — which says why and what still works — is opened. */
let storePaused = false;

window.axios.interceptors.response.use((response) => response, (error) => {
    if ([401, 419].includes(error.response?.status) && !sessionEnded) {
        sessionEnded = true;
        window.toast?.('Your session has ended. Sign in again to carry on.');
        setTimeout(() => window.location.reload(), 1500);
    }

    if (error.response?.status === 403 && error.response.data?.paused === true && !storePaused) {
        storePaused = true;
        window.toast?.(error.response.data.message);
        setTimeout(() => window.location.assign('/dashboard'), 1500);
    }

    return Promise.reject(error);
});
