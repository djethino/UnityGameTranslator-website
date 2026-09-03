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
import { optedIn, onMotionChange } from './motion.js';
import { createRogues } from './rogues.js';
import { rngFrom, lerp, clamp, smoothstep, easeInOut } from './patterns/util.js';

const PROPS = ['gain', 'scale', 'shearX', 'shearY', 'yaw', 'twist', 'grip', 'haste', 'sway', 'charm', 'roll'];

/**
 * 🔴 Which of those are ANGLES, and why they cannot be cross-faded like the rest.
 *
 * A figure that keeps turning writes an angle that keeps growing: the corridor's twist is tied to
 * the distance travelled, so twenty seconds in it is about **54 radians — eight and a half full
 * turns**. Every other property is a magnitude, and fading 54 to 0 over a second is a magnitude
 * moving; for an angle it is eight and a half revolutions in 1.3 seconds, which is what was seen —
 * the clouds spinning on themselves and then snapping.
 *
 * ⚠ And nothing about it looked wrong in the code. `twist` reads like any other number in `PROPS`,
 * and it is only wrong because 54 and 0 name the SAME orientation to within a fraction of a turn.
 * Interpolated along the shortest arc, the handover turns by at most half a revolution, which is
 * the true difference between the two figures rather than an artefact of how one of them counts.
 */
const ANGLES = new Set(['yaw', 'twist']);
const TAU = Math.PI * 2;

/** Interpolate two angles the short way round, whatever multiples of a turn they carry. */
function arc(a, b, k) {
    let d = (b - a) % TAU;
    if (d > Math.PI) d -= TAU;
    else if (d < -Math.PI) d += TAU;
    return a + d * k;
}

/**
 * How one figure becomes the next.
 *
 * ⚠ There used to be one answer — a 1.3 s cross-fade — and it was invisible in the good sense and
 * deadening in the bad one: every change felt the same, which told you there was a list being
 * played. These are the same mechanism with three dials, so none of them can break the invariant
 * the plain fade satisfies.
 *
 * | dial | what it does |
 * |---|---|
 * | `stagger` | the share of the window spent offsetting the clouds, so they change over one after another rather than at once |
 * | `swell` | brightness and size rise and fall across the handover — a breath between two figures |
 * | `spill` | the clouds are pushed outward mid-way and converge on the new figure from outside it |
 *
 * 🔴 `swell` and `spill` ride a `sin(π·raw)` bump: exactly zero at both ends, so a handover can
 * never leave anything behind it, however it is tuned.
 */
const HANDOVERS = [
    // ⚠ `calm` carries the same meaning as it does on a figure, because the deck below applies the
    // same test to both: under reduced motion only the calm ones are dealt. A handover left without
    // it would be silently excluded there, which is the sort of omission that shows up as "the
    // transitions all look the same on my machine" and never as an error.
    { id: 'fondu', seconds: 1.3, stagger: 0, calm: true, curve: (k) => smoothstep(0, 1, k) },
    { id: 'souple', seconds: 2.7, stagger: 0.15, calm: true, curve: (k) => smoothstep(0, 1, k) },
    { id: 'cascade', seconds: 2.3, stagger: 0.6, calm: true, curve: (k) => smoothstep(0, 1, k) },
    { id: 'bascule', seconds: 0.7, stagger: 0, calm: false, curve: easeInOut },
    { id: 'souffle', seconds: 1.9, stagger: 0.2, calm: false, curve: (k) => smoothstep(0, 1, k), swell: 0.55 },
    { id: 'essaim', seconds: 2.1, stagger: 0.4, calm: false, curve: (k) => smoothstep(0, 1, k), spill: 0.55 },
];

