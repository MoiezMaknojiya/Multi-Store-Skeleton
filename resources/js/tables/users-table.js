/**
 * Users table — accounts (docs/ORGANIZATION-SPEC.md rules 13, 19, 21, 24): every account, from the
 * platform's side alone — an organization's own people are its Members page (owner's rule, 2026-09-17). Nobody is
 * created or edited here: people join by invitation and keep their own details. What each row allows comes
 * from the server (`can`), so no button can mislead.
 */
import axios from 'axios';
import { expiresInWords } from '../core/clock.js';
import { createCrudTable } from '../core/crud-table-base.js';
import { isARepeatPress } from '../core/click-beside.js';
import { formStillAsked, notSavedWords } from '../core/form-asked.js';
import { passwordRefusal } from '../core/password-refusal.js';
import { validate, required, emailFormat, maxLen } from '../core/validate.js';

export function registerUsersTable(Alpine) {
    Alpine.data('usersTable', (config = {}) => createCrudTable({
        fetchUrl: '/users/data',
        dataKey: 'users',
        entityLabel: 'account',
        addressKeys: { group: ['group', ['', 'platform', 'organization']] },
        deleteModalName: 'confirm-account-deletion',
        // Newest accounts first; Account and Joined sort it (UserController::sortedBy, owner 2026-10-07).
        sortable: { by: 'joined', direction: 'desc' },
        extraState: {
            isSuperAdmin: config.isSuperAdmin ?? false,
            impersonating: false,
            /* The row whose Log In As is on its way: its button shows the spinner while the browser moves on. */
            impersonatingId: null,

            /* The super admin's tabs: '' every account, 'platform' the platform team, 'organization' everybody else —
             * and how many each holds, from the listing's own answer. */
            group: '',
            counts: null,

            /* Manage organizations, from the platform (super admins): a person's organizations, roles, and adding them to more. */
            accessTarget: null,
            // Moves each time the dialog opens: an answer about one account never acts on the dialog opened since for
            // another (or again) — its lists, its choices, its refusals.
            accessTicket: 0,
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

            /* The platform team's open invitations — super admins only — and whether they are on their way or could
             * not be loaded (said in the card, never "No invitations are waiting"). */
            invitations: [],
            invitationsLoading: true,
            invitationsFailed: false,
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
                this.accessTicket++;
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

            /** The dialog's lists — written only while it is the opening they were asked for: one opened since loads its own. */
            async loadAccess() {
                const ticket = this.accessTicket;
                const { data } = await axios.get(`/users/${this.accessTarget.id}/organizations`);
                if (ticket !== this.accessTicket) return;
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

            /* After any change: the row's badges in the table behind the dialog, and the dialog's lists while it is still the
             * one the change was made from (`asked`) — reloaded under a dialog opened since, they threw away the roles
             * picked there and not yet saved. One opened again for the same `account`, with no role picked there yet, is
             * brought up to date: its own lists may have been read before the change. */
            async afterAccessChange(message, asked = () => true, account = null) {
                window.toast(message, 'success');
                const reload = asked() || (account !== null && this.accessTarget?.id === account.id && !this.rolesPicked());
                await Promise.all([reload ? this.loadAccess() : null, this.refreshInPlace()]);
            },

            /** Whether a role is picked on a membership and not saved yet. */
            rolesPicked() {
                return this.access.memberships.some((membership) => membership.selected_role_id !== membership.role_id);
            },

            /** Is the dialog still the opening the press was made in? `press` is the control pressed: shut since, it has no
             *  place on the screen, and a refusal is said in a toast rather than in a dialog nobody sees. */
            accessStillAsked(press = null) {
                return formStillAsked(() => this.accessTicket, press);
            },

            async saveMembershipRole(membership, event = null) {
                if (membership.busy || membership.selected_role_id === membership.role_id) return;
                membership.busy = true;
                membership.error = '';
                const asked = this.accessStillAsked(event?.currentTarget ?? null);
                const account = this.accessTarget;
                const named = `${account.name}'s role in ${membership.organization_name}`;
                try {
                    const { data } = await axios.put(
                        `/users/${account.id}/organizations/${membership.organization_id}/role`,
                        { role_id: membership.selected_role_id },
                    );
                    await this.afterAccessChange(data.message, asked, account);
                } catch (error) {
                    const reason = error.response?.data?.errors?.role_id?.[0] ?? error.response?.data?.message ?? 'Could not change the role.';
                    if (asked()) membership.error = reason;
                    else window.toast(notSavedWords({ role_id: [reason] }, named));
                } finally {
                    membership.busy = false;
                }
            },

            /* The question opens further down the dialog: brought into sight, the keyboard on its password. */
            askRemove(membership) {
                this.removing = membership;
                this.removePassword = '';
                this.removePasswordError = '';
                this.$nextTick(() => {
                    const field = document.getElementById('remove-membership-password');
                    field?.scrollIntoView({ block: 'nearest' });
                    field?.focus({ preventScroll: true });
                });
            },

            /* Cancel: the question goes, and the keyboard is back on the Remove that asked it. */
            keepMembership() {
                const asked = this.removing?.organization_id;
                this.removing = null;
                this.$nextTick(() => document.querySelector(`[dusk="membership-remove-${asked}"]`)?.focus());
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
                const dialogAsked = this.accessStillAsked();
                const account = this.accessTarget;
                let said;
                try {
                    const { data } = await axios.delete(
                        `/users/${account.id}/organizations/${target.organization_id}`,
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
                await this.afterAccessChange(said, dialogAsked, account);
            },

            async assignToOrganization(event = null) {
                if (this.assigning) return;

                const errors = validate(this.assignForm, { organization_id: [required('Organization')], role_id: [required('Role')] });
                if (Object.keys(errors).length) {
                    this.assignErrors = errors;
                    return;
                }

                this.assigning = true;
                this.assignErrors = {};
                // The answer acts on this form alone: shut since, or opened again for another account, its choices and its
                // fields are not touched by it.
                const asked = this.accessStillAsked(event?.target ?? null);
                const account = this.accessTarget;
                try {
                    const { data } = await axios.post(`/users/${account.id}/organizations`, {
                        organization_id: Number(this.assignForm.organization_id),
                        role_id: Number(this.assignForm.role_id),
                    });
                    if (asked()) this.assignForm = { organization_id: '', role_id: '' };
                    await this.afterAccessChange(data.message, asked, account);
                } catch (error) {
                    const refused = error.response?.status === 422 ? (error.response.data?.errors ?? null) : null;
                    if (refused && asked()) {
                        this.assignErrors = refused;
                    } else {
                        window.toast(notSavedWords(refused, account.name, 'added') ?? error.response?.data?.message ?? 'Could not add them to the organization.');
                    }
                } finally {
                    this.assigning = false;
                }
            },

            /* ── Log in as ─────────────────────────────────────────────── */

            async impersonate(user) {
                if (this.impersonating) return;
                this.impersonating = true;
                this.impersonatingId = user.id;
                try {
                    const { data } = await axios.post(`/users/${user.id}/impersonate`);
                    // Stays true on success: the button remains dead while the browser moves on — to the dashboard,
                    // or to "Check your inbox" for an account that has not confirmed its email.
                    window.location.href = data?.redirect ?? '/dashboard';
                } catch (error) {
                    window.toast(error.response?.data?.message || 'Could not log in as this person.');
                    this.impersonating = false;
                    this.impersonatingId = null;
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
                this.invitationsFailed = false;
                try {
                    const { data } = await axios.get('/users/invitations');
                    this.invitations = data.invitations;
                } catch {
                    // A refresh after a change keeps the rows it has; the first load says so in the card.
                    if (this.invitations.length === 0) this.invitationsFailed = true;
                    else window.toast('Could not bring the invitations up to date.');
                } finally {
                    this.invitationsLoading = false;
                }
            },

            expiresText(invitation) {
                if (invitation.is_expired) return 'Expired';

                return expiresInWords(invitation.expires_at);
            },

            openInvite() {
                this.formTicket++;
                this.formErrors = {};
                this.inviteForm = { email: '', role_id: '' };
                this.$dispatch('open-modal', 'invite-platform-member');
            },

            async sendInvite(event = null) {
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
                // The answer acts on this form alone: shut since, or opened again, it is not shut or marked by it.
                const asked = formStillAsked(() => this.formTicket, event?.target);
                const named = `The invitation to ${this.inviteForm.email}`;
                try {
                    const { data } = await axios.post('/users/invitations', this.inviteForm);
                    if (asked()) this.$dispatch('close-modal', 'invite-platform-member');
                    window.toast(data.message, data.email_sent === false ? 'error' : 'success');
                    this.fetchInvitations();  // not awaited: the button is free again the moment the request is over
                } catch (error) {
                    const refused = error.response?.status === 422 ? (error.response.data?.errors ?? null) : null;
                    if (refused && asked()) {
                        this.formErrors = refused;
                    } else {
                        window.toast(notSavedWords(refused, named, 'sent') ?? error.response?.data?.message ?? 'Could not send the invitation.');
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
                // A late answer never shuts the question about another invitation (crud-table-base's deleteItem says why).
                const target = this.selectedInvitation.id;
                try {
                    const { data } = await axios.delete(`/users/invitations/${target}`);
                    if (this.selectedInvitation?.id === target) this.$dispatch('close-modal', 'confirm-revoke-platform-invitation');
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
