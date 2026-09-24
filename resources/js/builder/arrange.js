/**
 * Arranging what is selected (docs/AD-BUILDER-SPEC.md §10a): align, distribute, stacking order, the
 * clipboard — copy, cut, paste, and Elementor's "paste style" — and the right-click menu that offers them.
 *
 * Spread into the editor's component, so data and plain methods only — no getters (see the Alpine gotcha
 * in the project conventions).
 */
import { ancestorsOf, clampName, MAX_GROUP_DEPTH, newId, parentIdOf, renumberDepth } from './document.js';
import { alignTo, boundsOf, distribute, visualBounds } from './geometry.js';
import { clone } from './history.js';

/** Where the clipboard lives: the browser's storage, so it carries from one ad to another. */
export const CLIPBOARD_KEY = 'ad-builder.clipboard';

/** The style keys each kind of element takes from another when a style is pasted. */
const STYLE_KEYS = {
    text: [
        'fontFamily', 'fontSize', 'fontWeight', 'fontStyle', 'color', 'align', 'verticalAlign', 'lineHeight',
        'letterSpacing', 'wordSpacing', 'textTransform', 'textDecoration', 'padding', 'background', 'radius',
        'textShadow', 'textStroke', 'border', 'blend',
    ],
    picture: ['fit', 'position', 'radius', 'border', 'shadow', 'filters', 'flipX', 'flipY', 'blend'],
    // A line's thickness and dash pattern travel with its kind (§14): pasting a line's look onto a rectangle
    // makes it that very line, not a line of some other weight.
    shape: ['shape', 'fill', 'gradient', 'radius', 'border', 'shadow', 'blend', 'lineWidth', 'lineStyle'],
    // A group has no look of its own beyond how it mixes: the rest belongs to what it holds (§13).
    group: ['blend'],
};

function kindOf(element) {
    if (element.type === 'text' || element.type === 'shape' || element.type === 'group') return element.type;

    return 'picture';
}

/** The clipboard's elements, or an empty list when there is none (or storage cannot be read). */
export function readClipboard() {
    try {
        const stored = JSON.parse(localStorage.getItem(CLIPBOARD_KEY) ?? 'null');

        return Array.isArray(stored?.elements) ? stored.elements : [];
    } catch {
        return [];
    }
}

function writeClipboard(elements) {
    try {
        localStorage.setItem(CLIPBOARD_KEY, JSON.stringify({ version: 1, elements }));

        return true;
    } catch {
        return false;
    }
}

