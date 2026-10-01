/**
 * Client-side checks for Settings → Organizations (resources/views/organization-settings/edit.blade.php).
 * They mirror OrganizationSettingsController so obvious mistakes never reload the page; the backend
 * validates everything again and stays the source of truth.
 */
import { runClientValidation } from '../core/plain-form.js';
import { required, maxLen, digitsOnly } from '../core/validate.js';

export function registerOrganizationSettings(Alpine) {
    Alpine.data('organizationDetailsForm', () => ({
        validateBeforeSubmit(event) {
            runClientValidation(event,
                ['name', 'street', 'suite', 'city', 'state', 'zip_code', 'country'],
                {
                    name: [required('Organization name'), maxLen('Organization name', 255)],
                    street: [required('Street'), maxLen('Street', 255)],
                    suite: [maxLen('Suite / Unit', 100)],
                    city: [required('City'), maxLen('City', 100)],
                    state: [required('State')],
                    zip_code: [required('Zip code'), digitsOnly('Zip code'), maxLen('Zip code', 10)],
                    country: [required('Country'), maxLen('Country', 100)],
                }
            );
        },
    }));

    /* Create organization: the same details, under the organization_ names that keep the form apart from the details form. */
    Alpine.data('openOrganizationForm', () => ({
        validateBeforeSubmit(event) {
            runClientValidation(event,
                ['organization_name', 'organization_street', 'organization_suite', 'organization_city', 'organization_state', 'organization_zip_code', 'organization_country'],
                {
                    organization_name: [required('Organization name'), maxLen('Organization name', 255)],
                    organization_street: [required('Street'), maxLen('Street', 255)],
                    organization_suite: [maxLen('Suite / Unit', 100)],
                    organization_city: [required('City'), maxLen('City', 100)],
                    organization_state: [required('State')],
                    organization_zip_code: [required('Zip code'), digitsOnly('Zip code'), maxLen('Zip code', 10)],
                    organization_country: [required('Country'), maxLen('Country', 100)],
                }
            );
        },
    }));

    /* The typed name must match exactly — the same comparison the controller makes, which reads it
     * trimmed (TrimStrings), so a stray space before or after is not refused here either. */
    Alpine.data('deleteOrganizationForm', (organizationName) => ({
        validateBeforeSubmit(event) {
            runClientValidation(event, ['confirm_name', 'password'], {
                confirm_name: [
                    required('Organization name'),
                    (value) => (String(value ?? '').trim() === organizationName ? null : 'Type the organization name exactly as it is shown.'),
                ],
                password: [required('Password')],
            });
        },
    }));
}
