/**
 * The page gives a little at the top and the bottom, and springs back.
 *
 * ── Why this is written at all ─────────────────────────────────────────────────────────────────
 * Safari and iOS have done this since 2007 and nobody had to write it. Firefox and Chrome on
 * Windows and Linux do not: reaching the end of a page there is an abrupt stop with no
 * acknowledgement that anything happened. That is where this runs, and only there.
 *
 * ── What moves, and what deliberately does not ─────────────────────────────────────────────────
 * 🔴 Body's own children are moved, EXCEPT the ones already pinned to the viewport. That one line
 * is what keeps the whole thing out of trouble:
 *
 *   - `.ambient-canvas` is `position: fixed` and body's first child, so it is skipped and the moving
 *     field stays where it is — a wallpaper the page slides over, which is what iOS does too;
 *   - the grain is `.animated-bg::after`, a pseudo-element of BODY. Transforming body would have
 *     made it the containing block for its own fixed pseudo-element, stretching a viewport-sized
 *     tile over the full document height. Moving body's children instead leaves it alone;
 *   - and nothing in the markup changes. An earlier plan wrapped nav, main and footer in a div to
 *     transform as one, which meant moving `min-h-screen flex flex-col` onto the wrapper and hoping
 *     the footer still sat where it does.
 *
 * ⚠ A transformed element becomes the containing block for any `position: fixed` DESCENDANT, which
 * would tear a modal or the editor's full-screen grid off the viewport. Two answers, both here:
 * `hasPinned()` refuses to bounce while such an element is on screen, and the transform is removed
 * entirely at rest, so outside of the three hundred milliseconds of a bounce there is nothing to
 * interfere with at all.
 */

import { systemAsksReduced, onMotionChange } from './ambient/motion.js';

/**
 * How far the page can be pulled past its end, however hard you push.
 *
 * ⚠ Small on purpose. What makes this gesture good on the platforms that have it is that it is
 * barely there — an edge that gives, not a page that opens. In practice the ceiling is rarely
 * approached at all: the spring never stops pulling, so wheeling steadily settles at an equilibrium
 * between push and return, around nine pixels rather than at the limit.
 */
const MAX_PULL = 26;

/** What one pixel of would-be scroll is worth. One notch is 48px in Firefox and 100 in Chrome —
 *  their own idea of a notch, so a gentler scroll setting gets a gentler edge, which is right. */
const GAIN = 0.18;

/**
 * Spring stiffness, critically damped so it arrives without wobbling — a wobble reads as a bug, a
 * single soft return reads as a material. ω = 30 closes in about 190 ms.
 *
 * ⚠ Past what a single step can integrate: this is semi-implicit and needs ω·dt < 1, which 30 would
 * sit exactly on at 30 Hz. It is substepped instead, the same answer `bob.js` reached for the same
 * reason — the alternative is a softer spring, and softer is the thing being fixed.
 */
const OMEGA = 30;

/** Largest ω·h a substep may carry. Well under the stability limit, so a stalled tab coming back
 *  cannot hand the spring a step it cannot solve. */
const MAX_STEP = 0.35;

/** Below this the spring has arrived; anything smaller is a sub-pixel the eye cannot see. */
const SETTLED = 0.2;

/**
 * 🔴 One state, and it is the PIXELS.
 *
 * Two earlier shapes were wrong and the simulation said so before the browser could. Holding both an
 * accumulated push and a position let the spring pull one to zero while the other still held the
 * page open, so pushing again mid-return snapped it shut and reopened it. Keeping only the push and
 * deriving the pixels fixed that and broke the feel instead: the spring then worked in a space where
 * a hard flick is worth thousands, so the page hung at full stretch while the number came down, and
 * took most of a second to close.
 *
 * The resistance lives in the PUSH instead — each notch is worth less the further out you already
 * are — so the spring can act directly on what the eye is watching.
 */
let y = 0;              // pixels past the edge
let velocity = 0;
let movers = [];
let frame = 0;
let last = 0;
let enabled = false;

const doc = () => document.scrollingElement || document.documentElement;

/**
 * What a push is worth from where the page already sits.
 *
 * Squared, not linear: the give falls away quickly near the end of the travel, so the edge firms up
 * under the hand instead of arriving at a second wall. Pushing for ever approaches MAX_PULL and
 * never reaches it.
 */
function give() {
    const left = 1 - Math.abs(y) / MAX_PULL;
    return left > 0 ? left * left : 0;
}

/**
 * Is anything pinned to the viewport right now?
 *
 * ⚠ Not a list of components to keep up to date — `fixed` is the class Tailwind requires for any
 * element positioned that way, so this finds them by the mechanism rather than by name. Visibility
 * is checked too: the admin's ban modal carries `fixed` at all times and `hidden` until it opens.
 */
function hasPinned() {
    for (const el of document.body.querySelectorAll('.fixed')) {
        if (el.getClientRects().length) return true;
    }
    return false;
}

