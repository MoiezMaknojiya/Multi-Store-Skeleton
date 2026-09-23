/**
 * The arithmetic behind dragging, resizing and snapping on the Ad Builder's stage.
 *
 * Everything here works in STAGE pixels (0–1920 × 0–1080, or 0–1080 × 0–1920 for a portrait ad), never
 * in screen pixels: the stage is drawn at whatever zoom fits the window, and a design that changed with
 * the zoom would be a design nobody could trust. The caller converts a pointer's movement once, with
 * `toStage`, and the rest is exact.
 */

/** How close (in stage pixels) an edge has to be before it snaps. */
const SNAP_THRESHOLD = 8;

/** The smallest an element may be dragged down to. */
export const MIN_SIZE = 8;

/** Screen pixels → stage pixels. */
export function toStage(delta, zoom) {
    return zoom === 0 ? 0 : delta / zoom;
}

/**
 * Where an element would land after a move, with snapping.
 *
 * Returns the new x/y AND the guides that snapped, so the stage can draw the lines a person expects to
 * see the moment something lines up.
 */
export function snapMove(box, stage, others, threshold = SNAP_THRESHOLD, userGuides = null) {
    const guides = { x: null, y: null };

    // The lines worth lining up with: the stage's edges and middle, every other element's edges and
    // middle, and the guides a person dragged out of the rulers. The same list a person's eye uses.
    const verticals = [0, stage.width / 2, stage.width, ...(userGuides?.x ?? [])];
    const horizontals = [0, stage.height / 2, stage.height, ...(userGuides?.y ?? [])];

    for (const other of others) {
        verticals.push(other.x, other.x + other.w / 2, other.x + other.w);
        horizontals.push(other.y, other.y + other.h / 2, other.y + other.h);
    }

    // Each of the element's own three lines may snap; the closest wins.
    const x = snapAxis([box.x, box.x + box.w / 2, box.x + box.w], verticals, threshold);
    const y = snapAxis([box.y, box.y + box.h / 2, box.y + box.h], horizontals, threshold);

    if (x !== null) guides.x = x.line;
    if (y !== null) guides.y = y.line;

    return {
        x: Math.round(box.x + (x?.shift ?? 0)),
        y: Math.round(box.y + (y?.shift ?? 0)),
        guides,
    };
}

/**
 * Resize from one of the eight handles.
 *
 * `handle` is a compass point ('nw', 'n', 'ne', 'e', 'se', 's', 'sw', 'w'). With `keepRatio` the box
 * keeps its proportions — the corner handles only, because keeping a ratio while dragging one edge is
 * a guess about which side the person meant.
 */
function resizeBox(start, handle, dx, dy, { keepRatio = false, fromCentre = false } = {}) {
    let { x, y, w, h } = start;
    const ratio = start.h === 0 ? 1 : start.w / start.h;

    const west = handle.includes('w');
    const east = handle.includes('e');
    const north = handle.includes('n');
    const south = handle.includes('s');

    if (east) w = start.w + dx;
    if (west) { w = start.w - dx; x = start.x + dx; }
    if (south) h = start.h + dy;
    if (north) { h = start.h - dy; y = start.y + dy; }

    if (keepRatio && (west || east) && (north || south)) {
        // Follow whichever axis the person moved further, so the box tracks the pointer.
        if (Math.abs(dx) > Math.abs(dy)) {
            const next = w / ratio;
            if (north) y = start.y + (start.h - next);
            h = next;
        } else {
            const next = h * ratio;
            if (west) x = start.x + (start.w - next);
            w = next;
        }
    }

    if (fromCentre) {
        // Grow both ways from where it started: the centre stays put. Both edges move, so each one moves
        // by twice the change worked out above — which keeps the edge being dragged under the pointer
        // instead of trailing it at half the pace.
        const cx = start.x + start.w / 2;
        const cy = start.y + start.h / 2;
        w = Math.max(MIN_SIZE, start.w + (w - start.w) * 2);
        h = Math.max(MIN_SIZE, start.h + (h - start.h) * 2);
        x = cx - w / 2;
        y = cy - h / 2;
    }

    // A box dragged past itself stops at the smallest size, held by its opposite edge, rather than
    // going negative.
    if (w < MIN_SIZE) { x = west ? start.x + start.w - MIN_SIZE : x; w = MIN_SIZE; }
    if (h < MIN_SIZE) { y = north ? start.y + start.h - MIN_SIZE : y; h = MIN_SIZE; }

    return { x: Math.round(x), y: Math.round(y), w: Math.round(w), h: Math.round(h) };
}

