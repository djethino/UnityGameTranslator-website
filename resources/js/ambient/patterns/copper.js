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

import { wander, lerp } from './util.js';

const WIDTH = 1.45;      // half-width of a ribbon, in field units — wider than the field on purpose
const THICK = 0.055;     // and very nearly flat

export default {
    id: 'copper',
    kind: 'predefined',
    calm: true,
    duration: [12, 18],

    enter(ctx) {
        const r = ctx.rng;
        // ⚠ Copper bars were a fixed effect on a fixed machine; here there is no reason for the
        // swing, the tempo or the stacking order to be the same twice. `slant` is the one worth
        // naming: at 0 the bars are level, and a few degrees of tilt turns the same routine into a
        // different-looking screen.
        this.swing = lerp(0.45, 0.78, r());
        this.tempo = lerp(0.38, 0.72, r());
        this.slant = (r() - 0.5) * 0.5;
        this.depth = lerp(1.1, 1.4, r());
        this.stack = lerp(0.26, 0.55, r());
        this.step = lerp(0.02, 0.06, r()) * (r() < 0.5 ? -1 : 1);
        this.seed = r() * 6.28;
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
            const phase = (i / ctx.bobs.length) * Math.PI * 2 + this.seed;
            const rate = this.tempo + i * this.step;

            bob.scale = 1;
            // A few degrees of tilt on the whole rack, drawn once per run.
            bob.shearY = this.slant;
            out.push({
                x: wander(t * 0.09 + i, i) * 0.10,
                y: Math.sin(t * rate + phase) * this.swing,
                // A little depth between the bars: the near ones pass in front of the far ones, and
                // since the points never merge you see one ribbon crossing another.
                z: this.depth + Math.cos(t * 0.21 + phase) * this.stack,
            });
        }
        return out;
    },
};
