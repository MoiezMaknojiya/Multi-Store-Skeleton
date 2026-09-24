/**
 * Groups (docs/AD-BUILDER-SPEC.md §13): several elements made one, spread into the editor like the other
 * panels.
 *
 * A group is an element of type `group`; what is inside it says so with `parentId`. Every element keeps
 * STAGE coordinates whatever it is inside, and a group's own box is the box around its children — so
 * nothing here translates between coordinate spaces: moving a group moves its subtree by the same shift,
 * resizing it scales the subtree about the group's box, rotating it turns every element inside about the
 * group's centre and adds the angle to each one's own. The editor's stage and the published page paint the
 * tree the same way: a group is a wrapper (what its animations move) with its children placed against its
 * origin.
 *
 * Selecting works one level at a time, the way Figma and Canva do it: a click on anything inside a group
 * selects the group, a double-click enters it (`editingGroupId`), and Esc steps back out.
 */
import { clone } from './history.js';
import {
    ancestorsOf, childrenOf, clampName, descendantsOf, fitsUnder, isGroup, MAX_GROUP_DEPTH, MAX_NAME, newId,
    parentIdOf, renumberDepth, subtreeDepthOf, syncGroupBounds,
} from './document.js';
import { angleFromCentre, normaliseAngle, rotatePoint } from './geometry.js';

/** The style keys a group has of its own: the rest belong to what it holds. */
const GROUP_STYLE_KEYS = ['blend'];

