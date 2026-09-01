/**
 * 6 — The mirror. Choreography you can see the rule of.
 *
 * Symmetry was cheap on hardware that had none to spare — you computed half a screen and reflected
 * it — and it turned out to be one of the strongest things you can put in front of an eye: it is
 * recognised instantly, without being looked for.
 *
 * Two pairs mirror each other about an axis; the fifth bob rides the axis itself and belongs to
 * neither. Then the axis turns, and in the last third the symmetry BREAKS: the reflected pair drifts
 * off its own way. Holding a symmetry for a whole pattern would be decorative. Breaking one that
 * has been established is an event.
 */

import { lerp, smoothstep, wander, cast } from './util.js';

export default {
    id: 'miroir',
    kind: 'predefined',
    calm: true,
    duration: [10, 15],

    enter(ctx) {
        this.escape = ctx.bobs.map(() => ({ x: (ctx.rng() - 0.5) * 1.5, y: (ctx.rng() - 0.5) * 1.0 }));
        // Role 4 stands on the axis and is its own reflection; the other four pair off. Drawn per
        // run, or the same colour is the still point every time — see `cast`.
        this.part = cast(ctx.bobs.length, ctx.rng);
        return true;
    },

    update(ctx) {
        const t = ctx.t;

        // The axis turns slowly enough to be followed, never fast enough to be a spin.
        const axis = t * 0.19 + wander(t * 0.11, 1.7) * 0.5;
        const ca = Math.cos(axis), sa = Math.sin(axis);

        // Established, then undone. The break lands in the last third so there is something to
        // break — a symmetry nobody has had time to notice cannot be broken.
        const broken = smoothstep(0.66, 1, ctx.progress);

        // The two leaders' own small dance, in the axis frame.
        const leaders = [
            { u: 0.30 + wander(t * 0.47, 0.6) * 0.26, v: wander(t * 0.39, 2.2) * 0.42 },
            { u: 0.62 + wander(t * 0.35, 4.1) * 0.22, v: wander(t * 0.51, 5.5) * 0.38 },
        ];

        const out = [];
        for (let i = 0; i < ctx.bobs.length; i++) {
            let u, v, z;

            const role = this.part[i];

            if (role === 4) {
                // On the axis, and therefore its own reflection. The still point of the figure.
                u = 0;
                v = wander(t * 0.29, 3.3) * 0.55;
                z = 1.15 + wander(t * 0.23, 1.9) * 0.35;
            } else {
                const pair = leaders[role >> 1];
                const side = role % 2 === 0 ? 1 : -1;
                u = pair.u * side;
                v = pair.v;
                z = 1.2 + pair.v * 0.5 * side;
            }

            // Back out of the axis frame into the field.
            let x = u * ca - v * sa;
            let y = u * sa + v * ca;

            if (broken > 0 && i !== 4) {
                x = lerp(x, x + this.escape[i].x, broken);
                y = lerp(y, y + this.escape[i].y, broken);
            }

            out.push({ x: x * 0.9, y: y * 0.75, z });
        }
        return out;
    },
};
