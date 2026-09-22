/**
 * What an element looks like on the editor's stage (docs/AD-BUILDER-SPEC.md §7, stages 2–4).
 *
 * A mirror of AdCompiler's writers — text(), pictureStyle(), shapeStyle(), boxStyle() — with the same
 * defaults and the same limits, so the stage a person designs on IS the page the television gets. The
 * tables are not copied here: the page hands over AdCompiler::LIMITS and AdCompiler::FILTERS themselves,
 * and BuilderAdRequest::MAX_STOPS, and every number below is held inside them.
 *
 * Every function returns a style OBJECT for Alpine's `:style`, never a CSS string: the browser then sets
 * each property on its own, so no value can ever spill into the next declaration.
 */

const HEX = /^#[0-9a-fA-F]{3,8}$/;
const RGB = /^rgba?\(\s*[\d.]+\s*,\s*[\d.]+\s*,\s*[\d.]+\s*(,\s*[\d.]+\s*)?\)$/;

/** The nine places a picture can be pinned to, row by row, as CSS says them. */
export const POSITIONS = [
    'left top', 'center top', 'right top',
    'left center', 'center center', 'right center',
    'left bottom', 'center bottom', 'right bottom',
];

/** A colour the compiler would keep (hex, rgb(), rgba()), or null. */
export function colour(value) {
    if (typeof value !== 'string') return null;

    const trimmed = value.trim();

    return HEX.test(trimmed) || RGB.test(trimmed) ? trimmed : null;
}

/** A number held inside its row of the limits table; the fallback when it is no number at all. */
export function limit(limits, key, value, fallback) {
    const [min, max] = limits?.[key] ?? [-Infinity, Infinity];
    const number = Number(value);

    if (value === null || value === undefined || value === '' || !Number.isFinite(number)) return fallback;

    return Math.round(Math.max(min, Math.min(max, number)) * 1000) / 1000;
}

/** "Playfair Display" → `'Playfair Display', sans-serif`, with anything that is not a name removed. */
function fontStack(family) {
    const name = (typeof family === 'string' ? family : '')
        .replace(/[^A-Za-z0-9 -]/g, ' ')
        .replace(/\s+/g, ' ')
        .trim()
        .slice(0, 64);

    return name === '' ? 'sans-serif' : `'${name}', sans-serif`;
}

/**
 * A gradient's CSS, or null when it is not a real one (fewer than two stops with real colours). Only the
 * first `maxStops` stops are drawn (BuilderAdRequest::MAX_STOPS, handed over by the page) — no more than
 * a save would accept.
 */
export function gradientCss(gradient, limits, maxStops) {
    if (!gradient || !Array.isArray(gradient.stops)) return null;

    const stops = gradient.stops
        .slice(0, maxStops)
        .map((stop) => ({ color: colour(stop?.color), at: limit(limits, 'gradient.at', stop?.at, 0) }))
        .filter((stop) => stop.color !== null)
        .map((stop) => `${stop.color} ${stop.at}%`);

    if (stops.length < 2) return null;

    return gradient.kind === 'radial'
        ? `radial-gradient(circle at center, ${stops.join(', ')})`
        : `linear-gradient(${limit(limits, 'gradient.angle', gradient.angle, 180)}deg, ${stops.join(', ')})`;
}

/** `{width, style, color}` → a frame, or nothing. */
function borderCss(border, limits) {
    const width = limit(limits, 'border.width', border?.width, 0);
    const color = colour(border?.color);

    if (!border || !color || width === 0) return {};

    const style = ['solid', 'dashed', 'dotted', 'double'].includes(border.style) ? border.style : 'solid';

    return { border: `${width}px ${style} ${color}` };
}

/** `{x, y, blur, spread, color}` → a drop shadow, or nothing. */
function boxShadowCss(shadow, limits) {
    const color = colour(shadow?.color);

    if (!shadow || !color) return {};

    return {
        boxShadow: [
            limit(limits, 'shadow.x', shadow.x, 0) + 'px',
            limit(limits, 'shadow.y', shadow.y, 0) + 'px',
            limit(limits, 'shadow.blur', shadow.blur, 0) + 'px',
            limit(limits, 'shadow.spread', shadow.spread, 0) + 'px',
            color,
        ].join(' '),
    };
}

/**
 * The filters changed from neutral, in CSS's own words — or null. `table` is AdCompiler::FILTERS, handed
 * over by the page: each filter's [css name, unit, neutral], in the order CSS applies them.
 */
function filterCss(filters, limits, table) {
    if (!filters || typeof filters !== 'object') return null;

    const parts = Object.entries(table ?? {})
        .filter(([key]) => filters[key] !== undefined && filters[key] !== null)
        .map(([key, [name, unit, neutral]]) => [name, unit, neutral, limit(limits, `filters.${key}`, filters[key], neutral)])
        .filter(([, , neutral, value]) => value !== neutral)
        .map(([name, unit, , value]) => `${name}(${value}${unit})`);

    return parts.length > 0 ? parts.join(' ') : null;
}