export function groupPanel() {
    return {
        /* The group whose children are being worked on, or null for the stage itself. */
        editingGroupId: null,

        /* Folders folded shut in the Layers panel — a per-session choice, not part of the design. */
        collapsedGroupIds: [],

        /* ── Reading the tree ──────────────────────────────────────────── */

        isGroup(element) {
            return isGroup(element);
        },

        /** The elements inside a group (null: on the stage itself), back to front. */
        childrenOf(parentId) {
            return childrenOf(this.doc, parentId);
        },

        /** Everything a group holds, however deep. */
        descendantsOf(element) {
            return descendantsOf(this.doc, element);
        },

        /** How many elements a group holds, however deep. */
        countInside(element) {
            return this.descendantsOf(element).length;
        },

        /** The ids of the groups an element is inside, nearest first. */
        ancestorIds(element) {
            return ancestorsOf(this.doc, element).map((group) => group.id);
        },

        /** The group being worked in, or null on the stage itself. */
        editingGroup() {
            return this.editingGroupId === null ? null : (this.doc.elements.find((element) => element.id === this.editingGroupId) ?? null);
        },

        /**
         * The entered group's outline on the stage (§13): dashed, a colour of its own, so a person always
         * sees that clicks now reach inside it — and that Esc, or a click on empty stage, leaves it.
         */
        editingGroupFrameStyle() {
            const group = this.editingGroup();

            if (!group) return { display: 'none' };

            return {
                left: group.x + 'px',
                top: group.y + 'px',
                width: group.w + 'px',
                height: group.h + 'px',
                outline: `${1.5 / this.zoom}px dashed #a855f7`,
                outlineOffset: `${4 / this.zoom}px`,
            };
        },

        /** Locked itself, or inside a locked group. */
        isLocked(element) {
            return !!element?.locked || ancestorsOf(this.doc, element).some((group) => group.locked);
        },

        /** Shown itself, and inside no hidden group. */
        isShown(element) {
            return element?.visible !== false && ancestorsOf(this.doc, element).every((group) => group.visible !== false);
        },

        /** What is being worked on: the stage's own elements, or the entered group's. */
        levelItems() {
            return this.childrenOf(this.editingGroupId);
        },

        /** What a stage paints inside a node (null: the stage itself). */
        paintChildren(parent) {
            return this.childrenOf(parent ? parent.id : null);
        },

        /**
         * What a press on an element selects: the element itself when it is at the level being worked on,
         * otherwise the ancestor that is — a click on anything inside a group selects the group. An element
         * that is not inside the entered group at all steps out of it, and its top-most ancestor is chosen.
         */
        resolveTarget(element) {
            if (!element) return null;

            const chain = [element, ...ancestorsOf(this.doc, element)];
            const atLevel = chain.find((node) => parentIdOf(node) === this.editingGroupId);

            if (atLevel) return atLevel;

            this.editingGroupId = null;

            return chain[chain.length - 1];
        },

        /* ── Entering and leaving ──────────────────────────────────────── */

        /** Work on what is inside a group: its children can then be selected one by one. */
        enterGroup(group) {
            if (!isGroup(group) || this.isLocked(group)) return;

            this.stopPreview();
            this.editingGroupId = group.id;
            this.selectedIds = [];
        },

        /** Step out one level: the group left behind is selected, the way Figma does it. */
        exitGroup() {
            const left = this.doc.elements.find((element) => element.id === this.editingGroupId);

            if (!left) {
                this.editingGroupId = null;

                return;
            }

            this.editingGroupId = parentIdOf(left);
            this.setSelection([left.id]);
        },

        exitGroups() {
            this.editingGroupId = null;
        },

        /** After an undo, a delete or a jump in the history: a group that is gone cannot be worked in. */
        leaveMissingGroup() {
            if (this.editingGroupId !== null && !this.doc.elements.some((element) => element.id === this.editingGroupId && isGroup(element))) {
                this.editingGroupId = null;
            }
        },

        /**
         * A double-click: on a group at this level, go inside it and select the child under the pointer;
         * on a text at this level, edit its words where they are.
         */
        onDoubleClick(element) {
            if (this.previewing === 'all') return;

            const target = this.resolveTarget(element);

            if (!target || this.isLocked(target)) return;

            // A double-click on the group being worked in, where it holds nothing, does nothing at all.
            if (element.id === this.editingGroupId) return;

            if (isGroup(target)) {
                this.enterGroup(target);

                // Pressed where the group holds nothing: inside it, with nothing chosen yet.
                if (element.id === target.id) return;

                const child = this.resolveTarget(element);

                if (child && child.id !== target.id) this.setSelection([child.id]);

                return;
            }

            if (element.type === 'text' && target.id === element.id) this.startTextEdit(element);
        },

        /* ── Group and ungroup ─────────────────────────────────────────── */

        canGroup() {
            return this.selection().filter((element) => !this.isLocked(element)).length >= 2;
        },

        canUngroup() {
            return this.selection().some((element) => isGroup(element) && !this.isLocked(element));
        },

        /** Ctrl+G: the selected elements become one group, placed where the frontmost of them was. */
        groupSelection() {
            const items = this.selection().filter((element) => !this.isLocked(element));

            if (items.length < 2) return;

            const parentId = parentIdOf(items[0]);

            if (this.doc.elements.length >= this.maxElements) {
                window.toast(`An ad may hold at most ${this.maxElements} elements.`);

                return;
            }

            // A group is itself one level: whatever the selection holds goes one deeper than it is.
            const parent = parentId ? this.doc.elements.find((element) => element.id === parentId) : null;
            const base = parent ? ancestorsOf(this.doc, parent).length + 2 : 1;

            if (items.some((element) => base + subtreeDepthOf(this.doc, element) > MAX_GROUP_DEPTH)) {
                window.toast(`Groups can be ${MAX_GROUP_DEPTH} deep at most.`);

                return;
            }

            this.stopPreview();

            const front = items[items.length - 1];
            const group = {
                id: newId('grp'),
                type: 'group',
                name: this.nextGroupName(),
                parentId,
                x: 0, y: 0, w: 1, h: 1,
                rotation: 0,
                opacity: 1,
                z: (front.z ?? 0) + 0.5,
                locked: false,
                visible: true,
                style: {},
                animations: {},
            };

            items.forEach((element) => {
                element.parentId = group.id;
            });
            this.doc.elements.push(group);
            renumberDepth(this.doc);
            this.selectedIds = [group.id];
            this.commit('Group');
        },

        /** "Group 3": the first number no group in this design is called by. */
        nextGroupName() {
            const taken = new Set(this.doc.elements.filter(isGroup).map((group) => group.name));
            let number = 1;

            while (taken.has(`Group ${number}`)) number += 1;

            return `Group ${number}`;
        },

        /** Ctrl+Shift+G: every selected group's children take its place, and are selected. */
        ungroupSelection() {
            const groups = this.selection().filter((element) => isGroup(element) && !this.isLocked(element));

            if (groups.length === 0) return;

            this.stopPreview();

            const freed = [];
            let movedAnimations = false;

            groups.forEach((group) => {
                const children = this.childrenOf(group.id);
                const opacity = Number(group.opacity ?? 1);
                const blend = group.style?.blend ?? 'normal';

                children.forEach((child, index) => {
                    child.parentId = parentIdOf(group);
                    child.z = (group.z ?? 0) + (index + 1) / (children.length + 1);
                    // What the group gave them stays with them: a hidden group's children stay hidden, a faded
                    // group's children keep its fade, and its blend goes to those that had none of their own.
                    if (group.visible === false) child.visible = false;
                    if (opacity < 1) child.opacity = Math.round(Number(child.opacity ?? 1) * opacity * 100) / 100;
                    if (blend !== 'normal' && (child.style?.blend ?? 'normal') === 'normal') child.style = { ...(child.style ?? {}), blend };
                    freed.push(child.id);
                });

                if (Object.values(group.animations ?? {}).some((slot) => slot?.effect)) movedAnimations = true;

                this.doc.elements = this.doc.elements.filter((element) => element.id !== group.id);
            });

            renumberDepth(this.doc);
            this.selectedIds = freed;
            this.commit('Ungroup');

            // A group's animation moved them all as one; it cannot be handed to each of them.
            if (movedAnimations) window.toast("The group's animation went with the group. Undo brings it back.");
        },

        /* ── Moving, scaling and turning a subtree ─────────────────────── */

        /** Shift an element — and, for a group, everything inside it — by the same amount. */
        moveSubtree(element, dx, dy) {
            [element, ...this.descendantsOf(element)].forEach((item) => {
                item.x += dx;
                item.y += dy;
            });
        },

        /**
         * Where an element and everything inside it stand now — what a resize or a rotation of a group
         * is measured from on every pointer move, so nothing drifts with rounding.
         */
        subtreeStart(element) {
            return [element, ...this.descendantsOf(element)].map((item) => ({
                element: item,
                x: item.x, y: item.y, w: item.w, h: item.h,
                rotation: item.rotation ?? 0,
                style: clone(item.style ?? {}),
            }));
        },

        /**
         * A group resized to `box`: everything inside it scales about the group's starting box. From a
         * corner the shape is kept and type scales with it (Canva's rule); from a side the boxes stretch
         * and type keeps its size.
         */
        scaleGroupTo(group, box, starts, uniform) {
            const origin = starts.find((start) => start.element === group);

            if (!origin) return;

            const sx = origin.w > 0 ? box.w / origin.w : 1;
            const sy = origin.h > 0 ? box.h / origin.h : 1;
            const scale = uniform ? Math.min(sx, sy) : null;

            starts.forEach((start) => {
                if (start.element === group) return;

                const item = start.element;

                // Each child's centre moves with the stage's scale; its own sides take the scale along the way
                // they point — so a child turned 90° that the group is widened grows taller, as it looks. Exact
                // at right angles; in between, the nearest a turned box can come to a stretched one.
                const radians = ((Number(start.rotation) || 0) * Math.PI) / 180;
                const alongWidth = Math.hypot(sx * Math.cos(radians), sy * Math.sin(radians));
                const alongHeight = Math.hypot(sx * Math.sin(radians), sy * Math.cos(radians));
                const w = Math.max(1, tidy(start.w * alongWidth));
                const h = Math.max(1, tidy(start.h * alongHeight));
                const cx = box.x + (start.x + start.w / 2 - origin.x) * sx;
                const cy = box.y + (start.y + start.h / 2 - origin.y) * sy;

                item.x = tidy(cx - w / 2);
                item.y = tidy(cy - h / 2);
                item.w = w;
                item.h = h;
                // From a corner the look scales with the box — type, spacing, corners, frames, shadows, a
                // line's weight — the way Canva scales a group; from a side the boxes stretch and the look stays.
                item.style = scale === null ? clone(start.style) : scaledStyle(start.style, scale, this.limits);
            });

            syncGroupBounds(this.doc);
        },

        /** A group turned by `delta` degrees: every element inside turns about the group's centre. */
        rotateGroupBy(group, starts, centre, delta) {
            starts.forEach((start) => {
                if (start.element === group) return;

                const item = start.element;
                const turned = rotatePoint(start.x + start.w / 2, start.y + start.h / 2, centre.x, centre.y, delta);

                item.x = tidy(turned.x - start.w / 2);
                item.y = tidy(turned.y - start.h / 2);
                item.rotation = tidy(normaliseAngle(start.rotation + delta));
            });

            syncGroupBounds(this.doc);
        },

        /** The centroid of the centres of the elements a subtree turns (a group's own box is derived, not turned). */
        pivotOf(starts) {
            const turned = starts.filter((start) => !isGroup(start.element));
            const all = turned.length > 0 ? turned : starts;

            return {
                x: all.reduce((sum, start) => sum + start.x + start.w / 2, 0) / all.length,
                y: all.reduce((sum, start) => sum + start.y + start.h / 2, 0) / all.length,
            };
        },

        /** The angle of the pointer about a group's centre, as the rotate handle reads it. */
        angleAbout(box, point) {
            return angleFromCentre(box, point.x, point.y);
        },

        /* ── Copies ────────────────────────────────────────────────────── */

        /**
         * Copies of these elements and everything inside them, with fresh ids and the same shape: each
         * copy's group is the copy of its group. The roots keep the parent they are given by the caller.
         */
        subtreeClones(roots, parentId) {
            const ids = new Map();
            const copies = [];

            roots.forEach((root) => {
                [root, ...this.descendantsOf(root)].forEach((item) => {
                    const copy = clone(item);

                    copy.id = newId(isGroup(item) ? 'grp' : 'el');
                    ids.set(item.id, copy.id);
                    copies.push({ original: item, copy, root: item === root });
                });
            });

            copies.forEach(({ original, copy, root }) => {
                copy.parentId = root ? parentId : (ids.get(parentIdOf(original)) ?? parentId);
                // The copy itself comes unlocked, to be placed; a lock inside it — a backdrop — stays.
                if (root) copy.locked = false;
                copy.visible = copy.visible !== false;

                if (typeof copy.name === 'string') copy.name = clampName(copy.name, MAX_NAME);
            });

            return copies;
        },

        /* ── The Layers panel ──────────────────────────────────────────── */

        /** Front first, a group's children indented beneath it unless the folder is shut. */
        layerRows() {
            const rows = [];
            const walk = (parentId, depth) => {
                [...this.childrenOf(parentId)].reverse().forEach((element) => {
                    const group = isGroup(element);
                    const collapsed = group && this.collapsedGroupIds.includes(element.id);

                    rows.push({ element, depth, group, collapsed });

                    if (group && !collapsed && depth < MAX_GROUP_DEPTH) walk(element.id, depth + 1);
                });
            };

            walk(null, 0);

            return rows;
        },

        toggleCollapsed(group) {
            this.collapsedGroupIds = this.collapsedGroupIds.includes(group.id)
                ? this.collapsedGroupIds.filter((id) => id !== group.id)
                : [...this.collapsedGroupIds, group.id];
        },

        /**
         * A click on a row selects that element where it is — inside its group if it is in one, the way
         * Figma does it. Shift or Ctrl adds or removes a sibling; another level replaces the selection.
         */
        selectFromLayers(element, event = null) {
            event?.stopPropagation();

            if (!element || this.isLocked(element)) return;

            const level = parentIdOf(element);
            const additive = event && (event.shiftKey || event.ctrlKey || event.metaKey);

            if (additive && level === this.editingGroupId) {
                this.toggleInSelection(element);

                return;
            }

            if (level !== this.editingGroupId) {
                this.stopPreview();
                this.editingGroupId = level;
            }

            this.setSelection([element.id]);
        },

        /** The keys a group takes from a pasted style. */
        groupStyleKeys() {
            return GROUP_STYLE_KEYS;
        },

        /** Whether these elements may be dropped inside `parentId` (the Layers panel's drag). */
        fitsUnder(parentId, elements) {
            return fitsUnder(this.doc, parentId, elements);
        },
    };
}

