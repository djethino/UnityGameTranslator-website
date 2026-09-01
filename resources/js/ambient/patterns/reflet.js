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

import { wander, lerp, cast } from './util.js';
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
    duration: [15, 21],

    enter(ctx) {
        const r = ctx.rng;
        // ⚠ Where the waterline sits, how choppy it is and how far the pair ranges are drawn per
        // run. The horizon itself stays PUT once drawn — a horizon that drifts is not a horizon —
        // but it need not be at the same height every time.
        this.line = lerp(-0.06, 0.24, r());
        this.chop = lerp(0.6, 1.5, r());
        this.roam = lerp(0.8, 1.15, r());
        this.pace = lerp(0.34, 0.56, r());
        this.seed = [r() * 6.28, r() * 6.28, r() * 6.28, r() * 6.28];

        // 🔴 Which cloud plays which part, drawn fresh. Roles 0 and 1 are the shapes above the
        // water, 2 and 3 their reflections, 4 the waterline. Handed out by index before, so the
        // same colour was the horizon in every single run of this figure.
        this.part = cast(ctx.clouds.length, r);

        ctx.clouds.forEach((cloud, i) => {
            const role = this.part[i];
            if (role === 4) {
                // The waterline. Long, flat, and denser in the middle so it fades out sideways
                // rather than stopping dead at the edge of the screen.
                cloud.setShape((_, __, out) => {
                    out[0] = (Math.random() + Math.random() - 1) * SPAN;
                    out[1] = (Math.random() - 0.5) * 0.035;
                    out[2] = (Math.random() - 0.5) * 0.06;
                });
            } else {
                // Two above, pointing down; two below, the same shape pointing up.
                cloud.setShape(teardrop(role < 2 ? 1 : -1));
            }
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
                x: wander(t * this.pace, this.seed[0]) * 0.95 * this.roam,
                y: this.line - 0.36 - Math.abs(wander(t * 0.33, this.seed[1])) * 0.26,
            },
            {
                x: wander(t * this.pace * 0.85, this.seed[2]) * 1.0 * this.roam,
                y: this.line - 0.32 - Math.abs(wander(t * 0.29, this.seed[3])) * 0.30,
            },
        ];

        for (let i = 0; i < ctx.bobs.length; i++) {
            const bob = ctx.bobs[i];
            // ⚠ The ROLE, never the index — and the same roles the shapes were cut for in `enter`,
            // or a cloud shaped like a teardrop would be asked to lie flat on the water.
            const role = this.part[i];

            if (role < 2) {
                bob.scale = 0.85;
                out.push({ x: above[role].x, y: above[role].y, z: 1.3 + role * 0.12 });
            } else if (role < 4) {
                const src = above[role - 2];
                const depth = Math.abs(src.y - this.line);
                // Mirrored about the line, then let go of by the water: the further down, the less
                // it holds together.
                const shake = wander(t * 1.9 + i * 2.3, i) * 0.05 * this.chop * (0.35 + depth * 2.4);
                bob.scale = 0.9;
                bob.gain = DIM;
                out.push({
                    x: src.x + shake,
                    y: this.line + depth + Math.abs(wander(t * 1.2 + i, i + 3)) * 0.045 * this.chop,
                    z: 1.3 + (role - 2) * 0.12,
                });
            } else {
                // The surface. It is what makes the other four mean anything, so it is the brightest
                // thing on screen and it never moves — a horizon that drifts is not a horizon.
                bob.scale = 1;
                bob.gain = 1.25;
                out.push({ x: 0, y: this.line, z: 1.2 });
            }
        }
        return out;
    },
};
