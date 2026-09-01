/**
 * 3 — The escadrille. Formation flying.
 *
 * 🔴 The trick, and the only thing that makes this read as a squadron rather than as five things
 * moving in parallel: **the wingmen do not aim at an offset from the leader, they REPLAY the
 * leader's own path a moment later.** The leader turns, and the turn travels down the formation.
 * Aim at a rigid offset and you get a cardboard cutout sliding about; replay with a delay and you
 * get something that looks flown.
 *
 * It costs a ring buffer of the leader's positions and nothing else.
 */

import { wander, clamp, lerp } from './util.js';

const HISTORY = 240;      // ~4 s at 60 Hz, comfortably more than the deepest lag

export default {
    id: 'escadrille',
    kind: 'predefined',
    calm: false,
    duration: [10, 15],

    enter(ctx) {
        const r = ctx.rng;
        this.buf = [];
        this.head = 0;
        // ⚠ A squadron flying the same patrol every time is a squadron on rails. The formation's
        // shape, its spacing and the leader's course are all drawn per sortie — including whether
        // it is a wide lazy V or a tight one strung out behind.
        this.lag = lerp(0.22, 0.46, r());          // seconds between one wingman and the next
        this.spread = lerp(0.20, 0.40, r());       // how wide the V opens
        this.climb = lerp(0.02, 0.17, r());        // whether the wings sit above or level
        this.roam = lerp(0.65, 1.0, r());          // how far the leader ranges
        this.pace = lerp(0.42, 0.72, r());
        this.seed = [r() * 6.28, r() * 6.28, r() * 6.28];
        return true;
    },

    update(ctx) {
        const t = ctx.t;

        // The leader wanders in three dimensions; nobody is steering it anywhere in particular,
        // which is the point — a squadron on patrol, not a squadron going somewhere.
        const lead = {
            x: wander(t * this.pace, this.seed[0]) * 0.85 * this.roam,
            y: wander(t * this.pace * 0.76, this.seed[1]) * 0.5 * this.roam,
            z: 1.25 + wander(t * this.pace * 0.56, this.seed[2]) * 0.55,
            t,
        };

        this.buf[this.head % HISTORY] = lead;
        this.head++;

        // Read the leader's position as it was `age` seconds ago. Walking backwards is fine: the
        // buffer holds a few hundred entries and we sample five times a frame.
        const at = (age) => {
            const want = t - age;
            for (let k = 1; k <= Math.min(this.head, HISTORY); k++) {
                const e = this.buf[(this.head - k + HISTORY * 2) % HISTORY];
                if (e && e.t <= want) return e;
            }
            return lead;
        };

        // How hard the leader is turning, read from the recent past. It is what the whole formation
        // banks into — and banking is the second half of what makes flight legible.
        const back = at(0.18);
        const bank = clamp((lead.x - back.x) * 2.2, -0.6, 0.6);

        const out = [];
        for (let i = 0; i < ctx.bobs.length; i++) {
            const bob = ctx.bobs[i];
            const wing = i === 0 ? 0 : Math.ceil(i / 2);      // 0, 1, 1, 2, 2
            const side = i === 0 ? 0 : (i % 2 === 1 ? -1 : 1);
            const past = wing === 0 ? lead : at(wing * this.lag);

            // Roll into the turn, the outer wingmen a little more than the leader.
            bob.shearX = bank * (0.35 + wing * 0.22);

            out.push({
                x: past.x + side * this.spread * wing,
                y: past.y + wing * this.climb,
                z: past.z + wing * 0.13,
            });
        }
        return out;
    },
};
