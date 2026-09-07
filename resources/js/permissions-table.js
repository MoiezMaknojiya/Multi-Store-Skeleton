/**
 * Permissions table Alpine component.
 * Built on the shared CRUD table factory. Only defines the form shape
 * since permissions are the simplest entity (name-only).
 */
import axios from 'axios';
import { createCrudTable } from './crud-table-base.js';
import { validate, required, maxLen, lettersNumbersSpaces } from './validate.js';

export function registerPermissionsTable(Alpine) {
    Alpine.data('permissionsTable', createCrudTable({
        fetchUrl: '/permissions/data',
        dataKey: 'permissions',
        entityLabel: 'permission',
        formModalName: 'permission-form-modal',
        deleteModalName: 'confirm-permission-deletion',
        defaultForm: { name: '', label: '' },
        mapItemToForm: (perm) => ({ name: perm.name, label: perm.label ?? '' }),

        /* Mirrors PermissionController's rules (name uniqueness stays backend-only). */
        validateForm: (form) => validate(form, {
            name: [required('Name'), maxLen('Name', 255)],
            label: [lettersNumbersSpaces('Label'), maxLen('Label', 255)],
        }),

        extraState: {
            deletePassword: '',
            deleteError: '',
        },

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

            /** Override: deleting a permission requires the acting user's password */
            confirmDelete(item) {
                this.selectedItem = item;
                this.deletePassword = '';
                this.deleteError = '';
                this.$dispatch('open-modal', 'confirm-permission-deletion');
            },

            async deleteItem() {
                if (!this.selectedItem || this.deleting) return;
                if (!this.deletePassword.trim()) {
                    this.deleteError = 'Password is required.';
                    return;
                }
                this.deleting = true;
                this.deleteError = '';
                try {
                    await axios.delete(`/permissions/${this.selectedItem.id}`, {
                        data: { password: this.deletePassword },
                    });
                    this.$dispatch('close-modal', 'confirm-permission-deletion');
                    this.selectedItem = null;
                    this.deletePassword = '';
                    await this.fetchItems();
                    if (this.items.length === 0 && this.currentPage > 1) {
                        this.currentPage--;
                        await this.fetchItems();
                    }
                } catch (error) {
                    if (error.response?.status === 422) {
                        this.deleteError = error.response.data.errors?.password?.[0]
                            || error.response.data.message
                            || 'Could not delete this permission.';
                    } else {
                        window.toast(error.response?.data?.message ?? 'Could not delete permission. Please try again.');
                    }
                } finally {
                    this.deleting = false;
                }
            },
        },
    }));
}
