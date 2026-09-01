/**
 * The conductor. What runs, when, and how one figure becomes the next.
 *
 * The sequence is fixed and was asked for: **two predefined patterns drawn at random, then one
 * intelligent one, then round again.** Everything else here exists to make sure the seams do not
 * show.
 *
 * ── Two things it does that no pattern is allowed to know about ────────────────────────────────
 *
 * **Cross-fading.** Two patterns run at once for a little over a second, and every quantity they
 * produce — positions, brightness, size, shear, the ink rate — is blended between them. A pattern
 * therefore never has to think about how it starts or stops; it can be written as if it owned the
 * screen for its whole duration.
 *
 * **The bob that goes off on its own.** Every so often one detaches, flies over to a word or an
 * image that is actually on screen, circles it, and comes back. It is not a pattern and no pattern
 * is told: the conductor simply overrides that one bob's target. Same reasoning as the humanizer —
 * a behaviour that every pattern would have to remember to allow is a behaviour that will be
 * forgotten by the next one written.
 */

import { PATTERNS, PREDEFINED, INTELLIGENT } from './patterns/index.js';
import { pointer, pointerEverSeen } from './pointer.js';
import { rngFrom, lerp, clamp, smoothstep } from './patterns/util.js';

const BLEND = 1.3;              // seconds of overlap between two patterns

const ROGUE_CHECK = 26;         // how often we consider sending one off
const ROGUE_CHANCE = 0.42;
const ROGUE_TIME = [7, 12];     // how long it stays away

const PROPS = ['gain', 'scale', 'shearX', 'shearY', 'yaw', 'twist', 'grip', 'haste'];

