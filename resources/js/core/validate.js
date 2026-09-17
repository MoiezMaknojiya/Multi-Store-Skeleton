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
