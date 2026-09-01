/**
 * Les barres copper — the C64 and Amiga signature, and the one silhouette this system did not have.
 *
 * Everything else here is a MASS: a ball, a swarm, a letter, a squadron. This flattens the five
 * populations into wide horizontal ribbons that slide up and down out of phase — line, not volume.
 * Put next to any other pattern it does not read as a variation, it reads as a different program.
 *
 * ⚠ The bar moves as a WHOLE rather than undulating along its length, and that is faithful rather
 * than a shortcut: a copper bar was one colour written per scanline, so the bar itself was rigid
 * and it was its vertical position that followed the sine. Rippling it would be a modern effect
 * wearing an old name.
 *
 * The out-of-phase part is what makes it read. Five bars moving together would be one thick bar.
 */

import { wander } from './util.js';

const WIDTH = 1.45;      // half-width of a ribbon, in field units — wider than the field on purpose
const THICK = 0.055;     // and very nearly flat
const SWING = 0.62;      // how far a bar travels up and down

export default {
    id: 'copper',
    kind: 'predefined',
    calm: true,
    duration: [12, 17],

    enter(ctx) {
        ctx.clouds.forEach((cloud) => {
            cloud.setShape((_, __, out) => {
                // Denser in the middle of the bar than at its ends, so a ribbon fades out sideways
                // instead of stopping dead — the edges of the screen never show a cut.
                const u = (Math.random() + Math.random() - 1);
                out[0] = u * WIDTH;
                out[1] = (Math.random() - 0.5) * THICK;
                out[2] = (Math.random() - 0.5) * THICK * 1.6;
            });
        });
        return true;
    },

    update(ctx) {
        const t = ctx.t;
        const out = [];

        for (let i = 0; i < ctx.bobs.length; i++) {
            const bob = ctx.bobs[i];
            // Evenly spread phases, plus a slow drift on each so the five never lock into a pattern
            // the eye can predict. The rate differs slightly per bar for the same reason.
            const phase = (i / ctx.bobs.length) * Math.PI * 2;
            const rate = 0.52 + i * 0.035;

            bob.scale = 1;
            out.push({
                x: wander(t * 0.09 + i, i) * 0.10,
                y: Math.sin(t * rate + phase) * SWING,
                // A little depth between the bars: the near ones pass in front of the far ones, and
                // since the points never merge you see one ribbon crossing another.
                z: 1.25 + Math.cos(t * 0.21 + phase) * 0.42,
            });
        }
        return out;
    },
};
