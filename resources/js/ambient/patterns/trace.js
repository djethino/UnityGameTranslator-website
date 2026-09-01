/**
 * 1 — The letter. Five populations arrange themselves into a glyph.
 *
 * ⚠ This used to be a plotter: five brushes sweeping in bands, painting into an accumulation
 * buffer. That was the best a system with five moving shapes could do, and it is obsolete now that
 * a blob is a population — a cloud does not have to DRAW a letter, it can simply BE one. No trail,
 * no buffer, no sweep. The points are told where the ink is and they go there.
 *
 * Which also means the letter is alive: the points keep their private shimmer, the cloud keeps its
 * spread of eagerness, so the glyph assembles unevenly, breathes while it is held, and comes apart
 * on its own when the next pattern claims the centres.
 *
 * Each cloud takes a horizontal band, with the bands overlapping slightly so the letter reads as
 * five cooperating fluids rather than as five stacked stripes.
 *
 * 🔴 `square: true` — the conductor spreads a pattern's x to fill a widescreen field, and a letter
 * stretched to 16:9 is not a letter.
 */

import { pickGlyph } from '../glyphs.js';
import { smoothstep, lerp } from './util.js';

const BAND_OVERLAP = 0.18;
const LETTER_SCALE = 2.6;   // the clouds swell to hold the whole glyph between them
const THICKNESS = 0.10;     // a hair of depth, so it is a letter of matter and not a decal

export default {
    id: 'trace',
    kind: 'predefined',
    calm: true,
    square: true,
    duration: [10, 14],

    enter(ctx) {
        const glyph = pickGlyph(ctx.strings());
        // Nothing paintable — no font coverage, or every candidate was too dense for the eye to
        // read at this size. Decline, and the conductor runs something else.
        if (!glyph) return false;
        this.glyph = glyph;

        const clouds = ctx.clouds;
        const n = clouds.length;

        clouds.forEach((cloud, c) => {
            const lo = Math.max(0, c / n - BAND_OVERLAP / n);
            const hi = Math.min(1, (c + 1) / n + BAND_OVERLAP / n);

            cloud.setShape((i, total, out) => {
                // Rejection sampling: throw a point at the band and keep it where there is ink.
                // It needs no outline, no stroke order and no knowledge of the script — which is
                // exactly why it works the same for a Latin letter and for a Hangul syllable.
                for (let attempt = 0; attempt < 24; attempt++) {
                    const u = Math.random();
                    const v = lo + Math.random() * (hi - lo);
                    if (Math.random() < glyph.inkAt(u, v)) {
                        out[0] = (u - 0.5) * 2;
                        out[1] = (v - 0.5) * 2;
                        out[2] = (Math.random() - 0.5) * THICKNESS;
                        return;
                    }
                }
                // A band that is almost empty (the crossbar of a T, the gap in an O) would spin
                // here forever. Park the point in the middle of the band instead: it lands in the
                // haze around the letter rather than being lost.
                out[0] = (Math.random() - 0.5) * 0.3;
                out[1] = ((lo + hi) / 2 - 0.5) * 2;
                out[2] = (Math.random() - 0.5) * THICKNESS;
            });
        });

        return true;
    },

    update(ctx) {
        const p = ctx.progress;
        // Held flat and face-on for most of the run, then let go. The centres barely move — all the
        // motion the eye sees is the points converging onto the glyph and, at the end, the
        // conductor resetting the arrangement to a ball, which crumbles it.
        const settle = smoothstep(0, 0.22, p);

        const out = [];
        for (let i = 0; i < ctx.bobs.length; i++) {
            const bob = ctx.bobs[i];
            bob.scale = LETTER_SCALE;
            bob.gain = lerp(0.75, 1.05, settle);
            out.push({ x: 0, y: 0, z: 1.55 });
        }
        return out;
    },
};
