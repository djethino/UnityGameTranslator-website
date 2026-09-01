/**
 * One bob, and its personality.
 *
 * A pattern says where the five bobs SHOULD be. It never says where they are. Everything between
 * the two lives here, and that gap is the whole reason the thing looks alive rather than plotted.
 *
 * 🔴 The humanizer sits ABOVE the patterns, never inside them — the same arrangement as a groove in
 * music, where the feel is a post-process and not a property of the score. A pattern that had to
 * remember to be imperfect would eventually forget; here no pattern *can* come out too clean, and a
 * pattern written next year inherits the personality without knowing it exists.
 *
 * Each bob draws its personality once, at boot: how hard it pulls toward a target, whether it
 * overshoots, and a slow private drift that never quite lets it settle.
 */

const PHI = 1.6180339887;
const SQRT2 = 1.4142135624;
const SQRT3 = 1.7320508076;

/**
 * Depth range. `z` is a divisor, so small is near.
 *
 * ⚠ Perspective is real here: at z = 0.35 a bob only one third of the way off-axis is already off
 * the screen. That is not a bug to clamp away — it is what makes the camera charge read as a charge
 * — but a pattern that wants its bobs visible up close has to keep x and y small.
 *
 * 🔴 `Z_NEAR` is a NUMERICAL FLOOR, not an artistic near plane, and it was the second for a long
 * time. At 0.35 it silently capped every figure that wanted to come closer: `tunnel` asked for 0.36
 * and got one hundredth of clearance, so its rings turned back at the exact instant they finished
 * clearing the corners — the ring "changing direction before you had passed it" was this line, not
 * the figure. A pattern that wants to stop further out says so itself; the floor only exists so
 * that 1/z stays a number.
 */
export const Z_NEAR = 0.10;
export const Z_FAR = 2.5;

/** Critically damped spring, semi-implicit. Stable for the dt the engine allows (capped at 100 ms). */
function spring(pos, vel, target, omega, dt) {
    const v = vel + (-2 * omega * vel - omega * omega * (pos - target)) * dt;
    return [pos + v * dt, v];
}

export class Bob {
    constructor(index) {
        this.index = index;

        // Position and velocity in normalized space. Velocity is kept per axis because the spring
        // needs it; the ink layer reads screen-space speed instead, which is a different quantity
        // (a bob charging the camera moves fast in z and barely at all on screen).
        this.x = 0; this.y = 0; this.z = 1;
        this.vx = 0; this.vy = 0; this.vz = 0;

        // Where it was drawn last frame, in device pixels of the render buffer. Set by the engine.
        this.sx = 0; this.sy = 0; this.screenSpeed = 0;

        // ---- what a pattern may influence, and only for the frame it asks ----
        //
        // 🔴 These are cleared by `resetFrameProps()` before every pattern update. A pattern that
        // stops asking must stop getting, or the first pattern to brighten a bob would leave it
        // brightened for the rest of the session — and the bug would look like it belonged to
        // whichever pattern happened to be running when someone noticed.
        this.gain = 1;          // extra brightness (the storm's incandescent core)
        this.scale = 1;         // the cloud contracts or swells, independent of depth
        this.shearX = 0;        // the camera charge's fake fisheye
        this.shearY = 0;
        this.yaw = 0;           // rotation about the vertical axis — a globe, a tunnel
        this.twist = 0;         // extra rotation in the plane of the screen, on top of the engine's
        this.grip = 1;          // how tightly the points hold their places — 1 is loose and organic
        this.haste = 1;         // how eagerly the CENTRE reaches its target — for a fast return
        this.sway = 1;          // how much of its private drift it keeps — 0 for rigid structures

        this.resetFrameProps();

        // ---- personality, drawn once ----
        // Spread deliberately wide. Bobs that respond at nearly the same rate look like one object
        // with five parts; bobs that respond at clearly different rates look like five characters.
        const r = (n) => Math.sin(index * 12.9898 + n * 78.233) * 43758.5453 % 1;
        const u = (n) => Math.abs(r(n));

        this.omega = 3.2 + u(1) * 3.4;        // 3.2 – 6.6 : eagerness to reach a target
        this.zeal = 0.85 + u(2) * 0.32;       // 0.85 – 1.17 : undershoots or overshoots
        this.wobbleAmp = 0.020 + u(3) * 0.045;
        this.wobbleZ = 0.05 + u(4) * 0.10;
        this.phase = [u(5) * 6.28, u(6) * 6.28, u(7) * 6.28];

        // Same irrational frequency ratios (φ, √2, √3) the old background used, and for the same
        // reason: the private drift never closes into a loop, so no bob ever repeats a path the eye
        // could learn. Kept identical on purpose — it is the one thing about the old motion that
        // was worth carrying over untouched.
        this.freq = [0.31 * PHI, 0.23 * SQRT2, 0.19 * SQRT3];
    }

