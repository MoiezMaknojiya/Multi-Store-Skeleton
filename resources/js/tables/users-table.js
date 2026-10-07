/**
 * Users table — accounts (docs/ORGANIZATION-SPEC.md rules 13, 19, 21, 24): every account, from the
 * platform's side alone — an organization's own people are its Members page (owner's rule, 2026-09-17). Nobody is
 * created or edited here: people join by invitation and keep their own details. What each row allows comes
 * from the server (`can`), so no button can mislead.
 */
import axios from 'axios';
import { createCrudTable } from '../core/crud-table-base.js';
import { isARepeatPress } from '../core/click-beside.js';
import { passwordRefusal } from '../core/password-refusal.js';
import { validate, required, emailFormat, maxLen } from '../core/validate.js';

export function registerUsersTable(Alpine) {
    Alpine.data('usersTable', (config = {}) => createCrudTable({
        fetchUrl: '/users/data',
        dataKey: 'users',
        entityLabel: 'account',
        deleteModalName: 'confirm-account-deletion',
        // Newest accounts first; Account and Joined sort it (UserController::sortedBy, owner 2026-10-07).
        sortable: { by: 'joined', direction: 'desc' },
        extraState: {
            isSuperAdmin: config.isSuperAdmin ?? false,
            impersonating: false,

            /* The super admin's tabs: '' every account, 'platform' the platform team, 'organization' everybody else —
             * and how many each holds, from the listing's own answer. */
            group: '',
            counts: null,

            /* Manage organizations, from the platform (super admins): a person's organizations, roles, and adding them to more. */
            accessTarget: null,
            access: { memberships: [], organizations: [], organization_roles: [], custom_roles: {} },
            loadingAccess: false,
            assignForm: { organization_id: '', role_id: '' },
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

            extraParams() {
                return this.group ? { group: this.group } : {};
            },

            afterFetch(data) {
                this.counts = data.counts ?? null;
            },

            showGroup(group) {
                if (this.group === group) return;
                this.group = group;
                this.currentPage = 1;
                this.fetchItems();
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
                return `${membership.role_name} · ${membership.organization_name}`;
            },

            /* ── Manage organizations, from the platform ──────────────────────── */

            async openManageOrganizations(user) {
                if (this.loadingAccess) return;
                this.loadingAccess = true;
                try {
                    this.accessTarget = user;
                    this.removing = null;
                    this.assignForm = { organization_id: '', role_id: '' };
                    this.assignErrors = {};
                    await this.loadAccess();
                    this.$dispatch('open-modal', 'manage-organizations');
                } catch (error) {
                    window.toast(error.response?.data?.message ?? 'Could not load their organizations.');
                } finally {
                    this.loadingAccess = false;
                }
            },

            async loadAccess() {
                const { data } = await axios.get(`/users/${this.accessTarget.id}/organizations`);
                this.access = {
                    ...data,
                    // Each row keeps the role picked on screen apart from the one saved, until Save.
                    memberships: data.memberships.map((membership) => ({ ...membership, selected_role_id: membership.role_id, busy: false, error: '' })),
                };
            },

            /** The roles an organization offers: every organization role, then that organization's own custom roles. */
            rolesFor(organizationId) {
                if (!organizationId) return [];

                return [...this.access.organization_roles, ...(this.access.custom_roles?.[organizationId] ?? [])];
            },

            roleDescription(organizationId, roleId) {
                return this.rolesFor(organizationId).find((role) => Number(role.id) === Number(roleId))?.description ?? '';
            },

            /* After any change: this modal's lists, and the row's badges in the table behind it. */
            async afterAccessChange(message) {
                window.toast(message, 'success');
                await Promise.all([this.loadAccess(), this.refreshInPlace()]);
            },

            async saveMembershipRole(membership) {
                if (membership.busy || membership.selected_role_id === membership.role_id) return;
                membership.busy = true;
                membership.error = '';
                try {
                    const { data } = await axios.put(
                        `/users/${this.accessTarget.id}/organizations/${membership.organization_id}/role`,
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

            /* Taking someone out of an organization is a big delete: it asks for the password. */
            async removeMembership() {
                if (!this.removing || this.removingBusy) return;
                if (!this.removePassword) {
                    this.removePasswordError = 'Password is required.';
                    return;
                }
                this.removingBusy = true;
                this.removePasswordError = '';
                // A late answer never shuts the question about another organization (crud-table-base's deleteItem).
                const target = this.removing;
                const stillAsked = () => this.removing === target;
                let said;
                try {
                    const { data } = await axios.delete(
                        `/users/${this.accessTarget.id}/organizations/${target.organization_id}`,
                        { data: { password: this.removePassword } },
                    );
                    said = data.message;
                } catch (error) {
                    if (error.response?.status !== 404) {
                        const passwordError = passwordRefusal(error);
                        if (passwordError && stillAsked()) {
                            this.removePasswordError = passwordError;
                        } else {
                            window.toast(passwordError ?? error.response?.data?.message ?? 'Could not remove them from the organization.');
                        }
                        return;
                    }
                    said = 'They are already out of that organization.';
                } finally {
                    // Down when the request is over, not after the lists are brought up to date (crud-table-base's deleteItem).
                    this.removingBusy = false;
                }

                if (stillAsked()) this.removing = null;
                await this.afterAccessChange(said);
            },

            async assignToOrganization() {
                if (this.assigning) return;

                const errors = validate(this.assignForm, { organization_id: [required('Organization')], role_id: [required('Role')] });
                if (Object.keys(errors).length) {
                    this.assignErrors = errors;
                    return;
                }

                this.assigning = true;
                this.assignErrors = {};
                try {
                    const { data } = await axios.post(`/users/${this.accessTarget.id}/organizations`, {
                        organization_id: Number(this.assignForm.organization_id),
                        role_id: Number(this.assignForm.role_id),
                    });
                    this.assignForm = { organization_id: '', role_id: '' };
                    await this.afterAccessChange(data.message);
                } catch (error) {
                    if (error.response?.status === 422 && error.response.data.errors) {
                        this.assignErrors = error.response.data.errors;
                    } else {
                        window.toast(error.response?.data?.message ?? 'Could not add them to the organization.');
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
                    const { data } = await axios.post(`/users/${user.id}/impersonate`);
                    // Stays true on success: the button remains dead while the browser moves on — to the dashboard,
                    // or to "Check your inbox" for an account that has not confirmed its email.
                    window.location.href = data?.redirect ?? '/dashboard';
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
                // A late answer never shuts the question about another account (crud-table-base's deleteItem says why).
                const gone = this.selectedItem.id;
                const stillAsked = () => this.selectedItem?.id === gone;
                const forget = () => {
                    this.items = this.items.filter((item) => item.id !== gone);
                    if (!stillAsked()) return;
                    this.$dispatch('close-modal', 'confirm-account-deletion');
                    this.selectedItem = null;
                };
                let said;
                try {
                    const { data } = await axios.delete(`/users/${gone}`, { data: { password: this.deletePassword } });
                    said = data.ownerless_organizations?.length
                        ? `${data.message} Now without an owner: ${data.ownerless_organizations.join(', ')}.`
                        : data.message;
                } catch (error) {
                    if (error.response?.status !== 404) {
                        const passwordError = passwordRefusal(error);
                        if (passwordError && stillAsked()) {
                            this.deletePasswordError = passwordError;
                        } else {
                            window.toast(passwordError ?? error.response?.data?.message ?? 'Could not delete this account.');
                        }
                        return;
                    }
                    said = 'That account is already gone.';
                } finally {
                    // Down when the request is over, not after the list is brought up to date (crud-table-base's deleteItem).
                    this.deleting = false;
                }

                forget();
                window.toast(said, 'success');
                await this.refreshInPlace();
                if (this.items.length === 0 && this.currentPage > 1) {
                    this.currentPage--;
                    await this.fetchItems();
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
                // A late answer never shuts the question about somebody else (crud-table-base's deleteItem says why).
                const target = this.selectedForRole.id;
                const stillAsked = () => this.selectedForRole?.id === target;
                let said;
                try {
                    const { data } = await axios.delete(`/users/${target}/platform-role`, { data: { password: this.rolePassword } });
                    said = data.message;
                } catch (error) {
                    if (error.response?.status !== 404) {
                        const passwordError = passwordRefusal(error);
                        if (passwordError && stillAsked()) {
                            this.rolePasswordError = passwordError;
                        } else {
                            window.toast(passwordError ?? error.response?.data?.message ?? 'Could not remove the platform role.');
                        }
                        return;
                    }
                    said = 'That account is already gone.';
                } finally {
                    // Down when the request is over, not after the list is brought up to date (crud-table-base's deleteItem).
                    this.removingRole = false;
                }

                if (stillAsked()) this.$dispatch('close-modal', 'confirm-remove-platform-role');
                window.toast(said, 'success');
                await this.refreshInPlace();
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
                    this.fetchInvitations();  // not awaited: the button is free again the moment the request is over
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

            async resendInvitation(invitation, event) {
                if (this.busyInvitationId || isARepeatPress(event)) return;
                this.busyInvitationId = invitation.id;
                try {
                    const { data } = await axios.post(`/users/invitations/${invitation.id}/resend`);
                    window.toast(data.message, data.email_sent === false ? 'error' : 'success');
                    this.fetchInvitations();  // not awaited: the button is free again the moment the request is over
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
                    this.fetchInvitations();  // not awaited: the button is free again the moment the request is over
                } catch (error) {
                    window.toast(error.response?.data?.message ?? 'Could not revoke the invitation.');
                } finally {
                    this.revoking = false;
                }
            },
        },
    })());
}
