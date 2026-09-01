/**
 * Where the pointer is, and — the part that matters — whether there is one at all.
 *
 * The chase pattern needs an answer to "is somebody pointing at something right now?", and that
 * question has the same shape on both kinds of device once you stop treating them as two cases:
 *
 *   - touch: it follows the finger while it is DOWN, and the moment it lifts there is no pointer;
 *   - mouse: it follows the cursor while it is MOVING, and a cursor abandoned in a corner for a few
 *     seconds is not a pointer either — staying glued to it would look like the background had
 *     hung.
 *
 * One rule, no mobile branch, and it fixes the forgotten-cursor case on desktop for free.
 */

// How long a still mouse stays interesting. Long enough to survive a pause while reading, short
// enough that a cursor parked while someone went for coffee stops commanding the scene.
const MOUSE_IDLE_MS = 2600;

const state = {
    // Normalized to the viewport: -1 … 1 on each axis, 0 at the centre.
    x: 0,
    y: 0,
    // Where it is going, in the same units per second, smoothed. A pattern that reacts to the
    // GESTURE rather than to the position needs this: a slow drift through the field and a flick
    // across it are the same positions in a different order.
    vx: 0,
    vy: 0,
    active: false,
    // Has a pointer ever existed here? A touch device that has not been touched answers no, and
    // the conductor uses it to keep the pointer patterns out of a rotation nobody could feed.
    seen: false,
    _lastMove: -Infinity,
    _lastAt: 0,
    _touching: false,
};

function put(clientX, clientY) {
    const now = performance.now();
    const x = (clientX / window.innerWidth) * 2 - 1;
    const y = (clientY / window.innerHeight) * 2 - 1;

    const dt = (now - state._lastAt) / 1000;
    // ⚠ Guarded at both ends. Below a millisecond the division explodes; above a quarter of a
    // second the two samples are unrelated and the "velocity" between them is fiction.
    if (dt > 0.001 && dt < 0.25) {
        const k = 1 - Math.exp(-12 * dt);
        state.vx += ((x - state.x) / dt - state.vx) * k;
        state.vy += ((y - state.y) / dt - state.vy) * k;
    }

    state.x = x;
    state.y = y;
    state._lastAt = now;
    state.seen = true;
}

window.addEventListener('mousemove', (e) => {
    put(e.clientX, e.clientY);
    state._lastMove = performance.now();
}, { passive: true });

// Leaving the window is an answer, not an absence of one: the pointer is gone now, not in 2.6 s.
window.addEventListener('mouseleave', () => { state._lastMove = -Infinity; }, { passive: true });

const touchMove = (e) => {
    const t = e.touches && e.touches[0];
    if (t) put(t.clientX, t.clientY);
};

window.addEventListener('touchstart', (e) => { state._touching = true; touchMove(e); }, { passive: true });
window.addEventListener('touchmove', touchMove, { passive: true });
window.addEventListener('touchend', () => { state._touching = false; }, { passive: true });
window.addEventListener('touchcancel', () => { state._touching = false; }, { passive: true });

/**
 * The pointer as the patterns see it. `active` false means "nobody is pointing" — the chase then
 * lets the bobs go about their business instead of converging on a stale coordinate.
 */
export function pointer() {
    state.active = state._touching || (performance.now() - state._lastMove) < MOUSE_IDLE_MS;

    // Velocity decays on its own when nothing is moving. Left alone it would keep reporting the
    // last flick for ever, and a wake would stay carved after the hand had stopped.
    if (!state.active) { state.vx *= 0.9; state.vy *= 0.9; }

    return state;
}

/** Has this visitor ever had a pointer? Asked once by the conductor, not per frame. */
export function pointerEverSeen() {
    return state.seen;
}
