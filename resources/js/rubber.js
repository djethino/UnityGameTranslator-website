/**
 * The page gives a little at the top and the bottom, and springs back.
 *
 * ── Why this is written at all ─────────────────────────────────────────────────────────────────
 * Safari and iOS have done this since 2007 and nobody had to write it. Firefox and Chrome on
 * Windows and Linux do not: reaching the end of a page there is an abrupt stop with no
 * acknowledgement that anything happened. That is where this runs, and only there.
 *
 * ── What moves: the content, and only the content ──────────────────────────────────────────────
 * 🔴 `<main>` alone. The bar and the footer scroll with the page like everything else — they are not
 * pinned — they are simply not part of the bounce.
 *
 * ⚠ Moving them was the first version and it looked broken. At the top of a page the bar IS the top,
 * so sliding it down opened a band of bare background above it: not an edge giving, a header coming
 * unstuck. Reported as "it shifts the menu bar and leaves a gap above it". Holding the two ends and
 * stretching what is between them is the same gesture without that, and it is what the frame of a
 * window does everywhere else.
 *
 * ⚠ Two things stay put for their own reasons, and both are why body itself is never transformed:
 * `.ambient-canvas` is `position: fixed`, so the field reads as a wallpaper the page slides over;
 * and the grain is `.animated-bg::after`, a pseudo-element of BODY — transforming body would have
 * made it the containing block for its own fixed pseudo-element, stretching a viewport-sized tile
 * over the whole document. Nothing in the markup changes either way.
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
let pushed = false;     // did any wheel arrive since the previous frame?
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
    pushed = false;
    apply();
    // Handed back completely: no transform means no containing block, so a modal opened a moment
    // later is positioned against the viewport exactly as it would have been.
    for (const el of movers) el.style.willChange = '';
    movers = [];
    frame = 0;
}

function tick(now) {
    frame = requestAnimationFrame(tick);

    /**
     * 🔴 While the wheel is still turning, the wheel decides where the edge sits. The spring only
     * takes over once it stops. That is what a drag is, and getting it wrong cost two goes.
     *
     * First the release waited ninety milliseconds after the last event, which made the page look
     * stuck at the moment it should have been coming back. Removing the wait went too far the other
     * way: the spring then pulled during the very same frames the wheel was pushing, so the drawn
     * position was a push and a pull netted against each other, and a free-spinning wheel — whose
     * events arrive in uneven bursts — trembled by a few pixels a frame. Reported as exactly that.
     *
     * ⚠ The window is ONE FRAME, and it is not a tuned constant. A wheel spun freely delivers
     * something every frame, so it holds for as long as it turns and sits where it was pushed; a
     * notched wheel leaves gaps far longer than a frame, so each notch returns at once, with nothing
     * to wait for. Both complaints answered by the same line, and no number to get wrong.
     */
    if (pushed) {
        pushed = false;
        velocity = 0;   // being carried, not travelling: the release starts from rest
        last = now;
        apply();
        return;
    }

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

    // The content, named by the tag the layout already uses. Not a list of components to keep up to
    // date, and not a class: a page with no `<main>` simply does not bounce, which is the right way
    // round for something purely ornamental.
    const content = document.body.querySelector(':scope > main');
    if (!content || getComputedStyle(content).position === 'fixed') return false;
    movers = [content];

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

    // 🔴 Clamped, and `give()` alone was not enough. It is read from where the page sits BEFORE the
    // push, so one large event spends the whole gain at full give: a free-spinning wheel sends
    // deltas in the hundreds, and 500 × 0.18 is ninety pixels through a curve that was supposed to
    // stop at twenty-six. That is the gap that was reported, and the jitter with it — every big
    // event overshot and the spring yanked it back.
    const next = y - dy * px * GAIN * give();
    y = Math.max(-MAX_PULL, Math.min(MAX_PULL, next));
    pushed = true;

    // ⚠ Not drawn here. A wheel spun freely fires many times between two frames, and writing a
    // transform on each one is work the compositor throws away. `tick` writes once, after the last
    // of them has been counted.

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
