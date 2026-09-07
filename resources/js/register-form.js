/**
 * Client-side validation for the public registration form. Mirrors the backend
 * rules; on failure it blocks the submit and paints the same red-border + message
 * UX the server-side errors use — no round trip for obvious mistakes. The backend
 * validates everything again regardless.
 */
import { runClientValidation } from './plain-form.js';
import { required, maxLen, minLen, emailFormat, digitsExactly, digitsOnly } from './validate.js';

export function registerRegisterForm(Alpine) {
    Alpine.data('registerForm', () => ({
        handleSubmit(event) {
            runClientValidation(event,
                ['first_name', 'last_name', 'phone', 'email', 'password', 'password_confirmation', 'store_name', 'street', 'city', 'state', 'zip_code'],
                {
                    first_name: [required('First name'), maxLen('First name', 255)],
                    last_name: [required('Last name'), maxLen('Last name', 255)],
                    phone: [required('Phone'), digitsExactly('Phone', 10)],
                    email: [required('Email'), emailFormat('Email')],
                    password: [
                        required('Password'),
                        minLen('Password', 8),
                        (v, d) => v && v !== d.password_confirmation ? 'Password confirmation does not match.' : null,
                    ],
                    store_name: [required('Store name'), maxLen('Store name', 255)],
                    street: [required('Street'), maxLen('Street', 255)],
                    city: [required('City'), maxLen('City', 100)],
                    state: [required('State')],
                    zip_code: [required('Zip code'), digitsOnly('Zip code'), maxLen('Zip code', 10)],
                }
            );
        },
    }));
}