/** A run of words: AdCompiler::text(). */
export function textCss(style = {}, limits) {
    const css = {
        fontFamily: fontStack(style.fontFamily),
        fontSize: limit(limits, 'fontSize', style.fontSize, 48) + 'px',
        fontWeight: Math.round(limit(limits, 'fontWeight', style.fontWeight, 400)),
        fontStyle: style.fontStyle === 'italic' ? 'italic' : 'normal',
        color: colour(style.color) ?? '#ffffff',
        textAlign: ['left', 'center', 'right', 'justify'].includes(style.align) ? style.align : 'left',
        lineHeight: limit(limits, 'lineHeight', style.lineHeight, 1.2),
        letterSpacing: limit(limits, 'letterSpacing', style.letterSpacing, 0) + 'px',
        wordSpacing: limit(limits, 'wordSpacing', style.wordSpacing, 0) + 'px',
        textTransform: ['none', 'uppercase', 'lowercase', 'capitalize'].includes(style.textTransform) ? style.textTransform : 'none',
        textDecoration: ['none', 'underline', 'line-through', 'overline'].includes(style.textDecoration) ? style.textDecoration : 'none',
        display: 'flex',
        flexDirection: 'column',
        justifyContent: ['flex-start', 'center', 'flex-end'].includes(style.verticalAlign) ? style.verticalAlign : 'flex-start',
        padding: limit(limits, 'padding', style.padding, 0) + 'px',
        whiteSpace: 'pre-wrap',
        wordBreak: 'break-word',
        boxSizing: 'border-box',
    };

    const panel = colour(style.background);

    if (panel) {
        css.background = panel;
        css.borderRadius = limit(limits, 'radius', style.radius, 0) + 'px';
    }

    const shadow = style.textShadow;

    if (shadow && colour(shadow.color)) {
        css.textShadow = `${limit(limits, 'textShadow.x', shadow.x, 0)}px ${limit(limits, 'textShadow.y', shadow.y, 0)}px `
            + `${limit(limits, 'textShadow.blur', shadow.blur, 0)}px ${colour(shadow.color)}`;
    }

    const stroke = style.textStroke;
    const strokeWidth = limit(limits, 'textStroke.width', stroke?.width, 0);

    if (stroke && colour(stroke.color) && strokeWidth !== 0) {
        // Written with its prefix as the key: Alpine turns camelCase keys into kebab-case, and
        // `webkitTextStroke` would come out as `webkit-text-stroke`, which no browser knows.
        css['-webkit-text-stroke'] = `${strokeWidth}px ${colour(stroke.color)}`;
        css.paintOrder = 'stroke fill';
    }

    return { ...css, ...borderCss(style.border, limits) };
}

/** A picture or a video inside its box: AdCompiler::pictureStyle(). `filterTable`: AdCompiler::FILTERS. */
export function pictureCss(style = {}, limits, filterTable) {
    const css = {
        width: '100%',
        height: '100%',
        display: 'block',
        boxSizing: 'border-box',
        objectFit: ['cover', 'contain', 'fill', 'none', 'scale-down'].includes(style.fit) ? style.fit : 'cover',
        objectPosition: POSITIONS.includes(style.position) ? style.position : 'center center',
        borderRadius: limit(limits, 'radius', style.radius, 0) + 'px',
        ...borderCss(style.border, limits),
        ...boxShadowCss(style.shadow, limits),
    };

    const filter = filterCss(style.filters, limits, filterTable);

    if (filter) css.filter = filter;

    if (style.flipX === true || style.flipY === true) {
        css.transform = `scale(${style.flipX === true ? -1 : 1}, ${style.flipY === true ? -1 : 1})`;
    }

    return css;
}

/** A rectangle or an ellipse: AdCompiler::shapeStyle(). `maxStops`: BuilderAdRequest::MAX_STOPS. */
export function shapeCss(style = {}, limits, maxStops) {
    const gradient = gradientCss(style.gradient, limits, maxStops);

    return {
        width: '100%',
        height: '100%',
        boxSizing: 'border-box',
        ...(gradient ? { backgroundImage: gradient } : { backgroundColor: colour(style.fill) ?? '#2563eb' }),
        borderRadius: style.shape === 'ellipse' ? '50%' : limit(limits, 'radius', style.radius, 0) + 'px',
        ...borderCss(style.border, limits),
        ...boxShadowCss(style.shadow, limits),
    };
}

/**
 * Where an element sits: AdCompiler::boxStyle(). Its z-index starts at 1, above the background, and a
 * Ken Burns loop clips the box so the photograph zooms inside its own frame.
 */
export function boxCss(element, limits) {
    const style = element.style ?? {};
    const rotation = limit(limits, 'rotation', element.rotation, 0);
    const css = {
        left: limit(limits, 'x', element.x, 0) + 'px',
        top: limit(limits, 'y', element.y, 0) + 'px',
        width: limit(limits, 'w', element.w, 100) + 'px',
        height: limit(limits, 'h', element.h, 100) + 'px',
        opacity: limit(limits, 'opacity', element.opacity, 1),
        zIndex: 1 + Math.max(0, Math.round(Number(element.z) || 0)),
        transform: rotation !== 0 ? `rotate(${rotation}deg)` : null,
        mixBlendMode: style.blend && style.blend !== 'normal' ? style.blend : null,
        display: element.visible === false ? 'none' : null,
    };

    if (element.animations?.loop?.effect === 'kenburns') {
        css.overflow = 'hidden';
        css.borderRadius = limit(limits, 'radius', style.radius, 0) + 'px';
    }

    return css;
}
