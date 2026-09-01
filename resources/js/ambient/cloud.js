/**
 * A cloud: one blob's population.
 *
 * 🔴 A blob has no shape here, and that is the design. It has a centre, a radius, and several
 * hundred points that are each trying to reach a place near that centre. Everything that looks like
 * a shape — stretching, hollowing, splitting, re-forming, taking the outline of a letter — is where
 * the points happen to be. There is no code for "stretch" and there is none for "split".
 *
 * ── Where the stretching comes from ────────────────────────────────────────────────────────────
 * Each point pulls toward its place with its OWN eagerness, and the spread is deliberately wide.
 * When the centre moves off, the eager points arrive first and the reluctant ones trail: the cloud
 * elongates along its own motion, all by itself, and gathers back into a ball when it stops. That
 * is one line of variation standing in for a physical model, and it is the same trick as the
 * humanizer one level up — imperfection distributed rather than choreographed.
 *
 * ── What never happens ─────────────────────────────────────────────────────────────────────────
 * A point belongs to its cloud when it is created and belongs to it for the life of the page.
 * Two clouds can occupy the same space, and their points interleave — that is the mixing. Neither
 * ever acquires a point from the other, and no third body is ever produced. Fusion is not
 * forbidden by a rule; there is simply nothing in this file that could carry it out.
 */

import { SINE } from './trig.js';

const TAU = Math.PI * 2;

// Hoisted out of the object once, at module load: the point loop reads these millions of times a
// second and a property lookup per read is not free.
const SINE_T = SINE.table, SINE_M = SINE.mask, SINE_K = SINE.scale, SINE_Q = SINE.quarter;

/**
 * The stiffest a point may be for a given step, as `omega · dt`.
 *
 * 🔴 Derived, not chosen. Writing this spring's step as a matrix on (velocity, error) gives
 * `det = 1 - 2·omega·dt`, and a product of eigenvalues above 1 in modulus is divergence — so the
 * ceiling is `omega · dt ≤ 1`, and it is marginal there. 0.7 leaves room.
 *
 * ⚠ The first version of this line said 1.4, on the half-remembered rule of thumb that explicit
 * springs are fine up to 2. They are not, for this one: at 1.4 an eigenvalue is -3.3 and the cloud
 * reaches NaN in about two seconds. It did, and `finite()` caught it. A guard set above the
 * threshold it is guarding is worse than no guard, because it reads as protection.
 */
const MAX_OMEGA_DT = 0.7;

export class Cloud {
    /**
     * @param {number} index      which of the five
     * @param {number} count      how many points it carries
     * @param {number[]} rgb      its allegiance, 0-1 per channel
     */
    constructor(index, count, rgb) {
        this.index = index;
        this.count = count;
        this.rgb = rgb;

        // Where each point wants to sit, in the cloud's own unit space. Replaced wholesale when a
        // pattern asks for another arrangement (a letter); a ball the rest of the time.
        this.shape = new Float32Array(count * 3);
        // Where each point actually is, in the field. The gap between the two is the whole effect.
        this.pos = new Float32Array(count * 3);
        this.vel = new Float32Array(count * 3);

        // Per-point personality. `omega` is the spread that produces the stretching; `phase` gives
        // each point its own slow shimmer so a cloud at rest is still alive.
        this.omega = new Float32Array(count);
        this.phase = new Float32Array(count);
        this.jitter = new Float32Array(count);

        /**
         * How much of the pointer's pull a point feels — its weight, seen from the other end.
         *
         * 🔴 A fifth of them feel nothing at all, and that is the whole reason this is per point
         * rather than per cloud. Give every point the same susceptibility and the cursor moves the
         * shape; give them a spread with some at zero and the cursor TEARS it — the light ones fly
         * off, the heavy ones sag, the deaf ones hold the outline, and what is left behind is
         * recognisably the same shape with a bite out of it.
         */
        this.heft = new Float32Array(count);

        /**
         * How recently this point was disturbed, 1 down to 0, decaying over about half a second.
         *
         * 🔴 It exists because the bounce happens AFTER the hand has gone. Softening the damping
         * only while a point is being pushed achieves nothing: the push holds it out of place, and
         * the moment it is released the spring is critically damped again — which is precisely the
         * damping that never overshoots. The disturbance has to outlive its cause by about as long
         * as the return takes, or there is no return worth watching.
         */
        this.stir = new Float32Array(count);

        const rnd = mulberry(index * 7919 + 13);
        for (let i = 0; i < count; i++) {
            // 2.2 to 9.0 — better than a factor of four between the most and least eager point.
            // Narrow this and the cloud moves like a rigid object; widen it and it comes apart.
            this.omega[i] = 2.2 + rnd() * 6.8;
            this.phase[i] = rnd() * TAU;
            this.jitter[i] = 0.004 + rnd() * 0.012;
            const r = rnd();
            this.heft[i] = r < 0.2 ? 0 : 0.3 + (r - 0.2) * 0.875;
        }

        this.ball(rnd);
        for (let i = 0; i < count * 3; i++) this.pos[i] = this.shape[i];
    }

