/**
 * Le tunnel — rings coming at you, engulfing you, and racing back to the far end.
 *
 * ⚠ Not a repeat of `traversee`. That one is a straight flow: five bodies come at you and pass.
 * This is a STRUCTURE you fall into — the rings are all the same size on the same axis, so the
 * perspective alone tells you they are a corridor rather than five separate things.
 *
 * 🟢 The ring keeps one radius and lets the projection do the work: `p = 1/z` scales a shape by its
 * depth, so a far ring draws small and a near one is four times the screen without a single line
 * computing it.
 *
 * ── 🔴 The return is part of the trip, not an exception to it ──────────────────────────────────
 * A ring has to get from the camera back to the far end, and there is no path between them that
 * avoids the frame. Three versions tried to make that journey INVISIBLE — a teleport, then a
 * teleport with a fade, then a fade-out-move-fade-in — and each one made the real complaint worse:
 * the ring vanished before it had even engulfed you, and reappeared for no reason anybody watching
 * could see.
 *
 * The journey does not need hiding. It needs to be FAST, and to start only once the ring is
 * genuinely past. So `z` is a continuous function of one cycle: it descends over the first 88 %
 * until the ring is nearly four screens wide and you are inside it, then climbs back over the last
 * 12 % with the centre's spring stiffened so it snaps rather than drifts. `z(0)` and `z(1)` are the
 * same value, so the cycle wraps with nothing to smooth over — there is no seam left to hide.
 */

import { lerp, smoothstep, easeInOut } from './util.js';
import { Z_FAR } from '../bob.js';

// ⚠ Wide on purpose. From roughly halfway down the corridor the projected ring is already larger
// than the screen — the moment you stop watching a ring and start being inside one.
const RING = 1.4;        // radius, in field units
const THICK = 0.09;      // the ring has a little body, or it reads as wire

/** Nearest the ring gets. At 0.36 it projects nearly four screens across: you are well inside it. */
const Z_MIN = 0.36;

/** How much of a cycle is spent coming towards you. The rest is the way back. */
const FORWARD = 0.88;

/** Where every ring sits on the first frame — the depth a cloud is usually at, so nothing has to
 *  travel backwards to take its place when the figure begins. */
const START = 0.55;

export default {
    id: 'tunnel',
    kind: 'predefined',
    calm: false,
    duration: [13, 18],

    enter(ctx) {
        ctx.clouds.forEach((cloud) => {
            cloud.setShape((_, __, out) => {
                const a = Math.random() * Math.PI * 2;
                const r = RING + (Math.random() - 0.5) * THICK;
                out[0] = Math.cos(a) * r;
                out[1] = Math.sin(a) * r;
                out[2] = (Math.random() - 0.5) * THICK;
            });
        });
        return true;
    },

    update(ctx) {
        const SPEED = 0.11;
        const n = ctx.bobs.length;
        const out = [];

        for (let i = 0; i < n; i++) {
            const bob = ctx.bobs[i];

            // ⚠ The corridor starts FOLDED and unfolds over two seconds. Spaced from the first
            // frame, the rings that belong at the far end would have to get there from wherever the
            // previous figure left them — mid-field — so the pattern's first act would be to send
            // half of itself backwards. Measured: 27 frames of retreat before anything came forward.
            const spread = smoothstep(0, 2.2, ctx.t);
            const u = (ctx.t * SPEED + START + (i / n) * spread) % 1;

            let z;
            if (u <= FORWARD) {
                z = lerp(Z_FAR, Z_MIN, u / FORWARD);
                bob.haste = 1;
                bob.grip = 3.2;
            } else {
                // The way back. Eased so it leaves and arrives without a kick, and hurried so it
                // reads as a return rather than as a retreat — about a second for the whole
                // corridor, of which the first half happens while the ring is still wider than the
                // screen and there is nothing to see.
                const k = easeInOut((u - FORWARD) / (1 - FORWARD));
                z = lerp(Z_MIN, Z_FAR, k);
                bob.haste = 4.5;
                bob.grip = 6;
            }

            // 🔴 The rings turn by DIFFERENT amounts, and that is the whole trick. A circle rotated
            // is the same circle, so a rigid turn would be invisible; only the offset between one
            // ring and the next can be seen. The twist grows along the corridor, and what you read
            // is the corridor screwing itself into the distance.
            bob.scale = 1;
            bob.twist = ctx.t * 0.55 + u * 2.2;

            out.push({
                // Barely off the axis, and drifting: a perfectly centred tunnel reads as a target.
                x: Math.cos(ctx.t * 0.31) * 0.10,
                y: Math.sin(ctx.t * 0.27) * 0.08,
                z,
            });
        }
        return out;
    },
};
