/**
 * The Ad Builder's animation runtime (docs/AD-BUILDER-SPEC.md §8).
 *
 * ONE file, used in two places: the editor's Play button, and every published advert on a television.
 * That is the whole point of it being here rather than in the panel's bundle — what a person previews is
 * exactly what the screen runs, with the same Anime.js (MIT), from the same fixed address.
 *
 * Written in plain ES5 on purpose: the box behind a shop's television may run an old Chromium, and an
 * advert that dies on an arrow function is a black screen.
 *
 * Everything it reads is DATA. An effect is a name looked up in a table this file owns, a number is
 * clamped, an ease is a name from a list or four numbers — nothing a person typed is ever evaluated.
 * The server applies the same limits before the page is written; this is the second wall.
 *
 * The model, per element:
 *   in    — plays once, when the advert appears (fade, slide, zoom, rotate, blur, flip, wipe, bounce)
 *   loop  — then repeats for as long as the advert is on screen (float, pulse, sway, drift, spin,
 *           blink, shake, kenburns). The owner's example — "fade in, then float up and down about
 *           10 px for ever" — is `in: fade` + `loop: float, axis y, amount 10`.
 *   out   — plays once, at a moment counted from when the advert appeared, and stops the loop.
 * Nothing depends on how long the playlist gives the advert: the loop simply keeps going.
 */
