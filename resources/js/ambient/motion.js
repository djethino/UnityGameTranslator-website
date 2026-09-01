/**
 * The single place that decides how much this page is allowed to move.
 *
 * 🔴 Nothing else reads the media query or the stored preference directly. Two reasons, and the
 * second is the one that bites:
 *
 *   1. There are two sources — the visitor's OPERATING SYSTEM setting, which the browser hands us
 *      as `prefers-reduced-motion`, and the control on the profile screen. Read in two places, they
 *      drift.
 *   2. Either can change WHILE the page is open — somebody toggles the OS setting to see what
 *      happens, or moves a control and expects the background behind it to answer. A one-shot read
 *      at load keeps the answer it got at boot for ever; here it is a live subscription.
 *
 * ── Two questions, not one ─────────────────────────────────────────────────────────────────────
 * The background and the glitches are separate settings because they are separate annoyances. A
 * drifting field behind the text and a word that rewrites itself under your eyes bother different
 * people, and somebody who wants one gone does not necessarily want the other gone.
 *
 * ⚠ Stored per BROWSER, not per account. A phone and a desktop do not have the same tolerance for
 * full-screen motion, so this is a device comfort setting rather than an identity. It also has to
 * work for a visitor with no account, and localStorage does.
 */

const KEYS = { background: 'ugt_motion_background', glitch: 'ugt_motion_glitch' };

/**
 * What each level means, in one place so the screen and the engines cannot disagree.
 *
 * `speed` multiplies the background's own clock; `interval` multiplies the wait between two
 * glitches, so a bigger number means rarer.
 */
export const BACKGROUND_LEVELS = {
    off: { speed: 0 },
    slow: { speed: 0.55 },
    normal: { speed: 1 },
    fast: { speed: 1.8 },
};

export const GLITCH_LEVELS = {
    off: { interval: 0 },
    rare: { interval: 2.6 },
    normal: { interval: 1 },
    often: { interval: 0.4 },
};

/**
 * Where each setting starts when nobody has chosen.
 *
 * ⚠ Reduced motion does NOT mean a still page. A frozen background is not restful, it just looks
 * broken — so the field keeps drifting slowly and it is the glitches, the only part that rewrites
 * what somebody is reading, that stop.
 */
const DEFAULTS = { background: 'normal', glitch: 'normal' };
const REDUCED_DEFAULTS = { background: 'slow', glitch: 'off' };

const query = window.matchMedia('(prefers-reduced-motion: reduce)');
const listeners = new Set();

const stored = { background: read('background'), glitch: read('glitch') };

function read(kind) {
    try {
        const v = localStorage.getItem(KEYS[kind]);
        const table = kind === 'background' ? BACKGROUND_LEVELS : GLITCH_LEVELS;
        // Absent means "I have not chosen", which is a third state and the only one in which the
        // operating system gets to speak. A boolean could not have expressed it.
        return v && Object.prototype.hasOwnProperty.call(table, v) ? v : null;
    } catch {
        // Private mode, or storage blocked. Follow the system and say nothing.
        return null;
    }
}

function publish() {
    listeners.forEach((fn) => fn());
}

query.addEventListener('change', publish);

/** Does the visitor's system ask for less motion? Shown on the settings screen, not acted on here. */
export function systemAsksReduced() {
    return query.matches;
}

/** The level in force for `kind`, whether chosen or inherited from the system. */
export function level(kind) {
    if (stored[kind]) return stored[kind];
    return (query.matches ? REDUCED_DEFAULTS : DEFAULTS)[kind];
}

/** Has the visitor chosen for `kind`, or are we following their system? */
export function isChosen(kind) {
    return stored[kind] !== null;
}

/** How fast the background should run — 0 means it should not run at all. */
export function backgroundSpeed() {
    return BACKGROUND_LEVELS[level('background')].speed;
}

/** How much longer to wait between two glitches — 0 means never. */
export function glitchInterval() {
    return GLITCH_LEVELS[level('glitch')].interval;
}

/**
 * Record a choice, or clear it with `null` and hand the decision back to the operating system —
 * which is a real answer, not a way of saying "no": somebody who turns their OS setting on later
 * expects the site to follow.
 */
export function setLevel(kind, value) {
    const table = kind === 'background' ? BACKGROUND_LEVELS : GLITCH_LEVELS;
    if (value !== null && !Object.prototype.hasOwnProperty.call(table, value)) return;

    stored[kind] = value;
    try {
        if (value === null) localStorage.removeItem(KEYS[kind]);
        else localStorage.setItem(KEYS[kind], value);
    } catch { /* storage refused: the choice holds for this page, which beats an error */ }
    publish();
}

/** Run `fn` whenever any of this changes. Returns the unsubscribe. */
export function onMotionChange(fn) {
    listeners.add(fn);
    return () => listeners.delete(fn);
}

/**
 * ⚠ Kept for the counter in app.js, which only needs a yes or no. It answers for the GLITCH side,
 * because a number counting up is the same family of thing as a word rewriting itself: content
 * moving under the reader, rather than decoration behind them.
 */
export function reducedMotion() {
    return level('glitch') === 'off';
}
