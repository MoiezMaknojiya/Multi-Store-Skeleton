/**
 * Client-side checks for Settings → Stores (resources/views/store-settings/edit.blade.php).
 * They mirror StoreSettingsController so obvious mistakes never reload the page; the backend
 * validates everything again and stays the source of truth.
 */
import { runClientValidation } from '../core/plain-form.js';
import { required, maxLen, digitsOnly } from '../core/validate.js';

export function registerStoreSettings(Alpine) {
    Alpine.data('storeDetailsForm', () => ({
        validateBeforeSubmit(event) {
            runClientValidation(event,
                ['name', 'street', 'suite', 'city', 'state', 'zip_code', 'country'],
                {
                    name: [required('Store name'), maxLen('Store name', 255)],
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

    /* Create store: the same details, under the store_ names that keep the form apart from the details form. */
    Alpine.data('openStoreForm', () => ({
        validateBeforeSubmit(event) {
            runClientValidation(event,
                ['store_name', 'store_street', 'store_suite', 'store_city', 'store_state', 'store_zip_code', 'store_country'],
                {
                    store_name: [required('Store name'), maxLen('Store name', 255)],
                    store_street: [required('Street'), maxLen('Street', 255)],
                    store_suite: [maxLen('Suite / Unit', 100)],
                    store_city: [required('City'), maxLen('City', 100)],
                    store_state: [required('State')],
                    store_zip_code: [required('Zip code'), digitsOnly('Zip code'), maxLen('Zip code', 10)],
                    store_country: [required('Country'), maxLen('Country', 100)],
                }
            );
        },
    }));

    /* The typed name must match exactly — the same comparison the controller makes. */
    Alpine.data('deleteStoreForm', (storeName) => ({
        validateBeforeSubmit(event) {
            runClientValidation(event, ['confirm_name', 'password'], {
                confirm_name: [
                    required('Store name'),
                    (value) => (value === storeName ? null : 'Type the store name exactly as it is shown.'),
                ],
                password: [required('Password')],
            });
        },
    }));
}
