/**
 * Le tunnel — rings receding to a vanishing point, turning as they come.
 *
 * ⚠ Not a repeat of `traversee`, and the difference is the reason it earns its place. That one is a
 * straight flow: five bodies come at you and pass. This is a STRUCTURE you fall into — the rings
 * are all the same size and all on the same axis, so the perspective alone tells you they are a
 * corridor rather than five separate things. And it turns, which no other pattern here does.
 *
 * 🟢 The ring keeps one radius and lets the projection do the work: `p = 1/z` already scales a
 * shape by its depth, so a far ring draws small and a near one fills the frame without a single
 * line computing it. That is the whole of the fake perspective being used for what it is good at.
 *
 * ⚠ `spin`, not `yaw`. The rings lie in the plane of the screen and must keep facing you as they
 * come; yaw would turn them in depth and they would arrive edge-on, as lines.
 */

import { lerp, smoothstep } from './util.js';
import { Z_NEAR, Z_FAR } from '../bob.js';

// ⚠ Wide on purpose. From roughly halfway down the corridor the projected ring is already larger
// than the screen — which is the moment you stop watching a ring and start being inside one. A
// narrower tunnel is a tunnel seen from outside, and that was the other half of "we never get in".
const RING = 1.4;        // radius, in field units
const THICK = 0.09;      // the ring has a little body, or it reads as wire

/** Where every ring sits on the first frame — the depth a cloud is usually at, so nothing has to
 *  travel backwards to take its place. */
const START = 0.55;

export default {
    id: 'tunnel',
    kind: 'predefined',
    calm: false,
    duration: [13, 18],

    enter(ctx) {
        // Where each ring was along the corridor last frame. The only reason it is kept is to catch
        // the moment it wraps — see the note in update().
        this.lastU = ctx.bobs.map(() => -1);

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
            // Evenly spaced along the corridor, so one is always arriving as another leaves. The
            // modulo is what makes it endless: a ring that passes the camera is the next far ring.
            // ⚠ The corridor starts FOLDED and unfolds, instead of being laid out at once.
            //
            // Spaced from the first frame, the rings that belong at the far end have to get there
            // from wherever the previous pattern left them — mid-field — so the first thing the
            // pattern does is send half of itself backwards. Measured: 27 frames of visible retreat
            // before anything came forward, which reads as the tunnel pushing you out.
            //
            // Starting them all at the depth clouds normally sit at, and easing the spacing in over
            // two seconds, means every ring's first movement is toward the camera.
            const spread = smoothstep(0, 2.2, ctx.t);
            const u = (ctx.t * SPEED + START + (i / n) * spread) % 1;
            const z = lerp(Z_FAR, Z_NEAR + 0.05, u);

            // 🔴 The wrap has to be a teleport, not a target change. When `u` rolls over, the ring
            // has just passed the camera and its next place is the far end of the corridor — and
            // there is no path between the two that does not cross the frame. Left to the spring it
            // RECEDES back down the whole tunnel, in full view, which reads as the corridor pushing
            // you out instead of letting you in.
            //
            // ⚠ Safe to do here because at u ≈ 1 the ring is at z 0.45 and 1.4 units wide: the
            // projection puts it three screen-heights across, so its centre is well out of frame.
            // Its trailing points are not, which is what the fade just below is for.
            if (u < this.lastU[i]) ctx.teleport(i, 0, 0, Z_FAR);
            this.lastU[i] = u;

            // 🔴 And the teleport has to happen while the ring is INVISIBLE, not merely while its
            // centre is off-screen. Those are not the same moment: the points chase their places at
            // their own rates, so several hundred of them are still trailing across the frame when
            // the centre has already left it. Moved without this, the tail vanishes mid-screen —
            // which is what "the near ring disappears and jumps to the end" was.
            //
            // ⚠ Fading is the fix rather than teleporting later, because there is no later: `z` is
            // clamped at Z_NEAR, so a ring cannot travel far enough past the camera to take its
            // stragglers out of sight with it.
            const arriving = smoothstep(0, 0.06, u);
            const leaving = 1 - smoothstep(0.90, 0.99, u);
            bob.gain = arriving * leaving;

            // 🔴 The rings turn by DIFFERENT amounts, and that is the whole trick. A circle rotated
            // is the same circle, so a rigid turn would be completely invisible; only the offset
            // between one ring and the next can be seen. Hence `u * 2.2` — the twist grows along
            // the corridor, and what you read is the corridor screwing itself into the distance.
            bob.scale = 1;
            bob.twist = ctx.t * 0.55 + u * 2.2;
            // ⚠ Held tight, unlike everything else here. A ring has to arrive and leave as one
            // object; at the default looseness its slowest points trail half a corridor behind and
            // are still crossing the frame when it has gone.
            bob.grip = 3.2;

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
