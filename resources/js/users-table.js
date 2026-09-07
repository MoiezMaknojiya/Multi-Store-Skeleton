/**
 * Users table Alpine component.
 * Extends the shared CRUD table with user-specific store assignment management.
 * This is the most complex table due to user-store-role assignment features.
 */
import axios from 'axios';
import { createCrudTable } from './crud-table-base.js';
import { validate, required, maxLen, minLen, emailFormat, digitsExactly, digitsOnly } from './validate.js';

export function registerUsersTable(Alpine) {
    Alpine.data('usersTable', (config = {}) => {
        const base = createCrudTable({
            fetchUrl: '/users/data',
            dataKey: 'users',
            entityLabel: 'user',
            formModalName: 'user-form-modal',
            deleteModalName: 'confirm-user-deletion',
            perPage: 100,
            defaultForm: {
                first_name: '', last_name: '', phone: '',
                email: '', password: '', password_confirmation: '',
            },
            mapItemToForm: (user) => ({
                first_name: user.first_name,
                last_name: user.last_name,
                phone: user.phone || '',
                email: user.email,
                password: '',
                password_confirmation: '',
            }),

            /* Mirrors UserController's rules (email uniqueness stays backend-only).
             * Password: required on create, optional on edit; min 8 + match either way. */
            validateForm: (form, editingItem) => validate(form, {
                first_name: [required('First name'), maxLen('First name', 255)],
                last_name: [required('Last name'), maxLen('Last name', 255)],
                phone: [required('Phone'), digitsExactly('Phone', 10)],
                email: [required('Email'), emailFormat('Email')],
                password: [
                    ...(editingItem ? [] : [required('Password')]),
                    minLen('Password', 8),
                    (value, f) => value && value !== f.password_confirmation
                        ? 'Password confirmation does not match.'
                        : null,
                ],
            }),

            extraState: {
                isSuperAdmin: config.isSuperAdmin ?? false,
                /* Global users (Super-Admin or a custom global role) span every store,
                 * so they pick a store when assigning instead of using a session store. */
                isGlobalUser: config.isGlobalUser ?? false,
                currentStoreId: config.currentStoreId ?? null,
                assigning: false,
                removing: false,
                impersonating: false,
                openingAssignments: false,
                onboarding: false,
                openingOnboard: false,
                onboardRoles: [],
                onboardForm: {},
                selectedUserForStores: null,
                assignments: [],
                availableStores: [],
                availableRoles: [],
                assignForm: { store_id: '', role_id: '' },
                assignErrors: { store_id: '', role_id: '', general: '' },
                storeToRemove: null,
            },

            extraMethods: {
                /** Restricts phone input to digits only, max 10 characters */
                restrictPhoneInput(event) {
                    if (event.type === 'keydown') {
                        const key = event.key;
                        if (event.ctrlKey || event.metaKey) return; // allow Ctrl/Cmd shortcuts (paste, etc.)
                        const allowed = ['Backspace', 'Delete', 'Tab', 'Escape', 'Enter',
                            'ArrowLeft', 'ArrowRight', 'ArrowUp', 'ArrowDown', 'Home', 'End'];
                        if (allowed.includes(key) || /^\d$/.test(key)) return;
                        event.preventDefault();
                        return;
                    }
                    let digits = event.target.value.replace(/\D/g, '').slice(0, 10);
                    if (this.form.phone !== digits) this.form.phone = digits;
                },

                /** Blocks spaces in the email field — not a valid character in a deliverable email address */
                restrictEmailInput(event) {
                    if (event.type === 'keydown') {
                        if (event.key === ' ') event.preventDefault();
                        return;
                    }
                    const noSpaces = event.target.value.replace(/\s/g, '');
                    if (this.form.email !== noSpaces) this.form.email = noSpaces;
                },

                resetAssignForm() {
                    this.assignForm = {
                        store_id: this.isGlobalUser ? '' : (this.currentStoreId ?? ''),
                        role_id: '',
                    };
                },

                /** Global roles (Super-Admin, or any role marked is_global) are assigned
                 *  on the store_id = 0 sentinel — no store applies. */
                isGlobalRoleSelected() {
                    const role = this.availableRoles.find(r => String(r.id) === String(this.assignForm.role_id));
                    return !!(role && (role.is_global || role.name === 'Super-Admin'));
                },

                /* ── Onboarding: owner + store in one step ─────────────────── */

                /** Opens the onboarding modal with fresh fields and the role list;
                 *  the owner-ish role is pre-selected by NAME purely as a UI
                 *  convenience — the server works on ids only. */
                async openOnboardModal() {
                    if (this.openingOnboard) return;
                    this.openingOnboard = true;
                    try {
                        this.formErrors = {};
                        this.onboardForm = {
                            first_name: '', last_name: '', phone: '', email: '',
                            password: '', password_confirmation: '',
                            store_name: '', street: '', suite: '', city: '',
                            state: '', zip_code: '', country: 'USA', role_id: '',
                        };
                        const res = await axios.get('/users/assignable-roles');
                        // Onboarding creates a NEW store, so only store-less roles
                        // (store_id null) can be offered — a role built inside an
                        // existing store never travels to another one. Mirrors the
                        // server guard in UserController::onboardOwner.
                        this.onboardRoles = res.data.roles.filter(
                            r => !r.is_global && r.name !== 'Super-Admin' && !r.store_id,
                        );
                        const preferred = this.onboardRoles.find(r => /owner/i.test(r.name)) ?? this.onboardRoles[0];
                        this.onboardForm.role_id = preferred ? String(preferred.id) : '';
                        this.$dispatch('open-modal', 'onboard-modal');
                    } catch (error) {
                        console.error('Failed to open onboarding:', error);
                        this.onboardRoles = [];
                    } finally {
                        this.openingOnboard = false;
                    }
                },

                validateOnboardForm() {
                    return validate(this.onboardForm, {
                        first_name: [required('First name'), maxLen('First name', 255)],
                        last_name: [required('Last name'), maxLen('Last name', 255)],
                        phone: [required('Phone'), digitsExactly('Phone', 10)],
                        email: [required('Email'), emailFormat('Email')],
                        password: [
                            required('Password'),
                            minLen('Password', 8),
                            (value, f) => value && value !== f.password_confirmation
                                ? 'Password confirmation does not match.'
                                : null,
                        ],
                        store_name: [required('Store name'), maxLen('Store name', 255)],
                        street: [required('Street'), maxLen('Street', 255)],
                        city: [required('City'), maxLen('City', 100)],
                        state: [required('State')],
                        zip_code: [required('Zip code'), digitsOnly('Zip code'), maxLen('Zip code', 10)],
                        country: [required('Country'), maxLen('Country', 100)],
                        role_id: [required('Role')],
                    });
                },

                async saveOnboard() {
                    if (this.onboarding) return;

                    const errors = this.validateOnboardForm();
                    if (Object.keys(errors).length > 0) {
                        this.formErrors = errors;
                        return;
                    }
                    this.formErrors = {};
                    this.onboarding = true;
                    try {
                        await axios.post('/users/onboard', this.onboardForm);
                        this.$dispatch('close-modal', 'onboard-modal');
                        await this.fetchItems();
                    } catch (error) {
                        if (error.response?.status === 422 && error.response.data.errors) {
                            this.formErrors = error.response.data.errors;
                        } else {
                            window.toast(error.response?.data?.message ?? 'An error occurred. Please try again.');
                        }
                    } finally {
                        this.onboarding = false;
                    }
                },

                /** Opens the store assignment management modal for a specific user */
                async openStoreAssignmentModal(user) {
                    if (this.openingAssignments) return;
                    this.openingAssignments = true;
                    try {
                        this.selectedUserForStores = user;
                        this.assignErrors = { store_id: '', role_id: '', general: '' };
                        this.resetAssignForm();
                        await this.fetchAssignments(user.id);
                        if (this.isGlobalUser) await this.fetchAvailableStores();
                        await this.fetchAvailableRoles();
                        this.$dispatch('open-modal', 'user-stores-modal');
                    } finally {
                        this.openingAssignments = false;
                    }
                },

                async fetchAssignments(userId) {
                    try {
                        const res = await axios.get(`/users/${userId}/stores`);
                        this.assignments = res.data.assignments;
                    } catch (error) {
                        console.error('Failed to fetch assignments:', error);
                        this.assignments = [];
                    }
                },

                /* Both option lists come from dedicated endpoints gated by the assign
                 * permission itself — no store-view/role-view needed for this modal. */
                async fetchAvailableStores() {
                    try {
                        const res = await axios.get('/users/assignable-stores');
                        const assignedIds = this.assignments.map(a => a.store_id);
                        this.availableStores = res.data.stores.filter(s => !assignedIds.includes(s.id));
                    } catch (error) {
                        console.error('Failed to fetch stores:', error);
                        this.availableStores = [];
                    }
                },

                async fetchAvailableRoles() {
                    try {
                        const res = await axios.get('/users/assignable-roles');
                        this.availableRoles = res.data.roles;
                    } catch (error) {
                        console.error('Failed to fetch roles:', error);
                        this.availableRoles = [];
                    }
                },

                /** Assigns the user to a store with a role */
                async assignStore() {
                    if (this.assigning) return;
                    this.assigning = true;
                    this.assignErrors = { store_id: '', role_id: '', general: '' };
                    let hasError = false;

                    if (this.isGlobalRoleSelected()) {
                        this.assignForm.store_id = '';
                    } else if (this.isGlobalUser) {
                        if (!this.assignForm.store_id) {
                            this.assignErrors.store_id = 'Please select a store.';
                            hasError = true;
                        }
                    } else {
                        this.assignForm.store_id = this.currentStoreId;
                        if (!this.assignForm.store_id) {
                            this.assignErrors.store_id = 'No store selected. Switch to a store on the dashboard first.';
                            hasError = true;
                        }
                    }
                    if (!this.assignForm.role_id) {
                        this.assignErrors.role_id = 'Please select a role.';
                        hasError = true;
                    }
                    if (hasError) { this.assigning = false; return; }

                    try {
                        await axios.post(`/users/${this.selectedUserForStores.id}/stores`, this.assignForm);
                        await this.fetchAssignments(this.selectedUserForStores.id);
                        if (this.isGlobalUser) await this.fetchAvailableStores();
                        this.resetAssignForm();
                        this.assignErrors = { store_id: '', role_id: '', general: '' };
                    } catch (error) {
                        console.error('Failed to assign store:', error);
                        this.assignErrors.general = error.response?.data?.message || 'Could not assign store.';
                    } finally {
                        this.assigning = false;
                    }
                },

                /** Logs the current Super Admin in as this user, then hard-navigates
                 *  to the dashboard so the whole page picks up the new session identity. */
                async impersonate(user) {
                    if (this.impersonating) return;
                    this.impersonating = true;
                    try {
                        await axios.post(`/users/${user.id}/impersonate`);
                        // Deliberately stays true on success — keeps the button dead
                        // while the browser navigates to the new identity.
                        window.location.href = '/dashboard';
                    } catch (error) {
                        console.error('Failed to log in as user:', error);
                        window.toast(error.response?.data?.message || 'Could not log in as this user.');
                        this.impersonating = false;
                    }
                },

                confirmRemoveAssignment(storeId, storeName) {
                    this.storeToRemove = { id: storeId, name: storeName };
                    this.$dispatch('open-modal', 'confirm-store-removal');
                },

                /** Removes a user from a store */
                async removeAssignment() {
                    if (!this.storeToRemove || this.removing) return;
                    this.removing = true;
                    try {
                        await axios.delete(`/users/${this.selectedUserForStores.id}/stores/${this.storeToRemove.id}`);
                        this.$dispatch('close-modal', 'confirm-store-removal');
                        this.storeToRemove = null;
                        await this.fetchAssignments(this.selectedUserForStores.id);
                        if (this.isGlobalUser) await this.fetchAvailableStores();
                        this.assignErrors = { store_id: '', role_id: '', general: '' };
                    } catch (error) {
                        console.error('Failed to remove assignment:', error);
                        window.toast(error.response?.data?.message ?? 'Could not remove store assignment.');
                    } finally {
                        this.removing = false;
                    }
                },
            },
        })();

        return base;
    });
}
