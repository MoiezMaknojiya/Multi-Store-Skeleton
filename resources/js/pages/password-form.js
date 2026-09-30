import { runClientValidation } from '../core/plain-form.js';
import { required, minLen } from '../core/validate.js';

export function registerPasswordForm(Alpine) {
    /* "Your password is changed." is a toast (components/toasts.blade.php), not a word beside the button. */
    Alpine.data('passwordForm', () => ({
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
