/**
 * Intelligent — the ambush. The one you are meant to flinch at.
 *
 * They stop. Not dead — they hang there oscillating, the way something that has noticed you hangs
 * there. Then, together and without warning, they come straight at the camera — and miss, passing
 * above, below and to the sides, smeared by the lens as they go — and re-form far away, small
 * again, as if nothing had happened.
 *
 * ── The fisheye ────────────────────────────────────────────────────────────────────────────────
 * A real one. When the picture was five sprites there was nothing to distort and the effect had to
 * be faked with a shear; now that every point is placed individually, displacing each one radially
 * in the vertex shader IS barrel distortion, and it costs a dot product. `ctx.setWarp` drives it.
 *
 * ── Why it teleports ───────────────────────────────────────────────────────────────────────────
 * ⚠ Once past the camera a bob is behind you, and there is no path back to the far distance that
 * does not cross the screen again. Springing it back would read as a boomerang. So it is MOVED,
 * once, while it is provably off-frame — the one place in this system where a position is assigned
 * rather than approached.
 */

import { clamp, lerp, easeInOut, easeOut, smoothstep, wander } from './util.js';
import { Z_NEAR, Z_FAR } from '../bob.js';

const SETTLE = 0.20, OBSERVE = 0.44, CHARGE = 0.70, PASS = 0.80;

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
        this.sent = false;
        return true;
    },

    update(ctx) {
        const p = ctx.progress;
        const out = [];

        // The lens. It bends the whole field, hardest as they come past — and it is a real radial
        // displacement in the vertex shader, not a sheared sprite, because every point is now
        // placed on its own. Zero while they wait, so the distortion arrives WITH the charge.
        ctx.setWarp(p < OBSERVE ? 0 : smoothstep(OBSERVE, PASS, p) * 0.55 * (1 - smoothstep(PASS, 1, p)));

        // Re-arm once they are provably out of frame. Doing it on the way IN would be visible.
        if (p >= PASS && !this.sent) {
            this.sent = true;
            for (let i = 0; i < ctx.bobs.length; i++) {
                const a = (i / ctx.bobs.length) * Math.PI * 2;
                ctx.teleport(i, Math.cos(a) * 0.25, Math.sin(a) * 0.18, Z_FAR);
            }
        }

        for (let i = 0; i < ctx.bobs.length; i++) {
            const bob = ctx.bobs[i];
            const hold = this.hold[i];
            const slot = this.slots[i];

            if (p < OBSERVE) {
                // Come to a stop, then hang. The oscillation is tiny and slow — enough that they
                // are plainly not frozen, not enough to be going anywhere.
                const breathe = p < SETTLE ? 0 : (p - SETTLE) / (OBSERVE - SETTLE);
                const w = 0.035 * breathe;
                out.push({
                    x: hold.x + wander(ctx.t * 0.9 + i * 3.1, i) * w,
                    y: hold.y + wander(ctx.t * 0.7 + i * 1.9, i + 4) * w,
                    z: hold.z + Math.sin(ctx.t * 1.1 + i) * 0.04 * breathe,
                });
            } else if (p < PASS) {
                // The charge. Depth collapses fast, and the sideways push arrives late — so they
                // read as coming AT you, and only miss at the last moment.
                const k = clamp((p - OBSERVE) / (PASS - OBSERVE));
                const rush = easeInOut(k);
                const veer = k * k * k;

                const z = lerp(hold.z, Z_NEAR, rush);
                const x = lerp(hold.x, slot.x, veer);
                const y = lerp(hold.y, slot.y, veer);

                // The cloud leans into its own escape as well — the lens bends the field, this
                // bends the body inside it.
                const lean = smoothstep(0.35, 1, k) * 0.9;
                bob.shearX = x * lean * 0.4;
                bob.shearY = y * lean * 0.4;
                bob.gain = 1 + rush * 0.5;

                out.push({ x, y, z });
            } else {
                // Far away and small again, drifting back to something a pattern could pick up.
                const back = easeOut(clamp((p - PASS) / (1 - PASS)));
                const a = (i / ctx.bobs.length) * Math.PI * 2;
                out.push({
                    x: Math.cos(a) * lerp(0.25, 0.5, back),
                    y: Math.sin(a) * lerp(0.18, 0.34, back),
                    z: lerp(Z_FAR, 1.6, back),
                });
            }
        }
        return out;
    },
};
