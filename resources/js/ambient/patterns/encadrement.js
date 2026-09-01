/**
 * L'encadrement — the five go and stand at the corners of something on the page.
 *
 * They shrink to marks, take the four corners of a picture that is really there, hold it for a
 * moment, and disperse. It is the one pattern where the background looks like it has NOTICED
 * something, rather than performing in front of it.
 *
 * ⚠ It shares its target with `soulignement` and with both glitches, through `ctx.anchor()` — so
 * the same refusals apply, and it can never frame something inside an editor or an admin screen.
 *
 * ⚠ And it re-reads the rectangle every frame. Captured once, the frame would stay behind while the
 * reader scrolled, marking the corners of nothing — which reads as a fault rather than as an
 * intention.
 */

import { fromScreen, screenRect, smoothstep, lerp, wander } from './util.js';

const DEPTH = 1.45;
const ARRIVE = 0.26;     // spent converging on the corners
const LEAVE = 0.76;      // ...and the point at which they start to scatter
const MARK = 0.34;       // how small a cloud gets while it is being a corner mark

export default {
    id: 'encadrement',
    kind: 'intelligent',
    calm: true,
    duration: [10, 14],

    enter(ctx) {
        const el = ctx.anchor();
        if (!el) return false;
        this.el = el;
        this.scatter = ctx.bobs.map(() => ({
            x: (ctx.rng() - 0.5) * 1.8,
            y: (ctx.rng() - 0.5) * 1.1,
        }));
        return true;
    },

    update(ctx) {
        const rect = this.el.isConnected ? screenRect(this.el.getBoundingClientRect()) : null;
        const gone = !rect || !rect.onScreen;

        const p = ctx.progress;
        const held = smoothstep(0, ARRIVE, p) * (1 - smoothstep(LEAVE, 1, p));

        // Four corners, and the fifth on the middle of the top edge — a frame with an odd number of
        // marks reads as deliberate, where four alone read as a selection box.
        const spots = gone ? null : [
            { x: rect.left, y: rect.top },
            { x: rect.right, y: rect.top },
            { x: rect.left, y: rect.bottom },
            { x: rect.right, y: rect.bottom },
            { x: rect.x, y: rect.top - 0.03 },
        ];

        const out = [];
        for (let i = 0; i < ctx.bobs.length; i++) {
            const bob = ctx.bobs[i];
            const away = this.scatter[i];

            if (gone) {
                bob.gain = 1;
                out.push({ x: away.x, y: away.y, z: 1.6 });
                continue;
            }

            const spot = spots[i % spots.length];
            const target = fromScreen(spot.x, spot.y, DEPTH, ctx.aspect);

            // Tight and bright while it is a mark, back to a cloud on the way in and out.
            bob.scale = lerp(1, MARK, held);
            bob.gain = lerp(1, 1.4, held);

            out.push({
                x: lerp(away.x, target.x, held),
                y: lerp(away.y, target.y, held),
                z: lerp(1.7 + wander(ctx.t * 0.2 + i, i) * 0.3, target.z, held),
            });
        }
        return out;
    },
};
