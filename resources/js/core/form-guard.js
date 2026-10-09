/**
 * Global double-submit guard for plain (full-page) HTML forms.
 *
 * AJAX forms call preventDefault() in their own @submit handlers and manage their
 * own in-flight flags (saving/deleting/etc.), so anything already prevented is left
 * alone. For a normal form the first submit goes through and marks the form; any
 * further submit (double click, double Enter) is blocked while the page navigates.
 * A bfcache restore (back button) re-arms the form.
 */
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
        setTimeout(() => {
            form.querySelectorAll('button[type="submit"], button:not([type]), input[type="submit"]')
                .forEach((button) => {
                    button.dataset.guardDisabled = 'true';
                    button.disabled = true;
                });
        }, 0);
    });

    window.addEventListener('pageshow', () => {
        document.querySelectorAll('form[data-submitted]').forEach((form) => delete form.dataset.submitted);
        document.querySelectorAll('[data-guard-disabled]').forEach((button) => {
            button.disabled = false;
            delete button.dataset.guardDisabled;
        });
    });
}
