/**
 * Le mot — the readable one.
 *
 * ⚠ Its sibling `trace` gives all five clouds ONE glyph, in overlapping horizontal bands, and they
 * fuse: what you see is a handsome organic mass, not a letter. That turned out to be worth keeping,
 * so it was kept — and this exists beside it rather than instead of it.
 *
 * Here one cloud holds one whole letter, and the letters stand apart. Nothing overlaps, so nothing
 * fuses, and the five colours read as five separate characters.
 *
 * 🟢 And they are not five characters drawn at random: `pickWord` returns letters that stood NEXT
 * TO EACH OTHER in a real sentence, in whichever of the loaded languages it landed on. Five
 * unrelated glyphs read as debris; five consecutive ones read as a word — which, on a site that
 * exists to translate, is the whole joke.
 *
 * 🔴 `square: true` — the conductor spreads a pattern's x to fill a widescreen field, and a word
 * stretched to 16:9 would have its letters drift apart from each other.
 */

import { pickWord } from '../glyphs.js';
import { smoothstep, lerp } from './util.js';

const PLANE_Z = 1.9;     // held flat and face-on: a word has to be read
const SPACING = 0.58;    // between two letter centres, in field units
const LETTER = 0.22;     // ⚠ under half the spacing, or the letters touch and we are back to trace
const THICKNESS = 0.10;

export default {
    id: 'mot',
    kind: 'predefined',
    calm: true,
    square: true,
    duration: [11, 15],

    enter(ctx) {
        const run = pickWord(ctx.strings(), ctx.clouds.length);
        // Nothing paintable in any loaded language — no font coverage, or every candidate too
        // dense. Decline, and the conductor runs something else.
        if (!run) return false;
        this.run = run;

        run.forEach((glyph, i) => {
            ctx.clouds[i].setShape((_, __, out) => {
                // Rejection sampling over the whole glyph, not a band of it: one cloud, one letter.
                for (let attempt = 0; attempt < 24; attempt++) {
                    const u = Math.random();
                    const v = Math.random();
                    if (Math.random() < glyph.inkAt(u, v)) {
                        out[0] = (u - 0.5) * 2;
                        out[1] = (v - 0.5) * 2;
                        out[2] = (Math.random() - 0.5) * THICKNESS;
                        return;
                    }
                }
                // A very open glyph can starve the sampler. Park the point near the middle, where
                // it joins the haze around the letter instead of being lost.
                out[0] = (Math.random() - 0.5) * 0.4;
                out[1] = (Math.random() - 0.5) * 0.4;
                out[2] = (Math.random() - 0.5) * THICKNESS;
            });
        });

        return true;
    },

    update(ctx) {
        const n = this.run.length;
        const settle = smoothstep(0, 0.25, ctx.progress);
        const out = [];

        for (let i = 0; i < ctx.bobs.length; i++) {
            const bob = ctx.bobs[i];

            if (i < n) {
                bob.scale = LETTER;
                bob.gain = lerp(0.7, 1.05, settle);
                out.push({ x: (i - (n - 1) / 2) * SPACING, y: 0, z: PLANE_Z });
            } else {
                // ⚠ No letter to hold. It goes and waits behind, dimmed — sitting in the word with
                // no shape of its own, it would read as a smudge between two letters and undo the
                // one thing this pattern is for.
                bob.scale = 0.7;
                bob.gain = 0.22;
                out.push({ x: (i - 2) * 0.9, y: 0.8, z: 2.45 });
            }
        }

        return out;
    },
};
