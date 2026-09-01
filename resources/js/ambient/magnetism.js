/**
 * Le magnétisme — the layer where the visitor exists.
 *
 * Everything else in this system decides where the clouds should be. This decides how they feel
 * about the cursor, and it sits on top: a cloud flees it, another is drawn to it, two more do not
 * care, and each of them changes its mind on its own schedule. Move slowly and a shape bulges around
 * the pointer; sweep fast and you cut through it, and the pieces come back with a small overshoot.
 *
 * ── 🔴 It moves the TARGET, not the point ──────────────────────────────────────────────────────
 * The whole design rests on this one decision, and everything good about it follows.
 *
 * A magnet is naturally written as a force, added to the integrator. Doing that here would have been
 * a mistake three times over: a new term in an explicit spring integrator is a new way to diverge,
 * and this project has already paid for that twice; the effect would have depended on the local
 * stiffness, so it would have been violent on a loose cloud and invisible on a corridor ring at
 * `grip 45`; and it would have needed its own stability budget on top of the one the springs already
 * spend.
 *
 * Displacing the target instead costs nothing. The spring that was already running does the work, so
 * the response is the same everywhere no matter how stiff the figure is, the arrival is smoothed for
 * free, and — the part that matters — **it cannot destabilise anything**, because there is nothing
 * new to integrate.
 *
 * ── Strong up close, nothing at all far away ───────────────────────────────────────────────────
 * The falloff `(1 - (d/R)²)²` reaches exactly zero at `R` and stays there, rather than trailing off
 * asymptotically. That is the difference between a magnet and a fog: outside `R` a point is
 * *exactly* where its figure asked, not almost.
 *
 * ⚠ And the distance is measured **on the glass**, in clip units, not in the field. The cursor is on
 * the screen; a point that appears next to it is next to it, whatever its depth. Measuring in the
 * field would make a far cloud immune and a near one absurdly sensitive, for no reason a visitor
 * could see.
 */

import { lerp } from './patterns/util.js';

/** How long a cloud holds one attitude. */
const SPELL = [4.5, 14];

/** How fast it changes its mind, and how fast it notices the pointer coming and going. */
const TURN = 0.8;

/** Chances of each attitude when a cloud picks a new one. Indifference has to be common: five
 *  clouds all reacting at once is a special effect, one reacting is a creature. */
const MOODS = [
    { value: -1, odds: 0.34 },   // flees
    { value: 0, odds: 0.36 },   // does not care
    { value: 1, odds: 0.30 },   // is drawn in
];

/** Reach, in clip units — a bit over a quarter of the frame's height, before speed widens it. */
const REACH = 0.5;

/** How far a point can be pushed, in clip units, at the very centre of the field. */
const AMPLITUDE = 0.42;

/** What a fast sweep adds. A hand crossing the screen in a third of a second doubles both. */
const KICK = 0.5;

function roll() {
    let r = Math.random();
    for (const m of MOODS) { if ((r -= m.odds) <= 0) return m.value; }
    return 0;
}

export function createMagnetism(count) {
    const clouds = [];
    for (let i = 0; i < count; i++) {
        clouds.push({ mood: 0, want: roll(), left: lerp(SPELL[0], SPELL[1], Math.random()) });
    }
    let presence = 0;   // how present the pointer is, 0 to 1, smoothed

    return {
        /** For the record. Nothing depends on it; it is how you check from outside that the five are
         *  not all doing the same thing. */
        get moods() {
            return clouds.map((c) => +c.mood.toFixed(2));
        },

        update(dt, active, reduced) {
            // ⚠ Eased rather than switched, and this is not decoration: an attitude that flips
            // between frames moves every point of a cloud at once, which is the definition of the
            // jump this whole system is measured against.
            const rate = 1 - Math.exp(-dt / TURN);
            presence = lerp(presence, active && !reduced ? 1 : 0, rate);

            for (const c of clouds) {
                c.left -= dt;
                if (c.left <= 0) {
                    c.left = lerp(SPELL[0], SPELL[1], Math.random());
                    // ⚠ Redrawn freely, including the same value again. Forbidding a repeat would
                    // make "it stopped caring" always mean "it is about to care differently", which
                    // is a pattern a visitor learns without noticing.
                    c.want = roll();
                }
                c.mood = lerp(c.mood, c.want, rate);
            }
        },

        /**
         * What cloud `i` should be handed this frame, or `null` when it would do nothing — which is
         * the common case, and the reason this costs nothing when nobody is pointing.
         */
        at(i, pointer, aspect, bob, radius) {
            const c = clouds[i];
            const force = c.mood * presence;
            if (Math.abs(force) < 0.02) return null;

            const speed = Math.min(2.2, Math.hypot(pointer.vx, pointer.vy));
            const gain = 1 + speed * KICK;
            const reach = REACH * gain;

            // 🔴 Turn the whole cloud away before its points are ever projected. Without this, a
            // cloud on the far side of the screen still pays a divide and a square root for each of
            // its two thousand points to discover it is out of range — and there are five of them,
            // sixty times a second, for the whole of a visit.
            const p = 1 / bob.z;
            const cx = (bob.x * p) / aspect;
            const cy = -bob.y * p;
            // Its apparent size, generously: the shape may be a ring or a letter rather than a ball.
            const span = (radius * 2.4) * p;
            const dx = cx - pointer.x, dy = cy + pointer.y;
            if (dx * dx + dy * dy > (reach + span) * (reach + span)) return null;

            return {
                px: pointer.x,
                py: pointer.y,
                aspect,
                reach,
                amp: AMPLITUDE * gain,
                mood: force,
            };
        },
    };
}
