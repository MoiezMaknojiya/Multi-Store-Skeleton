// resources/js/core/modal.js
import { isAClickBeside, noteShut } from './click-beside.js';

/* The dialogs open now, the last one on top: only the top one answers Escape and keeps the keyboard, so a dialog
 * opened from another closes alone. */
const openModals = [];

function stillOpen() {
    for (let i = openModals.length - 1; i >= 0; i--) {
        if (!openModals[i].isConnected) openModals.splice(i, 1);
    }

    return openModals;
}

const isShown = (el) => !!el && el.isConnected && el.getClientRects().length > 0;

/* The buttons of every row that share a `dusk` but for the row's id at its end ("delete-screen-12"). */
function rowButtons(prefix) {
    const own = new RegExp(`^${prefix.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')}\\d+$`);

    return [...document.querySelectorAll(`[dusk^="${prefix}"]`)].filter((el) => own.test(el.getAttribute('dusk')));
}

/* Where the keyboard goes back to when a dialog closes. What opened it, while it is still on the page and in sight; a
 * row deleted from under it is replaced by the same button of the row that took its place (its `dusk` names it, with
 * the row's id at its end), or the last one; then the heading of the card it was in; then the page itself (WCAG
 * 2.4.3 — never the top of the page, where the next Tab would start again at the skip link). */
function placeToReturnTo(back, hint) {
    if (back && back !== document.body && isShown(back)) return back;

    if (hint.prefix) {
        const same = rowButtons(hint.prefix).filter((el) => isShown(el) && !el.disabled);

        if (same.length > 0) return same[Math.min(hint.index, same.length - 1)];
    }

    const heading = isShown(hint.area) ? hint.area.querySelector('h2, h3') : null;
    if (heading && isShown(heading)) {
        if (!heading.hasAttribute('tabindex')) heading.setAttribute('tabindex', '-1');

        return heading;
    }

    return document.getElementById('main-content');
}

/* What a person changes in a dialog is read field by field: each field — or button that chooses (aria-pressed: a
 * weekday, a picked tile; aria-checked: a switch) — the person touches is remembered with the value it had just before, and the dialog has
 * changes while one of them differs from it. A field the page fills in by itself is never touched, so it never counts
 * (the holding pictures that arrive after Edit Screen opens); typed and taken back is no change. A search box, a file
 * chooser and a password are not changes to keep, nor is anything inside `data-not-a-change`. */
const NOT_A_CHANGE = ['search', 'file', 'hidden', 'password', 'button', 'submit'];

function changeableOf(target) {
    const el = target?.closest?.('input, select, textarea, [aria-pressed], [role="switch"]') ?? (target?.closest?.('label')?.control ?? null);

    if (!el || el.closest('[data-not-a-change]')) return null;

    // A button that chooses is one before its type is asked: every <button> is a 'button' or a 'submit'.
    return el.hasAttribute('aria-pressed') || el.getAttribute('role') === 'switch' || !NOT_A_CHANGE.includes(el.type) ? el : null;
}

function valueOf(el) {
    if (el.hasAttribute('aria-pressed')) return el.getAttribute('aria-pressed');
    if (el.getAttribute('role') === 'switch') return el.getAttribute('aria-checked');

    return el.type === 'checkbox' || el.type === 'radio' ? String(el.checked) : el.value;
}