/**
 * Is something under the cursor still going to eat this scroll?
 *
 * ⚠ The listener is on `window`, so it hears every wheel on the page including those over an open
 * language list or a scrollable panel. Without this, reaching the end of a dropdown would bounce the
 * PAGE behind it — the document is at its end, after all, and nothing in the event says the gesture
 * was already spoken for. Walked only when we are at a document edge, which is rare.
 */
function consumedInside(target, dy) {
    for (let el = target instanceof Element ? target : null;
        el && el !== document.body; el = el.parentElement) {
        const overflow = getComputedStyle(el).overflowY;
        if (overflow !== 'auto' && overflow !== 'scroll') continue;
        const room = dy > 0
            ? el.scrollHeight - el.clientHeight - el.scrollTop > 1
            : el.scrollTop > 1;
        if (room) return true;
    }
    return false;
}

function apply() {
    const t = y ? `translate3d(0, ${y.toFixed(2)}px, 0)` : '';
    for (const el of movers) el.style.transform = t;
}

function stop() {
    velocity = 0;
    y = 0;
    apply();
    // Handed back completely: no transform means no containing block, so a modal opened a moment
    // later is positioned against the viewport exactly as it would have been.
    for (const el of movers) el.style.willChange = '';
    movers = [];
    frame = 0;
}

function tick(now) {
    frame = requestAnimationFrame(tick);

    // 🔴 No waiting for the wheel to stop. An earlier version held the page open for ninety
    // milliseconds after the last notch, on the idea that a gesture has an end to wait for. A finger
    // does; a wheel does not — the end is the end, and the delay only made the page look stuck at
    // the very moment it should have been coming back.
    //
    // So the spring runs from the first frame and never stops. Steady wheeling is then a series of
    // taps against something that is already pulling back, which settles at an equilibrium instead
    // of stacking up — the behaviour of an elastic edge, and it needs no rule of its own.
    const dt = Math.min((now - last) / 1000, 1 / 30);
    last = now;

    // Critically damped, semi-implicit, substepped: velocity first, then position.
    const steps = Math.min(8, Math.ceil((OMEGA * dt) / MAX_STEP) || 1);
    const h = dt / steps;
    for (let i = 0; i < steps; i++) {
        velocity += (-OMEGA * OMEGA * y - 2 * OMEGA * velocity) * h;
        y += velocity * h;
    }

    if (Math.abs(y) < SETTLED && Math.abs(velocity) < SETTLED * OMEGA) {
        cancelAnimationFrame(frame);
        stop();
        return;
    }

    apply();
}

function begin() {
    if (frame) return true;
    if (hasPinned()) return false;

    // Everything body holds, minus whatever is already pinned — the ambient canvas above all, which
    // must not travel with the page.
    movers = [...document.body.children]
        .filter((el) => el instanceof HTMLElement && getComputedStyle(el).position !== 'fixed');
    if (!movers.length) return false;

    for (const el of movers) el.style.willChange = 'transform';
    last = performance.now();
    frame = requestAnimationFrame(tick);
    return true;
}

function onWheel(event) {
    if (!enabled || event.ctrlKey) return;    // ctrl+wheel is a zoom, not a scroll

    const el = doc();
    // A page with nothing to scroll has no end to arrive at, and bouncing it on every wheel tick
    // would make short pages feel loose rather than lively.
    if (el.scrollHeight <= el.clientHeight + 1) return;

    const dy = event.deltaY;
    const atTop = el.scrollTop <= 0 && dy < 0;
    const atEnd = el.scrollTop + el.clientHeight >= el.scrollHeight - 1 && dy > 0;

    // Back inside the page. Nothing to do: the spring is already pulling the edge home.
    if (!atTop && !atEnd) return;

    if (consumedInside(event.target, dy)) return;
    if (!begin()) return;

    // ⚠ Deltas arrive in three units, and the magnitude matters in all three. An earlier version
    // read `Math.sign(dy) * 16` for lines, which threw the amount away — Firefox reports three lines
    // per notch, so its edge gave a third of what it should have while Chrome's gave all of it.
    const px = event.deltaMode === 1 ? 16 : event.deltaMode === 2 ? el.clientHeight : 1;
    y -= dy * px * GAIN * give();
    apply();
}

/**
 * 🔴 Where this does NOT run, and each is a refusal rather than an omission.
 *
 *   - `prefers-reduced-motion`: unrequested movement of the whole page is exactly what that setting
 *     is about, and for some people it is nausea rather than taste;
 *   - Apple platforms: the real thing is already there, built into the compositor, and a second
 *     bounce on top of it would fight the first;
 *   - touch: the platform owns the gesture. iOS bounces, Android shows its glow and offers
 *     pull-to-refresh, and taking that over would mean cancelling a feature people use to gain an
 *     ornament.
 */
function allowed() {
    if (systemAsksReduced()) return false;
    if (matchMedia('(pointer: coarse)').matches) return false;
    return !/Mac|iPhone|iPad|iPod/.test(navigator.platform || '');
}

export function startRubberBand() {
    const settle = () => {
        enabled = allowed();
        if (!enabled && frame) { cancelAnimationFrame(frame); stop(); }
    };

    settle();
    onMotionChange(settle);
    window.addEventListener('wheel', onWheel, { passive: true });
}
