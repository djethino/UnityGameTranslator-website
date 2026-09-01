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

import { wander } from './util.js';

const RADIUS = 0.62;

export default {
    id: 'ronde',
    kind: 'predefined',
    calm: true,
    duration: [13, 18],

    update(ctx) {
        const t = ctx.t;
        const n = ctx.bobs.length;

        // Two tilts, drifting. Their rates share no common multiple, so the pair never returns to
        // a position it has held before.
        const tiltX = 0.55 + wander(t * 0.21, 1.3) * 0.42;
        const tiltY = wander(t * 0.17, 2.7) * 0.7;
        const spin = t * 0.32;

        const cx = Math.cos(tiltX), sx = Math.sin(tiltX);
        const cy = Math.cos(tiltY), sy = Math.sin(tiltY);

        const out = [];
        for (let i = 0; i < n; i++) {
            const a = spin + (i / n) * Math.PI * 2;
            // A point on a flat ring, then tilted out of the screen plane.
            let px = Math.cos(a) * RADIUS;
            let py = 0;
            let pz = Math.sin(a) * RADIUS;

            // Rotate about X, then about Y. Written out rather than looped: five bobs, twice a
            // frame, and the explicit form is the one anybody can check.
            let y1 = py * cx - pz * sx;
            let z1 = py * sx + pz * cx;
            let x2 = px * cy + z1 * sy;
            let z2 = -px * sy + z1 * cy;

            out.push({
                x: x2,
                y: y1 * 0.8,
                // The ring straddles the reference plane, so half of it is genuinely nearer than
                // the other half — the swelling is perspective, not an animated size.
                z: 1.25 + z2 * 0.75,
            });
        }
        return out;
    },
};
