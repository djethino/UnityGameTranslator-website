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

import { lerp, clamp, smoothstep, easeInOut, wander } from './util.js';
import { Z_FAR } from '../bob.js';

const TAU = Math.PI * 2;

/**
 * Radius, in the cloud's own unit space.
 *
 * ⚠ It is also the clearance budget, which is why it grew with the speed. A ring leaves the frame
 * at `z ≤ R/√(aspect²+1)`, so a wider ring clears FURTHER OUT and leaves more room between there
 * and the depth floor — and that room is what pays for the lag a fast corridor brings.
 */
const RING = 1.6;
const THICK = 0.09;      // the ring has a little body, or it reads as wire

/**
 * Fraction of a cycle spent coming towards you. The rest is the flick back.
 *
 * 🔴 This constant is what caps the speed, and that is not obvious. The return has to carry a ring
 * across the whole corridor, and the time it gets is `span·(1/FORWARD − 1) / speed` — so at 0.94 it
 * had a twentieth of a lap, and going fast squeezed that below what any spring can do. Widening it
 * to 0.88 doubles the room, which buys **twice the speed for the same eighty milliseconds of
 * return**. Nothing is lost on screen: the extra share is spent where the ring is out of frame.
 */
const FORWARD = 0.82;

/** Seconds for one ring to travel the whole corridor, at pace 1. A ring passes about every fifth
 *  of that, so this is a bit over a third of a second between rings at rest. */
/**
 * 🔴 This number sets how often a ring sweeps past, and that is a safety question before it is an
 * aesthetic one. Ten rings cross per lap, so the rate is `10 · pace / LAP_SECONDS` — at 1.6 that was
 * six a second at rest and a dozen flat out, squarely inside the 3–30 Hz band that matters for
 * photosensitivity. At 2.8 it is three a second at rest, and the top is only reached by holding the
 * pointer down on purpose.
 *
 * ⚠ It is not the main guard and must not be mistaken for one: `calm: false` keeps this figure out
 * of the rotation entirely for anyone whose system asks for reduced motion. This is what protects
 * everybody else.
 */
const LAP_SECONDS = 2.8;

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
const SLOP = 0.085;

/** The softest member of each spread, which is the one the lag has to be solved against: a bob's
 *  `omega · zeal` bottoms out near 2.7, a point's `omega` at 2.2. */
const SOFTEST_BOB = 2.7;
const SOFTEST_POINT = 2.2;

/** How far apart the corridor is worked out, in field units. Finer than a ring's spacing, so no
 *  ring ever sits on a length nobody computed. */
const SAMPLE = 0.28;
/**
 * How hard a hand can turn the corridor, and how quickly the bend answers.
 *
 * 🔴 `STEER` is a SLOPE, not a position, and that distinction is the whole of what went wrong the
 * first time. There used to be a `WANDER_MAX` clamping how far the axis could stray, on the
 * reasoning that a corridor which walks off the screen stops being one. It does not limit the
 * bend — it **abolishes** it: everything here is measured as `axis(ring) − axis(camera)`, so once
 * the axis is pressed against its limit both terms are the limit and the difference is exactly
 * zero. Simulated: with the hand held to one side the rings sat dead centre at every depth, which
 * is precisely what was reported.
 *
 * ⚠ Nothing needs that clamp. The offset is relative, so a common drift cancels on its own, and
 * what actually has to be bounded is the slope — which is this constant. Over a corridor 2.2 units
 * long a slope of 0.8 puts the far end about half a screen off centre, and it cannot grow beyond
 * that however long the hand is held.
 *
 * `BANK` only sets how quickly the bend arrives, not how far it goes. At 0.09 its time constant was
 * 3.1 units — longer than the whole visible corridor, so the turn had not begun by the time you had
 * flown through it.
 */
const STEER = 1.35;
const BANK = 0.34;
/** How fast the commanded tilt itself may change, per second. Guards against a pointer that jumps. */
const TILT_RATE = 6;

/** How far ahead of the car the gradient is read. Short enough to be the hill it is ON. */
const LOOK = 0.45;

/**
 * The ride.
 *
 * ⚠ A descent pulls harder than a climb holds back, and deliberately so — the two are not the same
 * force. Falling is gravity; climbing is gravity minus whatever speed you brought into the hill, and
 * a ride that lost as fast as it gained would spend its time at a standstill, which is the one thing
 * this figure must never be.
 */
