/**
 * The Roles page (resources/views/roles/index.blade.php), from where the person stands
 * (docs/ORGANIZATION-SPEC.md §2–4 — owner's rules, 2026-09-17): on the platform every role — the
 * super admin makes a role and says what it is for (an organization role, offered in every organization, or a platform
 * role), and looks after the custom roles organizations made; inside an organization the organization roles to read and the
 * organization's own custom roles.
 *
 * The permissions offered in the form come from the server (/roles/assignable) and are only those the
 * role can hold — nothing greyed out — so the checklist never offers what RoleController would refuse.
 */
import axios from 'axios';
import { validate, required, maxLen, minCount } from '../core/validate.js';

/* Group titles, in the order an organization is set up and run. Anything else is appended. The catalogue's own
   permissions (permission-*) are listed for the Super-Admin role alone. */
const GROUPS = {
    organization: 'Organizations',
    member: 'Members',
    role: 'Roles',
    screen: 'Screens',
    daypart: 'Dayparts',
    media: 'Media library',
    ad: 'Ad Builder',
    channel: 'Channels',
    user: 'Accounts',
    activity: 'Activity log',
    permission: 'Permissions',
};

/* The groups that reach every organization on a platform role, and only the member's own organization on an organization's role.
   Accounts are not among them: they are the platform's alone, and never listed for an organization's role. */
const SCOPED_GROUPS = ['organization', 'channel', 'activity'];

const KINDS = {
    super_admin: { label: 'Super admin', badge: 'badge-danger' },
    organization: { label: 'Organization role', badge: 'badge-warning' },
    platform: { label: 'Platform role', badge: 'badge-info' },
    custom: { label: 'Custom role', badge: 'badge-neutral' },
};