export function createConductor({ engine, pickAnchor, strings }) {
    const bobs = engine.bobs;
    const clouds = engine.clouds;

    let current = null;
    let previous = null;
    let blend = 1;              // 1 = the current pattern owns everything
    let queue = [];
    let lastId = null;

    let rogue = null;
    let rogueClock = 0;
    let scroll = 0;   // signed scroll velocity for this frame, for the patterns that ride it

    function instantiate(module, seed) {
        // A fresh object over the module, so `this.buf`, `this.glyph` and friends belong to THIS
        // run. Using the module directly would let two overlapping runs of the same pattern write
        // over each other's state — rare, and impossible to reproduce on purpose.
        const inst = Object.create(module);
        inst.rng = rngFrom(seed);
        inst.t = 0;
        inst.duration = lerp(module.duration[0], module.duration[1], inst.rng());
        return inst;
    }

    function pick(pool) {
        // ⚠ A pattern that reads the pointer is only drawn once a pointer has existed. On a phone
        // that has not been touched there is none, and such a pattern would run its fallback for
        // its whole length — a figure whose entire content is "nothing is happening".
        const feasible = (p) => (!p.needsPointer || pointerEverSeen()) && (!engine.reduced || p.calm);
        const usable = pool.filter((p) => feasible(p) && p.id !== lastId);
        const from = usable.length ? usable : pool.filter(feasible);
        return from.length ? from[(Math.random() * from.length) | 0] : pool[0];
    }

    function refill() {
        // ⚠ Under reduced motion the intelligent patterns are the camera charge and the chase, and
        // neither is calm by any reading. The sequence becomes predefined-only rather than
        // stopping — a still background is not restful, it just looks broken.
        const a = pick(PREDEFINED);
        lastId = a.id;
        const b = pick(PREDEFINED);
        lastId = b.id;
        queue = engine.reduced ? [a, b] : [a, b, pick(INTELLIGENT)];
    }

    function advance() {
        if (!queue.length) refill();

        for (let attempt = 0; attempt < 4; attempt++) {
            const module = queue.shift() || pick(PREDEFINED);
            const inst = instantiate(module, (Math.random() * 0xffffffff) | 0);

            // 🔴 Back to a ball before anyone speaks. A cloud's arrangement is shared state that
            // outlives the pattern which set it — leave a letter in place and the NEXT pattern
            // inherits it, moving a glyph around as if it were a blob. Resetting here also gives
            // the letter its exit for free: the springs carry every point from the glyph back to
            // the ball over about a second, so it crumbles instead of being cut.
            clouds.forEach((c) => c.ball());
            // `enter` returning false means the pattern cannot run right now — the trace with no
            // paintable character is the real case. Take the next one instead of running an empty
            // figure.
            if (!inst.enter || inst.enter(context(inst)) !== false) {
                previous = current;
                current = inst;
                lastId = module.id;
                blend = previous ? 0 : 1;
                return;
            }
            if (!queue.length) refill();
        }
        // Four refusals in a row would mean every pattern declined, which cannot happen unless the
        // register is empty. Keep whatever was running rather than leave nothing on screen.
    }

    function context(inst) {
        return {
            get t() { return inst.t; },
            get progress() { return clamp(inst.t / inst.duration); },
            dt: 0,
            bobs,
            clouds,
            pointer: pointer(),
            reduced: engine.reduced,
            rng: inst.rng,
            strings,
            scroll,
            radius: engine.radius,
            // Something visible worth reacting to — the SAME scan the glitches use, so a pattern
            // can never point at a screen the glitches are forbidden from touching.
            anchor: pickAnchor,
            setWarp: (v) => { inst._warp = v; },
            aspect: engine.aspect,
        };
    }

    /** Run one pattern and collect everything it decided, without letting it leak into the others. */
    function evaluate(inst, dt) {
        bobs.forEach((b) => b.resetFrameProps());
        inst._warp = 0;
        inst.t += dt;

        const ctx = context(inst);
        ctx.dt = dt;
        const targets = inst.update(ctx) || [];

        // ⚠ The field is one unit tall and `aspect` units wide, so a pattern working in -1…1 would
        // sit in a column down the middle of a widescreen display. Its x is spread to fill, EXCEPT
        // for patterns that declare themselves square — a letter stretched to 16:9 is not a letter.
        // Applied here, per pattern, so a cross-fade between a square one and a wide one is right.
        const spread = inst.square ? 1 : Math.min(engine.aspect, 2.2);
        if (spread !== 1) for (const t of targets) t.x *= spread;

        return {
            targets,
            warp: inst._warp,
            props: bobs.map((b) => PROPS.map((k) => b[k])),
        };
    }

    function orbit(dt, time) {
        rogueClock += dt;

        if (rogue) {
            rogue.left += dt;
            if (rogue.left > rogue.span) { rogue = null; return; }
            return;
        }

        if (rogueClock < ROGUE_CHECK) return;
        rogueClock = 0;
        if (engine.reduced || Math.random() > ROGUE_CHANCE) return;

        const anchor = pickAnchor && pickAnchor();
        if (!anchor) return;

        rogue = {
            bob: (Math.random() * bobs.length) | 0,
            anchor,
            left: 0,
            span: lerp(ROGUE_TIME[0], ROGUE_TIME[1], Math.random()),
            phase: Math.random() * Math.PI * 2,
        };
    }

    /** Where the rogue should be: circling its anchor, in normalized space at its own depth. */
    function rogueTarget(time) {
        const el = rogue.anchor;
        const rect = el.getBoundingClientRect();
        // It left the view while the reader scrolled. Nothing to circle any more — go home.
        if (rect.bottom < 0 || rect.top > window.innerHeight || rect.width === 0) { rogue = null; return null; }

        const fx = ((rect.left + rect.width / 2) / window.innerWidth) * 2 - 1;
        const fy = ((rect.top + rect.height / 2) / window.innerHeight) * 2 - 1;

        // Eased in and out, so it neither snaps away from the formation nor snaps back into it.
        const k = Math.min(smoothstep(0, 1.2, rogue.left), smoothstep(rogue.span, rogue.span - 1.4, rogue.left));
        const a = time * 1.15 + rogue.phase;
        const z = 1.55;

        // ⚠ Landing on a point of the SCREEN means undoing the projection: it divides x by the
        // depth and by the aspect ratio, so both have to be put back. Forget either and the orbit
        // circles empty space somewhere nearer the middle.
        return {
            k,
            x: (fx + Math.cos(a) * 0.16) * z * engine.aspect,
            y: (fy + Math.sin(a) * 0.20) * z,
            z,
        };
    }

    advance();

    /**
     * Put a named pattern on screen now, jumping the queue.
     *
     * Exposed as `window.ambient.play(id)`. It is a real control, not scaffolding: with nineteen
     * figures in rotation, waiting for the one you want to look at is minutes of sitting still, and
     * nobody tunes anything that way. It goes through `advance()` so the cross-fade, the ball reset
     * and a refusal from `enter()` all behave exactly as they do in the ordinary sequence.
     */
    const play = (id) => {
        const found = PATTERNS.find((p) => p.id === id);
        if (!found) return PATTERNS.map((p) => p.id);
        // ⚠ `current` is NOT cleared. It becomes the outgoing half of the cross-fade, exactly as
        // in the ordinary sequence — clearing it made the new figure appear with no transition at
        // all, which is a different thing from what the rotation does and would have hidden every
        // fault the transition is supposed to smooth.
        queue.unshift(found);
        advance();
        return current ? current.id : 'refused';
    };

    function frame({ dt, patternTime, scroll: velocity }) {
        scroll = velocity || 0;
        if (!current) advance();

        const now = evaluate(current, dt);
        let targets = now.targets;
        let props = now.props;
        let warp = now.warp;

        if (previous) {
            blend = Math.min(1, blend + dt / BLEND);
            const before = evaluate(previous, dt);
            const k = smoothstep(0, 1, blend);

            targets = now.targets.map((t, i) => {
                const o = before.targets[i] || t;
                return { x: lerp(o.x, t.x, k), y: lerp(o.y, t.y, k), z: lerp(o.z, t.z, k) };
            });
            props = now.props.map((p, i) => p.map((v, j) => lerp(before.props[i][j], v, k)));
            warp = lerp(before.warp, now.warp, k);

            if (blend >= 1) previous = null;
        }

        // Hand the blended decisions back to the bobs. Done here rather than inside `evaluate` so
        // that a pattern running mid-cross-fade never sees the other one's values.
        for (let i = 0; i < bobs.length; i++) {
            PROPS.forEach((key, j) => { bobs[i][key] = props[i][j]; });
        }

        orbit(dt, patternTime);
        if (rogue) {
            const r = rogueTarget(patternTime);
            if (r) {
                const t = targets[rogue.bob];
                targets[rogue.bob] = {
                    x: lerp(t.x, r.x, r.k),
                    y: lerp(t.y, r.y, r.k),
                    z: lerp(t.z, r.z, r.k),
                };
            }
        }

        engine.setWarp(warp);

        if (current.t >= current.duration && blend >= 1) advance();

        return targets;
    }

    // 🔴 defineProperties, not Object.assign.
    //
    // Assign INVOKES a getter on the source and stores the value it returned, so these would have
    // been frozen on the state of the very first hand-over — and they were. Every reading of what
    // was on screen came back as the same figure at progress zero, for six simulated minutes of
    // rotation, while the field was plainly moving.
    //
    // ⚠ It cost three rounds of chasing the wrong cause. A diagnostic that lies is worse than none:
    // without it you know you do not know, with it you attribute every fault you find to whatever
    // it happens to be saying.
    return Object.defineProperties(frame, {
        play: { value: play },
        playing: { get() { return current && current.id; } },
        /** Who is on screen, who is leaving, and how far each is through its own timeline.
         *  A fault during a hand-over cannot be attributed without all four. */
        phase: {
            get() {
                return {
                    current: current && current.id,
                    currentAt: current ? +(current.t / current.duration).toFixed(3) : null,
                    previous: previous && previous.id,
                    previousAt: previous ? +(previous.t / previous.duration).toFixed(3) : null,
                    blend: +blend.toFixed(3),
                };
            },
        },
    });
}