/** Two decimals are more than a screen shows, and keep a group's geometry free of floating-point noise. */
function tidy(value) {
    return Math.round(value * 100) / 100;
}

/**
 * The style numbers that are lengths — what a uniform scale of a group scales, each held inside its row of
 * AdCompiler::LIMITS. Line height is a multiplier and colours are not lengths, so neither is here.
 */
const LENGTHS = [
    'fontSize', 'letterSpacing', 'wordSpacing', 'padding', 'radius', 'lineWidth',
    ['border', 'width'], ['textStroke', 'width'],
    ['shadow', 'x'], ['shadow', 'y'], ['shadow', 'blur'], ['shadow', 'spread'],
    ['textShadow', 'x'], ['textShadow', 'y'], ['textShadow', 'blur'],
    ['filters', 'blur'],
];

function scaledStyle(style, factor, limits) {
    const scaled = clone(style ?? {});

    LENGTHS.forEach((path) => {
        const [group, key] = Array.isArray(path) ? path : [null, path];
        const holder = group ? scaled[group] : scaled;
        const value = Number(holder?.[key]);

        if (!holder || typeof holder !== 'object' || !Number.isFinite(value)) return;

        const [min, max] = limits?.[group ? `${group}.${key}` : key] ?? [-Infinity, Infinity];

        holder[key] = Math.max(min, Math.min(max, Math.round(value * factor * 10) / 10));
    });

    return scaled;
}