/**
 * Resize an element that is turned. Its handles are drawn turned with it, so the pointer's movement is
 * first turned back into the element's own axes — along its width and its height — and resized there;
 * and because a turned box turns about its centre, the box is then shifted so that the edge or corner
 * opposite the handle stays exactly where it was on the stage. Unturned, this is resizeBox itself.
 */
export function resizeRotated(start, handle, dx, dy, rotation, options = {}) {
    const degrees = (((Number(rotation) || 0) % 360) + 360) % 360;

    if (degrees === 0) return resizeBox(start, handle, dx, dy, options);

    const radians = (degrees * Math.PI) / 180;
    const cos = Math.cos(radians);
    const sin = Math.sin(radians);
    const local = resizeBox(start, handle, dx * cos + dy * sin, dy * cos - dx * sin, options);

    // How far the centre moved in the element's own axes, turned onto the stage.
    const shiftX = local.x + local.w / 2 - (start.x + start.w / 2);
    const shiftY = local.y + local.h / 2 - (start.y + start.h / 2);
    const centreX = start.x + start.w / 2 + shiftX * cos - shiftY * sin;
    const centreY = start.y + start.h / 2 + shiftX * sin + shiftY * cos;

    return { x: Math.round(centreX - local.w / 2), y: Math.round(centreY - local.h / 2), w: local.w, h: local.h };
}

/** The angle from a box's centre to a point, in degrees, 0 = up. */
export function angleFromCentre(box, pointX, pointY) {
    const cx = box.x + box.w / 2;
    const cy = box.y + box.h / 2;
    const degrees = (Math.atan2(pointY - cy, pointX - cx) * 180) / Math.PI + 90;

    return Math.round(((degrees % 360) + 360) % 360);
}

/** Rotation in fifteen-degree steps, for a person holding Shift. */
export function stepAngle(degrees, step = 15) {
    return Math.round(degrees / step) * step;
}

/** The smallest box around several. */
export function boundsOf(boxes) {
    const left = Math.min(...boxes.map((box) => box.x));
    const top = Math.min(...boxes.map((box) => box.y));
    const right = Math.max(...boxes.map((box) => box.x + box.w));
    const bottom = Math.max(...boxes.map((box) => box.y + box.h));

    return { x: left, y: top, w: right - left, h: bottom - top };
}

/** Whether two boxes overlap at all — touching counts, which is what a marquee means. */
export function intersects(a, b) {
    return a.x <= b.x + b.w && b.x <= a.x + a.w && a.y <= b.y + b.h && b.y <= a.y + a.h;
}

/**
 * Where each box goes when lined up on one edge (or the middle) of a frame: a list of `{x}` or `{y}`
 * in the order the boxes were given. The frame is the stage for one box, the selection's bounds for many.
 */
export function alignTo(boxes, frame, edge) {
    return boxes.map((box) => {
        switch (edge) {
            case 'left': return { x: Math.round(frame.x) };
            case 'center': return { x: Math.round(frame.x + (frame.w - box.w) / 2) };
            case 'right': return { x: Math.round(frame.x + frame.w - box.w) };
            case 'top': return { y: Math.round(frame.y) };
            case 'middle': return { y: Math.round(frame.y + (frame.h - box.h) / 2) };
            case 'bottom': return { y: Math.round(frame.y + frame.h - box.h) };
            default: return {};
        }
    });
}

/**
 * Even gaps between three or more boxes along one axis ('x' across, 'y' down): the outermost two stay
 * where they are and the rest are spaced between them. Returns each box's new position, in the order
 * the boxes were given.
 */
export function distribute(boxes, axis) {
    const at = axis === 'x' ? 'x' : 'y';
    const size = axis === 'x' ? 'w' : 'h';
    const order = boxes.map((box, index) => ({ box, index })).sort((a, b) => a.box[at] - b.box[at]);
    const first = order[0].box;
    const last = order[order.length - 1].box;
    const span = last[at] + last[size] - first[at];
    const filled = order.reduce((sum, { box }) => sum + box[size], 0);
    const gap = (span - filled) / Math.max(1, order.length - 1);
    const positions = new Array(boxes.length);
    let cursor = first[at];

    order.forEach(({ box, index }) => {
        positions[index] = Math.round(cursor);
        cursor += box[size] + gap;
    });

    return positions;
}

/** The closest snap for one axis: which of our lines to move, and by how much. */
function snapAxis(ownLines, targetLines, threshold) {
    let best = null;

    for (const own of ownLines) {
        for (const target of targetLines) {
            const distance = Math.abs(target - own);

            if (distance <= threshold && (best === null || distance < best.distance)) {
                best = { distance, shift: target - own, line: target };
            }
        }
    }

    return best;
}