/**
 * 🔴 A coaster, written as one: gravity along the track, and drag that grows with the SQUARE of the
 * speed. Everything asked for falls out of those two terms rather than being arranged.
 *
 * | asked for | what produces it |
 * |---|---|
 * | the resting speed IS the minimum | on the flat the only force left is drag, so it coasts down to the floor and stays |
 * | the top is not reached in a moment | `dv/dt = g·s − k·v²` approaches its limit asymptotically; the last quarter of the range takes as long as the first three |
 * | a real top speed | the limit is `√(g·s/k)`, so raising it is a matter of the ratio rather than of a clamp |
 * | gaining beats losing | falling has gravity behind it, climbing has gravity minus whatever you brought into the hill — the asymmetry is in the physics, not in two constants |
 *
 * ⚠ The previous version had a pair of constants meant to be asymmetric and measured out exactly
 * even, because a taper near the ceiling cancelled the advantage it gave the descent. There is no
 * taper here and no ceiling to taper against: drag does that work, and it does it honestly.
 */
const GRAVITY = 2.9;        // pull along the track, per unit of slope
const DRAG = 0.1;           // resistance, per unit of speed squared
const MIN_PACE = 0.8;       // the resting speed, and the floor: a coaster on the flat is still rolling

/**
 * The braking that hands the figure over.
 *
 * ⚠ Asked for, and it is not decoration: a corridor running flat out and then simply being replaced
 * makes the cut visible. Coasting to a near-stop over the last couple of seconds gives the handover
 * something to begin from — and it is a brake, not a cut, so it must take long enough to be felt as
 * one.
 */
const BRAKE_FROM = 0.9;     // fraction of the figure's life after which it starts slowing
const BRAKE_TO = 0.42;      // the pace it coasts down to

/**
 * 🔴 The ceiling is not a matter of taste, it is where this figure's own return stops working.
 *
 * The flick back is 6 % of a cycle. At pace 3.4 a whole lap took 0.76 s, so the return had **three
 * frames** to carry a ring across two and a bit units of corridor — which no spring does, however
 * stiff. The cycle then degenerated: the ring never reached the far end, so it never left the frame
 * either, and 12 passes in 181 turned back with most of the cloud still on screen.
 *
 * What has to stay bounded is the PRODUCT `rate × pace` — the units of corridor covered per second —
 * against the time the return needs.
 *
 * ⚠ This is now a backstop rather than the thing you feel. Drag settles the ride well below it, so
 * nothing reaches this in normal use; it is here to keep the return safe if a slope, a scroll warp
 * and a burst of steering ever conspire.
 */
const MAX_PACE = 3.9;

/** Where the ride starts to rattle, as a fraction of the drag-limited top speed, and how hard. */
const SHAKE_FROM = 0.62;
const SHAKE = 0.022;

/** Where the light starts to smear, same scale. */
const RUSH_FROM = 0.5;

