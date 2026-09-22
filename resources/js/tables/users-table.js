/**
 * Users table — accounts (docs/STORE-ORGANIZATION-SPEC.md rules 13, 19, 21, 24): every account, from the
 * platform's side alone — a store's own people are its Members page (owner's rule, 2026-09-17). Nobody is
 * created or edited here: people join by invitation and keep their own details. What each row allows comes
 * from the server (`can`), so no button can mislead.
 */
import axios from 'axios';
import { createCrudTable } from '../core/crud-table-base.js';
import { validate, required, emailFormat, maxLen } from '../core/validate.js';

export function registerUsersTable(Alpine) {
    Alpine.data('usersTable', (config = {}) => createCrudTable({
        fetchUrl: '/users/data',
        dataKey: 'users',
        entityLabel: 'account',
        deleteModalName: 'confirm-account-deletion',
        extraState: {
            isSuperAdmin: config.isSuperAdmin ?? false,
            impersonating: false,

            /* Manage stores, from the platform (super admins): a person's stores, roles, and adding them to more. */
            accessTarget: null,
            access: { memberships: [], stores: [], store_roles: [], custom_roles: {} },
            loadingAccess: false,
            assignForm: { store_id: '', role_id: '' },
            assignErrors: {},
            assigning: false,
            removing: null,
            removingBusy: false,
            removePassword: '',
            removePasswordError: '',

            selectedForRole: null,
            removingRole: false,
            rolePassword: '',
            rolePasswordError: '',

            /* The platform team's open invitations — super admins only. */
            invitations: [],
            inviteForm: { email: '', role_id: '' },
            inviting: false,
            selectedInvitation: null,
            busyInvitationId: null,
            revoking: false,
        },

        extraMethods: {
            onInit() {
                if (this.isSuperAdmin) this.fetchInvitations();
            },

            initials(name) {
                return (name || '?').split(/\s+/).filter(Boolean).slice(0, 2).map((part) => Array.from(part)[0].toUpperCase()).join('');
            },

            formatDate(iso) {
                return iso ? new Date(iso).toLocaleDateString(undefined, { year: 'numeric', month: 'short', day: 'numeric' }) : '—';
            },

            roleBadgeClass(key) {
                if (key === 'owner') return 'badge-warning';
                if (key === 'admin') return 'badge-info';

                return 'badge-neutral';
            },

            membershipLabel(membership) {
                return `${membership.role_name} · ${membership.store_name}`;
            },

            /* ── Manage stores, from the platform ──────────────────────── */

            async openManageStores(user) {
                if (this.loadingAccess) return;
                this.loadingAccess = true;
                try {
                    this.accessTarget = user;
                    this.removing = null;
                    this.assignForm = { store_id: '', role_id: '' };
                    this.assignErrors = {};
                    await this.loadAccess();
                    this.$dispatch('open-modal', 'manage-stores');
                } catch (error) {
                    window.toast(error.response?.data?.message ?? 'Could not load their stores.');
                } finally {
                    this.loadingAccess = false;
                }
            },

            async loadAccess() {
                const { data } = await axios.get(`/users/${this.accessTarget.id}/stores`);
                this.access = {
                    ...data,
                    // Each row keeps the role picked on screen apart from the one saved, until Save.
                    memberships: data.memberships.map((membership) => ({ ...membership, selected_role_id: membership.role_id, busy: false, error: '' })),
                };
            },

            /** The roles a store offers: every store role, then that store's own custom roles. */
            rolesFor(storeId) {
                if (!storeId) return [];

                return [...this.access.store_roles, ...(this.access.custom_roles?.[storeId] ?? [])];
            },

            roleDescription(storeId, roleId) {
                return this.rolesFor(storeId).find((role) => Number(role.id) === Number(roleId))?.description ?? '';
            },

            /* After any change: this modal's lists, and the row's badges in the table behind it. */
            async afterAccessChange(message) {
                window.toast(message, 'success');
                await Promise.all([this.loadAccess(), this.fetchItems()]);
            },

            async saveMembershipRole(membership) {
                if (membership.busy || membership.selected_role_id === membership.role_id) return;
                membership.busy = true;
                membership.error = '';
                try {
                    const { data } = await axios.put(
                        `/users/${this.accessTarget.id}/stores/${membership.store_id}/role`,
                        { role_id: membership.selected_role_id },
                    );
                    await this.afterAccessChange(data.message);
                } catch (error) {
                    membership.error = error.response?.data?.errors?.role_id?.[0] ?? error.response?.data?.message ?? 'Could not change the role.';
                } finally {
                    membership.busy = false;
                }
            },

            askRemove(membership) {
                this.removing = membership;
                this.removePassword = '';
                this.removePasswordError = '';
            },

            /* Taking someone out of a store is a big delete: it asks for the password. */
            async removeMembership() {
                if (!this.removing || this.removingBusy) return;
                if (!this.removePassword) {
                    this.removePasswordError = 'Password is required.';
                    return;
                }
                this.removingBusy = true;
                this.removePasswordError = '';
                try {
                    const { data } = await axios.delete(
                        `/users/${this.accessTarget.id}/stores/${this.removing.store_id}`,
                        { data: { password: this.removePassword } },
                    );
                    this.removing = null;
                    await this.afterAccessChange(data.message);
                } catch (error) {
                    const passwordError = error.response?.status === 422 ? error.response.data.errors?.password?.[0] : null;
                    if (passwordError) {
                        this.removePasswordError = passwordError;
                    } else {
                        window.toast(error.response?.data?.message ?? 'Could not remove them from the store.');
                    }
                } finally {
                    this.removingBusy = false;
                }
            },

            async assignToStore() {
                if (this.assigning) return;

                const errors = validate(this.assignForm, { store_id: [required('Store')], role_id: [required('Role')] });
                if (Object.keys(errors).length) {
                    this.assignErrors = errors;
                    return;
                }

                this.assigning = true;
                this.assignErrors = {};
                try {
                    const { data } = await axios.post(`/users/${this.accessTarget.id}/stores`, {
                        store_id: Number(this.assignForm.store_id),
                        role_id: Number(this.assignForm.role_id),
                    });
                    this.assignForm = { store_id: '', role_id: '' };
                    await this.afterAccessChange(data.message);
                } catch (error) {
                    if (error.response?.status === 422 && error.response.data.errors) {
                        this.assignErrors = error.response.data.errors;
                    } else {
                        window.toast(error.response?.data?.message ?? 'Could not add them to the store.');
                    }
                } finally {
                    this.assigning = false;
                }
            },

            /* ── Log in as ─────────────────────────────────────────────── */

            async impersonate(user) {
                if (this.impersonating) return;
                this.impersonating = true;
                try {
                    await axios.post(`/users/${user.id}/impersonate`);
                    // Stays true on success: the button remains dead while the browser moves on.
                    window.location.href = '/dashboard';
                } catch (error) {
                    window.toast(error.response?.data?.message || 'Could not log in as this person.');
                    this.impersonating = false;
                }
            },

            /* ── Delete an account ─────────────────────────────────────── */

            /* Deleting an account and taking a platform role are big deletes: both ask for the password. */
            async deleteItem() {
                if (!this.selectedItem || this.deleting) return;
                if (!this.deletePassword) {
                    this.deletePasswordError = 'Password is required.';
                    return;
                }
                this.deleting = true;
                this.deletePasswordError = '';
                try {
                    const { data } = await axios.delete(`/users/${this.selectedItem.id}`, { data: { password: this.deletePassword } });
                    this.$dispatch('close-modal', 'confirm-account-deletion');
                    this.selectedItem = null;
                    window.toast(data.ownerless_stores?.length
                        ? `${data.message} Now without an owner: ${data.ownerless_stores.join(', ')}.`
                        : data.message, 'success');
                    await this.fetchItems();
                    if (this.items.length === 0 && this.currentPage > 1) {
                        this.currentPage--;
                        await this.fetchItems();
                    }
                } catch (error) {
                    const passwordError = error.response?.status === 422 ? error.response.data.errors?.password?.[0] : null;
                    if (passwordError) {
                        this.deletePasswordError = passwordError;
                    } else {
                        window.toast(error.response?.data?.message ?? 'Could not delete this account.');
                    }
                } finally {
                    this.deleting = false;
                }
            },

            /* ── Remove a platform role ────────────────────────────────── */

            confirmRemoveRole(user) {
                this.selectedForRole = user;
                this.rolePassword = '';
                this.rolePasswordError = '';
                this.$dispatch('open-modal', 'confirm-remove-platform-role');
            },

            async removeRole() {
                if (!this.selectedForRole || this.removingRole) return;
                if (!this.rolePassword) {
                    this.rolePasswordError = 'Password is required.';
                    return;
                }
                this.removingRole = true;
                this.rolePasswordError = '';
                try {
                    const { data } = await axios.delete(`/users/${this.selectedForRole.id}/platform-role`, { data: { password: this.rolePassword } });
                    this.$dispatch('close-modal', 'confirm-remove-platform-role');
                    window.toast(data.message, 'success');
                    await this.fetchItems();
                } catch (error) {
                    const passwordError = error.response?.status === 422 ? error.response.data.errors?.password?.[0] : null;
                    if (passwordError) {
                        this.rolePasswordError = passwordError;
                    } else {
                        window.toast(error.response?.data?.message ?? 'Could not remove the platform role.');
                    }
                } finally {
                    this.removingRole = false;
                }
            },

            /* ── Platform team invitations ─────────────────────────────── */

            async fetchInvitations() {
                try {
                    const { data } = await axios.get('/users/invitations');
                    this.invitations = data.invitations;
                } catch (error) {
                    this.invitations = [];
                }
            },

            expiresText(invitation) {
                if (invitation.is_expired) return 'Expired';
                const days = Math.ceil((new Date(invitation.expires_at) - new Date()) / 86400000);

                return days <= 1 ? 'Expires today' : `Expires in ${days} days`;
            },

            openInvite() {
                this.formErrors = {};
                this.inviteForm = { email: '', role_id: '' };
                this.$dispatch('open-modal', 'invite-platform-member');
            },

            async sendInvite() {
                if (this.inviting) return;

                const errors = validate(this.inviteForm, {
                    email: [required('Email'), emailFormat('Email'), maxLen('Email', 255)],
                    role_id: [required('Role')],
                });
                if (Object.keys(errors).length) {
                    this.formErrors = errors;
                    return;
                }

                this.inviting = true;
                this.formErrors = {};
                try {
                    const { data } = await axios.post('/users/invitations', this.inviteForm);
                    this.$dispatch('close-modal', 'invite-platform-member');
                    window.toast(data.message, data.email_sent === false ? 'error' : 'success');
                    await this.fetchInvitations();
                } catch (error) {
                    if (error.response?.status === 422 && error.response.data.errors) {
                        this.formErrors = error.response.data.errors;
                    } else {
                        window.toast(error.response?.data?.message ?? 'Could not send the invitation.');
                    }
                } finally {
                    this.inviting = false;
                }
            },

            async resendInvitation(invitation) {
                if (this.busyInvitationId) return;
                this.busyInvitationId = invitation.id;
                try {
                    const { data } = await axios.post(`/users/invitations/${invitation.id}/resend`);
                    window.toast(data.message, data.email_sent === false ? 'error' : 'success');
                    await this.fetchInvitations();
                } catch (error) {
                    window.toast(error.response?.data?.message ?? 'Could not resend the invitation.');
                } finally {
                    this.busyInvitationId = null;
                }
            },

            confirmRevoke(invitation) {
                this.selectedInvitation = invitation;
                this.$dispatch('open-modal', 'confirm-revoke-platform-invitation');
            },

            async revokeInvitation() {
                if (!this.selectedInvitation || this.revoking) return;
                this.revoking = true;
                try {
                    const { data } = await axios.delete(`/users/invitations/${this.selectedInvitation.id}`);
                    this.$dispatch('close-modal', 'confirm-revoke-platform-invitation');
                    window.toast(data.message, 'success');
                    await this.fetchInvitations();
                } catch (error) {
                    window.toast(error.response?.data?.message ?? 'Could not revoke the invitation.');
                } finally {
                    this.revoking = false;
                }
            },
        },
    })());
}