    /**
     * The resting arrangement: a ball, denser at the heart.
     *
     * ⚠ `cbrt` of a uniform variable would give even density throughout, which reads as a fuzzy
     * SPHERE — you can see its surface. The square root crowds the middle instead, so the cloud
     * fades outward and has no edge to notice.
     */
    ball(rnd = Math.random) {
        for (let i = 0; i < this.count; i++) {
            const u = rnd() * 2 - 1;
            const a = rnd() * TAU;
            const r = Math.sqrt(rnd());
            const s = Math.sqrt(1 - u * u);
            this.shape[i * 3] = Math.cos(a) * s * r;
            this.shape[i * 3 + 1] = Math.sin(a) * s * r;
            this.shape[i * 3 + 2] = u * r;
        }
    }

    /**
     * Take an arbitrary arrangement. `place(i, n, out)` writes a unit-space offset for point `i`.
     * This is how a cloud becomes a letter — nothing else in the class changes.
     */
    setShape(place) {
        const out = [0, 0, 0];
        for (let i = 0; i < this.count; i++) {
            place(i, this.count, out);
            this.shape[i * 3] = out[0];
            this.shape[i * 3 + 1] = out[1];
            this.shape[i * 3 + 2] = out[2];
        }
    }

    /**
     * Move every point one step toward where it should be.
     *
     * `spin` slowly turns the arrangement about the view axis so a resting cloud keeps turning over
     * instead of presenting the same face for twelve seconds. `shearX`/`shearY` lean it into a turn,
     * and `yaw` turns it in depth.
     */
    update(centre, radius, dt, time, spin = 0, shearX = 0, shearY = 0, active = this.count, yaw = 0, grip = 1, magnet = null) {
        const cs = Math.cos(spin), sn = Math.sin(spin);
        // ⚠ A second rotation, about the VERTICAL axis, and it is not the same as `spin`. Spin
        // turns the arrangement in the plane of the screen — a ring keeps facing you. Yaw turns it
        // in depth, so what was at the front goes behind: that is what makes a globe a globe rather
        // than a spinning disc, and it is the only rotation that changes a point's z.
        const cy = Math.cos(yaw), sy = Math.sin(yaw);
        const { shape, pos, vel, omega, phase, jitter, heft, stir } = this;
        // How much of a disturbance survives this frame. Half a second is about what a loose point
        // takes to come home, so the spring is still soft while it is on its way back.
        const fade = Math.exp(-dt / 0.45);
        // `active` thins the population without paying to integrate points nobody will draw.
        const count = Math.min(active, this.count);

        // 🔴 Substepped, so that `grip` has no ceiling worth speaking of.
        //
        // The integrator is explicit in the stiffness term: written as a matrix on (velocity,
        // error) its determinant is `1 - 2·w·dt`, so it diverges past `w · dt = 1` — a hard wall,
        // not a soft one. Simply clamping there put a ceiling on how rigid a body could be, and
        // therefore on how FAST the corridor could travel, since a spring following a ramp trails
        // it by `2v/w` and only stiffness buys that back. Splitting the step moves the wall instead
        // of living against it, and it is nearly free: the targets above are computed once per
        // frame, so a substep is three multiply-adds per axis and no trigonometry.
        // The eagerest a point can be: `omega` is drawn from 2.2 to 9.0 in the constructor, so 9 is
        // the ceiling of the spread and the only figure the substep count has to respect.
        const steps = Math.min(6, Math.ceil((9 * grip * dt) / 0.35) || 1);
        const h = dt / steps;

        for (let i = 0; i < count; i++) {
            const j = i * 3;

            const ox0 = shape[j], oy = shape[j + 1], oz0 = shape[j + 2];
            // Yaw first, in depth, then spin in the plane of the screen.
            const ox = yaw ? ox0 * cy + oz0 * sy : ox0;
            const oz = yaw ? oz0 * cy - ox0 * sy : oz0;
            const sx0 = ox * cs - oy * sn;
            const sy0 = ox * sn + oy * cs;
            // Shear leans the whole population into a turn. Applied to the arrangement, not to the
            // points' positions, so the spring still smooths it and the cloud banks rather than
            // snapping.
            const rx = sx0 + sy0 * shearX;
            const ry = sy0 + sx0 * shearY;

            // 🔴 The spread of eagerness is what makes a cloud organic — and what makes a RIGID
            // arrangement impossible. A tunnel ring whose slowest points are half a corridor behind
            // its centre does not leave the frame when the centre does: it dissolves in the middle
            // of the screen, which is exactly what "the near ring disappears" was. `grip` tightens
            // every point at once, so a pattern that needs an object rather than a mist can have
            // one without giving up the default.
            const w = Math.min(omega[i] * grip, MAX_OMEGA_DT / h);
            const jt = jitter[i];
            const ph = phase[i];

            // Target, plus a private shimmer. Added to the target rather than to the position so
            // the spring smooths it — added to the position it reads as noise, not as life.
            // ⚠ Computed ONCE, outside the substep loop below: it does not change within a frame,
            // and it carries the three sines, which are the expensive part of this loop.
            // ⚠ Read straight out of the sine table, indexed inline rather than through `sin()`:
            // this is the innermost line of the whole background — thirty-three thousand of these a
            // frame — and it is the one place where saving a function call is worth the ugliness.
            // See trig.js for why a 4096-entry table is three orders of magnitude finer than it
            // needs to be here.
            let tx = centre.x + rx * radius + SINE_T[((time * 0.7 + ph) * SINE_K) & SINE_M] * jt;
            let ty = centre.y + ry * radius + SINE_T[((time * 0.61 + ph * 1.3) * SINE_K + SINE_Q) & SINE_M] * jt;
            const tz = centre.z + oz * radius * 0.85 + SINE_T[((time * 0.53 + ph * 0.7) * SINE_K) & SINE_M] * jt;

            // ── the pointer, if this cloud has an opinion about it ──
            // Measured where the cursor actually is: on the glass. `p = 1/z` is the same projection
            // the vertex shader does, so "next to the cursor" here means next to it on screen.
            let stirred = stir[i] * fade;
            if (magnet && heft[i] > 0) {
                const p = 1 / tz;
                // ⚠ The pointer arrives with y pointing DOWN (it is a viewport coordinate) and the
                // projection has y pointing UP, so the cursor's clip position is `-py`. Subtracting
                // it gives `+ magnet.py`, and getting that wrong mirrors the whole interaction
                // vertically — invisible to any test that keeps the cursor on the horizon, which is
                // exactly what every one of mine did.
                const dx = (tx * p) / magnet.aspect - magnet.px;
                const dy = -ty * p + magnet.py;
                const d2 = dx * dx + dy * dy;
                const r2 = magnet.reach * magnet.reach;
                if (d2 < r2) {
                    // Zero at the edge and flat there, not merely small: outside the reach a point
                    // is exactly where its figure put it.
                    const f = 1 - d2 / r2;
                    const fall = f * f;
                    const d = Math.sqrt(d2) || 1e-5;
                    // Positive drives away from the cursor, so an attracted cloud (mood > 0) pulls in.
                    let move = -magnet.mood * magnet.amp * fall * heft[i];
                    // ⚠ An attracted point may not be dragged PAST the cursor, or it oscillates
                    // through it and the attraction reads as a jitter.
                    if (move < -d * 0.85) move = -d * 0.85;
                    tx += ((dx / d) * move) * tz * magnet.aspect;
                    ty += (-(dy / d) * move) * tz;
                    const now = Math.abs(move) / magnet.amp;
                    if (now > stirred) stirred = now;
                }
            }
            stir[i] = stirred;

            // Semi-implicit, and critically damped only while nothing is stirring it.
            //
            // 🔴 The bounce lives in this line. A critically damped spring is the fastest return
            // with NO overshoot — which is right for following a figure and wrong for letting go of
            // something you have just displaced, where the eye expects the small elastic overrun
            // every phone has taught it to expect. Damping is eased down with the disturbance, so
            // the springiness appears exactly where a hand went through and nowhere else.
            const k = w * w, c = 2 * w * (1 - 0.42 * stirred);
            let vx = vel[j], vy = vel[j + 1], vz = vel[j + 2];
            let px = pos[j], py = pos[j + 1], pz = pos[j + 2];
            for (let s = 0; s < steps; s++) {
                vx += (-c * vx - k * (px - tx)) * h;
                vy += (-c * vy - k * (py - ty)) * h;
                vz += (-c * vz - k * (pz - tz)) * h;
                px += vx * h; py += vy * h; pz += vz * h;
            }
            vel[j] = vx; vel[j + 1] = vy; vel[j + 2] = vz;
            pos[j] = px; pos[j + 1] = py; pos[j + 2] = pz;
        }
    }

    /**
     * Drop the whole population at once.
     *
     * ⚠ Used ONLY when the engine starts. Nothing else in this system assigns a position — a figure
     * that needs a cloud somewhere else asks for it and lets the springs take it there, hurried by
     * `haste` if it must be quick. A cloud that is put somewhere has jumped, however briefly, and
     * three attempts at hiding that jump all ended up worse than the fault they were hiding.
     */
    place(centre, radius) {
        for (let i = 0; i < this.count; i++) {
            const j = i * 3;
            this.pos[j] = centre.x + this.shape[j] * radius;
            this.pos[j + 1] = centre.y + this.shape[j + 1] * radius;
            this.pos[j + 2] = centre.z + this.shape[j + 2] * radius * 0.85;
            this.vel[j] = this.vel[j + 1] = this.vel[j + 2] = 0;
        }
    }
}

function mulberry(seed) {
    let a = seed >>> 0;
    return () => {
        a = (a + 0x6D2B79F5) >>> 0;
        let t = Math.imul(a ^ (a >>> 15), 1 | a);
        t = (t + Math.imul(t ^ (t >>> 7), 61 | t)) ^ t;
        return ((t ^ (t >>> 14)) >>> 0) / 4294967296;
    };
}