    /**
     * Move toward `target` for `dt` seconds. `t` is the engine's global time, which already carries
     * the scroll coupling — so the private drift speeds up when the reader scrolls, exactly like
     * everything else in this universe.
     */
    follow(target, t, dt) {
        // ⚠ `sway` scales the private drift rather than the position, because the drift is what
        // makes a bob a character instead of a coordinate — and exactly what a rigid structure must
        // not have. In a corridor it is not charm, it is each ring stopping at a different depth:
        // `wobbleZ` reaches 0.15, which is half the clearance the near end has to play with, so the
        // ring that drew the short straw turns back while still on screen.
        const w = this.wobbleAmp * this.sway;
        const dx = Math.sin(t * this.freq[0] + this.phase[0]) * w;
        const dy = Math.cos(t * this.freq[1] + this.phase[1]) * w;
        const dz = Math.sin(t * this.freq[2] + this.phase[2]) * this.wobbleZ * this.sway;

        // The drift is added to the TARGET, not to the position: the spring smooths it, so the bob
        // wanders rather than vibrates. Added to the position it would read as noise.
        // 🔴 `haste` is what lets a figure bring a cloud back QUICKLY instead of moving it
        // instantly. Those are not the same thing and the difference is the whole point: a fast
        // return is something you watch happen, a teleport is something that has happened. The
        // second one is a jump however carefully it is hidden.
        const omega = this.omega * this.zeal * this.haste;

        // 🔴 Substepped, and this is what makes a stiff spring usable at all. The integrator is
        // explicit in the stiffness term, so it diverges once `omega · dt` passes about 2 — and the
        // engine hands out a dt of up to 100 ms after a stall. A figure asking for a rigid centre
        // (a corridor: haste in the teens) would therefore be stable at 60 fps and blow up to NaN
        // on the first hitch, taking the whole field with it. One divide and up to eight iterations
        // over five bobs is not a cost worth trading a class of crash for.
        const steps = Math.min(8, Math.ceil((omega * dt) / 0.35) || 1);
        const h = dt / steps;
        // ⚠ And a ceiling even so: eight substeps of a 100 ms frame are 12.5 ms each, which is still
        // not enough for a spring in the teens. Past that the point is as eager as the step can
        // represent, which is the honest answer — `omega · dt` above 1 is not a stiffer spring, it
        // is a different and divergent one.
        const stiff = Math.min(omega, 0.7 / h);
        const tx = target.x + dx, ty = target.y + dy, tz = clampZ(target.z + dz);
        for (let s = 0; s < steps; s++) {
            [this.x, this.vx] = spring(this.x, this.vx, tx, stiff, h);
            [this.y, this.vy] = spring(this.y, this.vy, ty, stiff, h);
            [this.z, this.vz] = spring(this.z, this.vz, tz, stiff, h);
        }

        this.z = clampZ(this.z);
    }

    /** Back to neutral. Called once per frame, before the running pattern gets to speak. */
    resetFrameProps() {
        this.gain = 1;
        this.scale = 1;
        this.shearX = 0;
        this.shearY = 0;
        this.yaw = 0;
        this.twist = 0;
        this.grip = 1;
        this.haste = 1;
        this.sway = 1;
    }

    /** Drop it somewhere with no travel — used when the engine (re)starts, never mid-flight. */
    place(x, y, z) {
        this.x = x; this.y = y; this.z = clampZ(z);
        this.vx = this.vy = this.vz = 0;
    }
}

function clampZ(z) {
    return z < Z_NEAR ? Z_NEAR : z > Z_FAR ? Z_FAR : z;
}
