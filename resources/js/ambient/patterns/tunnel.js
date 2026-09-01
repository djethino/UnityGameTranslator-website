/**
 * Le tunnel — a corridor you fall into: rings coming at you, swallowing you whole, and flicking back
 * to the far end while you are inside them.
 *
 * ⚠ Not a repeat of `traversee`. That one is a straight flow: five bodies come at you and pass. This
 * is a STRUCTURE — the rings share one axis and one radius, so perspective alone tells you they are
 * a corridor rather than five separate things.
 *
 * ── The model: the corridor is fixed, the camera travels ───────────────────────────────────────
 * Everything here follows from that one choice, and the three faults it fixes all came from not
 * having made it. `travel` is a distance in field units, not a phase; a ring's `station` is where it
 * sits along the corridor and does not change while it is in flight; its depth is simply
 * `station - travel`. Which gives, for free:
 *
 * | it gives | because |
 * |---|---|
 * | **bends and slopes** | the corridor's axis is a function of station. The far end swings aside, and you go through where it went |
 * | **acceleration** | `travel` advances at whatever rate the moment asks for. Nothing else has to know |
 * | **a rigid tunnel** | rings do not slide sideways as they approach: their station is fixed, only yours moves |
 *
 * ── 🔴 Two failures this figure has already paid for ───────────────────────────────────────────
 *
 * **The ring turned back before it had passed you.** Not this file's fault: the system floored every
 * depth at `Z_NEAR = 0.35` and this figure asked for 0.36, so it turned one hundredth above a wall
 * it could not see. The exit depth is now COMPUTED, from the ring's radius and the frame's aspect —
 * `z ≤ R/√(aspect²+1)` is where the projected ring encloses the corners — and the turn happens
 * comfortably inside it. A hard-coded depth would have been right on one screen shape only.
 *
 * **The return was hidden instead of being fast.** Three versions tried to make the trip back
 * invisible, and each made things worse than the jump it replaced. It is part of the cycle now, run
 * at a stiffened spring: ~0.4 s from behind the camera to the far end, begun only once the ring is
 * out of sight, and it slides to its new station as it goes, so it arrives somewhere that makes
 * sense rather than snapping there.
 *
 * ── Why each cloud carries TWO rings ───────────────────────────────────────────────────────────
 * Five rings is not a corridor, it is five hoops: by the time the near one fills the screen the next
 * is a third of its size and there is a wide empty band between them. Splitting each cloud's points
 * into two coaxial rings doubles the corridor's density for nothing — same points, same draw. They
 * sit close rather than evenly interleaved, because BOTH have to be out of sight when the cloud
 * turns, and that is what the near end can afford; the pairing reads as double rings, which is a
 * corridor of its own kind rather than a compromise.
 */

import { lerp, clamp, smoothstep, easeInOut } from './util.js';
import { Z_FAR } from '../bob.js';

const TAU = Math.PI * 2;

const RING = 1.4;        // radius, in the cloud's own unit space
const THICK = 0.09;      // the ring has a little body, or it reads as wire

/** Fraction of a cycle spent coming towards you. The rest is the flick back. */
const FORWARD = 0.94;

/** Seconds for one ring to travel the whole corridor, at pace 1. */
const LAP_SECONDS = 7.2;

/** Nearest a point may sit. Below 0.12 the engine floors it and the ring stops being a ring. */
const Z_FLOOR = 0.14;

/** How far inside the exit depth the turn happens. At 1 it would turn on the frame it clears. */
const CLEAR = 0.90;

/**
 * 🔴 What the ring arrives with on top of the depth it was asked for, and why the near end has a
 * budget rather than a constant.
 *
 * A bob does not sit where a figure puts it, it springs towards it — and a critically damped spring
 * following a ramp settles a fixed distance BEHIND it, `2v/ω`. At the pace this corridor runs and
 * the softest bob's stiffness that came to half a unit: the target reached the turn depth, the ring
 * never did, and it swung round at 0.76 in plain sight. Measured, not deduced: that is what the
 * first version of this figure was doing after the depth floor was lifted.
 *
 * So the centre is driven stiff (`haste`) and its private drift is turned down (`sway`), which
 * leaves a small residue — and the residue is budgeted here rather than hoped away.
 */
const SLOP = 0.055;

