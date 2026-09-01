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

const TAU = Math.PI * 2;

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

        const rnd = mulberry(index * 7919 + 13);
        for (let i = 0; i < count; i++) {
            // 2.2 to 9.0 — better than a factor of four between the most and least eager point.
            // Narrow this and the cloud moves like a rigid object; widen it and it comes apart.
            this.omega[i] = 2.2 + rnd() * 6.8;
            this.phase[i] = rnd() * TAU;
            this.jitter[i] = 0.004 + rnd() * 0.012;
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
     * instead of presenting the same face for twelve seconds. `shearX`/`shearY` lean it into a turn.
     */
    update(centre, radius, dt, time, spin = 0, shearX = 0, shearY = 0, active = this.count) {
        const cs = Math.cos(spin), sn = Math.sin(spin);
        const { shape, pos, vel, omega, phase, jitter } = this;
        // `active` thins the population without paying to integrate points nobody will draw.
        const count = Math.min(active, this.count);

        for (let i = 0; i < count; i++) {
            const j = i * 3;

            const ox = shape[j], oy = shape[j + 1], oz = shape[j + 2];
            const sx0 = ox * cs - oy * sn;
            const sy0 = ox * sn + oy * cs;
            // Shear leans the whole population into a turn. Applied to the arrangement, not to the
            // points' positions, so the spring still smooths it and the cloud banks rather than
            // snapping.
            const rx = sx0 + sy0 * shearX;
            const ry = sy0 + sx0 * shearY;

            const w = omega[i];
            const jt = jitter[i];
            const ph = phase[i];

            // Target, plus a private shimmer. Added to the target rather than to the position so
            // the spring smooths it — added to the position it reads as noise, not as life.
            const tx = centre.x + rx * radius + Math.sin(time * 0.7 + ph) * jt;
            const ty = centre.y + ry * radius + Math.cos(time * 0.61 + ph * 1.3) * jt;
            const tz = centre.z + oz * radius * 0.85 + Math.sin(time * 0.53 + ph * 0.7) * jt;

            // Critically damped, semi-implicit. Stable for the dt the engine allows.
            const k = w * w, c = 2 * w;
            let vx = vel[j], vy = vel[j + 1], vz = vel[j + 2];
            vx += (-c * vx - k * (pos[j] - tx)) * dt;
            vy += (-c * vy - k * (pos[j + 1] - ty)) * dt;
            vz += (-c * vz - k * (pos[j + 2] - tz)) * dt;
            vel[j] = vx; vel[j + 1] = vy; vel[j + 2] = vz;
            pos[j] += vx * dt;
            pos[j + 1] += vy * dt;
            pos[j + 2] += vz * dt;
        }
    }

    /** Drop the whole population at once — only when the engine starts, or after a teleport. */
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
