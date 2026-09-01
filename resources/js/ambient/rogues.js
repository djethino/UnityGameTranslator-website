/**
 * Les électrons libres — the blobs that stop taking part.
 *
 * A figure says where its five clouds should be. Every so often, one to three of them decline: they
 * go and circle another cloud, trail it about, drift over to whatever the pointer is doing, or
 * simply wander off past the edge of the frame and come back a few seconds later as if nothing had
 * happened. The figure is never told. It goes on producing targets for all five, and those targets
 * are quietly overridden for whoever has left.
 *
 * 🔴 Why it lives here and not in the patterns. Nineteen figures would each have to remember to
 * allow it, and the twentieth — written in six months — would forget. Worse, it would then be a
 * property of *some* figures, which is exactly the thing this is meant to destroy: the sense that
 * what you are watching is a list of behaviours being played back. Written once, above them all, it
 * applies to a figure nobody thought about while writing it.
 *
 * ── What an episode is ─────────────────────────────────────────────────────────────────────────
 * An **errand** (what they are doing), a handful of **members** (who is doing it), and a span. Two
 * episodes can run at once, which is what makes several clouds deviate *separately* — where a
 * single episode with three members makes them deviate *together*. Both read differently, and
 * having both is the point.
 *
 * ── The two rules it never breaks ──────────────────────────────────────────────────────────────
 * **At least two clouds stay in formation**, or the figure ceases to exist and the whole thing
 * reads as a fault rather than as mischief. And **every arrival and departure is eased**, over a
 * second or more, because the invariant this system is measured against — a cloud never jumps — has
 * to survive a cloud changing its mind.
 */

import { clamp, lerp, smoothstep, fromScreen, wander } from './patterns/util.js';

const TAU = Math.PI * 2;

/** Seconds between two considerations. Not a period: a coin is tossed at each one. */
const CONSIDER = [6, 14];
const CHANCE = 0.6;
/** ...and the chance of a SECOND episode while one is already out. Lower, so a crowd is rare. */
const CHANCE_AGAIN = 0.28;

const SPAN = [5.5, 13];
const EASE_IN = 1.2;
const EASE_OUT = 1.7;

/** With five clouds, three may leave. The figure has to keep existing. */
const STAY = 2;

/**
 * The errands.
 *
 * `wants` is how many it enlists, `needs` what the page has to be able to offer, and `place` where
 * a member should be — in field units, the same space the patterns work in, so this can be blended
 * against a pattern's own target without either knowing about the other.
 */
