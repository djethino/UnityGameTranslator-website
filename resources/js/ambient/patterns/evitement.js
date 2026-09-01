/**
 * L'évitement — the one where the reader pushes.
 *
 * 🔴 The exact opposite of `poursuite`, and the point is not the geometry, it is the RELATIONSHIP.
 * Every other pattern here is something the background does at you; this is the only one where a
 * gesture of yours moves something. Chasing invites; fleeing responds.
 *
 * ⚠ The push has to be soft-edged or it reads as a collision. A cloud right under the cursor is
 * shoved hard, one at the edge of the field of influence is barely nudged, and the falloff between
 * them is smooth — so moving the pointer through the field parts it like a hand through water
 * rather than knocking things over.
 */

import { wander, smoothstep, lerp } from './util.js';

const REACH = 0.85;      // how far the pointer's presence is felt, in field units
const PUSH = 0.95;       // how far a cloud directly under it is thrown

export default {
    id: 'evitement',
    kind: 'intelligent',
    calm: false,
    needsPointer: true,
    duration: [14, 19],

    enter() {
        this.idle = 0;
        return true;
    },

    update(ctx) {
        const p = ctx.pointer;
        const t = ctx.t;

        // Crossing over takes about a second either way, so the field does not snap back into place
        // the instant a hand leaves the window.
        this.idle += ((p.active ? 0 : 1) - this.idle) * Math.min(1, ctx.dt * 1.2);

        // Where they would be if nobody were there: a loose, slow arrangement across the field.
        const out = [];
        for (let i = 0; i < ctx.bobs.length; i++) {
            const bob = ctx.bobs[i];
            const homeX = wander(t * 0.23 + i * 1.9, i * 2.1) * 0.80;
            const homeY = wander(t * 0.19 + i * 3.3, i * 1.3) * 0.52;

            let x = homeX;
            let y = homeY;

            if (this.idle < 0.98) {
                const dx = homeX - p.x;
                const dy = homeY - p.y * 0.8;
                const d = Math.hypot(dx, dy);
                // Smooth at both ends: nothing at the edge of REACH, full strength at the centre.
                const force = smoothstep(REACH, 0, d) * (1 - this.idle);
                if (force > 0) {
                    // ⚠ Guarded: dead on the pointer the direction is undefined, and normalising a
                    // zero vector would send the cloud to NaN and take it out of the frame for the
                    // rest of the session.
                    const inv = d > 1e-3 ? 1 / d : 0;
                    x += dx * inv * PUSH * force;
                    y += dy * inv * PUSH * force;
                    // Squeezed as it is shoved: something being pushed compresses.
                    bob.scale = lerp(1, 0.78, force);
                }
            }

            out.push({ x, y, z: 1.25 + wander(t * 0.15 + i, i + 4) * 0.35 });
        }
        return out;
    },
};
