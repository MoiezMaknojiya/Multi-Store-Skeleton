/**
 * The Background panel (docs/AD-BUILDER-SPEC.md §6, stage 3): the stage's own colour and the layers
 * stacked on it, shown whenever nothing on the stage is selected — and the gradient editor it shares
 * with shapes.
 *
 * Spread into the editor's component, so it holds data and plain methods ONLY: a getter here would be
 * read once at spread time with the wrong `this` (see the Alpine gotcha in the project conventions).
 */
import { layerLabel, layerStyle, newGradient, newLayer } from './background.js';
import { newId } from './document.js';
import { clone } from './history.js';
import { gradientCss, limit } from './styles.js';

export function backgroundPanel() {
    return {
        selectedLayerId: null,

        /* ── The stage's colour ─────────────────────────────────────────── */

        stageColour() {
            return this.doc.stage.background.color ?? '#000000';
        },

        setStageColour(value) {
            this.doc.stage.background.color = value;
            this.commit('Background');
        },

        /* ── Layers ─────────────────────────────────────────────────────── */

        /** Front first, the way every layers panel reads — the document keeps them back first. */
        layersFrontFirst() {
            return [...this.doc.stage.background.layers].reverse();
        },

        selectedLayer() {
            return this.doc.stage.background.layers.find((layer) => layer.id === this.selectedLayerId) ?? null;
        },

        selectLayer(layer) {
            this.selectedLayerId = layer.id;
        },

        canAddLayer() {
            return this.doc.stage.background.layers.length < this.maxLayers;
        },

        /** A new layer goes on top of the others. A picture or video layer asks for its file at once. */
        addLayer(type) {
            if (!this.canAddLayer()) {
                window.toast(`A background may stack at most ${this.maxLayers} layers.`);

                return;
            }

            const layer = newLayer(type);

            this.doc.stage.background.layers.push(layer);
            this.selectedLayerId = layer.id;
            this.commit('Add layer');

            if (type === 'image' || type === 'video') this.openAssetPicker('layer', type);
        },

        /** +1 moves a layer towards the front, -1 towards the back. */
        moveLayer(layer, direction) {
            const layers = this.doc.stage.background.layers;
            const index = layers.findIndex((row) => row.id === layer.id);
            const target = index + direction;

            if (index < 0 || target < 0 || target >= layers.length) return;

            const moved = layers.splice(index, 1)[0];
            layers.splice(target, 0, moved);
            this.commit('Reorder layers');
        },

        toggleLayer(layer) {
            layer.visible = layer.visible === false;
            this.commit('Layer visibility');
        },

        duplicateLayer(layer) {
            if (!this.canAddLayer()) {
                window.toast(`A background may stack at most ${this.maxLayers} layers.`);

                return;
            }

            const layers = this.doc.stage.background.layers;
            const copy = { ...clone(layer), id: newId('bg') };

            layers.splice(layers.findIndex((row) => row.id === layer.id) + 1, 0, copy);
            this.selectedLayerId = copy.id;
            this.commit('Duplicate layer');
        },

        removeLayer(layer) {
            this.doc.stage.background.layers = this.doc.stage.background.layers.filter((row) => row.id !== layer.id);

            if (this.selectedLayerId === layer.id) this.selectedLayerId = null;

            this.commit('Delete layer');
        },

        setLayer(key, value) {
            const layer = this.selectedLayer();

            if (!layer) return;

            layer[key] = value;
            this.commit('Layer');
        },

        /** A layer's number, held inside AdCompiler::LIMITS (`layer.opacity`, `layer.scale`). Returns what was kept. */
        setLayerNumber(key, value) {
            const layer = this.selectedLayer();

            if (!layer) return value;

            const number = limit(this.limits, 'layer.' + key, value, null);

            if (number === null) return layer[key];

            layer[key] = number;
            this.commit('Layer');

            return number;
        },

        /** The picture or video the asset picker chose for the selected layer. */
        setLayerAsset(asset) {
            const layer = this.selectedLayer();

            this.assetPickerOpen = false;

            if (!layer || asset.kind !== layer.type) return;

            layer.assetId = asset.id;
            this.commit('Layer file');
        },

        layerAsset(layer) {
            return layer.assetId ? (this.assets.find((asset) => asset.id === layer.assetId) ?? null) : null;
        },

        layerName(layer) {
            return layerLabel(layer, this.layerAsset(layer)?.title);
        },

        /** How a layer is drawn on the stage, or null when the television would not draw it either. */
        layerStyleFor(layer) {
            return layerStyle(layer, this.layerAsset(layer)?.url ?? null, this.limits, this.maxStops);
        },

        /** The little square beside a layer's name. */
        layerSwatch(layer) {
            if (layer.type === 'color') return { backgroundColor: layer.color ?? 'transparent' };
            if (layer.type === 'gradient') return { backgroundImage: gradientCss(layer.gradient, this.limits, this.maxStops) ?? 'none' };

            const asset = this.layerAsset(layer);
            const picture = asset?.thumbnail_url ?? (asset?.kind === 'image' ? asset.url : null);

            return picture
                ? { backgroundImage: `url("${encodeURI(picture)}")`, backgroundSize: 'cover', backgroundPosition: 'center' }
                : { backgroundColor: 'transparent' };
        },

        /* ── Gradients: one editor for a layer's and a shape's ─────────── */

        /** The gradient being edited: the selected layer's, or the selected shape's fill. */
        gradientOf(target) {
            return target === 'layer'
                ? (this.selectedLayer()?.gradient ?? null)
                : (this.selected?.style?.gradient ?? null);
        },

        writeGradient(target, gradient, label = 'Gradient') {
            if (target === 'layer') {
                const layer = this.selectedLayer();

                if (!layer) return;

                layer.gradient = gradient;
            } else {
                const element = this.selected;

                if (!element) return;

                element.style = { ...(element.style ?? {}), gradient };
            }

            this.commit(label);
        },

        /** Kind or angle. Returns the value kept, so a number input can show it. */
        setGradient(target, key, value) {
            const gradient = this.gradientOf(target);

            if (!gradient) return value;

            const kept = key === 'angle' ? limit(this.limits, 'gradient.angle', value, gradient.angle ?? 180) : value;

            this.writeGradient(target, { ...gradient, [key]: kept });

            return kept;
        },

        setStop(target, index, key, value) {
            const gradient = this.gradientOf(target);

            if (!gradient) return value;

            const kept = key === 'at' ? limit(this.limits, 'gradient.at', value, gradient.stops[index]?.at ?? 0) : value;
            const stops = gradient.stops.map((stop, i) => (i === index ? { ...stop, [key]: kept } : { ...stop }));

            this.writeGradient(target, { ...gradient, stops });

            return kept;
        },

        addStop(target) {
            const gradient = this.gradientOf(target);

            if (!gradient || gradient.stops.length >= this.maxStops) return;

            const last = gradient.stops[gradient.stops.length - 1];

            this.writeGradient(target, {
                ...gradient,
                stops: [...gradient.stops.map((stop) => ({ ...stop })), { color: last?.color ?? '#ffffff', at: 100 }],
            }, 'Add colour stop');
        },

        removeStop(target, index) {
            const gradient = this.gradientOf(target);

            if (!gradient || gradient.stops.length <= 2) return;

            this.writeGradient(target, {
                ...gradient,
                stops: gradient.stops.filter((_, i) => i !== index).map((stop) => ({ ...stop })),
            }, 'Remove colour stop');
        },

        /** The stops left to right, for the strip above them. */
        gradientPreview(target) {
            const gradient = this.gradientOf(target);

            return gradient ? (gradientCss({ ...gradient, kind: 'linear', angle: 90 }, this.limits, this.maxStops) ?? 'none') : 'none';
        },

        /** A shape is filled with one colour or with a gradient. */
        setShapeFill(kind) {
            const element = this.selected;

            if (!element) return;

            if (kind === 'gradient' && !element.style?.gradient) this.setStyle('gradient', newGradient());
            if (kind === 'solid' && element.style?.gradient) this.setStyle('gradient', null);
        },
    };
}
