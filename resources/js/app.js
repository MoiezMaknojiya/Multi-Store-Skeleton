/**
 * Main entry point: registers all Alpine.js components and starts Alpine.
 */
import './core/bootstrap';
import Alpine from 'alpinejs';

/* Layout & UI micro-components */
import { registerHeader }            from './core/header.js';
import { registerCustomModal }       from './core/modal.js';
import { registerLayoutHandler }     from './core/layout.js';
import { registerPasswordToggle }    from './core/password-toggle.js';
import { registerPasswordForm }      from './pages/password-form.js';
import { registerProfileInfo }       from './pages/profile-info.js';
import { registerDeleteAccountForm } from './pages/delete-account.js';
import { registerFormGuard }         from './core/form-guard.js';
import { registerRegisterForm }      from './pages/register-form.js';
import { registerStoreSettings }     from './pages/store-settings.js';
import { registerMembersPage }       from './pages/members-page.js';

/* The panel's listings (most of them built on crud-table-base) */
import { registerUsersTable }        from './tables/users-table.js';
import { registerStoresTable }       from './tables/stores-table.js';
import { registerRolesPage }         from './pages/roles-page.js';
import { registerPermissionsTable }  from './tables/permissions-table.js';
import { registerActivityTable }     from './tables/activity-table.js';
import { registerMediaTable }        from './tables/media-table.js';
import { registerScreensTable }      from './tables/screens-table.js';
import { registerScreenPlaylist }    from './pages/screen-playlist.js';
import { registerDaypartsTable }     from './tables/dayparts-table.js';
import { registerCampaignsTable }    from './tables/campaigns-table.js';
import { registerChannelsTable }     from './tables/channels-table.js';
import { registerChannelAds }        from './pages/channel-ads.js';

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

registerCustomModal(Alpine);
registerLayoutHandler(Alpine);
registerPasswordToggle(Alpine);
registerPasswordForm(Alpine);
registerProfileInfo(Alpine);
registerDeleteAccountForm(Alpine);
registerRegisterForm(Alpine);
registerStoreSettings(Alpine);
registerMembersPage(Alpine);

/* Register CRUD table components */
registerUsersTable(Alpine);
registerStoresTable(Alpine);
registerRolesPage(Alpine);
registerPermissionsTable(Alpine);
registerActivityTable(Alpine);
registerMediaTable(Alpine);
registerScreensTable(Alpine);
registerScreenPlaylist(Alpine);
registerDaypartsTable(Alpine);
registerCampaignsTable(Alpine);
registerChannelsTable(Alpine);
registerChannelAds(Alpine);

Alpine.start();
