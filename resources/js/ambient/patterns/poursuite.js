/**
 * Intelligent — the chase. Flat, far, and behind you.
 *
 * The clouds fall back into a plane, which is what earns the trail — and the trail costs nothing,
 * because it is not drawn. A cloud crossing the field fast leaves its slower points behind, so the
 * comet tail IS the population catching up. Nothing here switches it on; it just moves sideways,
 * fast, and the physics of the cloud does the rest.
 *
 * They do not all chase the same point. Each replays the pointer's path a little further back, so
 * the five arrive as a comet rather than a swarm — and because each has its own eagerness on top
 * (that is the humanizer, and it is not this file's business), the train never straightens out.
 *
 * 🔴 "Is anyone pointing?" is the pattern's real question, and `pointer.js` answers it the same way
 * for a finger and for a mouse: a finger that lifts and a cursor abandoned for a few seconds are
 * both an absence. When there is nobody, they go about their business — staying clamped to a stale
 * coordinate is what a hung program looks like.
 */

import { lerp, wander } from './util.js';

const HISTORY = 200;
const LAG = 0.16;          // seconds between one bob and the next in the train
const PLANE_Z = 1.8;

export default {
    id: 'poursuite',
    kind: 'intelligent',
    calm: false,
    duration: [15, 21],

    enter() {
        this.buf = [];
        this.head = 0;
        this.idle = 0;      // 0 = chasing, 1 = about its business. Eased, never switched.
        return true;
    },

    update(ctx) {
        const p = ctx.pointer;
        const t = ctx.t;

        this.buf[this.head % HISTORY] = { x: p.x * 0.95, y: p.y * 0.8, t };
        this.head++;

        const at = (age) => {
            const want = t - age;
            for (let k = 1; k <= Math.min(this.head, HISTORY); k++) {
                const e = this.buf[(this.head - k + HISTORY * 2) % HISTORY];
                if (e && e.t <= want) return e;
            }
            return this.buf[(this.head - 1 + HISTORY) % HISTORY];
        };

        // Crossing over takes about a second either way. An instant switch would read as a glitch,
        // and this is the one place in the system where a glitch is not the intention.
        const want = p.active ? 0 : 1;
        this.idle += (want - this.idle) * Math.min(1, ctx.dt * 1.4);

        const out = [];
        for (let i = 0; i < ctx.bobs.length; i++) {
            const bob = ctx.bobs[i];
            const past = at(i * LAG);

            // Where it would go if nobody were pointing: its own slow wander, well spread out.
            const freeX = wander(t * 0.31 + i * 1.7, i * 2.2) * 0.8;
            const freeY = wander(t * 0.27 + i * 2.9, i * 1.4) * 0.5;

            // Tighter and brighter at the head of the train — it is the one being followed.
            bob.scale = lerp(0.78, 1, i / ctx.bobs.length);

            out.push({
                x: lerp(past.x, freeX, this.idle),
                y: lerp(past.y, freeY, this.idle),
                z: PLANE_Z + i * 0.05 + lerp(0, 0.3, this.idle),
            });
        }
        return out;
    },
};