export function arrangePanel() {
    return {
        /** How many elements the clipboard holds — kept here so the menu and the panel can say so. */
        clipboardSize: readClipboard().length,

        /** Each paste lands a little further on, so copies never hide the original or each other. */
        pasteCount: 0,

        /** The right-click menu: where it opened and what it may offer, or null. */
        contextMenu: null,

        /* ── Align & distribute ─────────────────────────────────────────── */

        /**
         * One element lines up on the stage; several line up on the box around them. A group moves with
         * everything inside it (§13).
         */
        alignSelection(edge) {
            const items = this.selection().filter((element) => !this.isLocked(element));

            if (items.length === 0) return;

            const frame = items.length === 1
                ? { x: 0, y: 0, w: this.stage.width, h: this.stage.height }
                : boundsOf(items);

            alignTo(items, frame, edge).forEach((place, index) => {
                this.moveSubtree(items[index], (place.x ?? items[index].x) - items[index].x, (place.y ?? items[index].y) - items[index].y);
            });
            this.commit('Align');
        },

        /** Even gaps across ('x') or down ('y') — three elements or more. */
        distributeSelection(axis) {
            const items = this.selection().filter((element) => !this.isLocked(element));

            if (items.length < 3) return;

            distribute(items, axis).forEach((position, index) => {
                const key = axis === 'x' ? 'x' : 'y';

                this.moveSubtree(items[index], key === 'x' ? position - items[index].x : 0, key === 'y' ? position - items[index].y : 0);
            });
            this.commit('Distribute');
        },

        /* ── Stacking order ─────────────────────────────────────────────── */

        /**
         * 'front' and 'back' move the selection to the top or the bottom of the stack; 'forward' and
         * 'backward' move it one place past the nearest element that is not selected. The selection keeps
         * its own order throughout.
         */
        reorder(mode) {
            const chosen = new Set(this.selectedIds);

            if (chosen.size === 0) return;

            // Among its siblings: a selection is always at one level, and only that level's order is its
            // place in the stack (§13). The numbering of the whole design follows from the tree.
            const first = this.selection()[0];
            const ordered = this.childrenOf(first ? (first.parentId ?? null) : null);
            let result = ordered;

            if (mode === 'front') {
                result = [...ordered.filter((el) => !chosen.has(el.id)), ...ordered.filter((el) => chosen.has(el.id))];
            } else if (mode === 'back') {
                result = [...ordered.filter((el) => chosen.has(el.id)), ...ordered.filter((el) => !chosen.has(el.id))];
            } else if (mode === 'forward') {
                for (let i = result.length - 2; i >= 0; i--) {
                    if (chosen.has(result[i].id) && !chosen.has(result[i + 1].id)) {
                        [result[i], result[i + 1]] = [result[i + 1], result[i]];
                    }
                }
            } else if (mode === 'backward') {
                for (let i = 1; i < result.length; i++) {
                    if (chosen.has(result[i].id) && !chosen.has(result[i - 1].id)) {
                        [result[i], result[i - 1]] = [result[i - 1], result[i]];
                    }
                }
            }

            result.forEach((element, index) => {
                element.z = index;
            });
            renumberDepth(this.doc);

            const labels = { front: 'Bring to front', back: 'Send to back', forward: 'Bring forward', backward: 'Send backward' };

            this.commit(labels[mode] ?? 'Order');
        },

        bringToFront() {
            this.reorder('front');
        },

        sendToBack() {
            this.reorder('back');
        },

        /* ── Clipboard ──────────────────────────────────────────────────── */

        /** The selection with everything its groups hold (§13): the tree travels, ids and all, and paste remakes it. */
        clipboardItems(items) {
            return items.flatMap((element) => [element, ...this.descendantsOf(element)]).map((element) => clone(element));
        },

        copySelection() {
            const items = this.selection();

            if (items.length === 0) return;

            if (!writeClipboard(this.clipboardItems(items))) {
                window.toast('This browser would not let the editor keep a copy.');

                return;
            }

            this.clipboardSize = items.length;
            this.pasteCount = 0;
            window.toast(items.length === 1 ? 'Copied' : `Copied ${items.length} elements`, 'success');
        },

        cutSelection() {
            const items = this.selection().filter((element) => !this.isLocked(element));

            if (items.length === 0) return;

            // Nothing leaves the stage unless it really reached the clipboard: a cut that could not be
            // kept would simply be a delete.
            if (!writeClipboard(this.clipboardItems(items))) {
                window.toast('This browser would not let the editor keep a copy, so nothing was cut.');

                return;
            }

            this.clipboardSize = items.length;
            this.pasteCount = 0;
            this.removeSelection('Cut');
        },

        /**
         * Paste copies on top, a little further on each time, selected. An element whose picture is not on
         * this shop's shelf is left out — it would be an empty box here, and the television would drop it.
         * The shelf is the ad's own shop's, the same one the picker offers: above the stores the editor
         * holds every shop's pictures, and another shop's is not this ad's to use.
         */
        pasteClipboard() {
            const copied = readClipboard();

            if (copied.length === 0) return;

            const shelf = new Set(this.assets.filter((asset) => this.onThisShelf(asset)).map((asset) => asset.id));
            const usable = copied.filter((element) => !element.assetId || shelf.has(element.assetId));
            const skipped = copied.length - usable.length;

            if (usable.length === 0) {
                window.toast('Those pictures are not on this shop\'s shelf, so there was nothing to paste.');

                return;
            }

            if (this.doc.elements.length + usable.length > this.maxElements) {
                window.toast(`An ad may hold at most ${this.maxElements} elements.`);

                return;
            }

            // The tree comes back as it went (§13): a copied element's group is the copy of that group,
            // and whatever was copied at the top lands at the level being worked on — depth permitting.
            const copiedIds = new Set(usable.map((element) => element.id));
            const roots = usable.filter((element) => !copiedIds.has(element.parentId ?? null));
            const ids = new Map();
            const depthBelow = (element) => (element.type === 'group'
                ? 1 + Math.max(0, ...usable.filter((item) => item.parentId === element.id).map(depthBelow))
                : 0);
            const parent = this.editingGroupId ? this.doc.elements.find((element) => element.id === this.editingGroupId) : null;
            const base = parent ? ancestorsOf(this.doc, parent).length + 1 : 0;

            if (roots.some((element) => base + depthBelow(element) > MAX_GROUP_DEPTH)) {
                window.toast(`Groups can be ${MAX_GROUP_DEPTH} deep at most.`);

                return;
            }

            this.stopPreview();
            this.pasteCount += 1;

            const offset = 32 * this.pasteCount;
            const selected = [];

            [...usable]
                .sort((a, b) => (a.z ?? 0) - (b.z ?? 0))
                .forEach((element) => {
                    const copy = clone(element);

                    copy.id = newId(element.type === 'group' ? 'grp' : 'el');
                    ids.set(element.id, copy.id);
                    copy.x = Math.round((Number(copy.x) || 0) + offset);
                    copy.y = Math.round((Number(copy.y) || 0) + offset);
                    copy.visible = copy.visible !== false;
                    copy.z = this.doc.elements.length;
                    copy.parentId = copiedIds.has(element.parentId ?? null) ? ids.get(element.parentId) : this.editingGroupId;
                    // What was pasted at the top comes unlocked, to be placed; a lock inside a copied group stays.
                    if (!copiedIds.has(element.parentId ?? null)) copy.locked = false;

                    // The clipboard is the browser's, not the server's: a name it carries is held to what
                    // a save accepts.
                    if (typeof copy.name === 'string') copy.name = clampName(copy.name);

                    this.doc.elements.push(copy);

                    if (!copiedIds.has(element.parentId ?? null)) selected.push(copy.id);
                });

            this.bringOntoStage(this.doc.elements.slice(-usable.length));
            renumberDepth(this.doc);
            this.selectedIds = selected;
            this.commit('Paste');

            if (skipped > 0) {
                window.toast(`${skipped} ${skipped === 1 ? 'element was' : 'elements were'} left out: the picture is not on this shop's shelf.`);
            }
        },

        /**
         * Pasted elements that landed wholly off the stage — copied from an ad of the other shape (the right half
         * of a landscape ad is past a portrait stage's edge), or pushed there by the paste offset — are brought
         * onto it, together, as close to where they were as fits (centred when they are bigger than the stage).
         * What still touches the stage stays where it is: a design may bleed off an edge on purpose.
         */
        bringOntoStage(elements) {
            const leaves = elements.filter((element) => element.type !== 'group');
            const box = boundsOf((leaves.length > 0 ? leaves : elements).map(visualBounds));
            const width = Number(this.doc.stage?.width) || 1920;
            const height = Number(this.doc.stage?.height) || 1080;

            if (box.x < width && box.y < height && box.x + box.w > 0 && box.y + box.h > 0) return;

            const into = (start, size, room) => (size <= room ? Math.min(Math.max(start, 0), room - size) : (room - size) / 2) - start;
            const dx = Math.round(into(box.x, box.w, width));
            const dy = Math.round(into(box.y, box.h, height));

            elements.forEach((element) => {
                element.x += dx;
                element.y += dy;
            });
        },

        /** The copied element's look, onto every selected element — the keys that make sense for each kind. */
        pasteStyle() {
            const source = readClipboard()[0];
            const targets = this.selection().filter((element) => !this.isLocked(element));

            if (!source || targets.length === 0) return;

            const from = kindOf(source);

            targets.forEach((target) => {
                const to = kindOf(target);
                const keys = from === to ? STYLE_KEYS[to] : STYLE_KEYS[to].filter((key) => STYLE_KEYS[from].includes(key));
                const style = { ...(target.style ?? {}) };

                keys.forEach((key) => {
                    if (source.style?.[key] === undefined || source.style?.[key] === null) {
                        delete style[key];
                    } else {
                        style[key] = clone(source.style[key]);
                    }
                });

                target.style = style;
            });

            this.commit('Paste style');
        },

        /** The copied element's entrance, loop and exit, onto every selected element. */
        pasteAnimation() {
            const source = readClipboard()[0];
            const targets = this.selection().filter((element) => !this.isLocked(element));

            if (!source || targets.length === 0) return;

            targets.forEach((target) => {
                target.animations = clone(source.animations ?? {});
            });

            this.commit('Paste animation');
            this.previewElement(this.selected);
        },

        /* ── The right-click menu ───────────────────────────────────────── */

        /**
         * Open the menu on an element (selecting it first unless it is already part of the selection), on
         * a locked element (which only offers Unlock), or on the empty stage (which clears the selection).
         */
        openContextMenu(event, element = null, fromLayers = false) {
            event.preventDefault();

            if (this.previewing === 'all') return;

            // The group being worked in, pressed where it holds nothing, is the empty stage of that level.
            if (element && element.id === this.editingGroupId && !fromLayers) element = null;

            // A row of the Layers panel is that very element, at its own level — what a left-click on it
            // selects (selectFromLayers). On the stage, anything inside a group is the group, unless the group
            // has been entered (§13).
            if (element && fromLayers && parentIdOf(element) !== this.editingGroupId) {
                this.stopPreview();
                this.editingGroupId = parentIdOf(element);
            }

            let locked = null;
            const target = element ? (fromLayers ? element : this.resolveTarget(element)) : null;

            if (target && this.isLocked(target)) {
                locked = [target, ...ancestorsOf(this.doc, target)].find((node) => node.locked) ?? target;
                this.selectedIds = [];
            } else if (target) {
                if (!this.selectedIds.includes(target.id)) this.selectedIds = [target.id];
            } else {
                this.clearSelection();
            }

            this.clipboardSize = readClipboard().length;
            this.contextMenu = {
                x: Math.min(event.clientX, window.innerWidth - 240),
                y: Math.min(event.clientY, window.innerHeight - 440),
                locked,
            };
        },

        closeContextMenu() {
            this.contextMenu = null;
        },

        /** Run a menu item by name, closing the menu first so the action sees the page as it is. */
        runMenu(action) {
            const locked = this.contextMenu?.locked;

            this.closeContextMenu();

            if (action === 'unlock' && locked) {
                this.toggleLock(locked);

                return;
            }

            this[action]?.();
        },

        contextMenuStyle() {
            return this.contextMenu ? { left: this.contextMenu.x + 'px', top: this.contextMenu.y + 'px' } : {};
        },
    };
}
