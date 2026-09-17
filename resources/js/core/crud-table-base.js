/**
 * Factory function for CRUD table Alpine.js components.
 * Provides shared pagination, debounced search, fetch, save, and delete logic.
 * The listings built on it — accounts, stores, permissions, the activity log, media, screens, dayparts,
 * campaigns and channels — add their own form shape and overrides (roles, playlists and channel ads have
 * their own components instead).
 */
import axios from 'axios';

/**
 * Rows per page, for every listing in the app.
 *
 * One number in one place on purpose: the tables used to disagree — ten here,
 * twenty-five there, a hundred somewhere else — and a person moving between them had
 * no idea whether a short list meant "that is all there is" or "there is more below".
 * Mirrored by HandlesCrudData's own default so the server agrees when a client sends
 * no per_page at all.
 */
export const ROWS_PER_PAGE = 50;

export function createCrudTable({
    fetchUrl,            // API endpoint for listing, e.g. '/stores/data'
    dataKey,             // JSON response key holding the items array, e.g. 'stores'
    entityLabel,         // Human-readable name for error messages, e.g. 'store'

    formModalName,       // Modal name for create/edit form
    deleteModalName,     // Modal name for delete confirmation
    perPage = ROWS_PER_PAGE,  // Items per page — see the constant above
    defaultForm = {},    // Default empty form shape
    mapItemToForm = null,// Function: (item) => form object for editing
    validateForm = null, // Function: (form, editingItem) => errors object ({ field: ['msg'] });
                         // non-empty result blocks the request and shows the errors instead
    onSaved = null,      // Function: (responseData, wasEditing) => void, after a successful save —
                         // for a save whose outcome the refreshed row cannot show (an email sent)
    deleteNeedsPassword = false, // A big delete re-confirms the actor's password (ConfirmsPassword on the
                         // server; the modal uses <x-crud.password-confirm>)
    extraState = {},     // Additional reactive properties specific to the entity
    extraMethods = {},   // Additional methods specific to the entity
}) {
    return () => ({
        /* ── Shared reactive state ─────────────────────────────────────── */
        search: '',
        currentPage: 1,
        perPage,
        total: 0,
        lastPage: 1,
        items: [],
        loading: true,
        saving: false,
        deleting: false,
        deletePassword: '',
        deletePasswordError: '',
        selectedItem: null,
        editingItem: null,
        form: { ...defaultForm },
        formErrors: {},
        searchDebounceTimer: null,
        fetchToken: 0,

        /* Merge entity-specific state */
        ...extraState,

        /* ── Lifecycle ─────────────────────────────────────────────────── */
        init() {
            this.fetchItems();
            this.$watch('search', () => {
                if (this.searchDebounceTimer) clearTimeout(this.searchDebounceTimer);
                this.searchDebounceTimer = setTimeout(() => {
                    this.currentPage = 1;
                    this.fetchItems();
                }, 400);
            });
            /* Call entity-specific init hook if provided */
            if (typeof this.onInit === 'function') this.onInit();
        },

        /* ── Data fetching ─────────────────────────────────────────────── */
        async fetchItems() {
            /* Rapid pagination/search can fire overlapping requests; only the response
             * matching the most recently issued request is allowed to update state, so a
             * slow older response can't overwrite a newer one that resolved first. */
            const requestToken = ++this.fetchToken;
            this.items = [];
            this.loading = true;
            try {
                const params = { search: this.search, page: this.currentPage, per_page: this.perPage };
                /* Entity-specific listing filters (e.g. the activity log's date range). */
                if (typeof this.extraParams === 'function') Object.assign(params, this.extraParams());
                const response = await axios.get(fetchUrl, { params });
                if (requestToken !== this.fetchToken) return;
                const data = response.data;
                this.items = data[dataKey];
                this.total = data.total;
                this.currentPage = data.currentPage;
                this.lastPage = data.lastPage;
            } catch (error) {
                if (requestToken !== this.fetchToken) return;
                console.error(`Failed to fetch ${entityLabel}s:`, error);
                this.items = [];
            } finally {
                if (requestToken === this.fetchToken) this.loading = false;
            }
        },

        /* ── Pagination ────────────────────────────────────────────────── */
        get endItem() {
            return Math.min(this.currentPage * this.perPage, this.total);
        },

        next() {
            if (this.currentPage < this.lastPage) {
                this.currentPage++;
                this.fetchItems();
            }
        },

        prev() {
            if (this.currentPage > 1) {
                this.currentPage--;
                this.fetchItems();
            }
        },

        goTo(page) {
            if (page >= 1 && page <= this.lastPage && page !== this.currentPage) {
                this.currentPage = page;
                this.fetchItems();
            }
        },

        /* ── Form modal open/close ─────────────────────────────────────── */
        openFormModal(item = null) {
            this.editingItem = item;
            this.formErrors = {};
            this.form = item && mapItemToForm
                ? mapItemToForm(item)
                : { ...defaultForm };
            this.$dispatch('open-modal', formModalName);
        },

        closeFormModal() {
            this.$dispatch('close-modal', formModalName);
            this.editingItem = null;
            this.formErrors = {};
        },

        /* ── Save (create or update) ───────────────────────────────────── */
        async saveItem() {
            if (this.saving) return;

            /* Client-side pre-validation: obviously-invalid input never reaches the
             * server. The backend still validates everything — this only saves the trip. */
            if (validateForm) {
                const errors = validateForm(this.form, this.editingItem);
                if (Object.keys(errors).length > 0) {
                    this.formErrors = errors;
                    return;
                }
            }
            this.formErrors = {};
            this.saving = true;
            try {
                const baseUrl = fetchUrl.replace('/data', '');
                const wasEditing = Boolean(this.editingItem);
                const { data } = wasEditing
                    ? await axios.put(`${baseUrl}/${this.editingItem.id}`, this.form)
                    : await axios.post(baseUrl, this.form);
                this.closeFormModal();
                if (onSaved) onSaved(data, wasEditing);
                await this.fetchItems();
            } catch (error) {
                if (error.response?.status === 422 && error.response.data.errors) {
                    this.formErrors = error.response.data.errors;
                } else {
                    // Message-only 422s (guard rules) and everything else: show the
                    // server's actual reason instead of swallowing it.
                    window.toast(error.response?.data?.message ?? 'An error occurred. Please try again.');
                }
            } finally {
                this.saving = false;
            }
        },

        /* ── Delete ────────────────────────────────────────────────────── */
        confirmDelete(item) {
            this.selectedItem = item;
            this.deletePassword = '';
            this.deletePasswordError = '';
            this.$dispatch('open-modal', deleteModalName);
        },

        async deleteItem() {
            if (!this.selectedItem || this.deleting) return;
            if (deleteNeedsPassword && !this.deletePassword) {
                this.deletePasswordError = 'Password is required.';
                return;
            }
            this.deleting = true;
            this.deletePasswordError = '';
            try {
                const url = `${fetchUrl.replace('/data', '')}/${this.selectedItem.id}`;
                await axios.delete(url, deleteNeedsPassword ? { data: { password: this.deletePassword } } : undefined);
                this.$dispatch('close-modal', deleteModalName);
                this.selectedItem = null;
                this.deletePassword = '';
                await this.fetchItems();
                if (this.items.length === 0 && this.currentPage > 1) {
                    this.currentPage--;
                    await this.fetchItems();
                }
            } catch (error) {
                // A wrong or missing password goes under its field; anything else (a rule, too many tries) is a toast.
                const passwordError = error.response?.status === 422 ? error.response.data.errors?.password?.[0] : null;
                if (passwordError) {
                    this.deletePasswordError = passwordError;
                } else {
                    console.error(`Failed to delete ${entityLabel}:`, error);
                    window.toast(error.response?.data?.message ?? `Could not delete ${entityLabel}. Please try again.`);
                }
            } finally {
                this.deleting = false;
            }
        },

        /* Merge entity-specific methods */
        ...extraMethods,
    });
}
