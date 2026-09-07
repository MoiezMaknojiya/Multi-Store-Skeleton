/**
 * Client-side validation for plain-POST forms (registration + profile pages).
 * Reads the named fields off the submitted form, runs the given rules (validate.js),
 * and on failure blocks the submit and paints the same red-border + message UX the
 * server-side errors use — no page reload for obvious mistakes. Returns true when
 * clean (let it submit). The backend re-validates everything and stays the source
 * of truth.
 */
import { validate } from './validate.js';

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
        el.classList.remove('border-red-500');
        el.removeAttribute('data-client-invalid');
    });

    if (Object.keys(errors).length === 0) return true; // clean — let it submit

    event.preventDefault();

    for (const [field, messages] of Object.entries(errors)) {
        const input = form.querySelector(`[name="${field}"]`);
        if (!input) continue;

        input.classList.add('border-red-500');
        input.setAttribute('data-client-invalid', '1');

        const message = document.createElement('p');
        message.className = 'mt-1.5 text-xs text-red-500 client-error-msg';
        message.textContent = messages[0];

        // Password inputs may sit inside a relative wrapper (the eye toggle) — the
        // message goes after the wrapper, not inside it.
        const anchor = input.parentElement?.classList.contains('relative') ? input.parentElement : input;
        anchor.insertAdjacentElement('afterend', message);
    }

    form.querySelector('[data-client-invalid]')?.scrollIntoView({ behavior: 'smooth', block: 'center' });

    return false;
}
