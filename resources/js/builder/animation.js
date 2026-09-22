/**
 * The Animation tab's model (docs/AD-BUILDER-SPEC.md §8, stage 4).
 *
 * An element has three slots — `in` (plays once when the advert appears), `loop` (repeats for as long as
 * it is on screen) and `out` (plays once, at a moment counted from the start). The effects themselves
 * live in `public/ad-runtime/runtime.js`, the one file the editor's preview and the television both run;
 * this module only knows how to START a slot with numbers that already look right, which controls each
 * effect needs, and how to draw an ease.
 *
 * The numbers' limits and defaults are not copied here: the page hands over AdAnimations::NUMBERS, the
 * table the server clamps with.
 */

/** Which of a slot's controls an effect uses. Anything not listed is kept but not shown. */
export const ENTRANCE_FIELDS = {
    fade: [],
    slide: ['direction', 'distance'],
    zoom: ['scale'],
    rotate: ['degrees'],
    blur: ['blur'],
    flip: ['direction'],
    wipe: ['direction'],
    bounce: ['direction', 'distance'],
};

/**
 * A loop's controls, and what its `amount` means — the one number every loop has, read differently by
 * each (pixels for a float, per cent for a pulse, degrees for a sway).
 */
export const LOOP_FIELDS = {
    float: { fields: ['axis', 'amount'], amount: { label: 'Distance (px)', min: 0, max: 2000 } },
    pulse: { fields: ['amount'], amount: { label: 'Grow by (%)', min: 0, max: 200 } },
    sway: { fields: ['amount'], amount: { label: 'Tilt (°)', min: 0, max: 180 } },
    drift: { fields: ['amountX', 'amountY'], amount: null },
    spin: { fields: ['spinDirection'], amount: null },
    blink: { fields: ['amount'], amount: { label: 'Faintest (%)', min: 0, max: 100 } },
    shake: { fields: ['amount'], amount: { label: 'Distance (px)', min: 0, max: 200 } },
    kenburns: { fields: ['amount', 'amountX', 'amountY'], amount: { label: 'Zoom in by (%)', min: 0, max: 100 } },
};

/** What each loop starts with — chosen so that picking one already looks like the effect's name. */
const LOOP_PRESETS = {
    float: { axis: 'y', amount: 10, duration: 2, ease: 'sine.inOut', yoyo: true },
    pulse: { amount: 6, duration: 0.8, ease: 'sine.inOut', yoyo: true },
    sway: { amount: 4, duration: 1.6, ease: 'sine.inOut', yoyo: true },
    drift: { amountX: 40, amountY: 0, duration: 4, ease: 'sine.inOut', yoyo: true },
    spin: { amount: 1, duration: 6, ease: 'none', yoyo: false },
    blink: { amount: 30, duration: 0.8, ease: 'sine.inOut', yoyo: true },
    shake: { amount: 6, duration: 0.1, ease: 'sine.inOut', yoyo: true },
    kenburns: { amount: 12, amountX: 0, amountY: 0, duration: 12, ease: 'sine.inOut', yoyo: true },
};

/** A curve to start a custom ease from: CSS's own `ease`. */
export const DEFAULT_CUBIC = 'cubic(0.25,0.1,0.25,1)';

/**
 * A slot, freshly started with an effect: every number at its table default, then whatever the effect
 * reads best with. The whole slot is written, so the panel always has a value to show.
 */
export function newSlot(slot, effect, numbers) {
    const defaults = Object.fromEntries(
        Object.entries(numbers?.[slot] ?? {}).map(([key, row]) => [key, row[2]]),
    );

    if (slot === 'loop') {
        return { effect, axis: 'y', yoyo: true, ...defaults, ...(LOOP_PRESETS[effect] ?? {}) };
    }

    return {
        effect,
        direction: 'up',
        ...defaults,
        ease: slot === 'out' ? 'power2.in' : (effect === 'bounce' ? 'bounce.out' : 'power2.out'),
    };
}

