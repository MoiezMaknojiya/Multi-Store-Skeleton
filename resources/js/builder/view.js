/**
 * How the stage is looked at (docs/AD-BUILDER-SPEC.md §10a): zoom and pan, the rulers and the guides
 * dragged out of them, and the History panel.
 *
 * The stage sits in a FRAME that is zoomed and panned as one piece; the rulers, guides and selection
 * handles are drawn inside that frame but outside the stage itself, so they line up at any zoom, are
 * never clipped at the stage's edge, and never end up in a poster.
 *
 * Spread into the editor's component, so data and plain methods only — no getters.
 */
import { normaliseDocument, readPreference, writePreference } from './document.js';

/** Zoom bounds. Below 10 % nothing is readable; above 400 % nothing is placeable. */
const MIN_ZOOM = 0.1;
const MAX_ZOOM = 4;

/** How thick a ruler is on screen, whatever the zoom. */
const RULER = 20;

export function viewPanel() {
    return {
        panX: 0,
        panY: 0,

        /** Space held down: the next drag pans instead of selecting. */
        spaceHeld: false,

        showRulers: readPreference('rulers', true),
        historyOpen: false,
        shortcutsOpen: false,

        /** A guide being dragged — out of a ruler (index null) or an existing one — or null. */
        draggingGuide: null,

        /* ── Zoom and pan ───────────────────────────────────────────────── */

        canvasStyle() {
            return {
                width: this.stage.width + 'px',
                height: this.stage.height + 'px',
                transform: `translate(${this.panX}px, ${this.panY}px) scale(${this.zoom})`,
            };
        },

        /** Fit the whole stage in the space there is, with room for the rulers, and centre it. */
        zoomToFit() {
            const frame = this.$refs.viewport;

            if (!frame) return;

            const room = this.showRulers ? 120 : 80;
            const scale = Math.min(
                (frame.clientWidth - room) / this.stage.width,
                (frame.clientHeight - room) / this.stage.height,
            );

            this.zoom = Math.max(MIN_ZOOM, Math.min(MAX_ZOOM, scale));
            this.panX = 0;
            this.panY = 0;
        },

        zoomTo(value) {
            this.zoom = Math.max(MIN_ZOOM, Math.min(MAX_ZOOM, Math.round(value * 100) / 100));
            this.clampPan();
        },

        zoomBy(step) {
            this.zoomTo(this.zoom + step);
        },

        /** Ctrl+wheel (and a trackpad's pinch) zooms; the wheel on its own pans. */
        onWheel(event) {
            if (event.ctrlKey || event.metaKey) {
                this.zoomTo(this.zoom * (event.deltaY < 0 ? 1.1 : 1 / 1.1));

                return;
            }

            this.panX -= event.shiftKey ? event.deltaY : event.deltaX;
            this.panY -= event.shiftKey ? 0 : event.deltaY;
            this.clampPan();
        },

        /** Space+drag, or a drag started while Space is held. */
        startPan(event) {
            event.preventDefault();

            const start = { x: event.clientX, y: event.clientY, panX: this.panX, panY: this.panY };
            const move = (moveEvent) => {
                this.panX = start.panX + (moveEvent.clientX - start.x);
                this.panY = start.panY + (moveEvent.clientY - start.y);
                this.clampPan();
            };
            const up = () => {
                window.removeEventListener('pointermove', move);
                window.removeEventListener('pointerup', up);
            };

            window.addEventListener('pointermove', move);
            window.addEventListener('pointerup', up);
        },

        /** Never so far that the stage leaves the window altogether. */
        clampPan() {
            const viewport = this.$refs.viewport;

            if (!viewport) return;

            const limitX = (this.stage.width * this.zoom + viewport.clientWidth) / 2 - 80;
            const limitY = (this.stage.height * this.zoom + viewport.clientHeight) / 2 - 80;

            this.panX = Math.max(-limitX, Math.min(limitX, this.panX));
            this.panY = Math.max(-limitY, Math.min(limitY, this.panY));
        },

        /** A pointer's place on the stage, in stage pixels — wherever the frame is and however zoomed. */
        toStagePoint(clientX, clientY) {
            const rect = this.$refs.stage.getBoundingClientRect();

            return { x: (clientX - rect.left) / this.zoom, y: (clientY - rect.top) / this.zoom };
        },

        /* ── Rulers ─────────────────────────────────────────────────────── */

        toggleRulers() {
            this.showRulers = !this.showRulers;
            writePreference('rulers', this.showRulers);
        },

        /** A ruler's box: RULER screen pixels thick at any zoom, ticks every 20 and 100 stage pixels. */
        rulerStyle(axis) {
            const thickness = RULER / this.zoom;
            const line = 1 / this.zoom;
            const direction = axis === 'x' ? 'to right' : 'to bottom';
            const tick = (colour, every) => `repeating-linear-gradient(${direction}, ${colour} 0, ${colour} ${line}px, transparent ${line}px, transparent ${every}px)`;
            const place = axis === 'x'
                ? { left: '0', top: -thickness + 'px', width: this.stage.width + 'px', height: thickness + 'px' }
                : { top: '0', left: -thickness + 'px', height: this.stage.height + 'px', width: thickness + 'px' };

            return {
                ...place,
                backgroundImage: `${tick('#9ca3af', 100)}, ${tick('#d1d5db', 20)}`,
                backgroundSize: axis === 'x' ? '100% 100%, 100% 35%' : '100% 100%, 35% 100%',
                backgroundPosition: axis === 'x' ? 'left bottom, left bottom' : 'right top, right top',
                backgroundRepeat: 'no-repeat',
            };
        },

        rulerTicks(axis) {
            const size = axis === 'x' ? this.stage.width : this.stage.height;

            return Array.from({ length: Math.floor(size / 100) + 1 }, (_, index) => index * 100);
        },

        rulerLabelStyle(axis, tick) {
            const text = { fontSize: 9 / this.zoom + 'px', lineHeight: '1' };

            return axis === 'x'
                ? { ...text, left: tick + 3 / this.zoom + 'px', top: 2 / this.zoom + 'px' }
                : { ...text, top: tick + 3 / this.zoom + 'px', left: 2 / this.zoom + 'px' };
        },

        /* ── Guides ─────────────────────────────────────────────────────── */

        /** A guide's hit area — wider than its line, so it can be caught — across the whole stage. */
        guideStyle(axis, value) {
            const reach = 4 / this.zoom;

            return axis === 'x'
                ? { left: value - reach + 'px', top: '0', width: reach * 2 + 'px', height: this.stage.height + 'px' }
                : { top: value - reach + 'px', left: '0', height: reach * 2 + 'px', width: this.stage.width + 'px' };
        },

        guideLineStyle(axis) {
            return axis === 'x'
                ? { width: 1 / this.zoom + 'px', height: '100%', margin: '0 auto' }
                : { height: 1 / this.zoom + 'px', width: '100%', marginTop: 4 / this.zoom - 0.5 / this.zoom + 'px' };
        },

        /** Drag out of a ruler: the top ruler makes a guide across (y), the left ruler one down (x). */
        startGuideFromRuler(event, axis) {
            if (event.button !== 0) return;

            this.dragGuide(event, axis, null);
        },

        startGuideDrag(event, axis, index) {
            if (event.button !== 0) return;

            this.dragGuide(event, axis, index);
        },

        /**
         * Follow the pointer; let go on the stage to place the guide, anywhere else — back on its ruler —
         * to take it away.
         */
        dragGuide(event, axis, index) {
            event.preventDefault();
            event.stopPropagation();

            const size = axis === 'x' ? this.stage.width : this.stage.height;
            const valueAt = (pointerEvent) => Math.round(this.toStagePoint(pointerEvent.clientX, pointerEvent.clientY)[axis]);
            const pressedAt = { x: event.clientX, y: event.clientY };

            this.draggingGuide = { axis, index, value: valueAt(event) };

            const move = (moveEvent) => {
                this.draggingGuide = { axis, index, value: valueAt(moveEvent) };
            };
            const up = (upEvent) => {
                window.removeEventListener('pointermove', move);
                window.removeEventListener('pointerup', up);

                const value = valueAt(upEvent);
                const kept = value >= 0 && value <= size;
                const guides = [...this.doc.guides[axis]];

                this.draggingGuide = null;

                // A press let go where it began is a click, not a drag. On a guide it lands anywhere in
                // the hit area, a few pixels either side of the line, and must neither move the guide nor
                // take it away; on a ruler it makes nothing.
                if (upEvent.clientX === pressedAt.x && upEvent.clientY === pressedAt.y) return;

                if (index === null && kept) {
                    if (guides.length >= this.maxGuides) {
                        window.toast(`A stage may keep at most ${this.maxGuides} guides each way.`);

                        return;
                    }

                    guides.push(value);
                    this.doc.guides[axis] = guides;
                    this.commit('Add guide');
                } else if (index !== null && kept) {
                    // Dragged away and let go where it already was: nothing moved, nothing to remember.
                    if (guides[index] === value) return;

                    guides[index] = value;
                    this.doc.guides[axis] = guides;
                    this.commit('Move guide');
                } else if (index !== null) {
                    guides.splice(index, 1);
                    this.doc.guides[axis] = guides;
                    this.commit('Remove guide');
                }
            };

            window.addEventListener('pointermove', move);
            window.addEventListener('pointerup', up);
        },

        /* ── History panel ──────────────────────────────────────────────── */

        /** Every step, newest first: its label, and whether it is where the design is now or undone. */
        historySteps() {
            if (!this.history) return [];

            const position = this.history.position();

            return this.history.steps()
                .map((label, index) => ({ label, index, current: index === position, undone: index > position }))
                .reverse();
        },

        jumpToStep(index) {
            const document = this.history.jumpTo(index);

            if (!document) return;

            this.stopPreview();
            this.doc = normaliseDocument(document);
            this.markChanged();
            this.leaveMissingGroup();
            this.ensureSelectionExists();
        },
    };
}
