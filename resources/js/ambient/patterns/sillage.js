/**
 * Le sillage — the pointer as a bow, not as a position.
 *
 * 🔴 This is the only pattern that reads the pointer's VELOCITY, and that is what makes it a
 * different idea rather than a variation on the two that read its position. Park the cursor in the
 * middle of the field and nothing happens at all; sweep it across and you cut a trench. Same
 * coordinates, different gesture, opposite result — which is exactly what a wake is.
 *
 * ⚠ The displacement is PERPENDICULAR to the motion. Pushed along it, the clouds would simply be
 * herded ahead of the cursor, which is what `evitement` already does. Pushed sideways, they part
 * and close behind — and the closing is the half you actually watch.
 *
 * The offset decays on its own, so the water settles without anyone telling it to.
 */

import { wander, smoothstep } from './util.js';

const REACH = 0.7;        // how close the bow has to pass to move anything
const STRENGTH = 1.5;     // how far a cloud is thrown per unit of pointer speed
const SETTLE = 1.6;       // how quickly the trench closes again, per second

export default {
    id: 'sillage',
    kind: 'intelligent',
    calm: false,
    needsPointer: true,
    duration: [14, 20],

    enter(ctx) {
        // The displacement each cloud is currently carrying. Kept between frames: this is the only
        // pattern here with a memory, because a wake IS the memory of something having passed.
        this.push = ctx.bobs.map(() => ({ x: 0, y: 0 }));
        return true;
    },

    update(ctx) {
        const p = ctx.pointer;
        const t = ctx.t;

        const speed = Math.hypot(p.vx, p.vy);
        // Below a threshold there is no bow, only a floating object. Without this the field would
        // shiver constantly under the smallest hand tremor.
        const moving = p.active && speed > 0.35;

        // The normal to the direction of travel, which is where everything gets pushed.
        const nx = moving ? -p.vy / speed : 0;
        const ny = moving ? p.vx / speed : 0;

        const decay = Math.exp(-SETTLE * ctx.dt);
        const out = [];

        for (let i = 0; i < ctx.bobs.length; i++) {
            const bob = ctx.bobs[i];
            const homeX = wander(t * 0.21 + i * 2.4, i * 1.7) * 0.85;
            const homeY = wander(t * 0.17 + i * 1.1, i * 2.9) * 0.55;

            const carry = this.push[i];
            carry.x *= decay;
            carry.y *= decay;

            if (moving) {
                const d = Math.hypot(homeX - p.x, homeY - p.y * 0.8);
                const force = smoothstep(REACH, 0, d) * Math.min(speed, 4) * STRENGTH * ctx.dt;
                // Which side of the wake it is on decides which way it goes, so the two halves
                // separate instead of all sliding the same way.
                const side = Math.sign((homeX - p.x) * nx + (homeY - p.y * 0.8) * ny) || 1;
                carry.x += nx * force * side;
                carry.y += ny * force * side;
            }

            bob.scale = 1 + Math.min(0.35, Math.hypot(carry.x, carry.y) * 0.5);

            out.push({
                x: homeX + carry.x,
                y: homeY + carry.y,
                z: 1.3 + wander(t * 0.13 + i, i + 2) * 0.3,
            });
        }
        return out;
    },
};
