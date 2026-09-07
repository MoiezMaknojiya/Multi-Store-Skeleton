/**
 * Stores table Alpine component.
 * Extends the shared CRUD table with store-specific address form fields.
 */
import { createCrudTable } from './crud-table-base.js';
import { validate, required, maxLen, digitsOnly } from './validate.js';

export function registerStoresTable(Alpine) {
    Alpine.data('storesTable', (config = {}) => createCrudTable({
        fetchUrl: '/stores/data',
        dataKey: 'stores',
        entityLabel: 'store',
        formModalName: 'store-form-modal',
        deleteModalName: 'confirm-store-deletion',
        extraState: {
            // Drive the per-row Edit/Delete visibility (see stores/index.blade.php).
            isGlobalUser: config.isGlobalUser ?? false,
            currentStoreId: config.currentStoreId ?? null,
        },
        defaultForm: {
            name: '', street: '', suite: '', city: '',
            state: '', zip_code: '', country: 'USA', is_active: true,
        },

        /* Mirrors StoreController's rules (the 50-state whitelist stays backend-only —
         * the state field is a fixed dropdown anyway). */
        validateForm: (form) => validate(form, {
            name: [required('Name'), maxLen('Name', 255)],
            street: [required('Street'), maxLen('Street', 255)],
            suite: [maxLen('Suite', 100)],
            city: [required('City'), maxLen('City', 100)],
            state: [required('State')],
            zip_code: [required('Zip code'), digitsOnly('Zip code'), maxLen('Zip code', 10)],
            country: [required('Country'), maxLen('Country', 100)],
        }),
        mapItemToForm: (store) => ({
            name: store.name,
            street: store.street ?? '',
            suite: store.suite ?? '',
            city: store.city ?? '',
            state: store.state ?? '',
            zip_code: store.zip_code ?? '',
            country: store.country ?? '',
            is_active: store.is_active,
        }),

        extraMethods: {
            /** Restricts the zip code input to digits only, max 10 characters */
            restrictZipInput(event) {
                if (event.type === 'keydown') {
                    const key = event.key;
                    if (event.ctrlKey || event.metaKey) return; // allow Ctrl/Cmd shortcuts (paste, etc.)
                    const allowed = ['Backspace', 'Delete', 'Tab', 'Escape', 'Enter',
                        'ArrowLeft', 'ArrowRight', 'ArrowUp', 'ArrowDown', 'Home', 'End'];
                    if (allowed.includes(key) || /^\d$/.test(key)) return;
                    event.preventDefault();
                    return;
                }
                const digits = event.target.value.replace(/\D/g, '').slice(0, 10);
                if (this.form.zip_code !== digits) this.form.zip_code = digits;
            },
        },
    })());
}
