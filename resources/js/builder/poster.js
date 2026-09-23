/**
 * The poster: a photograph of the stage, taken in the browser when an ad is saved or published
 * (docs/AD-BUILDER-SPEC.md §10a).
 *
 * There is no browser on the server to photograph an HTML advert, so the editor takes its own picture —
 * `modern-screenshot` (MIT) clones the stage with its styles, pictures and fonts into an SVG and draws it
 * on a canvas. The server keeps the result only if GD can read it as a picture, and stores GD's own
 * re-encoding (`MediaStorage::storePoster`).
 *
 * The library is imported only when a poster is first taken, so the panel's shared bundle — every page
 * of the app loads it — does not carry it.
 */

/**
 * The poster's longer edge: a third of the stage — 640 × 360 for a landscape ad, 360 × 640 for a portrait
 * one (§12), the other edge following from the stage's own shape — which is what every listing and picker
 * shows at most.
 */
const POSTER_LONG_EDGE = 640;

/**
 * Alpine's attributes off the copy. The picture is an SVG, which is XML, and `x-bind:style`, `:key` or
 * `@pointerdown.self` are not attribute names XML allows (the library's own clean-up keeps anything with
 * a colon): left on, the whole picture failed to parse and every poster came out as its bare background
 * colour. The copy carries its styles inline, so it needs none of them. SVG's own namespaced attributes
 * (`xlink:href`, `xml:space`) stay.
 */
function withoutAlpineAttributes(node) {
    if (node.nodeType !== Node.ELEMENT_NODE) return;

    for (const name of node.getAttributeNames()) {
        const alpine = /^(x-|:|@)/.test(name);
        const unknownPrefix = name.includes(':') && !/^(xmlns|xlink|xml):/.test(name);

        if (alpine || unknownPrefix) node.removeAttribute(name);
    }
}

/**
 * A JPEG data URI of the stage node at the stage's own size (unscaled), or null when the picture could not
 * be taken — a missing poster is a nuisance, never a reason for a save to fail.
 */
export async function capturePoster(stage, width = 1920, height = 1080) {
    if (!stage) return null;

    try {
        const { domToJpeg } = await import('modern-screenshot');

        return await domToJpeg(stage, {
            width,
            height,
            scale: POSTER_LONG_EDGE / Math.max(width, height),
            quality: 0.82,
            backgroundColor: '#000000',
            timeout: 8000,
            // The stage is drawn as it stands, not where the editor has put it on screen.
            style: { transform: 'none', left: '0', top: '0' },
            onCloneEachNode: withoutAlpineAttributes,
        });
    } catch {
        return null;
    }
}
