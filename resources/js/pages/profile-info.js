import { runClientValidation } from '../core/plain-form.js';
import { required, maxLen, emailFormat, digitsExactly } from '../core/validate.js';

export function registerProfileInfo(Alpine) {
    Alpine.data('profileInfo', () => ({
        show: true,

        init() {
            setTimeout(() => this.show = false, 2000);
        },

        // Mirrors ProfileUpdateRequest — required/max/phone-digits/email — so obvious
        // mistakes never reload the page. The backend re-validates (incl. lowercase
        // and unique email) regardless.
        validateBeforeSubmit(event) {
            runClientValidation(event,
                ['first_name', 'last_name', 'phone', 'email'],
                {
                    first_name: [required('First name'), maxLen('First name', 255)],
                    last_name: [required('Last name'), maxLen('Last name', 255)],
                    phone: [required('Phone'), digitsExactly('Phone', 10)],
                    email: [required('Email'), emailFormat('Email'), maxLen('Email', 255)],
                }
            );
        },
    }));
}
