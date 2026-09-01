/**
 * 4 — The storm. No formation at all.
 *
 * This is the pattern that exists because the clouds cannot merge. They draw together, slowly,
 * until they occupy the same space — and what you get is not a third colour but a dense stipple of
 * five, each population squeezed and interleaved with the others, none of them dissolved. Then it
 * lets go, all at once, and they separate with every point still where it belongs.
 *
 * The others are choreography. This one is weather: the same shape every time, never the same
 * timing, and the eye cannot tell when the break is coming.
 */

import { clamp, lerp, easeOut, smoothstep, wander } from './util.js';

export default {
    id: 'orage',
    kind: 'predefined',
    calm: false,
    duration: [11, 15],

    enter(ctx) {
        // Two or three build-ups per run, decided once so the run has a shape rather than a rhythm.
        this.bursts = 2 + (ctx.rng() > 0.55 ? 1 : 0);
        this.dirs = ctx.bobs.map(() => {
            const a = ctx.rng() * Math.PI * 2;
            return { x: Math.cos(a), y: Math.sin(a) * 0.7, z: (ctx.rng() - 0.5) * 1.2 };
        });
        return true;
    },

    update(ctx) {
        const cycle = 1 / this.bursts;
        const phase = (ctx.progress % cycle) / cycle;   // 0 → 1 within one build-up

        // Drawing in occupies four fifths of the cycle, the break the rest. The asymmetry is the
        // whole effect: a slow gathering earns the release.
        const BREAK = 0.80;
        const gather = smoothstep(0, BREAK, phase);
        const burst = phase > BREAK ? easeOut((phase - BREAK) / (1 - BREAK)) : 0;

        // The eye of the storm drifts, so successive build-ups do not stack in the same place.
        const eye = {
            x: wander(ctx.t * 0.23, 1.1) * 0.45,
            y: wander(ctx.t * 0.19, 3.4) * 0.28,
            z: 1.05 + wander(ctx.t * 0.15, 5.2) * 0.3,
        };

        const out = [];
        for (let i = 0; i < ctx.bobs.length; i++) {
            const bob = ctx.bobs[i];
            const d = this.dirs[i];

            // Far apart, then converging, then thrown outwards.
            const spread = lerp(0.85, 0.05, gather) + burst * 1.5;

            // ⚠ They compress, they do not glow. An earlier version had them fuse into a
            // white-hot core, which was an artefact of additive blending and is simply wrong for
            // bodies that never mix: five fluids pressed together get DENSER and their interfaces
            // get tighter. The release is the decompression, not a flash.
            const press = gather * (1 - burst);
            bob.scale = lerp(1, 0.42, press);
            bob.gain = 1 + press * 0.3;

            out.push({
                x: eye.x + d.x * spread,
                y: eye.y + d.y * spread,
                z: clamp(eye.z + d.z * spread * 0.6, 0.5, 2.4),
            });
        }
        return out;
    },
};
