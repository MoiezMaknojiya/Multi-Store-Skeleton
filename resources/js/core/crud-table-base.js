/**
 * Factory function for CRUD table Alpine.js components.
 * Provides shared pagination, debounced search, fetch, save, and delete logic.
 * The listings built on it — accounts, organizations, permissions, the activity log, media, screens,
 * campaigns, channels, and the Ad Builder's ads and assets — add their own form shape and overrides
 * (roles, playlists and channel ads have their own components instead).
 */
import axios from 'axios';
import { unreadableFields } from './validate.js';

/**
 * Rows per page, for every listing in the app.
 *
 * One number in one place on purpose: the tables used to disagree — ten here,
 * twenty-five there, a hundred somewhere else — and a person moving between them had
 * no idea whether a short list meant "that is all there is" or "there is more below".
 * Mirrored by HandlesCrudData's own default so the server agrees when a client sends
 * no per_page at all. Not exported: a listing takes it through `perPage` below.
 */
const ROWS_PER_PAGE = 50;

export function createCrudTable({
    fetchUrl,            // API endpoint for listing, e.g. '/organizations/data'
    dataKey,             // JSON response key holding the items array, e.g. 'organizations'
    entityLabel,         // Human-readable name for error messages, e.g. 'organization'

    formModalName,       // Modal name for create/edit form
    deleteModalName,     // Modal name for delete confirmation
    perPage = ROWS_PER_PAGE,  // Items per page — see the constant above
    defaultForm = {},    // Default empty form shape
    mapItemToForm = null,// Function: (item) => form object for editing
    validateForm = null, // Function: (form, editingItem) => errors object ({ field: ['msg'] });
                         // non-empty result blocks the request and shows the errors instead
    onSaved = null,      // Function: (responseData, wasEditing) => void, after a successful save —
                         // for a save whose outcome the refreshed row cannot show (an email sent).
                         // Without one, the server's own message ("Screen updated successfully") is the toast.
    deleteNeedsPassword = false, // A big delete re-confirms the actor's password (ConfirmsPassword on the
                         // server; the modal uses <x-crud.password-confirm>)
    extraState = {},     // Additional reactive properties specific to the entity
    extraMethods = {},   // Additional methods specific to the entity
    sortable = null,     // { by, direction } the listing starts sorted by, when its headings sort (x-crud.sort-header):
                         // the server is sent sort and direction, and orders by its own list of them alone
}) {
    /* A fresh copy of the empty form every time, nested arrays included. A spread copy shared
     * them with defaultForm, so a campaign's ticked screens were
     * pushed into the default itself — and the next "Add" opened with them already there. */
    const blankForm = () => structuredClone(defaultForm);

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
        form: blankForm(),
        formErrors: {},
        searchDebounceTimer: null,
        fetchToken: 0,
        refreshFailed: false,   // a quiet refresh failed and has said so; cleared by the next success
        loadFailed: false,      // the list could not be loaded at all: the table says so, with Try again
        sortBy: sortable?.by ?? null,
        sortDirection: sortable?.direction ?? 'asc',

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
        /**
         * `quiet` is for a refresh nobody asked for — the screens list re-reading itself
         * every thirty seconds. The rows on the page stay where they are until the new ones
         * arrive, with no "Loading..." blink in between, and a failure keeps them and says so
         * once, rather than emptying the table without a word.
         */
        async fetchItems({ quiet = false } = {}) {
            /* Rapid pagination/search can fire overlapping requests; only the response
             * matching the most recently issued request is allowed to update state, so a
             * slow older response can't overwrite a newer one that resolved first. */
            const requestToken = ++this.fetchToken;
            if (!quiet) {
                this.items = [];
                this.loading = true;
                this.loadFailed = false;
            }
            try {
                const params = { search: this.search, page: this.currentPage, per_page: this.perPage };
                if (this.sortBy) Object.assign(params, { sort: this.sortBy, direction: this.sortDirection });
                /* Entity-specific listing filters (e.g. the activity log's date range). */
                if (typeof this.extraParams === 'function') Object.assign(params, this.extraParams());
                const response = await axios.get(fetchUrl, { params });
                if (requestToken !== this.fetchToken) return;
                const data = response.data;
                this.items = data[dataKey];
                this.total = data.total;
                this.currentPage = data.currentPage;
                this.lastPage = data.lastPage;
                this.refreshFailed = false;
                this.loadFailed = false;
                /* A listing may say more than its rows — the media library says how full its organization is. */
                if (typeof this.afterFetch === 'function') this.afterFetch(data);
            } catch (error) {
                if (requestToken !== this.fetchToken) return;
                console.error(`Failed to fetch ${entityLabel}s:`, error);
                if (quiet) {
                    /* The rows already shown are older, not wrong: keep them, and say once — not
                     * every thirty seconds — that they are no longer being brought up to date. */
                    if (!this.refreshFailed) window.toast(`Could not refresh the ${entityLabel}s. Showing the last list loaded.`);
                    this.refreshFailed = true;
                } else {
                    /* Not "nothing here yet": the table says the list could not be loaded, and offers it again. */
                    this.items = [];
                    this.loadFailed = true;
                }
            } finally {
                /* Also after a quiet request: one that overtook a normal fetch has to end its "Loading...". */
                if (requestToken === this.fetchToken) this.loading = false;
            }
        },

        /* ── Sorting (x-crud.sort-header) ──────────────────────────────── */
        /* A heading pressed: the rows sorted by it — the other way round when they already are — from page one. */
        sortOn(column, firstDirection = 'asc') {
            this.sortDirection = this.sortBy === column ? (this.sortDirection === 'asc' ? 'desc' : 'asc') : firstDirection;
            this.sortBy = column;
            this.currentPage = 1;
            this.fetchItems();
        },

        /* What aria-sort says of a heading. */
        sortState(column) {
            if (this.sortBy !== column) return 'none';

            return this.sortDirection === 'asc' ? 'ascending' : 'descending';
        },

        /* ── Pagination ────────────────────────────────────────────────── */
        /* "Showing 51–100 of 245": the first row on this page and the last. */
        get startItem() {
            return this.total === 0 ? 0 : (this.currentPage - 1) * this.perPage + 1;
        },

        get endItem() {
            return Math.min(this.currentPage * this.perPage, this.total);
        },

        next() {
            if (this.currentPage < this.lastPage) {
                this.currentPage++;
                this.turnedPage();
            }
        },

        prev() {
            if (this.currentPage > 1) {
                this.currentPage--;
                this.turnedPage();
            }
        },

        goTo(page) {
            if (page >= 1 && page <= this.lastPage && page !== this.currentPage) {
                this.currentPage = page;
                this.turnedPage();
            }
        },

        /* A new page starts at its top: the pager is at the foot of a long list, and the rows it brought are above. */
        turnedPage() {
            this.fetchItems();
            this.$nextTick(() => (this.$root.querySelector('[data-list-card]') ?? this.$root.querySelector('.card'))
                ?.scrollIntoView({ block: 'start', behavior: 'smooth' }));
        },

        /**
         * The page numbers the pager shows: every one while there are few; otherwise the first, the last and
         * the pages around the current one, with a gap ('gap-…', drawn as "…") where numbers are left out — so
         * thirty pages are seven buttons, not thirty that run out of the card. A gap of one page shows that page
         * instead: "…" standing for a single number is no shorter than the number.
         */
        pageList() {
            const last = this.lastPage;
            const current = this.currentPage;

            if (last <= 7) return Array.from({ length: last }, (_, index) => index + 1);

            const shown = new Set([1, last, current - 1, current, current + 1]);

            if (current <= 3) [2, 3, 4].forEach((page) => shown.add(page));
            if (current >= last - 2) [last - 3, last - 2, last - 1].forEach((page) => shown.add(page));

            const pages = [...shown].filter((page) => page >= 1 && page <= last).sort((a, b) => a - b);
            const list = [];

            pages.forEach((page, index) => {
                const left = index > 0 ? page - pages[index - 1] - 1 : 0;

                if (left === 1) list.push(page - 1);
                if (left > 1) list.push('gap-' + page);
                list.push(page);
            });

            return list;
        },

        /* ── Form modal open/close ─────────────────────────────────────── */
        openFormModal(item = null) {
            this.editingItem = item;
            this.formErrors = {};
            this.form = item && mapItemToForm
                ? mapItemToForm(item)
                : blankForm();
            this.$dispatch('open-modal', formModalName);
        },

        closeFormModal() {
            this.$dispatch('close-modal', formModalName);
            this.editingItem = null;
            this.formErrors = {};
        },

        /* ── Save (create or update) ───────────────────────────────────── */
        async saveItem(event) {
            if (this.saving) return;

            /* Client-side pre-validation: obviously-invalid input never reaches the
             * server. The backend still validates everything — this only saves the trip.
             * A field the browser could not read (a date typed only in part) is said
             * under it, never saved as left blank (unreadableFields). */
            const errors = {
                ...(validateForm ? validateForm(this.form, this.editingItem) : {}),
                ...unreadableFields(event?.target),
            };

            if (Object.keys(errors).length > 0) {
                this.formErrors = errors;
                this.focusFirstError();
                return;
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
                else window.toast(data?.message ?? 'Saved.', 'success');
                await this.fetchItems();
            } catch (error) {
                if (error.response?.status === 422 && error.response.data.errors) {
                    this.formErrors = error.response.data.errors;
                    this.focusFirstError();
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
                const { data } = await axios.delete(url, deleteNeedsPassword ? { data: { password: this.deletePassword } } : undefined);
                this.$dispatch('close-modal', deleteModalName);
                window.toast(data?.message ?? 'Deleted.', 'success');
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

        /* After a refused save, the keyboard goes to the first field that has to change: its message is read out
           with it (x-crud.form-field ties the two together). */
        focusFirstError() {
            this.$nextTick(() => this.$root.querySelector('.crud-field-error :is(input, select, textarea)')?.focus());
        },

        /* Merge entity-specific methods */
        ...extraMethods,
    });
}
