/**
 * Roles table Alpine component.
 * Extends the shared CRUD table with role-specific permission management.
 */
import axios from 'axios';
import { createCrudTable } from './crud-table-base.js';
import { validate, required, maxLen, minCount } from './validate.js';

export function registerRolesTable(Alpine) {
    Alpine.data('rolesTable', (config = {}) => createCrudTable({
        fetchUrl: '/roles/data',
        dataKey: 'roles',
        entityLabel: 'role',
        formModalName: 'role-form-modal',
        deleteModalName: 'confirm-role-deletion',
        defaultForm: { name: '', permissions: [], is_global: false, is_signup_default: false },

        /* Mirrors RoleController's rules (name uniqueness stays backend-only). */
        validateForm: (form) => validate(form, {
            name: [required('Name'), maxLen('Name', 255)],
            permissions: [minCount('Please select at least one permission.')],
        }),

        extraState: {
            permissionsList: [],
            groupedPermissions: {},
            openingModal: false,
            isSuperAdmin: config.isSuperAdmin ?? false,
            currentUserId: config.currentUserId ?? null,
        },

        extraMethods: {
            /** Only a role's creator (or a super admin) may edit/delete it —
             *  mirrors the server-side guard in RoleController. */
            canManageRole(item) {
                return this.isSuperAdmin || item.created_by === this.currentUserId;
            },

            /** Groups permissions by their prefix for organized display in the role form modal.
             *  Groups are ordered User-related first, Permission Management last, regardless of
             *  the alphabetical order the permissions themselves arrive in.
             *  Note: "user-store" (Laravel's store() action = create a user) belongs to the
             *  plain "user" group; only "user-store-*" are the store-assignment permissions. */
            groupPermissions(permissions) {
                const GROUP_ORDER = ['user', 'user-store-assignments', 'store', 'role', 'permission'];

                const groups = {};
                permissions.forEach(perm => {
                    let prefix = perm.name.split('-')[0];
                    if (perm.name.startsWith('user-store-')) {
                        prefix = 'user-store-assignments';
                    }
                    if (!groups[prefix]) groups[prefix] = [];
                    groups[prefix].push(perm);
                });
                Object.keys(groups).forEach(key => {
                    groups[key].sort((a, b) => a.name.localeCompare(b.name));
                });

                const ordered = {};
                GROUP_ORDER.forEach(key => {
                    if (groups[key]) ordered[key] = groups[key];
                });
                Object.keys(groups).forEach(key => {
                    if (!ordered[key]) ordered[key] = groups[key];
                });
                return ordered;
            },

            /** Whether every permission in a group is currently selected.
             *  IDs are compared as Numbers because Alpine's checkbox x-model stores newly-toggled
             *  values as strings (from the raw HTML value attribute), while permissions loaded from
             *  the API are numbers — strict-equality checks would otherwise miss real matches. */
            isGroupFullySelected(perms) {
                return perms.length > 0 && perms.every(p => this.form.permissions.some(id => Number(id) === Number(p.id)));
            },

            /** Whether some, but not all, permissions in a group are currently selected */
            isGroupPartiallySelected(perms) {
                const selectedCount = perms.filter(p => this.form.permissions.some(id => Number(id) === Number(p.id))).length;
                return selectedCount > 0 && selectedCount < perms.length;
            },

            /** Selects every permission in a group if any are unselected, otherwise clears the whole group */
            toggleGroupSelection(perms) {
                if (this.isGroupFullySelected(perms)) {
                    const idsToRemove = new Set(perms.map(p => Number(p.id)));
                    this.form.permissions = this.form.permissions.filter(id => !idsToRemove.has(Number(id)));
                } else {
                    const selected = new Set(this.form.permissions.map(id => Number(id)));
                    perms.forEach(p => selected.add(Number(p.id)));
                    this.form.permissions = Array.from(selected);
                }
            },

            /** Returns a human-readable label for each permission group prefix */
            getGroupDisplayName(prefix) {
                const names = {
                    'user': 'User Management',
                    'user-store-assignments': 'User-Store Assignments',
                    'store': 'Store Management',
                    'role': 'Role Management',
                    'permission': 'Permission Management',
                };
                return names[prefix] || prefix.charAt(0).toUpperCase() + prefix.slice(1);
            },

            /** Fetches all permissions the current user is allowed to assign */
            async fetchPermissionsList() {
                try {
                    const response = await axios.get('/roles/assignable');
                    this.permissionsList = response.data;
                    this.groupedPermissions = this.groupPermissions(this.permissionsList);
                } catch (error) {
                    console.error('Failed to fetch permissions:', error);
                    this.permissionsList = [];
                    this.groupedPermissions = {};
                }
            },

            /** Override: surface the backend's specific reason when a role can't be deleted */
            async deleteItem() {
                if (!this.selectedItem || this.deleting) return;
                this.deleting = true;
                try {
                    await axios.delete(`/roles/${this.selectedItem.id}`);
                    this.$dispatch('close-modal', 'confirm-role-deletion');
                    this.selectedItem = null;
                    await this.fetchItems();
                    if (this.items.length === 0 && this.currentPage > 1) {
                        this.currentPage--;
                        await this.fetchItems();
                    }
                } catch (error) {
                    console.error('Failed to delete role:', error);
                    window.toast(error.response?.data?.message || 'Could not delete role. Please try again.');
                } finally {
                    this.deleting = false;
                }
            },

            /** Override openFormModal: roles need to load permissions before opening */
            async openFormModal(role = null) {
                if (this.openingModal) return;
                this.openingModal = true;
                this.editingItem = role;
                this.formErrors = {};
                await this.fetchPermissionsList();

                if (role) {
                    this.form = { name: role.name, permissions: [], is_global: !!role.is_global, is_signup_default: !!role.is_signup_default };
                    try {
                        const res = await axios.get(`/roles/${role.id}/permissions`);
                        this.form.permissions = res.data.map(p => p.id);
                    } catch (error) {
                        console.error('Failed to fetch role permissions:', error);
                        this.form.permissions = [];
                    }
                } else {
                    this.form = { name: '', permissions: [], is_global: false, is_signup_default: false };
                }
                this.$dispatch('open-modal', 'role-form-modal');
                this.openingModal = false;
            },
        },
    })());
}
