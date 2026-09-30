// resources/js/core/modal.js

/* The dialogs open now, the last one on top: only the top one answers Escape and keeps the keyboard, so a dialog
 * opened from another closes alone. */
const openModals = [];

function stillOpen() {
    for (let i = openModals.length - 1; i >= 0; i--) {
        if (!openModals[i].isConnected) openModals.splice(i, 1);
    }

    return openModals;
}

export function registerCustomModal(Alpine) {
    Alpine.data('customModal', (modalName, initialShow, isFocusable) => ({
        show: initialShow,
        returnFocusTo: null,

        init() {
            this.nameTheDialog();

            if (this.show) openModals.push(this.$el);

            this.$watch('show', value => {
                if (value) {
                    this.returnFocusTo = document.activeElement;
                    openModals.push(this.$el);
                    document.body.classList.add('overflow-y-hidden');

                    // Into the dialog, so a keyboard and a screen reader start there: its first control when the dialog
                    // asks for that (focusable), else the panel itself — no phone keyboard springs up for a form
                    // nobody has touched yet. A page that already put the focus inside keeps it where it is.
                    setTimeout(() => {
                        if (!this.show || this.$el.contains(document.activeElement)) return;
                        const target = isFocusable ? this.focusables()[0] : this.$refs.panel;
                        target?.focus({ preventScroll: true });
                    }, 100);
                } else {
                    const at = openModals.indexOf(this.$el);
                    if (at !== -1) openModals.splice(at, 1);

                    if (stillOpen().length === 0) document.body.classList.remove('overflow-y-hidden');

                    // Back to what opened it, so a keyboard carries on from where it was (WCAG 2.4.3).
                    const back = this.returnFocusTo;
                    this.returnFocusTo = null;
                    if (back && back !== document.body && back.isConnected && typeof back.focus === 'function') {
                        setTimeout(() => {
                            if (!this.$el.contains(document.activeElement) && document.activeElement !== document.body) return;
                            back.focus({ preventScroll: true });
                        }, 0);
                    }
                }
            });
        },

        /* A screen reader announces the dialog by its title: the first heading in it. */
        nameTheDialog() {
            const panel = this.$refs.panel;
            const title = panel?.querySelector('h1, h2, h3');

            if (!panel || !title) return;

            if (!title.id) title.id = `modal-${String(modalName).replace(/[^\w-]/g, '-')}-title`;
            panel.setAttribute('aria-labelledby', title.id);
        },

        focusables() {
            let selector = 'a, button, input:not([type="hidden"]), textarea, select, details, [tabindex]:not([tabindex="-1"])';
            return [...this.$el.querySelectorAll(selector)]
                .filter(el => !el.hasAttribute('disabled') && el.getClientRects().length > 0);
        },

        onTop() {
            const open = stillOpen();

            return this.show && open[open.length - 1] === this.$el;
        },

        /* Tab and Shift+Tab go round the dialog's own controls while it is open, never behind it. */
        keepFocusInside(event) {
            if (!this.onTop()) return;

            const items = this.focusables();

            if (items.length === 0) {
                event.preventDefault();

                return;
            }

            const first = items[0];
            const last = items[items.length - 1];

            if (!this.$el.contains(document.activeElement) || document.activeElement === this.$refs.panel) {
                event.preventDefault();
                (event.shiftKey ? last : first).focus();
            } else if (event.shiftKey && document.activeElement === first) {
                event.preventDefault();
                last.focus();
            } else if (!event.shiftKey && document.activeElement === last) {
                event.preventDefault();
                first.focus();
            }
        },

        closeOnEscape() {
            if (this.onTop()) this.closeMe();
        },

        openEvent(event) {
            if (event.detail === modalName) this.show = true;
        },

        closeEvent(event) {
            if (event.detail === modalName) this.show = false;
        },

        closeMe() {
            this.show = false;
        }
    }));
}