export function registerCustomModal(Alpine) {
    Alpine.data('customModal', (modalName, initialShow, isFocusable, isPersistent = false) => {
        /* The fields this person touched, each with its value before (kept out of Alpine's state: DOM elements), and a
         * change the fields cannot show — a file that arrived, a rule added (`modal-changed`, `data-a-change`). */
        const touched = new Map();
        let marked = false;

        return {
        show: initialShow,
        returnFocusTo: null,
        /* The row and the card the dialog was opened from, should what opened it go while it is open. */
        modalReturnHint: { prefix: null, index: 0, area: null },
        openedAt: 0,
        /* Whether it is asking "Discard your changes?" (Escape on a dialog with changes in it). */
        modalAskingToDiscard: false,
        modalFocusBeforeAsk: null,

        init() {
            this.nameTheDialog();

            if (this.show) openModals.push(this.$el);

            // Brought back by the browser's own Back out of its page cache: no dialog is still open over the page.
            window.addEventListener('pageshow', (event) => {
                if (event.persisted && this.show) this.show = false;
            });

            this.$watch('show', value => {
                if (value) {
                    this.openedAt = performance.now();
                    touched.clear();
                    marked = false;
                    this.modalAskingToDiscard = false;
                    this.modalRememberWhereFrom(document.activeElement);
                    openModals.push(this.$el);
                    document.body.classList.add('overflow-y-hidden');

                    // Into the dialog, so a keyboard and a screen reader start there: its first control when the dialog
                    // asks for that (focusable), else the panel itself — no phone keyboard springs up for a form
                    // nobody has touched yet. A page that already put the focus inside keeps it where it is.
                    // On a touch screen a field is never focused by itself: its keyboard would spring up over the dialog
                    // (the Premium gallery's search) before the person has seen it.
                    setTimeout(() => {
                        if (!this.show || this.$el.contains(document.activeElement)) return;
                        const first = isFocusable ? this.focusables()[0] : null;
                        const typesText = first?.matches?.('textarea, input:not([type="checkbox"]):not([type="radio"]):not([type="button"]):not([type="submit"])');
                        const target = first && !(typesText && matchMedia('(pointer: coarse)').matches) ? first : this.$refs.panel;
                        target?.focus({ preventScroll: true });
                    }, 100);
                } else {
                    noteShut();
                    this.modalAskingToDiscard = false;
                    const at = openModals.indexOf(this.$el);
                    if (at !== -1) openModals.splice(at, 1);

                    if (stillOpen().length === 0) document.body.classList.remove('overflow-y-hidden');

                    // Back to what opened it, so a keyboard carries on from where it was (WCAG 2.4.3).
                    const back = this.returnFocusTo;
                    const hint = this.modalReturnHint;
                    this.returnFocusTo = null;
                    setTimeout(() => {
                        if (!this.$el.contains(document.activeElement) && document.activeElement !== document.body) return;
                        placeToReturnTo(back, hint)?.focus({ preventScroll: true });
                    }, 0);
                }
            });
        },

        /* What opened the dialog — or, when that was a button of another dialog shutting as this one opens (Premium
         * Template in Create Ad, Back in the gallery), what opened THAT one: a hidden button cannot take the focus
         * back, and the person never left the page they started from. */
        modalRememberWhereFrom(from) {
            const host = from?.closest?.('[data-modal-root]');

            if (host && host !== this.$el && Alpine.$data(host)?.show === false && host.__openedFrom) from = host.__openedFrom;

            this.returnFocusTo = from;
            this.$el.__openedFrom = from;

            const dusk = from?.getAttribute?.('dusk') ?? '';
            const prefix = /^(.*\D)\d+$/.exec(dusk)?.[1] ?? null;
            const same = prefix ? rowButtons(prefix) : [];

            this.modalReturnHint = {
                prefix,
                index: Math.max(0, same.indexOf(from)),
                area: from?.closest?.('[data-focus-area], .card, section') ?? null,
            };
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
            if (!this.onTop()) return;

            // Escape while it asks is "Keep Editing": the same key twice never throws the typing away.
            if (this.modalAskingToDiscard) {
                this.modalKeepEditing();

                return;
            }

            this.closeMe('escape');
        },

        /* A click on the backdrop — unless it is the rest of the double-click that opened the dialog (click-beside.js). */
        closeFromBeside(event) {
            if (isAClickBeside(event, this.openedAt)) this.closeMe('beside');
        },

        openEvent(event) {
            if (event.detail === modalName) this.show = true;
        },

        closeEvent(event) {
            if (event.detail === modalName) this.show = false;
        },

        /* A key or a press on a field: its value just before is remembered (once). A change that comes with no press
         * first (a paste from the menu) counts as it comes. */
        modalNoteTouch(event) {
            if (!isPersistent || !this.show || !event.isTrusted) return;

            const field = changeableOf(event.target);
            if (field && !touched.has(field)) touched.set(field, valueOf(field));
        },

        modalNoteInput(event) {
            if (!isPersistent || !this.show || !event.isTrusted) return;

            const field = changeableOf(event.target);
            if (field && !touched.has(field)) marked = true;
        },

        /* A press on a button that changes the form without being a field (Add a Schedule, Remove): `data-a-change`. */
        modalNoteClick(event) {
            if (isPersistent && this.show && event.isTrusted && event.target.closest?.('[data-a-change]')) marked = true;
        },

        modalMarkChanged() {
            if (isPersistent && this.show) marked = true;
        },

        modalChanged() {
            return marked || [...touched].some(([field, before]) => !field.isConnected || valueOf(field) !== before);
        },

        /* Escape, a click beside it or its own close: announced first, and a page may keep its dialog open — a file
           still going up asks before it is given up (channel-ads.js, campaigns-table.js). A form of several fields
           (persistent) with changes in it asks before Escape throws them away; its own Cancel, and a page's own
           close-modal event, are not asked: the person or the page has decided. */
        closeMe(reason = 'close') {
            const asked = new CustomEvent('modal-closing', { detail: modalName, cancelable: true });
            if (!window.dispatchEvent(asked)) return;

            if (isPersistent && reason !== 'close' && this.modalChanged()) {
                this.modalFocusBeforeAsk = this.$el.contains(document.activeElement) ? document.activeElement : null;
                this.modalAskingToDiscard = true;
                this.$nextTick(() => this.$refs.modalKeepEditing?.focus({ preventScroll: false }));

                return;
            }

            this.show = false;
        },

        modalKeepEditing() {
            this.modalAskingToDiscard = false;
            // Chosen once the question is gone: the field the person was in, else the dialog's first control.
            this.$nextTick(() => {
                const back = isShown(this.modalFocusBeforeAsk) ? this.modalFocusBeforeAsk
                    : this.focusables().find((el) => !el.closest('[data-discard-ask]'));
                back?.focus({ preventScroll: true });
            });
        },

        modalDiscardChanges() {
            this.modalAskingToDiscard = false;
            touched.clear();
            marked = false;
            this.show = false;
        },
        };
    });
}