export function createConductor({ engine, pickAnchor, strings }) {
    const bobs = engine.bobs;
    const clouds = engine.clouds;

    let current = null;
    let previous = null;
    let blend = 1;              // 1 = the current pattern owns everything
    let hand = HANDOVERS[0];    // how THIS handover is being played
    let queue = [];
    let lastId = null;
    let scroll = 0;   // signed scroll velocity for this frame, for the patterns that ride it

    const rogues = createRogues({ bobs, pickAnchor });

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

    // ⚠ A pattern that reads the pointer is only drawn once a pointer has existed. On a phone
    // that has not been touched there is none, and such a pattern would run its fallback for
    // its whole length — a figure whose entire content is "nothing is happening".
    // ⚠ `optIn` names the setting a figure needs, rather than the conductor naming the figure. A
    // list of ids here would be a second place to remember, and the one that gets forgotten.
    // ⚠ Named apart from the other two conditions because it behaves differently in time: those are
    // settled once per deal, this one has to bite the moment it changes. See the subscription below.
    const allowed = (p) => !p.optIn || optedIn(p.optIn);

    const feasible = (p) => (!p.needsPointer || pointerEverSeen())
        && (!engine.reduced || p.calm)
        && allowed(p);

    /**
     * Draw without replacement: a shuffled deck, dealt out, reshuffled when empty.
     *
     * 🔴 Fair BY CONSTRUCTION, which is the only kind of fair worth having here. Picking an index at
     * random is uniform in the long run and says nothing at all about the short one — and the short
     * run is the whole of a visit. Somebody who reads a page for four minutes sees perhaps a dozen
     * figures out of nineteen, and independent draws will hand them the same one three times while
     * five others never appear. A deck cannot: every figure is dealt exactly once before any is
     * dealt twice.
     *
     * ⚠ Feasibility is settled when the deck is refilled, not per draw. So a pointer appearing
     * mid-deck brings its figures in at the next reshuffle rather than immediately — a few figures'
     * delay, in exchange for the deal staying uniform over the whole set instead of over whichever
     * subset happened to be usable at the instant of each draw.
     */
    function dealer(pool) {
        let deck = [];
        const draw = function (avoid) {
            if (!deck.length) {
                deck = pool.filter(feasible);
                // ⚠ Never stall for want of a candidate — but the escape hatch keeps the opt-in.
                // The other two conditions are preferences this deliberately overrides (a still
                // background is not restful, it looks broken); an opt-in is a REFUSAL, and handing
                // somebody the one figure they turned off, as a fallback, is the worst moment to do
                // it. Unreachable while nineteen figures survive the filter; written because the
                // day it is reachable, nothing would say so.
                if (!deck.length) deck = pool.filter(allowed);
                for (let i = deck.length - 1; i > 0; i--) {
                    const j = (Math.random() * (i + 1)) | 0;
                    [deck[i], deck[j]] = [deck[j], deck[i]];
                }
            }
            // Take the one below the top when the top would repeat what just ran. A swap inside the
            // deck, not a reject: the figure keeps its place in this deal, so the guarantee holds.
            let i = deck.length - 1;
            if (deck.length > 1 && deck[i].id === avoid) i -= 1;
            return deck.splice(i, 1)[0];
        };
        // Drop cards the visitor has just refused, WITHOUT reshuffling. The guarantee this deck
        // exists for is about the order of what remains — removing a card nobody may be shown does
        // not disturb it, where a reshuffle would deal some figures twice before others once.
        draw.refuse = () => { deck = deck.filter(allowed); };
        return draw;
    }

    const drawPredefined = dealer(PREDEFINED);
    const drawIntelligent = dealer(INTELLIGENT);
    const drawHandover = dealer(HANDOVERS);

    /**
     * 🔴 A refusal takes effect NOW. The other two conditions in `feasible` are settled at refill on
     * purpose; this one cannot be, and the asymmetry is the point:
     *
     *   turning a figure ON is a **permission** — it may arrive in its own time, and the settings
     *   card carries a Test button for whoever will not wait;
     *   turning it OFF is a **refusal**, and a refusal that lands a minute later is indistinguishable
     *   from a control that does not work.
     *
     * ⚠ Reported exactly that way — "enabling is taken into account, disabling isn't" — because the
     * deck in hand had been filled while the figure was still allowed, so it went on being dealt.
     *
     * Three places can hold a figure that has just been refused, and missing any one of them lets it
     * turn up after somebody said no: the decks already dealt from, the queue built out of them, and
     * the one on screen.
     */
    onMotionChange(() => {
        drawPredefined.refuse();
        drawIntelligent.refuse();
        queue = queue.filter(allowed);
        // ⚠ Through `advance()` rather than a cut: the refused figure is crossfaded out the way any
        // figure is, the balls are reset and a handover is dealt. `play()` already changes figure
        // mid-run by this exact route, so there is no second path here.
        // `current` is `Object.create(module)`, so it inherits `optIn` — no need to track the module.
        if (current && !allowed(current)) advance();
    });

    function refill() {
        // ⚠ Under reduced motion the intelligent patterns are the camera charge and the chase, and
        // neither is calm by any reading. The sequence becomes predefined-only rather than
        // stopping — a still background is not restful, it just looks broken.
        const a = drawPredefined(lastId);
        const b = drawPredefined(a.id);
        queue = engine.reduced ? [a, b] : [a, b, drawIntelligent(b.id)];
    }

    function advance() {
        if (!queue.length) refill();

        for (let attempt = 0; attempt < 4; attempt++) {
            const module = queue.shift() || drawPredefined(lastId);
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
                // ⚠ Chosen against the INCOMING figure. A corridor born out of a scatter is a
                // corridor whose first second denies what it is; the rigid ones are handed over
                // plainly, and every other pairing draws from the deck like the figures do.
                hand = module.rigid ? HANDOVERS[0] : drawHandover(hand.id);
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
            // How hard the ride is running, 0 to 1 — the light pass smears with it. Same road as
            // `setWarp`: declared by the figure, blended across the handover, handed to the engine.
            setRush: (v) => { inst._rush = v; },
            aspect: engine.aspect,
        };
    }

    /** Run one pattern and collect everything it decided, without letting it leak into the others. */
    function evaluate(inst, dt) {
        bobs.forEach((b) => b.resetFrameProps());
        inst._warp = 0;
        inst._rush = 0;
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
            rush: inst._rush,
            props: bobs.map((b) => PROPS.map((k) => b[k])),
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
        let rush = now.rush;

        if (previous) {
            blend = Math.min(1, blend + dt / hand.seconds);
            const before = evaluate(previous, dt);
            const n = bobs.length;

            // Each cloud on its own clock. With `stagger` at 0 they all change together, which is
            // the plain fade; at 0.6 the last one starts when the first is already there.
            const share = hand.stagger / Math.max(1, n - 1);
            const kOf = (i) => hand.curve(clamp((blend - i * share) / (1 - hand.stagger)));

            // Zero at both ends by construction, so whatever these do cannot outlive the handover.
            const bump = Math.sin(Math.PI * blend);
            const spill = 1 + (hand.spill || 0) * bump;
            const swell = (hand.swell || 0) * bump;

            targets = now.targets.map((t, i) => {
                const o = before.targets[i] || t;
                const k = kOf(i);
                return {
                    x: lerp(o.x, t.x, k) * spill,
                    y: lerp(o.y, t.y, k) * spill,
                    z: lerp(o.z, t.z, k),
                };
            });
            props = now.props.map((p, i) => {
                const k = kOf(i);
                return p.map((v, j) => {
                    const blended = ANGLES.has(PROPS[j])
                        ? arc(before.props[i][j], v, k)
                        : lerp(before.props[i][j], v, k);
                    if (!swell) return blended;
                    if (PROPS[j] === 'gain') return blended * (1 + swell * 0.55);
                    if (PROPS[j] === 'scale') return blended * (1 + swell * 0.3);
                    return blended;
                });
            });
            warp = lerp(before.warp, now.warp, hand.curve(blend));
            rush = lerp(before.rush, now.rush, hand.curve(blend));

            if (blend >= 1) previous = null;
        }

        // Hand the blended decisions back to the bobs. Done here rather than inside `evaluate` so
        // that a pattern running mid-cross-fade never sees the other one's values.
        //
        // 🔴 Except the stiffness, which is capped while a handover runs.
        //
        // Everything about a change of figure is smoothed except one thing: the ARRANGEMENT is
        // replaced outright, at the instant `advance` runs. That is deliberate and it works,
        // because the springs then carry each point from the old shape to the new one over about a
        // second. It stops working when the outgoing figure was holding its points rigid: the
        // corridor runs a grip of 45, so on the first frame after the switch each point is offered
        // a target a whole unit away and a spring stiff enough to cross it in one step. Measured at
        // 0.47 clip units of travel in a single frame — a jump, and reported as one.
        //
        // ⚠ The cap lifts as the blend completes, so a figure that needs rigidity has it a moment
        // later, and the only thing given up is a fraction of a second of firmness on rings that
        // are leaving the frame anyway.
        // ⚠ The floor is 1.2, not 5, and the difference is arithmetic rather than taste: a point's
        // stiffness is `omega · grip` with omega up to 9, so a cap of 5 still allows 45 — and a
        // spring that stiff crosses half a unit in a single 16 ms step, which is exactly the jump
        // being fixed. At 1.2 the eagerest point moves about three hundredths of a screen in that
        // frame. Squared, so the cap stays low through the beginning of the handover, which is the
        // part where the two arrangements are furthest apart.
        const cap = previous ? lerp(1.2, 60, blend * blend) : 60;
        for (let i = 0; i < bobs.length; i++) {
            PROPS.forEach((key, j) => {
                const v = props[i][j];
                bobs[i][key] = (key === 'grip' || key === 'haste') ? Math.min(v, cap) : v;
            });
        }

        // 🔴 After the cross-fade, and after the pattern's own spread. What a cloud that has left
        // the figure is doing has nothing to do with what the figure would have asked of it, so it
        // must not be blended with the outgoing figure's idea of the same cloud.
        //
        // ⚠ `rigid` figures are handed `false`: their whole content is a formation, and a member
        // wandering off does not read as a cloud with a mind of its own, it reads as the corridor
        // being broken. Whoever is already out comes home over the ordinary ease rather than being
        // dropped.
        targets = rogues.steer(targets, {
            dt, time: patternTime, bobs, aspect: engine.aspect, pointer: pointer(),
        }, !engine.reduced && !current.rigid);

        engine.setWarp(warp);
        engine.setRush(rush);

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
                    handover: hand.id,
                    rogues: rogues.current,
                };
            },
        },
    });
}
