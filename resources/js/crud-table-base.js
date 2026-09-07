/**
 * Factory function for CRUD table Alpine.js components.
 * Provides shared pagination, debounced search, fetch, save, and delete logic.
 * Each entity (users, stores, roles, permissions) extends this base with its own overrides.
 */
import axios from 'axios';

export function createCrudTable({
    fetchUrl,            // API endpoint for listing, e.g. '/stores/data'
    dataKey,             // JSON response key holding the items array, e.g. 'stores'
    entityLabel,         // Human-readable name for error messages, e.g. 'store'
    deleteUrlFn,         // Function: (item) => delete URL, e.g. (item) => `/stores/${item.id}`
    formModalName,       // Modal name for create/edit form
    deleteModalName,     // Modal name for delete confirmation
    perPage = 10,        // Items per page
    defaultForm = {},    // Default empty form shape
    mapItemToForm = null,// Function: (item) => form object for editing
    validateForm = null, // Function: (form, editingItem) => errors object ({ field: ['msg'] });
                         // non-empty result blocks the request and shows the errors instead
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
                if (this.editingItem) {
                    await axios.put(`${baseUrl}/${this.editingItem.id}`, this.form);
                } else {
                    await axios.post(baseUrl, this.form);
                }
                this.closeFormModal();
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
            this.$dispatch('open-modal', deleteModalName);
        },

        async deleteItem() {
            if (!this.selectedItem || this.deleting) return;
            this.deleting = true;
            try {
                const url = deleteUrlFn
                    ? deleteUrlFn(this.selectedItem)
                    : `${fetchUrl.replace('/data', '')}/${this.selectedItem.id}`;
                await axios.delete(url);
                this.$dispatch('close-modal', deleteModalName);
                this.selectedItem = null;
                await this.fetchItems();
                if (this.items.length === 0 && this.currentPage > 1) {
                    this.currentPage--;
                    await this.fetchItems();
                }
            } catch (error) {
                console.error(`Failed to delete ${entityLabel}:`, error);
                window.toast(error.response?.data?.message ?? `Could not delete ${entityLabel}. Please try again.`);
            } finally {
                this.deleting = false;
            }
        },

        /* Merge entity-specific methods */
        ...extraMethods,
    });
}
