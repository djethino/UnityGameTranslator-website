/**
 * 5 — The crossing. Depth for its own sake.
 *
 * A continuous flow: the bobs are spaced along one receding axis and stream toward the camera, each
 * growing, softening and sliding aside until it leaves by an edge — then it is back at the far end,
 * small and sharp, and the queue never breaks.
 *
 * ⚠ Not the same thing as the camera charge in `guet-apens`, and the difference is the point. That
 * one is a burst you are meant to flinch at. This one is a current you are meant to fall into. Same
 * axis, opposite intent.
 *
 * The sideways drift is not decoration: with `p = 1/z`, a bob that stays near the axis would arrive
 * dead centre and swallow the screen. Pushing it off-axis as it approaches is what sends it past
 * you instead of into you.
 */

import { lerp, wander } from './util.js';
import { Z_NEAR, Z_FAR } from '../bob.js';

export default {
    id: 'traversee',
    kind: 'predefined',
    calm: true,
    duration: [14, 19],

    enter(ctx) {
        // A fixed bearing each, so the five do not all exit through the same corner.
        this.bearings = ctx.bobs.map((_, i) => {
            const a = (i / ctx.bobs.length) * Math.PI * 2 + ctx.rng() * 0.9;
            return { x: Math.cos(a), y: Math.sin(a) * 0.72 };
        });
        return true;
    },

    update(ctx) {
        const SPEED = 0.085;   // one full trip takes about twelve seconds
        const out = [];

        for (let i = 0; i < ctx.bobs.length; i++) {
            const bob = ctx.bobs[i];
            const b = this.bearings[i];

            // Evenly spaced along the trip, so one is always arriving while another is leaving.
            const u = (ctx.t * SPEED + i / ctx.bobs.length) % 1;
            const z = lerp(Z_FAR, Z_NEAR + 0.08, u);

            // Almost on the axis when far, well off it by the time it passes. Squared so the drift
            // is imperceptible for most of the trip and then decisive.
            const off = 0.06 + u * u * 0.55;

            // A slight sway, different for each, so the five trajectories are not five straight
            // lines drawn from the same vanishing point.
            const sway = wander(ctx.t * 0.4 + i * 2.1, i) * 0.09 * u;

            out.push({
                x: b.x * off + sway,
                y: b.y * off + sway * 0.6,
                z,
            });
        }
        return out;
    },
};
