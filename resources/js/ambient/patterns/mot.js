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

const PLANE_Z = 1.6;     // held flat and face-on: a word has to be read

/**
 * 🔴 The two numbers that decide whether this reads as a word, and they are read together.
 *
 * `LETTER` is a multiplier on the cloud's resting radius (0.60), so a letter's half-extent is
 * `LETTER × 0.60` and its full width twice that. At 0.22 that was 0.264 against a spacing of 0.58 —
 * **the gap between two letters was larger than a letter** — and five small glyphs strung far apart
 * read as five objects, not as a word. They now fill about four fifths of their own step.
 */
const SPACING = 0.50;    // between two letter centres, in field units
const LETTER = 0.34;     // × the resting radius: half-extent 0.204, so 0.41 wide against a 0.50 step
const THICKNESS = 0.10;

/** How far a letter may lean, and how far it may sit off the line — the whole of the humanizer. */
const TILT = 0.10;       // radians, ±: about 6°
const OFFSET = 0.045;    // field units, ±: a twentieth of a step
const BREATH = 0.035;    // how much of that offset keeps moving, and how much of the tilt

export default {
    id: 'mot',
    kind: 'predefined',
    calm: true,
    square: true,
    // Barely longer than a single letter: a word takes a moment more to read, and then it is read.
    duration: [8, 12],

    enter(ctx) {
        const run = pickWord(ctx.strings(), ctx.clouds.length);
        // Nothing paintable in any loaded language — no font coverage, or every candidate too
        // dense. Decline, and the conductor runs something else.
        if (!run) return false;
        this.run = run;

        run.forEach((glyph, i) => {
            ctx.clouds[i].setShape((_, __, out) => {
                // Drawn ON the ink — see `picker` in glyphs.js. Guessing until a random spot landed
                // on the letter missed a third of the time on a thin one, and every miss went to a
                // little box at the centre.
                glyph.pointOn(out);
                out[2] = (Math.random() - 0.5) * THICKNESS;
            });
        });

        /**
         * 🔴 The hand that wrote it. One draw per letter, kept for the run.
         *
         * Perfectly even letters on a perfectly straight line read as a font specimen, not as
         * writing — the thing was mechanical in exactly the way a word never is. Each letter gets
         * its own lean, its own place a little off the line, its own size, and its own slow breath,
         * all small: this is meant to be felt rather than seen, and a word that visibly wobbles is
         * a different effect and a worse one.
         *
         * ⚠ Drawn from the pattern's own `rng`, so a run is stable while it plays — a letter that
         * re-drew its lean every frame would shake — and different the next time it comes round.
         */
        const spread = (k) => (this.rng() - 0.5) * 2 * k;
        this.hand = run.map(() => ({
            tilt: spread(TILT),
            dx: spread(OFFSET),
            dy: spread(OFFSET),
            size: 1 + spread(0.09),
            rate: 0.5 + this.rng() * 0.5,   // each breathes at its own pace, or they pulse as one
            phase: this.rng() * Math.PI * 2,
        }));

        return true;
    },

    update(ctx) {
        const n = this.run.length;
        const settle = smoothstep(0, 0.25, ctx.progress);
        const out = [];

        for (let i = 0; i < ctx.bobs.length; i++) {
            const bob = ctx.bobs[i];

            if (i < n) {
                const h = this.hand[i];
                // ⚠ `ctx.t` — the pattern's own elapsed time. There is no `ctx.time`: the engine's
                // clock is not handed to patterns, and reaching for one that does not exist is how
                // this went NaN and put five clouds out for the rest of the session.
                const breath = Math.sin(ctx.t * h.rate + h.phase);

                bob.scale = LETTER * h.size;
                bob.gain = lerp(0.7, 1.05, settle);

                // 🔴 The engine rolls every cloud on its own, from a different starting angle — up
                // to 229° apart across five. Every other pattern is a shape that reads the same
                // whichever way up it is; a letter is not, and this one was spelling words with its
                // characters lying on their sides and turning. `roll: 0` stops the turn where it
                // is; the lean below is this letter's own.
                bob.roll = 0;
                bob.twist = h.tilt + breath * BREATH;

                out.push({
                    x: (i - (n - 1) / 2) * SPACING + h.dx,
                    y: h.dy + breath * BREATH,
                    z: PLANE_Z,
                });
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
