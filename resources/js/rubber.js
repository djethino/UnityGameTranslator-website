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

/** How far the page can be pulled past its end, in pixels, however hard you push. */
const MAX_PULL = 48;

/** What one pixel of wheel is worth at rest. A notch is about 100, so the first one opens some
 *  fifteen pixels — enough to see, nowhere near the end of the travel. Simulated before it was
 *  chosen: at 1.0 a single notch went two thirds of the way, which reads as a page coming loose. */
const GAIN = 0.15;

/**
 * Spring stiffness, critically damped so it arrives without wobbling — a wobble reads as a bug, a
 * single soft return reads as a material.
 *
 * ⚠ 22, not 16, and the difference was measured rather than felt: 16 took 450 to 550 ms to close,
 * where the thing being imitated takes about 300. Well inside the stability limit — the integrator
 * is semi-implicit and needs ω·dt < 1, and dt is clamped at a thirtieth of a second, so 22 gives
 * 0.73 at the very worst frame this can be handed.
 */
const OMEGA = 22;

/** A wheel is a stream of discrete ticks, not a held finger. This is how long after the last tick
 *  we decide the gesture is over and let go. Shorter and a slow scroll snaps back mid-push. */
const RELEASE_MS = 90;

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
let releaseAt = 0;
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

    // The gesture is over once the ticks stop arriving. Until then the page holds where it was
    // pushed, which is what makes it feel held rather than flicked.
    // ⚠ The clock is reset while holding, not merely paused: releasing after a second of stillness
    // would otherwise hand the spring a second-long step and fire the page off the screen.
    if (now < releaseAt) { last = now; return; }

    // Critically damped, semi-implicit: velocity first, then position. Clamped at a 30 Hz step, so a
    // dropped frame or a tab coming back cannot push ω·dt anywhere near the stability limit.
    const dt = Math.min((now - last) / 1000, 1 / 30);
    last = now;
    velocity += (-OMEGA * OMEGA * y - 2 * OMEGA * velocity) * dt;
    y += velocity * dt;

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

    if (!atTop && !atEnd) {
        // Back inside the page: let go at once rather than waiting out the release delay, so a
        // scroll that turns round does not drag a stretched edge with it.
        if (frame) releaseAt = 0;
        return;
    }

    if (consumedInside(event.target, dy)) return;
    if (!begin()) return;

    // ⚠ Deltas arrive in three units depending on the device. A line or a page would push the edge
    // to its limit in one tick, so they are given the pixel worth of one notch instead.
    const step = event.deltaMode === 0 ? dy : Math.sign(dy) * (event.deltaMode === 1 ? 16 : 100);
    y -= step * GAIN * give();
    // Pushing again while it is springing back adds to where it has got to rather than fighting the
    // spring, so the page is never caught mid-flight.
    velocity = 0;
    releaseAt = performance.now() + RELEASE_MS;
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
