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
import { registerDigitsOnly }        from './core/digits-only.js';
import { registerRegisterForm }      from './pages/register-form.js';
import { registerAuthForms }         from './pages/auth-forms.js';
import { registerStoreSettings }     from './pages/store-settings.js';
import { registerMembersPage }       from './pages/members-page.js';
import { registerUploadDropzone }    from './core/upload-dropzone.js';

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

/* The Ad Builder's two listings. The editor itself is loaded on its own page only (below). */
import { registerAdsTable }           from './tables/ads-table.js';
import { registerBuilderAssetsTable } from './tables/builder-assets-table.js';

window.Alpine = Alpine;

/* Toast notifications: one shared store; call window.toast('...', 'error'|'success')
 * from anywhere. Errors deserve a readable message, not a browser alert(). A success goes after five
 * seconds, an error after ten (it may name what to do next), and neither while the pointer or the keyboard
 * is on it (hold/release). The timers live outside the reactive state. */
const toastTimers = new Map();

Alpine.store('toasts', {
    items: [],
    push(message, type = 'error') {
        const id = Date.now() + Math.random();
        this.items.push({ id, message, type });
        this.release(id);
    },
    hold(id) {
        clearTimeout(toastTimers.get(id));
    },
    release(id) {
        const toast = this.items.find(t => t.id === id);
        if (!toast) return;
        clearTimeout(toastTimers.get(id));
        toastTimers.set(id, setTimeout(() => this.dismiss(id), toast.type === 'success' ? 5000 : 10000));
    },
    dismiss(id) {
        clearTimeout(toastTimers.get(id));
        toastTimers.delete(id);
        this.items = this.items.filter(t => t.id !== id);
    },
});
window.toast = (message, type = 'error') => Alpine.store('toasts').push(message, type);

/* Block double submits on plain (full-page) forms — login, profile, logout, etc. */
registerFormGuard();

/* A digits-only field (`data-digits`, a phone or a ZIP code) keeps to its digits, and to that many, on every page. */
registerDigitsOnly();

/* Register layout & UI components */
registerHeader(Alpine);

registerCustomModal(Alpine);
registerLayoutHandler(Alpine);
registerPasswordToggle(Alpine);
registerPasswordForm(Alpine);
registerProfileInfo(Alpine);
registerDeleteAccountForm(Alpine);
registerRegisterForm(Alpine);
registerAuthForms(Alpine);
registerStoreSettings(Alpine);
registerMembersPage(Alpine);

/* The uploader every page that takes a file uses (docs/UPLOADS-SPEC.md) */
registerUploadDropzone(Alpine);

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

/* Register the Ad Builder's listings */
registerAdsTable(Alpine);
registerBuilderAssetsTable(Alpine);

/**
 * The Ad Builder's editor is some two fifths of the panel's script, and only its own page uses it (owner, 2026-09-30:
 * "editor ko alag load karo"): it is fetched there alone, before Alpine starts, so every other page opens without it.
 * Every other page starts Alpine at once — nothing is awaited on the way. A page whose editor could not be fetched
 * (the connection dropped as it opened) still works everywhere else and says so; the editor's part is left alone.
 */
async function start() {
    const editors = document.querySelectorAll('[x-data^="adEditor"]');

    if (editors.length > 0) {
        try {
            const { registerAdEditor } = await import('./builder/editor.js');
            registerAdEditor(Alpine);
        } catch {
            editors.forEach((editor) => editor.setAttribute('x-ignore', ''));
            window.toast('The editor could not be loaded. Check the connection, then reload the page.');
        }
    }

    Alpine.start();
}

start();