export function registerRolesPage(Alpine) {
    Alpine.data('rolesPage', (config = {}) => ({
        isPlatform: config.isPlatform ?? false,
        organizationName: config.organizationName ?? null,

        loading: true,
        roles: [],
        assignable: [],
        loadingAssignable: false,

        form: { name: '', type: 'organization', permissions: [] },
        formErrors: {},
        editingRole: null,
        openingForm: false,
        saving: false,
        assignableToken: 0,

        selectedRole: null,
        deleting: false,
        deletePassword: '',
        deletePasswordError: '',
        viewedRole: null,

        init() {
            this.fetch();
        },

        async fetch() {
            this.loading = true;
            try {
                const { data } = await axios.get('/roles/data');
                this.roles = data.roles;
            } catch (error) {
                window.toast(error.response?.data?.message ?? 'Could not load the roles. Please refresh the page.');
            } finally {
                this.loading = false;
            }
        },

        /* Methods, not getters: plain functions keep this component safe to extend. */
        mainRoles() {
            return this.isPlatform ? this.roles.filter((role) => role.kind !== 'custom') : this.roles;
        },

        organizationCustomRoles() {
            return this.isPlatform ? this.roles.filter((role) => role.kind === 'custom') : [];
        },

        kindLabel(role) {
            return KINDS[role.kind]?.label ?? role.kind;
        },

        kindBadgeClass(role) {
            return KINDS[role.kind]?.badge ?? 'badge-neutral';
        },

        /** Permissions grouped by their prefix, in GROUPS order. */
        grouped(permissions) {
            const groups = {};
            for (const permission of permissions) {
                const key = permission.name.split('-')[0];
                (groups[key] ??= []).push(permission);
            }

            const order = Object.keys(GROUPS);
            return Object.keys(groups)
                .sort((a, b) => (order.indexOf(a) === -1 ? 99 : order.indexOf(a)) - (order.indexOf(b) === -1 ? 99 : order.indexOf(b)))
                .map((key) => ({ key, title: GROUPS[key] ?? key.charAt(0).toUpperCase() + key.slice(1), permissions: groups[key] }));
        },

        /* ── Create / edit ─────────────────────────────────────────────── */

        /** What the role in the form is: organization, platform or custom. */
        formType() {
            return this.editingRole?.kind ?? (this.isPlatform ? this.form.type : 'custom');
        },

        formHint() {
            const type = this.formType();
            if (this.editingRole?.is_owner_role) {
                return 'This is the Owner role: whoever holds it owns their organization. You can rename it and change what it allows; it is never deleted.';
            }
            if (type === 'organization') return 'An organization role is offered in every organization. What you save here applies in all of them at once.';
            if (type === 'platform') return 'A platform role works above the organizations: what it allows reaches every organization. The permission catalogue stays with Super-Admin.';
            if (this.isPlatform) return `A custom role of ${this.editingRole?.organization_name ?? 'one organization'}: it is offered there alone.`;

            return `A custom role of ${this.organizationName}. You can only give it permissions you hold yourself.`;
        },

        /** The hint beside a group whose permissions reach the member's own organization alone on an organization's role. */
        groupHint(group) {
            return this.formType() !== 'platform' && SCOPED_GROUPS.includes(group.key) ? 'This organization only' : '';
        },

        async loadAssignable(params) {
            // Only the list asked for last may fill the checklist: Organization role, Platform role, Organization role again
            // sends three requests, and the platform's list landing last would offer an organization role what it
            // cannot hold — and untick what it can.
            const token = ++this.assignableToken;
            this.loadingAssignable = true;
            try {
                const { data } = await axios.get('/roles/assignable', { params });
                if (token !== this.assignableToken) return;
                this.assignable = data;
                // A permission this kind of role cannot hold does not stay ticked out of sight.
                this.form.permissions = this.form.permissions.filter((id) => data.some((permission) => Number(permission.id) === Number(id)));
            } catch (error) {
                // A request already overtaken has nothing left to report.
                if (token !== this.assignableToken) return;
                throw error;
            } finally {
                if (token === this.assignableToken) this.loadingAssignable = false;
            }
        },

        async openForm(role = null) {
            if (this.openingForm) return;
            this.openingForm = true;
            try {
                this.editingRole = role;
                this.formErrors = {};
                this.form = {
                    name: role?.name ?? '',
                    type: role?.kind === 'platform' ? 'platform' : 'organization',
                    permissions: role ? role.permissions.map((permission) => Number(permission.id)) : [],
                };
                this.assignable = [];
                await this.loadAssignable(role ? { role: role.id } : (this.isPlatform ? { type: this.form.type } : {}));
                this.$dispatch('open-modal', 'role-form');
            } catch (error) {
                window.toast(error.response?.data?.message ?? 'Could not open the role form.');
            } finally {
                this.openingForm = false;
            }
        },

        /** A new role's purpose changed: offer what that kind of role can hold. */
        async changeType(type) {
            this.form.type = type;
            this.formErrors = {};
            try {
                await this.loadAssignable({ type });
            } catch (error) {
                window.toast(error.response?.data?.message ?? 'Could not load the permissions.');
            }
        },

        isChecked(permission) {
            return this.form.permissions.some((id) => Number(id) === Number(permission.id));
        },

        toggle(permission) {
            this.form.permissions = this.isChecked(permission)
                ? this.form.permissions.filter((id) => Number(id) !== Number(permission.id))
                : [...this.form.permissions, Number(permission.id)];
        },

        groupState(permissions) {
            const checked = permissions.filter((permission) => this.isChecked(permission)).length;
            if (checked === 0) return 'none';

            return checked === permissions.length ? 'all' : 'some';
        },

        toggleGroup(permissions) {
            const ids = permissions.map((permission) => Number(permission.id));
            this.form.permissions = this.groupState(permissions) === 'all'
                ? this.form.permissions.filter((id) => !ids.includes(Number(id)))
                : [...new Set([...this.form.permissions.map(Number), ...ids])];
        },

        async save() {
            if (this.saving || this.loadingAssignable) return;

            /* Mirrors RoleController (name clashes are the server's to find: it knows every list). */
            const errors = validate(this.form, {
                name: [required('Name'), maxLen('Name', 255)],
                permissions: [minCount('Choose at least one permission.')],
            });
            if (Object.keys(errors).length) {
                this.formErrors = errors;
                return;
            }

            this.saving = true;
            this.formErrors = {};
            try {
                const payload = { name: this.form.name, permissions: this.form.permissions };
                const { data } = this.editingRole
                    ? await axios.put(`/roles/${this.editingRole.id}`, payload)
                    : await axios.post('/roles', this.isPlatform ? { ...payload, type: this.form.type } : payload);
                this.$dispatch('close-modal', 'role-form');
                window.toast(data.message, 'success');
                await this.fetch();
            } catch (error) {
                if (error.response?.status === 422 && error.response.data.errors) {
                    this.formErrors = error.response.data.errors;
                } else {
                    window.toast(error.response?.data?.message ?? 'Could not save the role.');
                }
            } finally {
                this.saving = false;
            }
        },

        /* ── View / delete ─────────────────────────────────────────────── */

        view(role) {
            this.viewedRole = role;
            this.$dispatch('open-modal', 'role-permissions');
        },

        confirmDelete(role) {
            // A role somebody still holds cannot go (owner's rule, 2026-09-17): said at once, before any password is
            // typed. RoleController::destroy refuses it again with the same words, should the list be out of date.
            if (role.holders_count > 0) {
                const holders = role.holders_count === 1 ? '1 person still holds' : `${role.holders_count} people still hold`;
                window.toast(`Please unassign ${role.name} from everyone first: ${holders} it.`);
                return;
            }

            this.selectedRole = role;
            this.deletePassword = '';
            this.deletePasswordError = '';
            this.$dispatch('open-modal', 'confirm-role-deletion');
        },

        /* Deleting a role is a big delete: it asks for the password (ConfirmsPassword on the server). */
        async destroy() {
            if (!this.selectedRole || this.deleting) return;
            if (!this.deletePassword) {
                this.deletePasswordError = 'Password is required.';
                return;
            }
            this.deleting = true;
            this.deletePasswordError = '';
            try {
                const { data } = await axios.delete(`/roles/${this.selectedRole.id}`, { data: { password: this.deletePassword } });
                this.$dispatch('close-modal', 'confirm-role-deletion');
                window.toast(data.message, 'success');
                await this.fetch();
            } catch (error) {
                const passwordError = error.response?.status === 422 ? error.response.data.errors?.password?.[0] : null;
                if (passwordError) {
                    this.deletePasswordError = passwordError;
                } else {
                    window.toast(error.response?.data?.message ?? 'Could not delete the role.');
                }
            } finally {
                this.deleting = false;
            }
        },
    }));
}
