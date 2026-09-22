/**
 * The stage's background layers in the editor (docs/AD-BUILDER-SPEC.md §6, stage 3).
 *
 * A mirror of AdCompiler::background(): the same layer kinds, the same defaults and the same CSS, so the
 * stage in the editor is the stage the television gets. Each layer is its own full-stage box with its
 * own opacity and blend mode — which is what lets a texture sit at 30 % over a photograph, or a gradient
 * multiply over a video — and the first layer in the list is the one furthest back.
 */
import { newId } from './document.js';
import { colour, gradientCss, limit, POSITIONS } from './styles.js';

/** A new layer of a kind, with defaults that already look like something. */
export function newLayer(type) {
    const base = {
        id: newId('bg'),
        type,
        visible: true,
        opacity: 1,
        blend: 'normal',
    };

    if (type === 'color') return { ...base, color: '#1e3a8a', opacity: 0.6 };

    if (type === 'gradient') return { ...base, gradient: newGradient() };

    if (type === 'image') {
        return { ...base, assetId: null, size: 'cover', scale: 100, position: 'center center', repeat: 'no-repeat' };
    }

    return { ...base, assetId: null, fit: 'cover' };
}

/** A gradient to start from: two stops, diagonal — which is what most people reach for first. */
export function newGradient() {
    return {
        kind: 'linear',
        angle: 135,
        stops: [{ color: '#1e3a8a', at: 0 }, { color: '#9333ea', at: 100 }],
    };
}

/**
 * The style one layer is drawn with on the editor's stage — or null when the compiler would leave it
 * out (a colour that is not one, a gradient with fewer than two stops, a picture nobody chose yet).
 * `maxStops` is BuilderAdRequest::MAX_STOPS, handed over by the page.
 */
export function layerStyle(layer, assetUrl, limits, maxStops) {
    const style = {
        opacity: limit(limits, 'layer.opacity', layer.opacity, 1),
        mixBlendMode: layer.blend && layer.blend !== 'normal' ? layer.blend : null,
    };

    if (layer.type === 'color') {
        const fill = colour(layer.color);

        return fill ? { ...style, backgroundColor: fill } : null;
    }

    if (layer.type === 'gradient') {
        const gradient = gradientCss(layer.gradient, limits, maxStops);

        return gradient ? { ...style, backgroundImage: gradient } : null;
    }

    if (layer.type === 'image') {
        if (!assetUrl) return null;

        return {
            ...style,
            backgroundImage: `url("${encodeURI(assetUrl)}")`,
            backgroundSize: layer.size === 'custom'
                ? limit(limits, 'layer.scale', layer.scale, 100) + '%'
                : (['cover', 'contain', 'auto'].includes(layer.size) ? layer.size : 'cover'),
            backgroundPosition: POSITIONS.includes(layer.position) ? layer.position : 'center center',
            backgroundRepeat: ['no-repeat', 'repeat', 'repeat-x', 'repeat-y'].includes(layer.repeat) ? layer.repeat : 'no-repeat',
        };
    }

    if (layer.type === 'video') {
        return assetUrl ? { ...style, objectFit: layer.fit === 'contain' ? 'contain' : 'cover' } : null;
    }

    return null;
}

/** A layer's name in the list, the way a person would say it. */
export function layerLabel(layer, assetTitle) {
    if (layer.type === 'color') return `Colour ${layer.color ?? ''}`.trim();
    if (layer.type === 'gradient') return layer.gradient?.kind === 'radial' ? 'Radial gradient' : 'Linear gradient';
    if (layer.type === 'image') return assetTitle ? `Picture · ${assetTitle}` : 'Picture (choose one)';

    return assetTitle ? `Video · ${assetTitle}` : 'Video (choose one)';
}