const ERRANDS = [
    {
        // Circles another cloud. The oldest and plainest: one body caught by another.
        id: 'orbite', wants: [1, 2],
        place(ep, m, ctx) {
            const host = ctx.bobs[ep.host];
            const a = ctx.time * m.rate + m.phase;
            return {
                x: host.x + Math.cos(a) * m.radius * ctx.aspect,
                y: host.y + Math.sin(a) * m.radius * 0.72,
                z: host.z + Math.sin(a * 0.7) * 0.16,
            };
        },
    },
    {
        // Two or three around ONE cloud, at their own radii and their own speeds: a little system,
        // and it does not stay in step, because nothing that stays in step looks alive.
        id: 'halo', wants: [2, 3],
        place(ep, m, ctx) {
            const host = ctx.bobs[ep.host];
            const a = ctx.time * m.rate + m.phase;
            return {
                x: host.x + Math.cos(a) * m.radius * ctx.aspect,
                y: host.y + Math.sin(a) * m.radius * (0.55 + m.tilt),
                z: host.z + Math.cos(a * 1.3) * 0.2,
            };
        },
    },
    {
        // Two that circle EACH OTHER, about a midpoint that is nobody's. They keep half a turn
        // apart, which is the only thing here that is exactly regular — a binary should be.
        id: 'duo', wants: [2, 2],
        place(ep, m, ctx) {
            const a = ctx.time * ep.rate + m.side * Math.PI;
            const c = ep.centre;
            return {
                x: c.x + Math.cos(a) * ep.radius * ctx.aspect,
                y: c.y + Math.sin(a) * ep.radius * 0.7,
                z: c.z + Math.sin(a) * 0.22,
            };
        },
    },
    {
        // Curious about another cloud: sits behind it, in its wake, and goes where it goes. Uses the
        // host's own velocity, so it follows the MOTION rather than the position — which is what
        // makes it read as following rather than as being attached.
        id: 'filature', wants: [1, 2],
        place(ep, m, ctx) {
            const host = ctx.bobs[ep.host];
            const s = Math.hypot(host.vx, host.vy, host.vz) || 1e-4;
            const back = m.radius * 1.5;
            return {
                x: host.x - (host.vx / s) * back + wander(ctx.time * 0.8 + m.phase, m.phase) * 0.08,
                y: host.y - (host.vy / s) * back + wander(ctx.time * 0.7 + m.phase * 2, m.phase) * 0.08,
                z: host.z - (host.vz / s) * back * 0.5,
            };
        },
    },
    {
        // Curious about the visitor. Hovers near the pointer rather than landing on it: something
        // that sits exactly under the cursor reads as a cursor decoration, not as an animal.
        id: 'curieux', wants: [1, 2], needs: 'pointer',
        place(ep, m, ctx) {
            const p = ctx.pointer;
            const a = ctx.time * m.rate * 0.6 + m.phase;
            const near = fromScreen(p.x, p.y, 1.25 + m.tilt, ctx.aspect);
            return {
                x: near.x + Math.cos(a) * m.radius * 0.9 * ctx.aspect,
                y: near.y + Math.sin(a) * m.radius * 0.7,
                z: near.z,
            };
        },
    },
    {
        // Circles something the visitor is actually reading. The one errand with a reason to be
        // where it is — and it uses the SAME scan the glitches use, so it can never point at a
        // screen they are forbidden to touch.
        id: 'ancre', wants: [1, 2], needs: 'anchor',
        place(ep, m, ctx) {
            const r = ep.rect();
            if (!r) return null;
            const a = ctx.time * m.rate * 0.85 + m.phase;
            const z = 1.55;
            return {
                x: (r.x + Math.cos(a) * (0.14 + m.radius * 0.2)) * z * ctx.aspect,
                y: (r.y + Math.sin(a) * (0.18 + m.radius * 0.2)) * z,
                z,
            };
        },
    },
    {
        // Leaves. Goes past the edge of the frame, spends a moment out there, and comes back.
        // 🔴 This is the one that answers "it should be able to spill out of the pattern": every
        // other errand keeps a cloud somewhere reasonable, and a formation whose members only ever
        // wander *within* it is still a formation.
        id: 'fugue', wants: [1, 2],
        place(ep, m, ctx) {
            const out = 1.15 + m.radius * 0.9;
            const a = m.phase + wander(ctx.time * 0.33 + m.phase, m.side) * 0.5;
            const z = 0.95 + m.tilt * 1.4;
            return {
                x: Math.cos(a) * out * z * ctx.aspect,
                y: Math.sin(a) * out * 0.85 * z,
                z,
            };
        },
    },
    {
        // Stops paying attention. Drifts on its own slow curve, at its own depth, going nowhere in
        // particular — the straggler at the back of the group.
        id: 'flanerie', wants: [1, 1],
        place(ep, m, ctx) {
            const t = ctx.time * 0.22 + m.phase;
            return {
                x: wander(t, m.phase) * 0.85 * ctx.aspect,
                y: wander(t * 0.8, m.phase + 3) * 0.55,
                z: 1.25 + wander(t * 0.6, m.phase + 7) * 0.45,
            };
        },
    },
];

