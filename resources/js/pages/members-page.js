/**
 * The Members page (resources/views/members/index.blade.php): the current store's team and its
 * open invitations. What may be done to each row comes from the server (`can_manage`, the
 * assignable roles), so the page never offers an action MemberController would refuse.
 */
import axios from 'axios';
import { validate, required, emailFormat, maxLen } from '../core/validate.js';

export function registerMembersPage(Alpine) {
    Alpine.data('membersPage', (config = {}) => ({
        storeName: config.storeName ?? '',

        loading: true,
        members: [],
        invitations: [],
        assignableRoles: [],
        ownerCount: 0,
        search: '',
        tab: 'members',

        formErrors: {},
        inviteForm: { email: '', role_id: '' },
        inviting: false,

        selectedMember: null,
        roleForm: { role_id: '' },
        changingRole: false,
        removing: false,
        removePassword: '',
        removePasswordError: '',

        selectedInvitation: null,
        busyInvitationId: null,
        revoking: false,
        leaving: false,

        init() {
            this.fetch();
        },

        async fetch() {
            this.loading = true;
            try {
                const { data } = await axios.get('/members/data');
                this.members = data.members;
                this.invitations = data.invitations;
                this.assignableRoles = data.assignable_roles;
                this.ownerCount = data.owner_count;
            } catch (error) {
                window.toast(error.response?.data?.message ?? 'Could not load the team. Please refresh the page.');
            } finally {
                this.loading = false;
            }
        },

        /* ── Lists ─────────────────────────────────────────────────────── */

        filteredMembers() {
            const term = this.search.trim().toLowerCase();
            if (!term) return this.members;

            return this.members.filter((member) =>
                member.name.toLowerCase().includes(term) || member.email.toLowerCase().includes(term));
        },

        initials(name) {
            return (name || '?').split(/\s+/).filter(Boolean).slice(0, 2).map((part) => Array.from(part)[0].toUpperCase()).join('');
        },

        roleBadgeClass(role) {
            if (role?.key === 'owner') return 'badge-warning';
            if (role?.key === 'admin') return 'badge-info';

            return 'badge-neutral';
        },

        formatDate(iso) {
            return iso ? new Date(iso).toLocaleDateString(undefined, { year: 'numeric', month: 'short', day: 'numeric' }) : '—';
        },

        expiresText(invitation) {
            if (invitation.is_expired) return 'Expired';

            const days = Math.ceil((new Date(invitation.expires_at) - new Date()) / 86400000);

            return days <= 1 ? 'Expires today' : `Expires in ${days} days`;
        },

        /* ── Invite ────────────────────────────────────────────────────── */

        openInvite() {
            this.formErrors = {};
            this.inviteForm = { email: '', role_id: '' };
            this.$dispatch('open-modal', 'invite-member');
        },

        /** The description under the role picked in a picker. */
        roleDescription(roleId) {
            return this.assignableRoles.find((role) => String(role.id) === String(roleId))?.description ?? '';
        },

        async sendInvite() {
            if (this.inviting) return;

            /* Mirrors InvitationController — the server checks it all again. */
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
                const { data } = await axios.post('/members/invitations', this.inviteForm);
                this.$dispatch('close-modal', 'invite-member');
                window.toast(data.message, data.email_sent === false ? 'error' : 'success');
                this.tab = 'invitations';
                await this.fetch();
            } catch (error) {
                this.handleError(error);
            } finally {
                this.inviting = false;
            }
        },

        async resend(invitation) {
            if (this.busyInvitationId) return;
            this.busyInvitationId = invitation.id;
            try {
                const { data } = await axios.post(`/members/invitations/${invitation.id}/resend`);
                window.toast(data.message, data.email_sent === false ? 'error' : 'success');
                await this.fetch();
            } catch (error) {
                window.toast(error.response?.data?.message ?? 'Could not resend the invitation.');
            } finally {
                this.busyInvitationId = null;
            }
        },

        confirmRevoke(invitation) {
            this.selectedInvitation = invitation;
            this.$dispatch('open-modal', 'revoke-invitation');
        },

        async revoke() {
            if (!this.selectedInvitation || this.revoking) return;
            this.revoking = true;
            try {
                const { data } = await axios.delete(`/members/invitations/${this.selectedInvitation.id}`);
                this.$dispatch('close-modal', 'revoke-invitation');
                window.toast(data.message, 'success');
                await this.fetch();
            } catch (error) {
                window.toast(error.response?.data?.message ?? 'Could not revoke the invitation.');
            } finally {
                this.revoking = false;
            }
        },

        /* ── Change role / remove ──────────────────────────────────────── */

        openChangeRole(member) {
            this.selectedMember = member;
            this.formErrors = {};
            this.roleForm = { role_id: member.role?.id ?? '' };
            this.$dispatch('open-modal', 'change-member-role');
        },

        async saveRole() {
            if (!this.selectedMember || this.changingRole) return;

            const errors = validate(this.roleForm, { role_id: [required('Role')] });
            if (Object.keys(errors).length) {
                this.formErrors = errors;
                return;
            }

            this.changingRole = true;
            this.formErrors = {};
            try {
                const { data } = await axios.put(`/members/${this.selectedMember.id}`, this.roleForm);
                this.$dispatch('close-modal', 'change-member-role');
                window.toast(data.message, 'success');
                await this.fetch();
            } catch (error) {
                this.handleError(error);
            } finally {
                this.changingRole = false;
            }
        },

        confirmRemove(member) {
            this.selectedMember = member;
            this.removePassword = '';
            this.removePasswordError = '';
            this.$dispatch('open-modal', 'remove-member');
        },

        /* Removing somebody is a big delete: it asks for the password (ConfirmsPassword on the server). */
        async removeMember() {
            if (!this.selectedMember || this.removing) return;
            if (!this.removePassword) {
                this.removePasswordError = 'Password is required.';
                return;
            }
            this.removing = true;
            this.removePasswordError = '';
            try {
                const { data } = await axios.delete(`/members/${this.selectedMember.id}`, { data: { password: this.removePassword } });
                this.$dispatch('close-modal', 'remove-member');
                window.toast(data.message, 'success');
                await this.fetch();
            } catch (error) {
                const passwordError = error.response?.status === 422 ? error.response.data.errors?.password?.[0] : null;
                if (passwordError) {
                    this.removePasswordError = passwordError;
                } else {
                    window.toast(error.response?.data?.message ?? 'Could not remove this member.');
                }
            } finally {
                this.removing = false;
            }
        },

        /* ── Leave ─────────────────────────────────────────────────────── */

        /** The only Owner cannot leave (rule 10) — the button explains instead of failing. */
        isSoleOwner() {
            const me = this.members.find((member) => member.is_you);

            return me?.role?.key === 'owner' && this.ownerCount === 1;
        },

        async leave() {
            if (this.leaving) return;
            this.leaving = true;
            try {
                const { data } = await axios.post('/members/leave');
                window.location.href = data.redirect;
            } catch (error) {
                window.toast(error.response?.data?.message ?? 'Could not leave this store.');
                this.leaving = false;
            }
        },

        /** Field errors go under their fields; anything else becomes a toast. */
        handleError(error) {
            if (error.response?.status === 422 && error.response.data.errors) {
                this.formErrors = error.response.data.errors;
            } else {
                window.toast(error.response?.data?.message ?? 'Something went wrong. Please try again.');
            }
        },
    }));
}
