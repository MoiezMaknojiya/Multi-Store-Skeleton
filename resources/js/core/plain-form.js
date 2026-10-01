/**
 * Client-side validation for plain-POST forms (the auth pages, the invitation page, Profile and
 * Settings → Organizations).
 * Reads the named fields off the submitted form, runs the given rules (validate.js),
 * and on failure blocks the submit and paints the same red-border + message UX the
 * server-side errors use — no page reload for obvious mistakes. Returns true when
 * clean (let it submit). The backend re-validates everything and stays the source
 * of truth.
 *
 * What the eye gets, a screen reader gets too: the field is aria-invalid and aria-describedby its message
 * (keeping its hint), and the keyboard is put on the first field to fix. A message the server gave on the last
 * submit (data-error-for) is taken away for each field checked again, so a field never says two things at once.
 */
import { validate } from './validate.js';

/* The message ids a field had before this check touched it — its hint's — kept on the field itself. */
function describe(input, messageId) {
    if (!input.hasAttribute('data-described-before')) {
        input.setAttribute('data-described-before', input.getAttribute('aria-describedby') ?? '');
    }
    const kept = input.getAttribute('data-described-before').split(/\s+/).filter((id) => id && !id.endsWith('-error'));
    input.setAttribute('aria-describedby', [...kept, messageId].join(' '));
}

function undescribe(input) {
    if (!input.hasAttribute('data-described-before')) return;
    const kept = input.getAttribute('data-described-before').split(/\s+/).filter((id) => id && !id.endsWith('-error'));
    if (kept.length) input.setAttribute('aria-describedby', kept.join(' '));
    else input.removeAttribute('aria-describedby');
    input.removeAttribute('data-described-before');
}

export function runClientValidation(event, fieldNames, rules) {
    const form = event.target;

    const data = {};
    for (const name of fieldNames) {
        data[name] = form.querySelector(`[name="${name}"]`)?.value ?? '';
    }

    const errors = validate(data, rules);

    // Clear previous client-side decorations before re-evaluating.
    form.querySelectorAll('.client-error-msg').forEach((el) => el.remove());
    form.querySelectorAll('[data-client-invalid]').forEach((el) => {
        el.classList.remove('!border-red-500');
        el.removeAttribute('data-client-invalid');
        el.removeAttribute('aria-invalid');
        undescribe(el);
    });

    if (Object.keys(errors).length === 0) return true; // clean — let it submit

    event.preventDefault();

    for (const [field, messages] of Object.entries(errors)) {
        const input = form.querySelector(`[name="${field}"]`);
        if (!input) continue;

        // The server's message from the last submit said something older: this one takes its place.
        form.querySelectorAll(`[data-error-for="${CSS.escape(field)}"]`).forEach((el) => el.remove());

        // The important form: .form-select sits in a CSS layer below the utilities, and this has to win there too.
        input.classList.add('!border-red-500');
        input.setAttribute('data-client-invalid', '1');
        input.setAttribute('aria-invalid', 'true');

        const message = document.createElement('p');
        message.id = `${input.id || field}-client-error`;
        message.className = 'mt-1.5 text-xs text-red-600 dark:text-red-400 client-error-msg';
        message.textContent = messages[0];
        describe(input, message.id);

        // Password inputs sit inside a relative wrapper (the eye toggle) — the
        // message goes after the wrapper, not inside it.
        const anchor = input.parentElement?.classList.contains('relative') ? input.parentElement : input;
        anchor.insertAdjacentElement('afterend', message);
    }

    // The keyboard goes to the first field to fix, and the page brings it into view.
    const first = form.querySelector('[data-client-invalid]');
    first?.focus({ preventScroll: true });
    first?.scrollIntoView({ behavior: 'smooth', block: 'center' });

    return false;
}
