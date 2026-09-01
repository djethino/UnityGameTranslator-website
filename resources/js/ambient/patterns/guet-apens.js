/**
 * Le guet-apens — the one you are meant to flinch at.
 *
 * They stop. Not dead — they hang there oscillating, the way something that has noticed you hangs
 * there. Then, together and without warning, they come straight at the camera — and miss, passing
 * above, below and to the sides, bent by the lens as they go — and race back to the far distance,
 * small again, as if nothing had happened.
 *
 * ── The fisheye ────────────────────────────────────────────────────────────────────────────────
 * A real one. Every point is placed individually, so displacing each radially in the vertex shader
 * IS barrel distortion, and it costs a dot product. `ctx.setWarp` drives it.
 *
 * ── 🔴 They come back. They are not put back. ──────────────────────────────────────────────────
 * An earlier version teleported them once they were past the camera, on the reasoning that there is
 * no path from behind you to the far end that avoids the frame. True — and the wrong conclusion.
 * The journey does not need hiding; it needs to be quick. `haste` stiffens the centre's spring for
 * the length of the retreat, so five clouds sweep back to the distance in about a second, which is
 * something you watch happen. A teleport is something that has happened, and reads as a fault
 * however carefully it is dressed.
 */

import { clamp, lerp, easeInOut, easeOut, smoothstep, wander } from './util.js';
import { Z_NEAR, Z_FAR } from '../bob.js';

const SETTLE = 0.20, OBSERVE = 0.44, PASS = 0.80;

export default {
    id: 'guet-apens',
    kind: 'intelligent',
    calm: false,
    duration: [11, 14],

    enter(ctx) {
        // Each is assigned a way past the camera — above, below, left, right, and one corner. They
        // must not all leave through the same edge, or it reads as a wipe.
        const slots = [
            { x: -1.0, y: -0.35 }, { x: 1.0, y: -0.30 },
            { x: -0.85, y: 0.55 }, { x: 0.90, y: 0.50 },
            { x: 0.05, y: -1.0 },
        ];
        this.slots = ctx.bobs.map((_, i) => slots[i % slots.length]);
        this.hold = ctx.bobs.map(() => ({
            x: (ctx.rng() - 0.5) * 1.3,
            y: (ctx.rng() - 0.5) * 0.85,
            z: 1.1 + ctx.rng() * 0.5,
        }));
        return true;
    },

    update(ctx) {
        const p = ctx.progress;
        const out = [];

        // The lens bends the whole field, hardest as they come past. Zero while they wait, so the
        // distortion arrives WITH the charge.
        ctx.setWarp(p < OBSERVE ? 0 : smoothstep(OBSERVE, PASS, p) * 0.55 * (1 - smoothstep(PASS, 1, p)));

        for (let i = 0; i < ctx.bobs.length; i++) {
            const bob = ctx.bobs[i];
            const hold = this.hold[i];
            const slot = this.slots[i];

            if (p < OBSERVE) {
                // Come to a stop, then hang. The oscillation is tiny and slow — enough that they are
                // plainly not frozen, not enough to be going anywhere.
                const breathe = p < SETTLE ? 0 : (p - SETTLE) / (OBSERVE - SETTLE);
                const w = 0.035 * breathe;
                out.push({
                    x: hold.x + wander(ctx.t * 0.9 + i * 3.1, i) * w,
                    y: hold.y + wander(ctx.t * 0.7 + i * 1.9, i + 4) * w,
                    z: hold.z + Math.sin(ctx.t * 1.1 + i) * 0.04 * breathe,
                });
            } else if (p < PASS) {
                // The charge. Depth collapses fast and the sideways push arrives late — so they read
                // as coming AT you, and only miss at the last moment.
                const k = clamp((p - OBSERVE) / (PASS - OBSERVE));
                const rush = easeInOut(k);
                const veer = k * k * k;

                const z = lerp(hold.z, Z_NEAR, rush);
                const x = lerp(hold.x, slot.x, veer);
                const y = lerp(hold.y, slot.y, veer);

                // The cloud leans into its own escape as well — the lens bends the field, this bends
                // the body inside it.
                const lean = smoothstep(0.35, 1, k) * 0.9;
                bob.shearX = x * lean * 0.4;
                bob.shearY = y * lean * 0.4;
                bob.gain = 1 + rush * 0.5;

                out.push({ x, y, z });
            } else {
                // The way back, watched rather than hidden. Eased at both ends, and hurried by the
                // stiffened spring, so it takes about a second from behind the camera to the far
                // distance.
                const k = easeOut(clamp((p - PASS) / (1 - PASS)));
                const a = (i / ctx.bobs.length) * Math.PI * 2;

                bob.haste = lerp(4.5, 1, k);
                bob.grip = lerp(4, 1, k);
                // ⚠ Picked up where the charge left it, not reset. The charge ends at 1.5 and this
                // branch used to say nothing, so brightness fell to 1 in a single frame — a step of
                // more than half, on the exact frame the phases change. A quantity two phases both
                // write has to agree at the seam.
                bob.gain = lerp(1.5, 1, k);

                out.push({
                    // From where it left the frame, back to a ring in the distance. Starting from
                    // the slot rather than from nowhere is what makes it a return.
                    x: lerp(slot.x, Math.cos(a) * 0.5, k),
                    y: lerp(slot.y, Math.sin(a) * 0.34, k),
                    z: lerp(Z_NEAR, Z_FAR * 0.85, k),
                });
            }
        }
        return out;
    },
};
