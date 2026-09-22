/**
 * Undo and redo for the Ad Builder — and the History panel's list of steps (docs/AD-BUILDER-SPEC.md §10a).
 *
 * A stack of whole-document snapshots rather than a stack of reversible commands: a design is small
 * (a few hundred kilobytes of JSON at the very most), and a snapshot cannot drift out of step with the
 * thing it is supposed to undo — which is the failure mode that makes an editor untrustworthy.
 *
 * Every change goes through `commit(label)`, so nothing can quietly skip the history, and every step
 * keeps its label so the panel can say what it was. A drag is remembered once, when it is let go; what
 * arrives in a stream — an arrow key held down, a filter's slider — commits on every step, so changes
 * with the same label inside COALESCE_MS collapse into one, and holding a key does not leave forty steps
 * behind it.
 *
 * A save does not clear the history: autosave runs every half minute, and undo must reach further back
 * than that.
 */

/** How long two changes of the same kind stay one undo step. */
const COALESCE_MS = 600;

/** Steps kept. Fifty is far past what anybody reaches for, and costs nothing at this size. */
const LIMIT = 50;

export function createHistory(initial) {
    return {
        past: [],
        future: [],
        present: { doc: clone(initial), label: 'Opened' },
        lastLabel: null,
        lastAt: 0,

        /** Remember the document as it is now, under a label the panel can show. */
        commit(document, label, now = Date.now()) {
            const coalesce = label !== null
                && label === this.lastLabel
                && now - this.lastAt < COALESCE_MS;

            if (coalesce) {
                this.present = { doc: clone(document), label: this.present.label };
            } else {
                this.past.push(this.present);

                if (this.past.length > LIMIT) this.past.shift();

                this.present = { doc: clone(document), label: label ?? 'Change' };
            }

            this.future = [];
            this.lastLabel = label;
            this.lastAt = now;
        },

        canUndo() {
            return this.past.length > 0;
        },

        canRedo() {
            return this.future.length > 0;
        },

        /** Step back. Returns the document to show, or null when there is nothing behind. */
        undo() {
            if (!this.canUndo()) return null;

            this.future.push(this.present);
            this.present = this.past.pop();
            this.lastLabel = null;

            return clone(this.present.doc);
        },

        /** Step forward again. */
        redo() {
            if (!this.canRedo()) return null;

            this.past.push(this.present);
            this.present = this.future.pop();
            this.lastLabel = null;

            return clone(this.present.doc);
        },

        /** Every step's label, oldest first — the ones undone at the end. */
        steps() {
            return [
                ...this.past.map((entry) => entry.label),
                this.present.label,
                ...[...this.future].reverse().map((entry) => entry.label),
            ];
        },

        /** Where in `steps()` the document is now. */
        position() {
            return this.past.length;
        },

        /** Go straight to a step, backwards or forwards. Returns the document, or null if already there. */
        jumpTo(index) {
            const target = Math.max(0, Math.min(index, this.past.length + this.future.length));

            if (target === this.position()) return null;

            while (this.position() > target) {
                this.future.push(this.present);
                this.present = this.past.pop();
            }

            while (this.position() < target) {
                this.past.push(this.present);
                this.present = this.future.pop();
            }

            this.lastLabel = null;

            return clone(this.present.doc);
        },
    };
}

/**
 * A deep copy. structuredClone where the browser has it, JSON otherwise — the document is plain data
 * (numbers, strings, arrays, objects) by design, so both are exact.
 */
export function clone(value) {
    // structuredClone is the faster path, but it throws on a PROXY — and the document it is handed
    // here is Alpine's reactive proxy, not the plain object underneath. JSON reads straight through
    // one, and the document is plain data by design, so the round trip is exact either way. Without
    // this fallback every commit threw and undo silently had nothing to go back to.
    try {
        if (typeof structuredClone === 'function') return structuredClone(value);
    } catch {
        // fall through
    }

    return JSON.parse(JSON.stringify(value));
}
