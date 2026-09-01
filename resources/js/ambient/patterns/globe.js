/**
 * Le globe — the one every Amiga demo had.
 *
 * Points on a sphere, turning. It is the effect the whole "vector bob" idea was invented for, and a
 * population of points is literally what it is made of: there is no surface to shade, no wireframe
 * to hide, just where the points are.
 *
 * 🟢 The five colours become five LATITUDE BANDS. That falls straight out of the rule that a point
 * belongs to its cloud for life — five populations, five parallels, and as the globe turns each
 * band goes round the back and comes out the other side without ever mixing with its neighbours.
 * A striped planet, and nobody had to write "stripe" anywhere.
 *
 * ⚠ It needs `yaw`, not `spin`. Spin turns the arrangement in the plane of the screen, so a sphere
 * would just rotate on the spot and look completely still. Yaw turns it in DEPTH — what was at the
 * front goes behind — and it is the only rotation that changes a point's z, which is what makes the
 * near half large and bright and the far half small and dim.
 */

import { wander, lerp } from './util.js';

const TAU = Math.PI * 2;

export default {
    id: 'globe',
    kind: 'predefined',
    calm: true,
    // Long: it turns, and what was behind comes round. A figure that keeps showing you something
    // new earns the time; one that has shown you everything in three seconds does not.
    duration: [16, 23],

    enter(ctx) {
        const n = ctx.clouds.length;
        const r = ctx.rng;
        // ⚠ Which way it turns, how fast, how big and how far away — all drawn per run. A globe
        // that always spins the same way at the same speed is a logo.
        this.spin = lerp(0.24, 0.52, r()) * (r() < 0.5 ? -1 : 1);
        this.size = lerp(1.0, 1.32, r());
        this.depth = lerp(1.2, 1.55, r());
        this.tiltRate = lerp(0.09, 0.19, r());
        this.seed = [r() * 6.28, r() * 6.28, r() * 6.28];
        ctx.clouds.forEach((cloud, c) => {
            // The band this cloud owns, in sin(latitude) rather than latitude itself — equal steps
            // of sin give equal AREA, so the bands carry the same number of points per square inch.
            // Stepping the angle instead would crowd the poles and thin out the equator.
            const lo = -1 + (2 * c) / n;
            const hi = -1 + (2 * (c + 1)) / n;

            cloud.setShape((_, __, out) => {
                const u = lo + Math.random() * (hi - lo);
                const a = Math.random() * TAU;
                const ring = Math.sqrt(1 - u * u);
                // A shell, not a ball: the points sit on the surface, with a little thickness so it
                // reads as a body rather than as a soap bubble.
                const r = 0.94 + Math.random() * 0.12;
                out[0] = Math.cos(a) * ring * r;
                out[1] = u * r;
                out[2] = Math.sin(a) * ring * r;
            });
        });
        return true;
    },

    update(ctx) {
        const t = ctx.t;
        // One rotation about every seventeen seconds, and the axis itself leans slowly, so the
        // globe never presents the same face twice at the same tilt.
        const yaw = t * this.spin;
        const lean = wander(t * this.tiltRate, this.seed[0]) * 0.28;

        const out = [];
        for (const bob of ctx.bobs) {
            bob.yaw = yaw;
            // All five share one centre and one radius: they are bands of the SAME sphere, and any
            // difference between them would take it apart.
            bob.scale = this.size;
            bob.shearX = lean * 0.25;
            out.push({
                x: wander(t * 0.11, this.seed[1]) * 0.20,
                y: wander(t * 0.09, this.seed[2]) * 0.12,
                z: this.depth,
            });
        }
        return out;
    },
};
