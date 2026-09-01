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
    active: false,
    _lastMove: -Infinity,
    _touching: false,
};

function put(clientX, clientY) {
    state.x = (clientX / window.innerWidth) * 2 - 1;
    state.y = (clientY / window.innerHeight) * 2 - 1;
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
    return state;
}
