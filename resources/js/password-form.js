import { runClientValidation } from './plain-form.js';
import { required, minLen } from './validate.js';

export function registerPasswordForm(Alpine) {
    Alpine.data('passwordForm', (status) => ({
        showSuccess: status === 'password-updated',

        init() {
            if (this.showSuccess) {
                setTimeout(() => this.showSuccess = false, 2000);
            }
        },

        // Mirrors PasswordController — current password required, new password min 8
        // and confirmed. The backend re-checks the current password and full rules.
        validateBeforeSubmit(event) {
            runClientValidation(event,
                ['current_password', 'password', 'password_confirmation'],
                {
                    current_password: [required('Current password')],
                    password: [
                        required('New password'),
                        minLen('New password', 8),
                        (v, d) => v && v !== d.password_confirmation ? 'Password confirmation does not match.' : null,
                    ],
                    password_confirmation: [required('Confirm password')],
                }
            );
        },
    }));
}
