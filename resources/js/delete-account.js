import { runClientValidation } from './plain-form.js';
import { required } from './validate.js';

export function registerDeleteAccountForm(Alpine) {
    Alpine.data('deleteAccountForm', () => ({
        open() {
            this.$dispatch('open-modal', 'confirm-user-deletion');
        },
        close() {
            this.$dispatch('close');
        },

        // The password is required to confirm deletion; the backend re-checks it is
        // the user's current password.
        validateBeforeSubmit(event) {
            runClientValidation(event, ['password'], { password: [required('Password')] });
        },
    }));
}
