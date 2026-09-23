/**
 * The design document's small, shared rules (docs/AD-BUILDER-SPEC.md §6, §13) — used by the editor and by
 * the panels spread into it, so an id, a stacking order, a group's box or a loaded document is made the
 * same way everywhere.
 */
import { boundsOf, visualBounds } from './geometry.js';

/** A fresh id: opaque, short, and made of the characters an id may carry in the published page. */
export function newId(prefix = 'el') {
    return prefix + '_' + Math.random().toString(36).slice(2, 10);
}

/** The longest name an element may carry: BuilderAdRequest's `elements.*.name` rule (max:120). */
export const MAX_NAME = 120;

/** How many groups an element may be inside: BuilderAdRequest::MAX_GROUP_DEPTH (§13). */
export const MAX_GROUP_DEPTH = 3;

/**
 * A name cut to what the server accepts, counted in characters the way the server counts them — never
 * in UTF-16 units. Cutting through the middle of an emoji leaves half of one, which is not text the
 * server can read, and the whole save would be refused over a layer's name.
 */
export function clampName(name, max = MAX_NAME) {
    return Array.from(typeof name === 'string' ? name : '').slice(0, max).join('');
}

/* ── The tree (§13) ────────────────────────────────────────────────────── */

export function isGroup(element) {
    return element?.type === 'group';
}

/** The group an element is inside, or null at the top level. */
export function parentIdOf(element) {
    return typeof element?.parentId === 'string' && element.parentId !== '' ? element.parentId : null;
}

/** The elements inside one group (null: the stage itself), back to front. */
export function childrenOf(doc, parentId) {
    return doc.elements
        .filter((element) => parentIdOf(element) === parentId)
        .sort((a, b) => (a.z ?? 0) - (b.z ?? 0));
}

/** Everything inside a group, however deep, back to front within each level. */
export function descendantsOf(doc, element) {
    const found = [];
    const seen = new Set([element.id]);
    const walk = (parentId) => {
        childrenOf(doc, parentId).forEach((child) => {
            if (seen.has(child.id)) return;

            seen.add(child.id);
            found.push(child);

            if (isGroup(child)) walk(child.id);
        });
    };

    if (isGroup(element)) walk(element.id);

    return found;
}

/** The groups an element is inside, nearest first. */
export function ancestorsOf(doc, element) {
    const byId = new Map(doc.elements.map((item) => [item.id, item]));
    const chain = [];
    const seen = new Set([element?.id]);

    for (let parent = byId.get(parentIdOf(element)); parent && !seen.has(parent.id); parent = byId.get(parentIdOf(parent))) {
        seen.add(parent.id);
        chain.push(parent);
    }

    return chain;
}

/** How many groups an element is inside. */
export function groupDepthOf(doc, element) {
    return ancestorsOf(doc, element).length;
}

/** How many levels of groups an element holds below itself: 0 for anything but a group with a group in it. */
export function subtreeDepthOf(doc, element) {
    if (!isGroup(element)) return 0;

    return 1 + Math.max(0, ...childrenOf(doc, element.id).map((child) => subtreeDepthOf(doc, child)));
}

/**
 * Whether these elements (each with whatever it holds) may be put inside `parentId` without anything
 * ending up deeper than groups go.
 */
export function fitsUnder(doc, parentId, elements) {
    const parent = parentId ? doc.elements.find((element) => element.id === parentId) : null;
    const base = parent ? groupDepthOf(doc, parent) + 1 : 0;

    return elements.every((element) => base + subtreeDepthOf(doc, element) <= MAX_GROUP_DEPTH);
}

/**
 * Every group's box made the box around what it holds — rotated corners counted, deepest groups first so
 * a group inside a group is measured after its own box is right — and a group with nothing in it removed:
 * it does not exist (§13). Returns the ids of the groups removed.
 */
