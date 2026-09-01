/**
 * The single place that decides whether this browser wants less motion.
 *
 * 🔴 Nothing else in the ambient system is allowed to read the media query or the stored
 * preference directly. Two reasons, and the second is the one that bites:
 *
 *   1. There are two sources — the visitor's OPERATING SYSTEM setting, which the browser hands us
 *      as `prefers-reduced-motion`, and our own in-site control. Read in two places, they drift.
 *   2. The OS setting can change WHILE the page is open (someone toggles it to see what happens).
 *      A one-shot read at load, which is what the old code did (app.js:415, app.js:457), silently
 *      keeps the answer it got at boot forever. Here it is a live subscription.
 *
 * "Reduced" does NOT mean "frozen". A still background is not calmer to look at, it just looks
 * broken. What it means is decided by the engine and the conductor: gentle drift stays, the fast
 * patterns, the camera charge, the fisheye, the ink trail and the scroll coupling all go.
 *
 * ⚠ Stored per BROWSER, not per account. A phone and a desktop do not have the same tolerance for
 * full-screen motion, so this is a device comfort setting, not an identity. It also has to work for
 * a visitor with no account, and localStorage does.
 */

const STORAGE_KEY = 'ugt_reduced_motion';

const query = window.matchMedia('(prefers-reduced-motion: reduce)');
const listeners = new Set();

let stored = readStored();
let current = compute();

function readStored() {
    try {
        const v = localStorage.getItem(STORAGE_KEY);
        // Three states on purpose: "1" forces reduced, "0" forces full, absent follows the system.
        // A plain boolean could not express "I have not chosen", which is the default and the only
        // state in which the OS setting is allowed to speak.
        return v === '1' ? true : v === '0' ? false : null;
    } catch {
        // Private mode, or storage blocked by the browser. Follow the system and say nothing.
        return null;
    }
}

function compute() {
    return stored === null ? query.matches : stored;
}

function publish() {
    const next = compute();
    if (next === current) return;
    current = next;
    listeners.forEach((fn) => fn(current));
}

query.addEventListener('change', publish);

/** Does this visitor want less motion, right now? */
export function reducedMotion() {
    return current;
}

/** Has the visitor made an explicit choice, or are we following their system? */
export function motionPreference() {
    return stored === null ? 'system' : stored ? 'reduced' : 'full';
}

/**
 * Record a choice. `'system'` clears it and hands the decision back to the operating system —
 * which is a real answer, not a way of saying "no": someone who turns their OS setting on later
 * expects the site to follow.
 */
export function setMotionPreference(value) {
    try {
        if (value === 'system') localStorage.removeItem(STORAGE_KEY);
        else localStorage.setItem(STORAGE_KEY, value === 'reduced' ? '1' : '0');
    } catch { /* storage refused: the choice holds for this page, which is better than an error */ }
    stored = value === 'system' ? null : value === 'reduced';
    publish();
}

/** Run `fn` whenever the answer changes. Returns the unsubscribe. */
export function onMotionChange(fn) {
    listeners.add(fn);
    return () => listeners.delete(fn);
}
