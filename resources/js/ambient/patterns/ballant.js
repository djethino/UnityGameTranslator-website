/**
 * Le ballant — the reader's scrolling throws them.
 *
 * 🔴 Scrolling already reaches this background, but only as a MAGNITUDE: it speeds the clock up, and
 * a speed has no direction, so nothing could tell scrolling up from scrolling down. This is the
 * first pattern to read the signed value — so the field is thrown the opposite way to the page,
 * piles up, swings back past the middle and settles. Liquid in a glass somebody just put down.
 *
 * 🟢 And it adds no new input. Everything it needs was already measured, smoothed and decayed by
 * the engine for the time warp; it was simply never handed on with its sign.
 *
 * ⚠ Its own spring, not the bobs'. The overshoot is the whole effect and the bobs' spring is
 * critically damped, which by definition never overshoots. Underdamped here, on purpose.
 */

import { wander, clamp } from './util.js';

const THROW = 0.055;     // how hard a unit of scroll velocity pushes
const STIFF = 5.5;       // how eagerly it comes back
const DAMP = 1.9;        // ⚠ below 2·sqrt(STIFF) — that is what makes it swing past instead of easing

export default {
    id: 'ballant',
    kind: 'intelligent',
    calm: false,
    duration: [12, 17],

    enter(ctx) {
        // Displacement and its velocity, one per cloud. Kept between frames: a slosh is a memory of
        // a movement that has already stopped.
        this.slosh = ctx.bobs.map(() => ({ y: 0, v: 0 }));
        // Each takes the push a little differently, so the surface tilts rather than moving as a
        // slab. The heaviest lags, which is what makes it look like a body of liquid.
        this.weight = ctx.bobs.map((_, i) => 0.6 + (i / ctx.bobs.length) * 0.8);
        return true;
    },

    update(ctx) {
        const t = ctx.t;
        // Reversed: scroll the page down and its contents rise, so the field rises too.
        const push = clamp(-ctx.scroll, -12, 12) * THROW;
        const dt = Math.min(ctx.dt, 0.05);

        const out = [];
        for (let i = 0; i < ctx.bobs.length; i++) {
            const bob = ctx.bobs[i];
            const s = this.slosh[i];

            // Underdamped spring, semi-implicit. The push enters as an acceleration rather than as
            // a position, which is why a long slow scroll builds a swell and a flick gives a slap.
            s.v += (push * this.weight[i] * 60 - STIFF * s.y - DAMP * s.v) * dt;
            s.y += s.v * dt;
            s.y = clamp(s.y, -0.9, 0.9);

            // Stretched along the direction it is travelling — a mass in motion is not a ball.
            bob.shearY = clamp(s.v * 0.05, -0.4, 0.4);

            out.push({
                x: wander(t * 0.19 + i * 2.2, i) * 0.85,
                y: wander(t * 0.15 + i * 1.4, i + 3) * 0.28 + s.y,
                z: 1.3 + wander(t * 0.12 + i, i + 6) * 0.35,
            });
        }
        return out;
    },
};