export default {
    id: 'tunnel',
    kind: 'predefined',
    calm: false,
    duration: [15, 21],

    enter(ctx) {
        const n = ctx.bobs.length;
        const rnd = ctx.rng;
        const R = RING * ctx.radius;              // the ring's radius in field units

        // 🔴 Where the ring is genuinely gone. Projected, it is an ellipse with semi-axes
        // R/(z·aspect) and R/z; it encloses the frame's corner (1,1) when (z·aspect/R)² + (z/R)² ≤ 1,
        // hence z ≤ R/√(aspect²+1). On a wider screen the ring is relatively narrower, so it has to
        // come CLOSER to clear — which is why this cannot be a constant.
        // ⚠ The ring's INNER edge, not its centre line: it has body, and the innermost points are
        // the last to leave.
        this.zExit = (R - (THICK / 2) * ctx.radius) / Math.hypot(ctx.aspect, 1);

        // The near end's budget, in full. The pair's far ring must be out of sight and its near
        // ring must stay in front of the engine's floor — both after whatever the spring adds.
        const hi = CLEAR * this.zExit - SLOP;     // no ring may sit above this at the turn
        const lo = Z_FLOOR + SLOP;                // nor below this
        this.zTurn = (lo + hi) / 2;               // the middle: the same margin either way

        this.span = Z_FAR - this.zTurn;           // the visible corridor, in field units
        this.lap = this.span / FORWARD;           // one full cycle, return included
        this.rate = this.lap / LAP_SECONDS;       // field units per second at pace 1

        // Half the spacing between clouds interleaves the pairs evenly with the singles. Where the
        // near end cannot afford that much — a wide frame, where the ring has to come closer before
        // it clears — the pair simply tightens, down to a single ring if the budget runs out.
        this.gap = Math.max(0, Math.min((this.lap / n) * 0.5, hi - lo));

        // Start with the corridor folded around a middle depth and let it open both ways, so
        // nothing has to rush forward or retreat to take its place when the figure begins.
        this.travel = Z_FAR - 1.5;

        // ── the route: where the corridor bends, and where it climbs ──
        // Two waves per axis so a bend is never a plain sine, at wavelengths of roughly one to four
        // laps: long enough that you feel yourself being steered rather than shaken.
        const wave = (lo, hi, flo, fhi) => ({
            a: lerp(lo, hi, rnd()), f: lerp(flo, fhi, rnd()), h: rnd(),
        });
        this.route = {
            x: [wave(0.20, 0.44, 0.15, 0.30), wave(0.08, 0.20, 0.36, 0.62)],
            y: [wave(0.14, 0.31, 0.13, 0.27), wave(0.06, 0.14, 0.33, 0.58)],
        };

        // ── the pace: when it presses on and when it eases off ──
        // Also two waves, on periods that do not divide into each other, so the run never settles
        // into a rhythm you can predict.
        this.tempo = {
            mid: lerp(0.98, 1.20, rnd()),
            amp: lerp(0.42, 0.66, rnd()),
            p1: lerp(4.5, 7.5, rnd()), h1: rnd(),
            p2: lerp(9.0, 15.0, rnd()), h2: rnd(),
        };

        // Two coaxial rings per cloud, one either side of its centre in depth. `oz` is in the
        // cloud's unit space and the engine scales it by radius·0.85, so this is undone here to
        // land the pair exactly `gap` apart in the field.
        const half = this.gap / 2 / (ctx.radius * 0.85);
        ctx.clouds.forEach((cloud) => {
            cloud.setShape((i, _, out) => {
                const a = rnd() * TAU;
                const r = RING + (rnd() - 0.5) * THICK;
                out[0] = Math.cos(a) * r;
                out[1] = Math.sin(a) * r;
                out[2] = (i % 2 ? half : -half) + (rnd() - 0.5) * THICK;
            });
        });
        return true;
    },

    /** Where the corridor's axis sits at a given station. Both bends and slopes come from here. */
    axis(s) {
        const { x, y } = this.route;
        return [
            x[0].a * Math.sin(TAU * (s * x[0].f + x[0].h)) + x[1].a * Math.sin(TAU * (s * x[1].f + x[1].h)),
            y[0].a * Math.sin(TAU * (s * y[0].f + y[0].h)) + y[1].a * Math.sin(TAU * (s * y[1].f + y[1].h)),
        ];
    },

    update(ctx) {
        const n = ctx.bobs.length;
        const t = ctx.t;

        // How hard we are pressing on, right now. Clamped low rather than allowed to reach zero: a
        // corridor that stops is a corridor you are no longer travelling.
        const { mid, amp, p1, h1, p2, h2 } = this.tempo;
        const pace = clamp(
            mid + amp * (0.62 * Math.sin(TAU * (t / p1 + h1)) + 0.38 * Math.sin(TAU * (t / p2 + h2))),
            0.34, 2.05,
        );
        this.travel += this.rate * pace * ctx.dt;

        // The corridor starts folded and opens over two seconds, half of it ahead and half behind
        // the middle depth, so no ring has to cross the whole field to take its place.
        const open = smoothstep(0, 2.0, t);

        // 🔴 We aim where the corridor GOES, not where we stand — one station ahead, exactly the
        // depth at which a ring turns back.
        //
        // It is what anyone steering does, and here it is also what lets the ring leave. Measured
        // against `axis(travel)`, the ring about to swallow you sits off to the side by the bend
        // accumulated over its own depth: its projected ellipse stops enclosing the frame's corners
        // and a slice of it hangs on screen just as it turns — 20 passages in 45, and the harder
        // the bend the worse. Aiming ahead puts the near ring back on the axis, where it exits
        // cleanly, and costs nothing elsewhere: the far rings keep their full offset, which is the
        // bend you actually see.
        const [cx, cy] = this.axis(this.travel + this.zTurn);
        const out = [];

        for (let i = 0; i < n; i++) {
            const bob = ctx.bobs[i];

            let w = (this.travel + (i / n - 0.4) * this.lap * open) % this.lap;
            if (w < 0) w += this.lap;

            let z, station, slide;
            if (w <= this.span) {
                // Coming at you. Depth falls exactly as fast as `travel` rises, which is what keeps
                // the station — and therefore the bend — fixed while the ring is in flight.
                z = Z_FAR - w;
                station = this.travel + z;
                slide = 0;
                // Stiff, because a corridor is rigid and because anything softer arrives late — see
                // SLOP above. The organic quality is not lost with it: it lives in the points'
                // own springs, which `grip` only tightens part of the way.
                bob.haste = 14;
                // 🔴 Ten, not three. The points have their own springs and a deliberately wide
                // spread of eagerness — which is what makes a cloud organic, and what makes a ring
                // travelling this fast trail its slowest points a fifth of a corridor behind. Those
                // stragglers are still on screen when the ring's centre has cleared it, so the
                // figure reads as "it turned back in front of me" for the sake of a value that was
                // right at half this speed.
                bob.grip = 10;
            } else {
                // Gone past, and out of sight since `zTurn`. The flick back, eased at both ends and
                // hurried by a stiffened spring so it is over in about four tenths of a second.
                const k = easeInOut((w - this.span) / (this.lap - this.span));
                z = lerp(this.zTurn, Z_FAR, k);
                // ⚠ The station it held when it turned, not the one it would have now. `travel` has
                // moved on since — reading it live would slide the ring sideways for the whole of
                // the return, which is the one thing this branch exists to avoid.
                station = this.travel - (w - this.span) + this.zTurn;
                slide = k;
                bob.haste = 18;
                bob.grip = 12;
            }

            // A corridor does not breathe. Not quite zero, so the rings are not machined.
            bob.sway = 0.10;

            // Its own place along the corridor, minus ours: what is left is where it sits on screen.
            // During the flick it slides to the station one lap further on — which is the station it
            // will hold for the whole of its next trip, so nothing has to move again when it lands.
            const here = this.axis(station);
            const next = this.axis(station + this.lap);
            const ax = lerp(here[0], next[0], slide) - cx;
            const ay = lerp(here[1], next[1], slide) - cy;

            // 🔴 The rings turn by DIFFERENT amounts, and that is the whole trick. A circle rotated
            // is the same circle, so a rigid turn would be invisible; only the offset between one
            // ring and the next can be seen. Tied to the station, the twist is a fixed helix cut
            // into the corridor — travel faster and the rings arrive turning faster, for nothing.
            bob.scale = 1;
            bob.twist = station * 1.35 + this.travel * 0.30;

            out.push({ x: ax, y: ay, z });
        }
        return out;
    },
};