/** Whether an element does anything at all. */
export function hasAnimation(element) {
    const animations = element?.animations;

    return Boolean(animations && (animations.in?.effect || animations.loop?.effect || animations.out?.effect));
}

/* ── The Ease Visualizer ─────────────────────────────────────────────── */

/*
 * The graph is drawn in a 200×200 box: time runs left to right across 160 px, and the value climbs
 * 100 px from 0 to 1 — leaving half a unit of room above and below for the eases that overshoot
 * (back, elastic) and for a custom curve's handles.
 */
const GRAPH = { left: 20, width: 160, zero: 150, unit: 100 };

export function graphX(t) {
    return GRAPH.left + t * GRAPH.width;
}

export function graphY(value) {
    return GRAPH.zero - value * GRAPH.unit;
}

/** A point in the graph's own coordinates → [time, value], held inside what a handle may reach. */
export function graphPoint(x, y) {
    const t = (x - GRAPH.left) / GRAPH.width;
    const value = (GRAPH.zero - y) / GRAPH.unit;

    return [round(Math.max(0, Math.min(1, t))), round(Math.max(-0.5, Math.min(1.5, value)))];
}

/** `cubic(a,b,c,d)` → [a, b, c, d], or null for a named ease. */
export function parseCubic(ease) {
    if (typeof ease !== 'string') return null;

    const match = ease.match(/^cubic\(([^)]*)\)$/);

    if (!match) return null;

    const parts = match[1].split(',').map(Number);

    return parts.length === 4 && parts.every(Number.isFinite) ? parts : null;
}

export function formatCubic([x1, y1, x2, y2]) {
    return `cubic(${round(x1)},${round(y1)},${round(x2)},${round(y2)})`;
}

/**
 * The SVG path of an ease. A named one is sampled through the runtime's own `AdRuntime.ease` — the
 * function a television eases with — so the line is the motion the element will really make; a custom
 * curve is drawn from its four numbers directly.
 */
export function easePath(ease, runtime) {
    const cubic = parseCubic(ease);
    const points = [];

    if (cubic) {
        const [x1, y1, x2, y2] = cubic;

        for (let i = 0; i <= 64; i++) {
            const s = i / 64;
            points.push([bezier(s, x1, x2), bezier(s, y1, y2)]);
        }
    } else {
        const fn = easeFunction(ease, runtime);

        for (let i = 0; i <= 64; i++) {
            const t = i / 64;
            points.push([t, fn(t)]);
        }
    }

    return points
        .map(([t, value], index) => `${index === 0 ? 'M' : 'L'}${graphX(t).toFixed(1)},${graphY(value).toFixed(1)}`)
        .join(' ');
}

/**
 * An ease as a function of time, for the dot that runs along the graph. A custom curve is solved for
 * its time (the curve is written in x, y pairs, and x is time), so the dot moves as the element will.
 */
export function easeFunction(ease, runtime) {
    const cubic = parseCubic(ease);

    if (cubic) {
        const [x1, y1, x2, y2] = cubic;

        return (t) => bezier(solveForX(t, x1, x2), y1, y2);
    }

    const parsed = runtime?.ease?.(ease);

    return typeof parsed === 'function' ? parsed : (t) => t;
}

/** One coordinate of a cubic Bézier from (0,0) to (1,1), at parameter s. */
function bezier(s, p1, p2) {
    const inverse = 1 - s;

    return 3 * inverse * inverse * s * p1 + 3 * inverse * s * s * p2 + s * s * s;
}

/** The parameter at which the curve reaches time x — bisection, which cannot fail on a monotonic x. */
function solveForX(x, x1, x2) {
    let low = 0;
    let high = 1;

    for (let i = 0; i < 40; i++) {
        const middle = (low + high) / 2;

        if (bezier(middle, x1, x2) < x) low = middle;
        else high = middle;
    }

    return (low + high) / 2;
}

function round(value) {
    return Math.round(value * 100) / 100;
}