export function createRogues({ bobs, pickAnchor }) {
    const episodes = [];
    let clock = 0;
    let wait = lerp(CONSIDER[0], CONSIDER[1], Math.random());

    const busy = () => episodes.reduce((n, e) => n + e.members.length, 0);

    /** Which clouds are free to be enlisted, given who is already out. */
    function available() {
        const taken = new Set();
        episodes.forEach((e) => e.members.forEach((m) => taken.add(m.bob)));
        const free = [];
        for (let i = 0; i < bobs.length; i++) if (!taken.has(i)) free.push(i);
        return free;
    }

    function begin(ctx) {
        const free = available();
        const room = bobs.length - STAY - busy();
        if (room < 1 || free.length < 2) return;   // one to leave, one left to be a host

        const usable = ERRANDS.filter((e) => {
            if (e.wants[0] > room) return false;
            if (e.needs === 'pointer' && !(ctx.pointer && ctx.pointer.active)) return false;
            if (e.needs === 'anchor' && !(pickAnchor && pickAnchor())) return false;
            return true;
        });
        if (!usable.length) return;

        const errand = usable[(Math.random() * usable.length) | 0];
        const howMany = Math.min(
            room, free.length - 1,
            errand.wants[0] + ((Math.random() * (errand.wants[1] - errand.wants[0] + 1)) | 0),
        );
        if (howMany < errand.wants[0]) return;

        // Shuffle the free list rather than picking indices at random: the same cloud must not be
        // the one that always leaves.
        for (let i = free.length - 1; i > 0; i--) {
            const j = (Math.random() * (i + 1)) | 0;
            [free[i], free[j]] = [free[j], free[i]];
        }

        const members = free.slice(0, howMany).map((bob, k) => ({
            bob,
            phase: Math.random() * TAU,
            rate: 0.75 + Math.random() * 0.95,
            radius: 0.3 + Math.random() * 0.34,
            tilt: Math.random() * 0.3,
            side: k % 2,
        }));

        const ep = {
            errand,
            members,
            age: 0,
            span: lerp(SPAN[0], SPAN[1], Math.random()),
        };

        // A host must not be one of the members, or a cloud ends up orbiting itself.
        if (errand.id === 'orbite' || errand.id === 'halo' || errand.id === 'filature') {
            const hosts = free.slice(howMany);
            if (!hosts.length) return;
            ep.host = hosts[(Math.random() * hosts.length) | 0];
        }
        if (errand.id === 'duo') {
            ep.rate = 0.8 + Math.random() * 0.9;
            ep.radius = 0.32 + Math.random() * 0.3;
            ep.centre = {
                x: (Math.random() - 0.5) * 1.1,
                y: (Math.random() - 0.5) * 0.7,
                z: 1.1 + Math.random() * 0.5,
            };
        }
        if (errand.id === 'ancre') {
            const el = pickAnchor();
            let cached = null;
            let stale = 0;
            // 🔴 Re-read often, but NOT every frame. `getBoundingClientRect` forces the browser to
            // flush layout there and then, so calling it once per member per frame makes the cost
            // of this errand depend on the size of the page's DOM rather than on anything in the
            // field — measured at roughly double the whole frame budget on the documentation page.
            //
            // ⚠ Nor can it be read once and kept: the visitor scrolls, and a rectangle taken at the
            // start sends the cloud to circle a place the text has since left. Every sixth frame is
            // ten times a second, which no eye can distinguish from continuous on an orbit this
            // slow, and the spring smooths what is left.
            ep.rect = () => {
                if (stale-- <= 0) {
                    stale = 6;
                    const r = el.getBoundingClientRect();
                    cached = (r.width === 0 || r.bottom < 0 || r.top > window.innerHeight) ? null : {
                        x: ((r.left + r.width / 2) / window.innerWidth) * 2 - 1,
                        y: ((r.top + r.height / 2) / window.innerHeight) * 2 - 1,
                    };
                }
                return cached;
            };
        }

        episodes.push(ep);
    }

    return {
        /** What is out there, for the record — nothing depends on it. */
        get current() {
            return episodes.map((e) => ({ errand: e.errand.id, bobs: e.members.map((m) => m.bob) }));
        },

        /**
         * Decide, then override. `allow` false — a figure whose whole point is a rigid formation —
         * starts nothing and retires whatever is out, over its ordinary ease-out rather than at
         * once.
         */
        steer(targets, ctx, allow) {
            clock += ctx.dt;

            if (allow && clock >= wait) {
                clock = 0;
                wait = lerp(CONSIDER[0], CONSIDER[1], Math.random());
                const odds = episodes.length ? CHANCE_AGAIN : CHANCE;
                if (Math.random() < odds) begin(ctx);
            }

            for (let e = episodes.length - 1; e >= 0; e--) {
                const ep = episodes[e];
                ep.age += ctx.dt;

                // Told to come home: bring the end forward to exactly one ease-out from now, once.
                if (!allow && ep.span - ep.age > EASE_OUT) ep.span = ep.age + EASE_OUT;

                if (ep.age >= ep.span) { episodes.splice(e, 1); continue; }

                const k = Math.min(
                    smoothstep(0, EASE_IN, ep.age),
                    smoothstep(ep.span, ep.span - EASE_OUT, ep.age),
                );

                for (const m of ep.members) {
                    const want = ep.errand.place(ep, m, ctx);
                    // An errand can withdraw mid-flight — the anchored text scrolled away. Let the
                    // cloud finish its journey home instead of dropping it where it stands.
                    if (!want) { ep.span = Math.min(ep.span, ep.age + EASE_OUT); continue; }
                    const t = targets[m.bob];
                    if (!t) continue;
                    targets[m.bob] = {
                        x: lerp(t.x, want.x, k),
                        y: lerp(t.y, want.y, k),
                        z: lerp(t.z, want.z, k),
                    };
                }
            }

            return targets;
        },
    };
}
