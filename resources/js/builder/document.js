/**
 * The design document's small, shared rules (docs/AD-BUILDER-SPEC.md §6) — used by the editor and by the
 * panels spread into it, so an id, a stacking order or a loaded document is made the same way everywhere.
 */

/** A fresh id: opaque, short, and made of the characters an id may carry in the published page. */
export function newId(prefix = 'el') {
    return prefix + '_' + Math.random().toString(36).slice(2, 10);
}

/** The longest name an element may carry: BuilderAdRequest's `elements.*.name` rule (max:120). */
export const MAX_NAME = 120;

/**
 * A name cut to what the server accepts, counted in characters the way the server counts them — never
 * in UTF-16 units. Cutting through the middle of an emoji leaves half of one, which is not text the
 * server can read, and the whole save would be refused over a layer's name.
 */
export function clampName(name, max = MAX_NAME) {
    return Array.from(typeof name === 'string' ? name : '').slice(0, max).join('');
}

/** 0, 1, 2… back to front: two elements sharing a depth would make "Bring forward" do nothing. */
export function renumberDepth(doc) {
    [...doc.elements]
        .sort((a, b) => (a.z ?? 0) - (b.z ?? 0))
        .forEach((element, index) => {
            element.z = index;
        });
}

/**
 * A document as the editor expects it, whatever the server's JSON did to it on the way: PHP writes an
 * empty object back as an empty ARRAY, and a property set on an array is dropped the next time it is
 * turned into JSON — so an element's `style` and `animations`, and the guides, are made objects again
 * here, once, and every element gets its own place in the stacking order.
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

    doc.elements.forEach((element) => {
        element.style = object(element.style);
        element.animations = object(element.animations);
    });

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
