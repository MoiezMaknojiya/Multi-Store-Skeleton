/**
 * Main entry point: registers all Alpine.js components and starts Alpine.
 */
import './bootstrap';
import Alpine from 'alpinejs';

/* Layout & UI micro-components */
import { registerHeader }            from './header.js';
import { registerDropdown }          from './dropdown.js';
import { registerCustomModal }       from './modal.js';
import { registerLayoutHandler }     from './layout.js';
import { registerPasswordToggle }    from './password-toggle.js';
import { registerPasswordForm }      from './password-form.js';
import { registerProfileInfo }       from './profile-info.js';
import { registerDeleteAccountForm } from './delete-account.js';
import { registerFormGuard }         from './form-guard.js';
import { registerRegisterForm }      from './register-form.js';

/* CRUD table components (all extend crud-table-base) */
import { registerUsersTable }        from './users-table.js';
import { registerStoresTable }       from './stores-table.js';
import { registerRolesTable }        from './roles-table.js';
import { registerPermissionsTable }  from './permissions-table.js';
import { registerActivityTable }     from './activity-table.js';

window.Alpine = Alpine;

/* Toast notifications: one shared store; call window.toast('...', 'error'|'success')
 * from anywhere. Errors deserve a readable message, not a browser alert(). */
Alpine.store('toasts', {
    items: [],
    push(message, type = 'error') {
        const id = Date.now() + Math.random();
        this.items.push({ id, message, type });
        setTimeout(() => this.dismiss(id), 5000);
    },
    dismiss(id) {
        this.items = this.items.filter(t => t.id !== id);
    },
});
window.toast = (message, type = 'error') => Alpine.store('toasts').push(message, type);

/* Block double submits on plain (full-page) forms — login, profile, logout, etc. */
registerFormGuard();

/* Register layout & UI components */
registerHeader(Alpine);
registerDropdown(Alpine);
registerCustomModal(Alpine);
registerLayoutHandler(Alpine);
registerPasswordToggle(Alpine);
registerPasswordForm(Alpine);
registerProfileInfo(Alpine);
registerDeleteAccountForm(Alpine);
registerRegisterForm(Alpine);

/* Register CRUD table components */
registerUsersTable(Alpine);
registerStoresTable(Alpine);
registerRolesTable(Alpine);
registerPermissionsTable(Alpine);
registerActivityTable(Alpine);

Alpine.start();
