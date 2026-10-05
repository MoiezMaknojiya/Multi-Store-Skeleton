/**
 * The poster: a photograph of the stage, taken in the browser when an ad is saved or published
 * (docs/AD-BUILDER-SPEC.md §10a).
 *
 * There is no browser on the server to photograph an HTML advert, so the editor takes its own picture —
 * `modern-screenshot` (MIT) clones the stage with its styles, pictures and fonts into an SVG and draws it
 * on a canvas. The server keeps the result only if GD can read it as a picture, and what it keeps is GD's own
 * re-encoding (`MediaStorage::storePosterWithin`). Pictures still on their way are waited for first, and handed to
 * the library as the stage already shows them (`picturesOnStage`).
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
 * How long a photograph waits for the stage's pictures still on their way. One that never comes leaves its gap: a
 * missing poster picture is a nuisance, never a save held for good.
 */
const READY_WAIT_MS = 15000;

/** The longest side a picture is handed to the photograph at: twice the poster's, more than any part of it shows. */
const HANDED_LONG_EDGE = POSTER_LONG_EDGE * 2;

/**
 * The stage's pictures, loaded and decoded, by address — its <img> elements and an Image of every picture a
 * background draws in CSS — with every video showing a frame and the fonts ready, waited for at most READY_WAIT_MS.
 * The photograph's library fetches every picture again with a time limit of its own, and one still on its way when
 * Save was pressed came out as a gap (the SS6 burger menu's first poster, 2026-10-05).
 */
async function picturesOnStage(stage) {
    const pictures = new Map();
    const waits = [];
    const decoded = (picture, address) => picture.decode().then(() => pictures.set(address, picture)).catch(() => {});

    stage.querySelectorAll('img').forEach((img) => {
        const address = img.currentSrc || img.src;

        if (address) waits.push(decoded(img, address));
    });

    for (const node of [stage, ...stage.querySelectorAll('*')]) {
        for (const [, address] of getComputedStyle(node).backgroundImage.matchAll(/url\("?(.*?)"?\)/g)) {
            if (address.startsWith('data:')) continue;

            const picture = new Image();
            picture.src = address;
            waits.push(decoded(picture, address));
        }
    }

    stage.querySelectorAll('video').forEach((video) => {
        if (video.readyState >= 2) return;

        waits.push(new Promise((resolve) => {
            video.addEventListener('loadeddata', resolve, { once: true });
            video.addEventListener('error', resolve, { once: true });
            // While designing a video shows only what its metadata gives: the photograph needs its first frame.
            video.preload = 'auto';
        }));
    });

    if (document.fonts?.ready) waits.push(document.fonts.ready);

    await Promise.race([Promise.all(waits), new Promise((resolve) => setTimeout(resolve, READY_WAIT_MS))]);

    return pictures;
}

/**
 * A picture the page already has, as the data URL the photograph's copy carries — no larger than it needs, its
 * transparency kept — or false, and the library fetches it itself.
 */
function handedOver(picture) {
    const longest = Math.max(picture.naturalWidth, picture.naturalHeight);

    if (!longest) return false;

    const scale = Math.min(1, HANDED_LONG_EDGE / longest);
    const canvas = document.createElement('canvas');
    canvas.width = Math.max(1, Math.round(picture.naturalWidth * scale));
    canvas.height = Math.max(1, Math.round(picture.naturalHeight * scale));
    canvas.getContext('2d').drawImage(picture, 0, 0, canvas.width, canvas.height);

    try {
        return canvas.toDataURL('image/webp', 0.9);
    } catch {
        return false;
    }
}

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
        const [{ domToJpeg }, pictures] = await Promise.all([import('modern-screenshot'), picturesOnStage(stage)]);

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
            // A picture the stage already shows is handed over as it is, never fetched a second time.
            fetchFn: async (address) => (pictures.has(address) ? handedOver(pictures.get(address)) : false),
        });
    } catch {
        return null;
    }
}
