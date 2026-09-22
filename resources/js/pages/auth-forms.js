/**
 * Client-side validation for the sign-in pages that are not the registration form: sign in, "forgot
 * password" and the new-password page the emailed link opens. Each mirrors its backend rules (LoginRequest,
 * PasswordResetLinkController::store, NewPasswordController::store) and, on failure, blocks the submit and
 * paints the same red-border + message UX the server's errors use. The backend validates everything again
 * regardless.
 */
import { runClientValidation } from '../core/plain-form.js';
import { required, minLen, emailFormat } from '../core/validate.js';

const email = [required('Email'), emailFormat('Email')];

export function registerAuthForms(Alpine) {
    Alpine.data('loginForm', () => ({
        handleSubmit(event) {
            runClientValidation(event, ['email', 'password'], {
                email,
                password: [required('Password')],
            });
        },
    }));

    Alpine.data('forgotPasswordForm', () => ({
        handleSubmit(event) {
            runClientValidation(event, ['email'], { email });
        },
    }));

    Alpine.data('resetPasswordForm', () => ({
        handleSubmit(event) {
            runClientValidation(event, ['email', 'password', 'password_confirmation'], {
                email,
                password: [
                    required('Password'),
                    minLen('Password', 8),
                    (v, d) => v && v !== d.password_confirmation ? 'Password confirmation does not match.' : null,
                ],
            });
        },
    }));
}