(function (global) {
    'use strict';

    /**
     * The eases a person may pick. They keep the spelling GSAP gave them, which is how every saved design
     * names them, and are drawn with Anime.js's own curves (see `easeFunction`). Anything else becomes
     * the slot's fallback.
     */
    var EASES = [
        'none',
        'power1.in', 'power1.out', 'power1.inOut',
        'power2.in', 'power2.out', 'power2.inOut',
        'power3.in', 'power3.out', 'power3.inOut',
        'power4.in', 'power4.out', 'power4.inOut',
        'sine.in', 'sine.out', 'sine.inOut',
        'expo.in', 'expo.out', 'expo.inOut',
        'circ.in', 'circ.out', 'circ.inOut',
        'back.in', 'back.out', 'back.inOut',
        'elastic.in', 'elastic.out', 'elastic.inOut',
        'bounce.in', 'bounce.out', 'bounce.inOut'
    ];

    /** Each family's name in Anime.js: power1 is a square, power2 a cube… (the curves are identical). */
    var FAMILIES = {
        power1: 'Quad', power2: 'Cubic', power3: 'Quart', power4: 'Quint',
        sine: 'Sine', expo: 'Expo', circ: 'Circ', back: 'Back', elastic: 'Elastic', bounce: 'Bounce'
    };

    var ENTRANCES = ['fade', 'slide', 'zoom', 'rotate', 'blur', 'flip', 'wipe', 'bounce'];
    var LOOPS = ['float', 'pulse', 'sway', 'drift', 'spin', 'blink', 'shake', 'kenburns'];
    var DIRECTIONS = ['up', 'down', 'left', 'right'];

    /* ── Readers: every value goes through one of these ───────────────── */

    function number(value, min, max, fallback) {
        var n = typeof value === 'number' ? value : parseFloat(value);

        if (!isFinite(n)) return fallback;

        return Math.max(min, Math.min(max, n));
    }

    function oneOf(value, list, fallback) {
        for (var i = 0; i < list.length; i++) {
            if (list[i] === value) return value;
        }

        return fallback;
    }

    /**
     * An ease, as a function of time: a name from the list, or `cubic(x1,y1,x2,y2)` — the four numbers
     * the Ease Visualizer's two handles produce. Anything else is `fallback` (itself a name from the
     * list), or an even pace when there is none. The editor draws its curves with this very function.
     */
    function easeFunction(value, fallback) {
        var anime = global.anime;

        if (typeof value === 'string' && value.indexOf('cubic(') === 0) {
            var parts = value.slice(6, -1).split(',');

            if (parts.length === 4) {
                return anime.cubicBezier(
                    number(parts[0], 0, 1, 0.25),
                    number(parts[1], -1, 2, 0.1),
                    number(parts[2], 0, 1, 0.25),
                    number(parts[3], -1, 2, 1)
                );
            }
        }

        var name = oneOf(value, EASES, null);

        if (name === null) {
            return fallback ? easeFunction(fallback) : anime.eases.linear;
        }

        if (name === 'none') return anime.eases.linear;

        var family = name.split('.')[0];
        var ease = anime.eases[name.split('.')[1] + FAMILIES[family]];

        // Back and elastic take their shape as arguments; called bare they get the same defaults GSAP has
        // (an overshoot of 1.7; an amplitude of 1 and a period of 0.3).
        return family === 'back' || family === 'elastic' ? ease() : ease;
    }

    function seconds(value) {
        return value * 1000;
    }

    /* ── What each effect does ──────────────────────────────────────────── */

    /** The state an entrance starts FROM (and an exit ends AT) — the element's own place is the other end. */
    function hiddenState(slot) {
        var effect = oneOf(slot.effect, ENTRANCES, 'fade');
        var distance = number(slot.distance, 0, 2000, 80);
        var direction = oneOf(slot.direction, DIRECTIONS, 'up');
        var state = { opacity: 0 };

        if (effect === 'slide' || effect === 'bounce') {
            // "Slide up" arrives moving upwards, so it starts below its place.
            if (direction === 'up') state.translateY = distance;
            if (direction === 'down') state.translateY = -distance;
            if (direction === 'left') state.translateX = distance;
            if (direction === 'right') state.translateX = -distance;
        }

        if (effect === 'zoom') state.scale = number(slot.scale, 0, 5, 0.6);
        if (effect === 'rotate') state.rotate = number(slot.degrees, -720, 720, -90);
        if (effect === 'blur') state.filter = 'blur(' + number(slot.blur, 0, 100, 20) + 'px)';

        if (effect === 'flip') {
            // Anime.js writes a perspective ahead of every other transform, so the turn has depth.
            state.perspective = 800;

            if (direction === 'left' || direction === 'right') {
                state.rotateY = direction === 'left' ? 90 : -90;
            } else {
                state.rotateX = direction === 'up' ? -90 : 90;
            }
        }

        if (effect === 'wipe') {
            // A reveal: the element is cut away from one side and uncovered. It stays opaque throughout.
            state.opacity = 1;
            state.clipPath = {
                up: 'inset(100% 0% 0% 0%)',
                down: 'inset(0% 0% 100% 0%)',
                left: 'inset(0% 0% 0% 100%)',
                right: 'inset(0% 100% 0% 0%)'
            }[direction];
        }

        return state;
    }

    /** The designed state of one property a hidden state names — where an entrance lands. */
    function restingValue(property) {
        if (property === 'opacity' || property === 'scale') return 1;
        if (property === 'perspective') return 800;
        if (property === 'filter') return 'blur(0px)';
        if (property === 'clipPath') return 'inset(0% 0% 0% 0%)';

        return 0;
    }

    /**
     * Start a loop's repeating animation at once — nothing for an unknown effect. `own` records every
     * animation it creates (so the loop can be stopped, and the element put back, however far it got);
     * `active` says whether the loop is still wanted when a swing's lead-in lands.
     */
    function startLoop(anime, target, slot, own, active) {
        var effect = oneOf(slot.effect, LOOPS, null);

        if (effect === null) return;

        var amount = number(slot.amount, -2000, 2000, 10);
        var duration = number(slot.duration, 0.1, 120, 2);
        var base = {
            duration: seconds(duration),
            loop: true,
            alternate: slot.yoyo !== false,
            ease: easeFunction(slot.ease, 'sine.inOut')
        };

        switch (effect) {
            case 'float':
                // Around its place, not away from it: half the amount each way reads as floating.
                swing(anime, target, slot.axis === 'x' ? 'translateX' : 'translateY', amount / 2, base, own, active);
                break;

            case 'pulse':
                own(anime.animate(target, extend(base, {
                    scale: [1, 1 + number(slot.amount, 0, 200, 6) / 100]
                })));
                break;

            case 'sway':
                swing(anime, target, 'rotate', amount, base, own, active);
                break;

            case 'drift':
                own(anime.animate(target, extend(base, {
                    translateX: [0, number(slot.amountX, -2000, 2000, amount)],
                    translateY: [0, number(slot.amountY, -2000, 2000, amount)]
                })));
                break;

            case 'spin':
                // A full turn, forever, at an even pace — a yoyo would make it wind back.
                own(anime.animate(target, extend(base, {
                    rotate: [0, amount < 0 ? -360 : 360],
                    alternate: false,
                    ease: anime.eases.linear
                })));
                break;

            case 'blink':
                own(anime.animate(target, extend(base, {
                    opacity: [1, number(slot.amount, 0, 100, 30) / 100]
                })));
                break;

            case 'shake':
                own(anime.animate(target, extend(base, {
                    translateX: [-amount, amount],
                    duration: seconds(Math.min(duration, 0.2))
                })));
                break;

            case 'kenburns':
                // The slow push-in a photograph gets on television.
                own(anime.animate(target, extend(base, {
                    scale: [1, 1 + number(slot.amount, 0, 100, 12) / 100],
                    translateX: [0, number(slot.amountX, -500, 500, 0)],
                    translateY: [0, number(slot.amountY, -500, 500, 0)]
                })));
                break;
        }
    }

    /**
     * A swing between -reach and +reach on one property, for ever — that starts from where the element
     * RESTS: half a beat out to one side first, then back and forth. Starting the swing at one end
     * instead would make the element jump there the moment its loop began.
     */
    function swing(anime, target, property, reach, base, own, active) {
        var leadIn = {};
        var cycle = {};

        leadIn[property] = [0, -reach];
        leadIn.duration = base.duration / 2;
        leadIn.ease = easeFunction('sine.out');
        leadIn.onComplete = function () {
            if (active()) own(anime.animate(target, extend(base, cycle)));
        };
        cycle[property] = [-reach, reach];

        own(anime.animate(target, leadIn));
    }

    function keep(made, animation) {
        made.push(animation);

        return animation;
    }

    function extend(base, extra) {
        var result = {};
        var key;

        for (key in base) if (Object.prototype.hasOwnProperty.call(base, key)) result[key] = base[key];
        for (key in extra) if (Object.prototype.hasOwnProperty.call(extra, key)) result[key] = extra[key];

        return result;
    }

    /** Run `callback` after `delay` seconds on Anime.js's own clock — the one every animation here keeps. */
    function after(anime, delay, callback, made) {
        if (delay <= 0) {
            callback();

            return;
        }

        keep(made, anime.createTimer({ duration: seconds(delay), onComplete: callback }));
    }

    /* ── Putting it together ────────────────────────────────────────────── */

    /**
     * Build and start one element's animations on `target` (its `.ad-anim` wrapper). Returns a handle
     * whose `kill()` stops everything and puts the element back exactly as it was designed — the
     * editor's Stop button.
     */
    function play(target, animations) {
        var anime = global.anime;
        var slots = animations || {};
        var entrance = slots['in'];
        var loop = slots.loop;
        var exit = slots.out;
        var made = [];
        var style = target.getAttribute('style');
        var running = true;
        var left = false;
        var arrival = null;
        var looping = false;
        var loopParts = [];
        var afterIn = 0;

        function ownLoop(animation) {
            loopParts.push(animation);

            return keep(made, animation);
        }

        function stopLoop() {
            looping = false;

            for (var i = 0; i < loopParts.length; i++) loopParts[i].pause();
        }

        if (entrance && entrance.effect) {
            var hidden = hiddenState(entrance);
            var params = {
                duration: seconds(number(entrance.duration, 0.05, 60, 0.8)),
                delay: seconds(number(entrance.delay, 0, 600, 0)),
                ease: easeFunction(entrance.ease, entrance.effect === 'bounce' ? 'bounce.out' : 'power2.out')
            };

            for (var property in hidden) {
                if (Object.prototype.hasOwnProperty.call(hidden, property)) {
                    params[property] = [hidden[property], restingValue(property)];
                }
            }

            // Hidden from the first frame, so nothing is seen in its final place before its delay is up.
            keep(made, anime.utils.set(target, extend({}, hidden)));
            arrival = keep(made, anime.animate(target, params));
            afterIn = (params.delay + params.duration) / 1000;
        }

        if (loop && loop.effect) {
            // Its own clock: it repeats for ever, from the moment the entrance has landed (plus its delay) —
            // unless the element has already left by then.
            after(anime, afterIn + number(loop.delay, 0, 600, 0), function () {
                if (!running || left) return;

                looping = true;
                startLoop(anime, target, loop, ownLoop, function () {
                    return looping;
                });
            }, made);
        }

        if (exit && exit.effect) {
            after(anime, number(exit.at, 0, 3600, 5), function () {
                if (!running) return;

                // Leaving ends everything else: an entrance still on its way (or still waiting for its
                // delay) and a loop, whether it has started or not.
                left = true;
                if (arrival) arrival.pause();
                stopLoop();

                var gone = hiddenState(exit);
                var params = {
                    duration: seconds(number(exit.duration, 0.05, 60, 0.6)),
                    ease: easeFunction(exit.ease, 'power2.in')
                };

                for (var property in gone) {
                    if (!Object.prototype.hasOwnProperty.call(gone, property)) continue;

                    // From wherever it stands now (a loop may have carried it off), except for the three
                    // an element does not carry while it rests — a filter, a clip-path, the flip's
                    // perspective — which are given their resting value to start from.
                    params[property] = property === 'filter' || property === 'clipPath' || property === 'perspective'
                        ? [restingValue(property), gone[property]]
                        : gone[property];
                }

                keep(made, anime.animate(target, params));
            }, made);
        }

        return {
            kill: function () {
                running = false;
                stopLoop();

                // Newest first, so each animation hands the element back the way the one before left it.
                for (var i = made.length - 1; i >= 0; i--) made[i].revert();

                if (style === null) {
                    target.removeAttribute('style');
                } else {
                    target.setAttribute('style', style);
                }
            }
        };
    }

    /**
     * Start every animated element under `root`. `config` maps an element id to its animations, and the
     * page marks each element's wrapper with `data-anim-id`. Used by the published page on load.
     */
    function run(root, config) {
        var started = [];
        var nodes = root.querySelectorAll('[data-anim-id]');

        for (var i = 0; i < nodes.length; i++) {
            var id = nodes[i].getAttribute('data-anim-id');
            var target = nodes[i].querySelector('.ad-anim');

            if (target && config && config[id]) {
                started.push(play(target, config[id]));
            }
        }

        return started;
    }

    global.AdRuntime = {
        play: play,
        run: run,
        ease: easeFunction
    };
})(window);
