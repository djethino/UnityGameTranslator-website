/**
 * La capture — one of them is yours.
 *
 * 🔴 Every other pattern treats the five as a set: they do the same thing, offset. Here ONE is held
 * by the pointer and follows it exactly, and the other four back off and watch. That asymmetry is
 * the whole content — a group of five doing one thing is choreography, four watching a fifth is a
 * situation.
 *
 * It ends by letting go, and the release is the part worth waiting for: the held one is on a very
 * tight spring, so the moment the pointer stops commanding it, it snaps back to the ring and
 * overshoots. Nothing in the code says "snap"; it is what a stiff spring does when its target moves.
 */

import { wander, smoothstep, lerp } from './util.js';

const HOLD_UNTIL = 0.78;   // it is released with a quarter of the pattern left, so the return is seen
const RING = 0.82;         // how far the onlookers keep back

export default {
    id: 'capture',
    kind: 'intelligent',
    calm: false,
    needsPointer: true,
    duration: [11, 16],

    enter(ctx) {
        this.held = (ctx.rng() * ctx.bobs.length) | 0;
        this.corner = ctx.bobs.map(() => ctx.rng() * Math.PI * 2);
        return true;
    },

    update(ctx) {
        const p = ctx.pointer;
        const t = ctx.t;
        const taken = ctx.progress < HOLD_UNTIL && p.active;
        // Eased so the four do not lurch backwards the instant the pattern starts.
        const back = smoothstep(0, 0.18, ctx.progress) * (ctx.progress < HOLD_UNTIL ? 1 : 0);

        const out = [];
        for (let i = 0; i < ctx.bobs.length; i++) {
            const bob = ctx.bobs[i];

            if (i === this.held) {
                if (taken) {
                    // Held: tight, bright, and slightly smaller — something gripped is compressed.
                    bob.scale = 0.72;
                    bob.gain = 1.35;
                    out.push({ x: p.x * 0.95, y: p.y * 0.8, z: 1.05 });
                } else {
                    // Let go. It goes back to where the others are, and the spring does the rest.
                    bob.gain = 1.1;
                    out.push({
                        x: wander(t * 0.3, 1.4) * 0.3,
                        y: wander(t * 0.26, 3.7) * 0.2,
                        z: 1.35,
                    });
                }
            } else {
                // Backed off, and facing in: they orbit slowly at a distance, dimmer, as if the
                // interesting thing were happening elsewhere. Which it is.
                const a = this.corner[i] + t * 0.22;
                bob.gain = lerp(1, 0.55, back);
                bob.scale = lerp(1, 0.9, back);
                out.push({
                    x: Math.cos(a) * lerp(0.35, RING, back),
                    y: Math.sin(a) * lerp(0.25, RING * 0.62, back),
                    z: 1.5 + lerp(0, 0.45, back),
                });
            }
        }
        return out;
    },
};
