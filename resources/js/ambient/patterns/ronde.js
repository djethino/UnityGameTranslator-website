/**
 * 2 — The ronde. The one that breathes.
 *
 * The Amiga vector-bob routine in its simplest form: points on a ring, a rotation matrix, and the
 * depth doing the rest. Each bob swells as it comes round the front and thins as it goes behind —
 * that alone is enough to read as three dimensions, which is exactly what those demos proved.
 *
 * What keeps it from looking like a screensaver is that the ring itself never holds still: its tilt
 * drifts on two axes at incommensurable rates, so the ellipse you see is never quite the ellipse
 * you saw. Every sequence needs one pattern you can rest on, and this is it.
 */

import { lerp, wander } from './util.js';

export default {
    id: 'ronde',
    kind: 'predefined',
    calm: true,
    duration: [10, 14],

    /**
     * ⚠ Everything below used to be a constant, and the ronde was therefore the SAME ronde every
     * time — same radius, same speed, same direction, five bobs at exactly 72°. Watch the
     * background for ten minutes and you recognise it; recognise it twice and it stops being
     * weather and becomes a playlist.
     *
     * ⚠ `hitch` is the one that does the most for the least: a few degrees of irregularity in the
     * spacing, drawn once. A perfectly regular ring is the single clearest signal that something
     * was generated rather than observed.
     */
    enter(ctx) {
        const r = ctx.rng;
        this.radius = lerp(0.5, 0.78, r());
        this.rate = lerp(0.22, 0.46, r()) * (r() < 0.5 ? -1 : 1);
        this.depth = lerp(1.1, 1.45, r());
        this.squash = lerp(0.62, 0.95, r());
        this.lean = lerp(0.35, 0.75, r());
        this.driftA = lerp(0.15, 0.28, r());
        this.driftB = lerp(0.12, 0.24, r());
        this.seedA = r() * 6.28;
        this.seedB = r() * 6.28;
        this.hitch = ctx.bobs.map(() => (r() - 0.5) * 0.5);
        return true;
    },

    update(ctx) {
        const t = ctx.t;
        const n = ctx.bobs.length;

        // Two tilts, drifting. Their rates share no common multiple, so the pair never returns to
        // a position it has held before.
        const tiltX = this.lean + wander(t * this.driftA, this.seedA) * 0.42;
        const tiltY = wander(t * this.driftB, this.seedB) * 0.7;
        const spin = t * this.rate;

        const cx = Math.cos(tiltX), sx = Math.sin(tiltX);
        const cy = Math.cos(tiltY), sy = Math.sin(tiltY);

        const out = [];
        for (let i = 0; i < n; i++) {
            const a = spin + (i / n) * Math.PI * 2 + this.hitch[i];
            // A point on a flat ring, then tilted out of the screen plane.
            let px = Math.cos(a) * this.radius;
            let py = 0;
            let pz = Math.sin(a) * this.radius;

            // Rotate about X, then about Y. Written out rather than looped: five bobs, twice a
            // frame, and the explicit form is the one anybody can check.
            let y1 = py * cx - pz * sx;
            let z1 = py * sx + pz * cx;
            let x2 = px * cy + z1 * sy;
            let z2 = -px * sy + z1 * cy;

            out.push({
                x: x2,
                y: y1 * this.squash,
                // The ring straddles the reference plane, so half of it is genuinely nearer than
                // the other half — the swelling is perspective, not an animated size.
                z: this.depth + z2 * 0.75,
            });
        }
        return out;
    },
};