export default {
    id: 'tunnel',
    kind: 'predefined',
    calm: false,
    // 🔴 The one figure that refuses free electrons. Everywhere else a cloud leaving the formation
    // reads as a cloud with a mind of its own; here the formation IS the figure, and a ring that
    // wanders off does not read as mischief, it reads as the corridor coming apart.
    rigid: true,
    // The longest of the nineteen. A corridor is not a shape you recognise and move on from, it is
    // somewhere you settle into — and it now has bends, slopes and a changing pace to get through.
    duration: [20, 30],

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
        this.pace = 1;

        // ── the corridor itself, laid down one length at a time ──
        //
        // Rebuilt every frame by `lay`, outward from the camera. Nothing is remembered from one
        // frame to the next, which is exactly what makes the corridor answer the hand at once.
        this.rail = null;
        this.wantX = 0;
        this.wantY = 0;
        this.drift = [rnd() * 6.28, rnd() * 6.28];   // where it wanders when nobody is steering

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

    /**
     * Lay the corridor out ahead of the camera, this frame, from where the hand is pointing.
     *
     * 🔴 Built OUTWARD FROM THE CAMERA every frame — each length from the one before it plus the
     * steering — and not laid down in advance. That distinction was the whole of what made the ride
     * incomprehensible.
     *
     * The first version wrote the track into a history that ran three and a half units ahead of the
     * car, which is where new track has to go if it is never to be revised. It is rigid, and it is
     * unplayable: your hand only reached the car two and a half seconds later, so the speed changed
     * for a reason that had left the screen. Measured — the pace was still climbing long after the
     * hand had been let go.
     *
     * ⚠ What is given up is that the far end of the corridor reshapes as you steer, instead of
     * standing still while you fly into it. What is bought is that the corridor bends where you
     * point, NOW, and the speed changes for a reason you can see. For a background that answers a
     * hand, that trade is not close.
     *
     * ⚠ The bend accumulates with distance, so the ring about to swallow you barely moves while the
     * far end swings: that gradient IS the read of a turn. A corridor displaced bodily would just be
     * a corridor somewhere else.
     */
    lay(ctx) {
        const p = ctx.pointer;
        const steering = p && p.active;
        // Where the hand asks the track to tilt, as a slope. With nobody pointing it wanders on its
        // own, so the figure is never a straight pipe on a machine that has no mouse.
        const cmdX = steering ? p.x * STEER : wander(this.travel * 0.55 + this.drift[0], this.drift[0]) * STEER * 0.8;
        const cmdY = steering ? p.y * STEER * 0.8 : wander(this.travel * 0.41 + this.drift[1], this.drift[1]) * STEER * 0.6;

        // ⚠ Eased in TIME as well as along the corridor, and the two are not the same guard. `BANK`
        // smooths the bend with distance, which does nothing about a pointer that moves instantly —
        // and on a touch screen it does: a tap somewhere else teleports it. Measured, an
        // instantaneous jump swung the far rings a quarter of a screen in one frame. A sixth of a
        // second of lag costs nothing anybody can feel and makes that impossible.
        const k = Math.min(1, ctx.dt * TILT_RATE);
        this.wantX += (cmdX - this.wantX) * k;
        this.wantY += (cmdY - this.wantY) * k;

        // Eased along the corridor rather than applied flat, so a turn arrives as a curve and the
        // near rings stay put while the far ones swing.
        const reach = Z_FAR + this.lap + 1;
        const n = Math.ceil(reach / SAMPLE) + 2;
        if (!this.rail || this.rail.length !== n * 2) this.rail = new Float32Array(n * 2);

        let vx = 0, vy = 0, x = 0, y = 0;
        for (let i = 0; i < n; i++) {
            this.rail[i * 2] = x;
            this.rail[i * 2 + 1] = y;
            vx += (this.wantX - vx) * BANK;
            vy += (this.wantY - vy) * BANK;
            x += vx * SAMPLE;
            y += vy * SAMPLE;
        }
    },

    /** Where the corridor's axis sits a given distance AHEAD OF THE CAMERA. */
    axis(d) {
        const rail = this.rail;
        const last = rail.length / 2 - 2;
        const i = clamp(Math.floor(d / SAMPLE), 0, last);
        const k = clamp(d / SAMPLE - i);
        return [
            lerp(rail[i * 2], rail[i * 2 + 2], k),
            lerp(rail[i * 2 + 1], rail[i * 2 + 3], k),
        ];
    },

    update(ctx) {
        const n = ctx.bobs.length;
        const t = ctx.t;

        // ── the ride ──
        //
        // 🔴 Point low on the screen and it picks up speed; point high and it bleeds off. Not a
        // dial: an acceleration. The pace carries its own momentum, so letting go leaves it still
        // running fast and easing back rather than snapping to a value — which is the whole
        // difference between a rollercoaster and a slider.
        //
        // ⚠ And it never stops. The floor is where it coasts to, not a speed it is held at: on the
        // flat, drag is the only force left and it settles here.
        const { mid, amp, p1, h1, p2, h2 } = this.tempo;
        // A small wobble on the floor itself, so two runs do not idle at exactly the same speed.
        const floor = MIN_PACE * (mid + amp * 0.18 * Math.sin(TAU * (t / p1 + h1)));
        // ⚠ The rails are laid FIRST: the gradient about to be read is read off them.
        this.lay(ctx);

        // 🔴 The speed comes from the gradient of the track AT THE CAR — which, because the track is
        // now built outward from the car, is the tilt the hand is asking for right now. Track and
        // speed therefore say the same thing at the same moment: you tip the nose down, the corridor
        // ahead tips down, and you gather speed. That is what was broken.
        const slope = (this.axis(LOOK)[1] - this.axis(0)[1]) / LOOK;   // positive is downhill
        // Coasting into the handover: extra drag over the last couple of seconds, so the corridor is
        // already slowing when the next figure takes it — and it slows by the same physics as
        // everything else here, which is why it reads as coasting rather than as being switched off.
        const braking = smoothstep(BRAKE_FROM, 1, ctx.progress);

        // 🔴 Gravity along the track, minus drag proportional to the square of the speed. Two terms,
        // and every quality asked of the ride falls out of them — see GRAVITY above.
        const pull = slope * GRAVITY * (1 - braking);
        const drag = (DRAG + braking * 0.9) * this.pace * this.pace;
        this.pace = clamp(this.pace + (pull - drag) * ctx.dt, floor, MAX_PACE);

        // How far up the range we are, for the things that only happen at speed. Measured against
        // the limit drag actually allows on the steepest descent, not against the backstop.
        const top = Math.sqrt((GRAVITY * 0.45) / DRAG);
        const fast = clamp((this.pace - floor) / Math.max(0.1, top - floor));

        const speed = this.rate * this.pace;      // field units per second, right now
        this.travel += speed * ctx.dt;

        // 🔴 The stiffness is derived from the speed, not chosen. A critically damped spring
        // following a ramp settles `2v/omega` behind it, so the eagerness needed to stay within a
        // given lag is `2v/(lag · omega)` — and half the budget goes to the centre, half to the
        // points, since the two lags add up. Fixed values were right for one pace and one
        // LAP_SECONDS; the next person to make this faster would have found the rings turning back
        // in plain sight again, with nothing on screen to say why.
        const budget = SLOP * 0.5;
        // ⚠ The ceilings were 60 and 45, and the faster ride reaches straight through them: at
        // 3.4 units a second the formula asks for 60 and 73, so the grip clamp would have bitten
        // and the ring would have stopped clearing the frame — the fault this whole figure has been
        // chased around three times. Raised to what the new top speed needs, which both springs can
        // now carry because they are substepped: at six substeps the point spring tolerates 250 and
        // this asks 73.
        const haste = clamp((2 * speed) / (budget * SOFTEST_BOB), 3, 95);
        const grip = clamp((2 * speed) / (budget * SOFTEST_POINT), 4, 85);

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
        const aim = this.axis(this.zTurn);

        /**
         * The rattle, once the ride is running hard.
         *
         * ⚠ Applied to the aim rather than to the rings, so the whole corridor shakes together and
         * nothing is displaced RELATIVE to anything else — the geometry that lets a ring leave the
         * frame is untouched. It is the car rattling, not the tunnel coming apart.
         *
         * ⚠ Deliberately small and not a flash: a positional jitter of a diffuse field, ramping in
         * over the last third of the range. A background that starts strobing at speed would undo
         * the care taken over the ring rate a few constants above.
         */
        const rattle = smoothstep(SHAKE_FROM, 1, fast) * SHAKE;
        const cx = aim[0] + (rattle ? wander(t * 47, 1.3) * rattle : 0);
        const cy = aim[1] + (rattle ? wander(t * 53, 4.1) * rattle : 0);

        // Told to the engine, which hands it to the light pass: at speed the glow smears outward
        // from the vanishing point. See the wash shader.
        ctx.setRush(smoothstep(RUSH_FROM, 1, fast));

        const out = [];

        for (let i = 0; i < n; i++) {
            const bob = ctx.bobs[i];

            let w = (this.travel + (i / n - 0.4) * this.lap * open) % this.lap;
            if (w < 0) w += this.lap;

            let z;
            if (w <= this.span) {
                z = Z_FAR - w;                    // coming at you
                bob.haste = haste;
                bob.grip = grip;
            } else {
                // Gone past, and out of sight since `zTurn`. The flick back, eased at both ends and
                // hurried by a stiffened spring so it is over in about a tenth of a second.
                const k = easeInOut((w - this.span) / (this.lap - this.span));
                z = lerp(this.zTurn, Z_FAR, k);
                // The flick back covers the corridor in a fraction of the time the trip took, so it
                // needs more of both than the approach — proportionally, from the same figures.
                bob.haste = Math.min(60, haste * 1.6);
                bob.grip = Math.min(45, grip * 1.4);
            }

            // A corridor does not breathe. Not quite zero, so the rings are not machined.
            bob.sway = 0.10;
            // 🔴 And it does not answer the cursor either. Everywhere else the pointer pushes and
            // pulls at the points themselves; here it steers the whole corridor instead, which is a
            // far stronger thing to do with a hand — and a ring that also bulged around the cursor
            // would simply have stopped being a ring.
            bob.charm = 0;

            // 🟢 Where the corridor is at that depth, minus where it is at ours. With the rail built
            // outward from the camera, a ring's depth IS its distance along it — so this is the
            // whole of the lateral placement, and the flick back needs no special case: as `z`
            // climbs, the ring simply follows the corridor back out to the far end.
            const [rx, ry] = this.axis(z);
            const ax = rx - cx;
            const ay = ry - cy;

            // 🔴 The rings turn by DIFFERENT amounts, and that is the whole trick. A circle rotated
            // is the same circle, so a rigid turn would be invisible; only the offset between one
            // ring and the next can be seen. Tied to the station, the twist is a fixed helix cut
            // into the corridor — travel faster and the rings arrive turning faster, for nothing.
            bob.scale = 1;
            bob.twist = (this.travel + z) * 1.35 + this.travel * 0.30;

            out.push({ x: ax, y: ay, z });
        }
        return out;
    },
};
