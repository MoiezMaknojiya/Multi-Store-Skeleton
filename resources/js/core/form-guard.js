/**
 * Global double-submit guard for plain (full-page) HTML forms.
 *
 * AJAX forms call preventDefault() in their own @submit handlers and manage their
 * own in-flight flags (saving/deleting/etc.), so anything already prevented is left
 * alone. For a normal form the first submit goes through and marks the form; any
 * further submit (double click, double Enter) is blocked while the page navigates.
 * A bfcache restore (back button) re-arms the form.
 */
/* The panel's "working on it" circle (components/spinner.blade.php), drawn into the button that sent a plain form: on a
 * slow line the page says it is on its way rather than only greying the button. */
const SPINNER = '<svg data-guard-spinner class="mr-2 inline-block h-4 w-4 shrink-0 animate-spin align-[-2px] motion-reduce:animate-none" fill="none" viewBox="0 0 24 24" aria-hidden="true">'
    + '<circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>'
    + '<path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z"></path></svg>';

export function registerFormGuard() {
    document.addEventListener('submit', (event) => {
        if (event.defaultPrevented) return;

        const form = event.target;
        if (form.dataset.submitted === 'true') {
            event.preventDefault();
            return;
        }
        form.dataset.submitted = 'true';

        /* Whose page sent it (RefuseAStaleTab): a form left open after the session changed in another tab is sent back, not done. */
        const context = document.querySelector('meta[name="session-context"]')?.content;
        if (context && (form.method || '').toLowerCase() === 'post' && !form.querySelector('input[name="_context"]')) {
            const field = document.createElement('input');
            field.type = 'hidden';
            field.name = '_context';
            field.value = context;
            form.appendChild(field);
        }

        /* Disabled a tick later: the browser serializes the form's data right after
         * this event finishes, and a submit button disabled before that would drop
         * its name/value from the request. */
        const sender = event.submitter ?? form.querySelector('button[type="submit"], button:not([type])');
        setTimeout(() => {
            form.querySelectorAll('button[type="submit"], button:not([type]), input[type="submit"]')
                .forEach((button) => {
                    button.dataset.guardDisabled = 'true';
                    button.disabled = true;
                });
            if (sender?.tagName === 'BUTTON' && !sender.querySelector('svg')) {
                sender.insertAdjacentHTML('afterbegin', SPINNER);
                sender.setAttribute('aria-busy', 'true');
            }
        }, 0);
    });

    window.addEventListener('pageshow', () => {
        document.querySelectorAll('form[data-submitted]').forEach((form) => delete form.dataset.submitted);
        document.querySelectorAll('[data-guard-disabled]').forEach((button) => {
            button.disabled = false;
            button.removeAttribute('aria-busy');
            delete button.dataset.guardDisabled;
        });
        document.querySelectorAll('[data-guard-spinner]').forEach((spinner) => spinner.remove());
    });
}
