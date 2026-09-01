/**
 * Le soulignement — the background notices a heading and draws a line under it.
 *
 * One cloud flattens into a thin ribbon the width of something actually on screen, slides under it
 * as if the line were being drawn left to right, holds, and lets go. The other four stand off.
 *
 * 🔴 It aims through `ctx.anchor()`, which is the SAME scan the two glitches use. That is not a
 * convenience: it means this pattern inherits every refusal they inherit — the editors, the admin
 * screens, anything marked `data-no-glitch` — and it cannot ever underline a line of somebody's
 * translation while they are arbitrating it. One question, one answer, three consumers.
 *
 * ⚠ The rectangle is re-read every frame rather than captured once. The reader can scroll, and a
 * line left behind at the coordinates the heading used to have is worse than no line: it points
 * confidently at nothing. If it leaves the view, the pattern lets go and goes back to drifting.
 */

import { fromScreen, screenRect, smoothstep, lerp, wander } from './util.js';

const DEPTH = 1.5;       // near enough to read as being ON the page rather than behind it
const THICK = 0.05;      // the ribbon's half-height, in its own unit space
const DRAW = 0.28;       // the fraction of the pattern spent drawing the line
const HOLD = 0.74;       // ...and the point at which it starts letting go

export default {
    id: 'soulignement',
    kind: 'intelligent',
    calm: true,
    duration: [10, 14],

    enter(ctx) {
        const el = ctx.anchor();
        if (!el) return false;
        this.el = el;
        this.marker = 0;   // which cloud does the drawing

        ctx.clouds[this.marker].setShape((_, __, out) => {
            // Denser in the middle, so the line fades out at both ends instead of stopping dead.
            out[0] = (Math.random() + Math.random() - 1);
            out[1] = (Math.random() - 0.5) * THICK;
            out[2] = (Math.random() - 0.5) * THICK;
        });
        return true;
    },

    update(ctx) {
        const rect = this.el.isConnected ? screenRect(this.el.getBoundingClientRect()) : null;
        const gone = !rect || !rect.onScreen;

        const p = ctx.progress;
        const grow = smoothstep(0, DRAW, p) * (1 - smoothstep(HOLD, 1, p));

        const out = [];
        for (let i = 0; i < ctx.bobs.length; i++) {
            const bob = ctx.bobs[i];

            if (i === this.marker && !gone) {
                // Half the element's width, converted into the field at this depth — and the
                // cloud's own radius divided out, because `scale` is a multiplier on it, not a size.
                bob.scale = (rect.halfWidth * DEPTH * ctx.aspect * grow) / ctx.radius;
                bob.gain = lerp(0.4, 1.2, grow);
                // A hair below the box, so it underlines rather than strikes through.
                out.push(fromScreen(rect.x, rect.bottom + 0.012, DEPTH, ctx.aspect));
            } else {
                // Standing off: dim, behind, and slowly circling. The interesting thing is the line.
                const a = (i / ctx.bobs.length) * Math.PI * 2 + ctx.t * 0.19;
                bob.gain = gone ? 1 : 0.5;
                out.push({
                    x: Math.cos(a) * 0.9 + wander(ctx.t * 0.2 + i, i) * 0.15,
                    y: Math.sin(a) * 0.55,
                    z: 2.0,
                });
            }
        }
        return out;
    },
};
