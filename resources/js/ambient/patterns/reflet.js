/**
 * Le miroir d'eau — a waterline, something above it, and that something upside down below.
 *
 * 🔴 A first version put two clouds above and two below and called it a reflection. It was not one,
 * and the reason is worth keeping: a reflection is only legible if the ORIGINAL HAS A SHAPE and if
 * there is a SURFACE between the two. Five featureless balls in two rows are five balls in two
 * rows — measured third-least-animated of nineteen figures, and correctly reported as "nothing
 * happens".
 *
 * So: the two above are teardrops, wide at the top and tapering down, which is a silhouette you can
 * recognise upside down. The two below carry the SAME shape flipped, so the pairing is visible.
 * And the fifth is no longer a squashed ball on the line — it is a thin ribbon spanning the field,
 * which is the waterline itself. Without it there is no surface, and without a surface there is
 * nothing for anything to be reflected in.
 *
 * ⚠ The wobble grows with distance below the line: near the surface the reflection is nearly true,
 * further down it comes apart. Uniform wobble reads as a picture being shaken; graduated wobble
 * reads as water.
 */

import { wander } from './util.js';

const HORIZON = 0.10;    // slightly below centre: more sky than water, which is how a view sits
const DIM = 0.6;         // a reflection is never as bright as what it reflects
const SPAN = 1.5;        // half-width of the waterline — wider than the field, so it has no ends

/** Wide at the top, tapering to a point below. Recognisable, and recognisable upside down. */
function teardrop(flip) {
    return (_, __, out) => {
        // Biased towards the top, where the body is.
        const v = Math.min(Math.random(), Math.random());
        const width = (1 - v) * (1 - v * 0.35);
        const u = (Math.random() + Math.random() - 1) * width;
        out[0] = u;
        out[1] = (v * 2 - 1) * flip;
        out[2] = (Math.random() - 0.5) * width * 0.8;
    };
}

export default {
    id: 'reflet',
    kind: 'predefined',
    calm: true,
    duration: [13, 18],

    enter(ctx) {
        // Two above, pointing down; two below, the same shape pointing up.
        ctx.clouds[0].setShape(teardrop(1));
        ctx.clouds[1].setShape(teardrop(1));
        ctx.clouds[2].setShape(teardrop(-1));
        ctx.clouds[3].setShape(teardrop(-1));

        // The waterline. Long, flat, and denser in the middle so it fades out sideways rather than
        // stopping dead at the edge of the screen.
        ctx.clouds[4].setShape((_, __, out) => {
            out[0] = (Math.random() + Math.random() - 1) * SPAN;
            out[1] = (Math.random() - 0.5) * 0.035;
            out[2] = (Math.random() - 0.5) * 0.06;
        });
        return true;
    },

    update(ctx) {
        const t = ctx.t;
        const out = [];

        // ⚠ They TRAVEL now, rather than hovering. The reflection only becomes legible at the moment
        // the thing above moves and the thing below moves with it — a still pair proves nothing.
        const above = [
            {
                x: wander(t * 0.46, 0.4) * 0.95,
                y: HORIZON - 0.36 - Math.abs(wander(t * 0.33, 1.6)) * 0.26,
            },
            {
                x: wander(t * 0.39, 2.7) * 1.0,
                y: HORIZON - 0.32 - Math.abs(wander(t * 0.29, 4.2)) * 0.30,
            },
        ];

        for (let i = 0; i < ctx.bobs.length; i++) {
            const bob = ctx.bobs[i];

            if (i < 2) {
                bob.scale = 0.85;
                out.push({ x: above[i].x, y: above[i].y, z: 1.3 + i * 0.12 });
            } else if (i < 4) {
                const src = above[i - 2];
                const depth = Math.abs(src.y - HORIZON);
                // Mirrored about the line, then let go of by the water: the further down, the less
                // it holds together.
                const shake = wander(t * 1.9 + i * 2.3, i) * 0.05 * (0.35 + depth * 2.4);
                bob.scale = 0.9;
                bob.gain = DIM;
                out.push({
                    x: src.x + shake,
                    y: HORIZON + depth + Math.abs(wander(t * 1.2 + i, i + 3)) * 0.045,
                    z: 1.3 + (i - 2) * 0.12,
                });
            } else {
                // The surface. It is what makes the other four mean anything, so it is the brightest
                // thing on screen and it never moves — a horizon that drifts is not a horizon.
                bob.scale = 1;
                bob.gain = 1.25;
                out.push({ x: 0, y: HORIZON, z: 1.2 });
            }
        }
        return out;
    },
};