export function syncGroupBounds(doc) {
    const removed = [];
    const groups = doc.elements
        .filter(isGroup)
        .map((group) => ({ group, depth: groupDepthOf(doc, group) }))
        .sort((a, b) => b.depth - a.depth);

    groups.forEach(({ group }) => {
        const children = childrenOf(doc, group.id);

        if (children.length === 0) {
            doc.elements = doc.elements.filter((element) => element.id !== group.id);
            removed.push(group.id);

            return;
        }

        const shown = children.filter((child) => child.visible !== false);
        const bounds = boundsOf((shown.length > 0 ? shown : children).map(visualBounds));

        group.x = tidy(bounds.x);
        group.y = tidy(bounds.y);
        group.w = Math.max(1, tidy(bounds.w));
        group.h = Math.max(1, tidy(bounds.h));
        group.rotation = 0;
    });

    return removed;
}

/** A coordinate without floating-point noise: two decimals are more than a screen can show. */
function tidy(value) {
    return Math.round(value * 100) / 100;
}

/**
 * 0, 1, 2… back to front in one walk of the tree — siblings in their order, a group's children right
 * after it — so `z` stays unique across the design and sorted-by-z is still a valid paint order.
 */
export function renumberDepth(doc) {
    let counter = 0;
    const seen = new Set();
    const walk = (parentId) => {
        childrenOf(doc, parentId).forEach((element) => {
            if (seen.has(element.id)) return;

            seen.add(element.id);
            element.z = counter++;

            if (isGroup(element)) walk(element.id);
        });
    };

    walk(null);

    // Anything a walk from the top never reaches (a parent that is gone) is numbered after the rest.
    doc.elements.forEach((element) => {
        if (!seen.has(element.id)) element.z = counter++;
    });
}

/**
 * A document as the editor expects it, whatever the server's JSON did to it on the way: PHP writes an
 * empty object back as an empty ARRAY, and a property set on an array is dropped the next time it is
 * turned into JSON — so an element's `style` and `animations`, and the guides, are made objects again
 * here, once; every element's group is one that exists (a parent that is not a group of this design,
 * the element itself, a circle, or deeper than groups go, reads as the top level); every group's box is
 * the box around its children; and every element gets its own place in the stacking order.
 */
export function normaliseDocument(doc) {
    const object = (value) => (value && typeof value === 'object' && !Array.isArray(value) ? value : {});
    const numbers = (value) => (Array.isArray(value) ? value.map(Number).filter(Number.isFinite) : []);

    doc.stage = object(doc.stage);
    doc.stage.background = object(doc.stage.background);

    if (!Array.isArray(doc.stage.background.layers)) doc.stage.background.layers = [];
    if (!Array.isArray(doc.elements)) doc.elements = [];

    doc.guides = object(doc.guides);
    doc.guides.x = numbers(doc.guides.x);
    doc.guides.y = numbers(doc.guides.y);

    const byId = new Map(doc.elements.map((element) => [element.id, element]));

    doc.elements.forEach((element) => {
        element.style = object(element.style);
        element.animations = object(element.animations);

        const parent = parentIdOf(element);

        element.parentId = parent !== null && parent !== element.id && isGroup(byId.get(parent)) ? parent : null;
    });

    doc.elements.forEach((element) => {
        const seen = new Set([element.id]);
        let depth = 0;

        for (let parent = element.parentId; parent !== null; parent = byId.get(parent)?.parentId ?? null) {
            if (seen.has(parent) || ++depth > MAX_GROUP_DEPTH) {
                element.parentId = null;
                break;
            }

            seen.add(parent);
        }
    });

    syncGroupBounds(doc);
    renumberDepth(doc);

    return doc;
}

/** A per-browser setting (autosave on, rulers shown…), or its default when storage is unavailable. */
export function readPreference(key, fallback) {
    try {
        const stored = localStorage.getItem('ad-builder.' + key);

        return stored === null ? fallback : JSON.parse(stored);
    } catch {
        return fallback;
    }
}

export function writePreference(key, value) {
    try {
        localStorage.setItem('ad-builder.' + key, JSON.stringify(value));
    } catch {
        // A private window or blocked storage: the setting simply lasts until the page closes.
    }
}
