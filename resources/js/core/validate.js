/**
 * Client-side form validation helpers, mirroring the backend rules so
 * obviously-invalid input never leaves the browser. The server-side
 * validation always runs too and stays the source of truth — this layer
 * only saves the round trip and gives instant feedback.
 *
 * validate() returns errors in Laravel's shape ({ field: ['message'] })
 * so the existing formErrors rendering works unchanged.
 */

const empty = (value) => value === null || value === undefined || String(value).trim() === '';

export function validate(form, rules) {
    const errors = {};
    for (const [field, checks] of Object.entries(rules)) {
        for (const check of checks) {
            const message = check(form[field], form);
            if (message) {
                errors[field] = [message];
                break;
            }
        }
    }
    return errors;
}

export const required = (label) => (value) =>
    empty(value) ? `${label} is required.` : null;

export const maxLen = (label, max) => (value) =>
    !empty(value) && String(value).length > max ? `${label} may not be longer than ${max} characters.` : null;

export const minLen = (label, min) => (value) =>
    !empty(value) && String(value).length < min ? `${label} must be at least ${min} characters.` : null;

export const emailFormat = (label) => (value) =>
    !empty(value) && !/^\S+@\S+\.\S+$/.test(String(value)) ? `${label} must be a valid email address.` : null;

export const digitsExactly = (label, count) => (value) =>
    !empty(value) && !new RegExp(`^\\d{${count}}$`).test(String(value)) ? `${label} must be exactly ${count} digits.` : null;

export const digitsOnly = (label) => (value) =>
    !empty(value) && !/^\d+$/.test(String(value)) ? `${label} can only contain numbers.` : null;

export const lettersNumbersSpaces = (label) => (value) =>
    !empty(value) && !/^[a-zA-Z0-9 ]*$/.test(String(value)) ? `${label} can only contain letters, numbers, and spaces.` : null;

export const minCount = (message, min = 1) => (value) =>
    !Array.isArray(value) || value.length < min ? message : null;

export const maxNumber = (message, max) => (value) =>
    !empty(value) && Number(value) > max ? message : null;

export const minNumber = (message, min) => (value) =>
    !empty(value) && Number(value) < min ? message : null;

/** What the browser hands over as '' for a field it could not read, by the field's kind. */
const UNREADABLE = {
    date: 'Enter the whole date, or leave it blank.',
    time: 'Enter the whole time, or leave it blank.',
    'datetime-local': 'Enter the whole date and time, or leave it blank.',
    number: 'Enter a number.',
};

/**
 * The fields of a form the browser could not read — a date or a time typed only in part, letters in a number box —
 * which it hands over as '' (validity.badInput). A form with `novalidate` (so that its own messages, not the
 * browser's bubble, say what is wrong) would otherwise save such a field as left blank: a campaign's end date lost
 * because one part of it was cleared. Keyed by the name in the input's x-model ("form.ends_on" says ends_on).
 */
export function unreadableFields(form) {
    const errors = {};

    form?.querySelectorAll?.('input').forEach((input) => {
        if (!input.validity?.badInput) return;

        const model = [...input.attributes].find((attribute) => attribute.name.startsWith('x-model'));
        const field = model?.value.split('.').pop();

        if (field) errors[field] = [UNREADABLE[input.type] ?? 'Enter a value that can be read, or leave it blank.'];
    });

    return errors;
}

/** A field that must be filled, refused in its own words (required() says "{label} is required."). */
export const requiredMessage = (message) => (value) =>
    empty(value) ? message : null;

export const wholeNumber = (message) => (value) =>
    !empty(value) && !Number.isInteger(Number(value)) ? message : null;
