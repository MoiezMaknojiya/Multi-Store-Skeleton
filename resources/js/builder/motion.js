/**
 * The Animation tab and the stage preview (docs/AD-BUILDER-SPEC.md §8, stage 4).
 *
 * The preview runs `window.AdRuntime` — the very file a television runs — with the same Anime.js, so what
 * plays here is what plays on the screen. What it is running is kept in `motion`, a plain object OUTSIDE
 * Alpine's reactive state: an animation wrapped in a proxy is one the library can no longer drive
 * properly, and nothing on the page needs to watch it.
 *
 * Spread into the editor's component, so data and plain methods only — no getters (see the Alpine gotcha
 * in the project conventions).
 */
import {
    DEFAULT_CUBIC,
    easeFunction,
    easePath,
    ENTRANCE_FIELDS,
    formatCubic,
    graphPoint,
    graphX,
    graphY,
    hasAnimation,
    LOOP_FIELDS,
    newSlot,
    parseCubic,
} from './animation.js';
import { clone } from './history.js';
import { limit } from './styles.js';

export function motionPanel(motion) {
    return {
        /** null, 'all' (the Play button: every element, the stage locked) or 'element' (one, from the tab). */
        previewing: null,

        /** Which slots show their Ease Visualizer. */
        easeOpen: { in: false, loop: false, out: false },

        /* ── The slots ──────────────────────────────────────────────────── */

        slotOf(slot) {
            return this.selected?.animations?.[slot] ?? null;
        },

        animates(element) {
            return hasAnimation(element);
        },

        /** Whether the selected element's slot shows a control — each effect reads only some. */
        slotUses(slot, field) {
            const effect = this.slotOf(slot)?.effect;

            if (!effect) return false;

            return slot === 'loop'
                ? (LOOP_FIELDS[effect]?.fields ?? []).includes(field)
                : (ENTRANCE_FIELDS[effect] ?? []).includes(field);
        },

        /**
         * What a loop's `amount` means for its effect: a label and the range that makes sense — or, for an
         * effect with no reading of its own, the whole range AdAnimations::NUMBERS allows.
         */
        loopAmount() {
            const [min, max] = this.animationNumbers?.loop?.amount ?? [];

            return LOOP_FIELDS[this.slotOf('loop')?.effect]?.amount ?? { label: 'Amount', min, max };
        },

        /**
         * Start, change or clear a slot's effect. Changing an entrance keeps its timing and direction;
         * changing a loop starts from the new loop's own numbers, because the same `amount` means pixels
         * to one and per cent to another.
         */
        setEffect(slot, effect) {
            const element = this.selected;

            if (!element) return;

            const animations = { ...(element.animations ?? {}) };
            const current = animations[slot];

            if (!effect) {
                delete animations[slot];
            } else if (slot === 'loop') {
                animations.loop = { ...newSlot('loop', effect, this.animationNumbers), delay: current?.delay ?? 0 };
            } else {
                const fresh = newSlot(slot, effect, this.animationNumbers);
                const kept = current?.effect
                    ? { direction: current.direction, duration: current.duration, delay: current.delay, at: current.at }
                    : {};

                animations[slot] = { ...fresh, ...withoutEmpty(kept) };
            }

            element.animations = animations;
            this.commit('Animation');
            this.previewElement(element);
        },

        /** One value in a slot; numbers held inside AdAnimations::NUMBERS. Returns what was kept. */
        setSlot(slot, key, value) {
            const element = this.selected;
            const current = element?.animations?.[slot];

            if (!current) return value;

            let kept = value;
            const row = this.animationNumbers?.[slot]?.[key];

            if (row) {
                kept = limit({ [key]: row }, key, value, current[key] ?? row[2]);

                // A loop's amount is read differently by each effect — held inside that effect's range.
                if (slot === 'loop' && key === 'amount' && LOOP_FIELDS[current.effect]?.amount) {
                    const { min, max } = LOOP_FIELDS[current.effect].amount;
                    kept = Math.max(min, Math.min(max, kept));
                }
            }

            element.animations = { ...element.animations, [slot]: { ...current, [key]: kept } };
            this.commit('Animation');
            this.previewElement(element);

            return kept;
        },

        /** A spin's direction is the sign of its amount. */
        spinDirection() {
            return (this.slotOf('loop')?.amount ?? 1) < 0 ? 'anticlockwise' : 'clockwise';
        },

        setSpinDirection(direction) {
            this.setSlot('loop', 'amount', direction === 'anticlockwise' ? -1 : 1);
        },

        /* ── Eases ──────────────────────────────────────────────────────── */

        /** What the ease list shows: the name, or "custom" for a curve drawn by hand. */
        easeChoice(slot) {
            const ease = this.slotOf(slot)?.ease;

            return parseCubic(ease) ? 'custom' : (ease ?? 'power2.out');
        },

        chooseEase(slot, value) {
            if (value === 'custom') {
                const current = this.slotOf(slot)?.ease;

                this.setSlot(slot, 'ease', parseCubic(current) ? current : DEFAULT_CUBIC);
                this.easeOpen[slot] = true;

                return;
            }

            this.setSlot(slot, 'ease', value);
        },

        easeCurve(slot) {
            return easePath(this.slotOf(slot)?.ease ?? 'none', window.AdRuntime);
        },

        /** Where a custom curve's two handles sit on the graph, or null for a named ease. */
        easeHandles(slot) {
            const cubic = parseCubic(this.slotOf(slot)?.ease);

            if (!cubic) return null;

            return { x1: graphX(cubic[0]), y1: graphY(cubic[1]), x2: graphX(cubic[2]), y2: graphY(cubic[3]) };
        },

        /**
         * Drag one of a custom curve's handles. The curve follows the pointer, and the step is remembered
         * once, when the handle is let go — the same way moving an element is — and only if the curve
         * really changed: a press that never moved is not a step.
         */
        startEaseHandle(event, slot, handle) {
            event.preventDefault();
            event.stopPropagation();

            const svg = event.target.closest('svg');
            const element = this.selected;

            if (!svg || !element) return;

            const before = element.animations?.[slot]?.ease;

            const move = (moveEvent) => {
                // The pointer in the graph's own coordinates, through the SVG's own transform: the
                // 200×200 box is drawn "meet" — centred, at its own proportions — inside a wider one, so a
                // pointer read against the element's edges would land off to one side of the handle.
                const matrix = svg.getScreenCTM();

                if (!matrix) return;

                const point = new DOMPoint(moveEvent.clientX, moveEvent.clientY).matrixTransform(matrix.inverse());
                const [t, value] = graphPoint(point.x, point.y);
                const cubic = parseCubic(element.animations?.[slot]?.ease) ?? parseCubic(DEFAULT_CUBIC);

                if (handle === 1) {
                    cubic[0] = t;
                    cubic[1] = value;
                } else {
                    cubic[2] = t;
                    cubic[3] = value;
                }

                element.animations = {
                    ...element.animations,
                    [slot]: { ...element.animations[slot], ease: formatCubic(cubic) },
                };
            };

            const up = () => {
                window.removeEventListener('pointermove', move);
                window.removeEventListener('pointerup', up);

                if (element.animations?.[slot]?.ease === before) return;

                this.commit('Ease');
                this.tryEase(slot);
                this.previewElement(element);
            };

            window.addEventListener('pointermove', move);
            window.addEventListener('pointerup', up);
        },

        /** Run a dot along the graph, and along a track beneath it, at the ease's pace. */
        tryEase(slot) {
            const anime = window.anime;
            const dot = document.querySelector(`[data-ease-dot="${slot}"]`);
            const ball = document.querySelector(`[data-ease-ball="${slot}"]`);

            if (!anime || !dot) return;

            const fn = easeFunction(this.slotOf(slot)?.ease ?? 'none', window.AdRuntime);
            const progress = { t: 0 };

            motion.easeTweens[slot]?.cancel();
            motion.easeTweens[slot] = anime.animate(progress, {
                t: 1,
                duration: 1200,
                ease: 'linear',
                onUpdate: () => {
                    const value = fn(progress.t);

                    dot.setAttribute('cx', graphX(progress.t).toFixed(1));
                    dot.setAttribute('cy', graphY(value).toFixed(1));

                    if (ball) ball.style.left = `calc(${(value * 100).toFixed(2)}% - ${(value * 12).toFixed(2)}px)`;
                },
            });
        },

        /* ── Preview ────────────────────────────────────────────────────── */

        motionReady() {
            return Boolean(window.anime && window.AdRuntime);
        },

        togglePlay() {
            if (this.previewing === 'all') {
                this.stopPreview();

                return;
            }

            this.playAll();
        },

        /**
         * Play the whole advert as the television will: every visible element's animations, from the
         * top, with the videos running. The stage is locked while it plays — Stop, Esc or a click ends it.
         */
        playAll() {
            if (!this.motionReady()) {
                window.toast('The animation library did not load, so the ad cannot be previewed here.');

                return;
            }

            this.stopPreview();
            this.editingTextId = null;
            this.previewing = 'all';

            const config = {};

            this.doc.elements
                .filter((element) => element.visible !== false && hasAnimation(element))
                .forEach((element) => {
                    config[element.id] = clone(element.animations);
                });

            this.$nextTick(() => {
                if (this.previewing !== 'all') return;

                motion.running = window.AdRuntime.run(this.$refs.stage, config);
                this.stageVideos().forEach((video) => {
                    video.currentTime = 0;
                    video.play().catch(() => {});
                });
            });
        },

        /** Play one element's animations — what the Animation tab does after every change. */
        previewElement(element) {
            if (this.previewing === 'all' || !this.motionReady()) return;

            this.stopPreview();

            if (!element || element.visible === false || !hasAnimation(element)) return;

            this.previewing = 'element';

            this.$nextTick(() => {
                if (this.previewing !== 'element') return;

                const node = this.$refs.stage.querySelector(`[data-anim-id="${CSS.escape(element.id)}"] > .ad-anim`);

                if (node) motion.running = [window.AdRuntime.play(node, clone(element.animations))];
            });
        },

        /** Stop everything and put every element back exactly where it was designed. */
        stopPreview() {
            motion.running.forEach((animation) => animation.kill());
            motion.running = [];

            if (this.previewing === 'all') {
                this.stageVideos().forEach((video) => {
                    video.pause();
                    video.currentTime = 0;
                });
            }

            this.previewing = null;
        },

        stageVideos() {
            return [...(this.$refs.stage?.querySelectorAll('video') ?? [])];
        },
    };
}

/** An object without its undefined values, so spreading it never overwrites a default with nothing. */
function withoutEmpty(values) {
    return Object.fromEntries(Object.entries(values).filter(([, value]) => value !== undefined && value !== null));
}
