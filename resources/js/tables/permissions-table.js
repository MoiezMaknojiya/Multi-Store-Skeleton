/**
 * Permissions table Alpine component — the catalogue itself, the Super-Admin role's alone.
 * Built on the shared CRUD table factory: a name and a label, a typed-input filter, and a delete that
 * re-confirms the password.
 */
import { createCrudTable } from '../core/crud-table-base.js';
import { validate, required, maxLen, lettersNumbersSpaces } from '../core/validate.js';

export function registerPermissionsTable(Alpine) {
    Alpine.data('permissionsTable', createCrudTable({
        fetchUrl: '/permissions/data',
        dataKey: 'permissions',
        entityLabel: 'permission',
        formModalName: 'permission-form-modal',
        deleteModalName: 'confirm-permission-deletion',
        deleteNeedsPassword: true,
        defaultForm: { name: '', label: '' },
        mapItemToForm: (perm) => ({ name: perm.name, label: perm.label ?? '' }),

        /* Mirrors PermissionController's rules (name uniqueness stays backend-only). */
        validateForm: (form) => validate(form, {
            name: [required('Name'), maxLen('Name', 255)],
            label: [lettersNumbersSpaces('Label'), maxLen('Label', 255)],
        }),

        extraMethods: {
            /** Restricts the label input to letters, numbers, and spaces (no dashes, symbols, etc.) */
            restrictLabelInput(event) {
                if (event.type === 'keydown') {
                    const key = event.key;
                    if (event.ctrlKey || event.metaKey) return; // allow Ctrl/Cmd shortcuts (paste, etc.)
                    const allowed = ['Backspace', 'Delete', 'Tab', 'Escape', 'Enter',
                        'ArrowLeft', 'ArrowRight', 'ArrowUp', 'ArrowDown', 'Home', 'End'];
                    if (allowed.includes(key) || /^[a-zA-Z0-9 ]$/.test(key)) return;
                    event.preventDefault();
                    return;
                }
                const sanitized = event.target.value.replace(/[^a-zA-Z0-9 ]/g, '');
                if (this.form.label !== sanitized) this.form.label = sanitized;
            },
        },
    }));
}
