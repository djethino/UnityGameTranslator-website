/**
 * The ambient glitch: every so often something on screen flinches.
 *
 * Rewritten from the version that shipped for months doing nothing on all but three pages. Three
 * faults, and only the first was ever visible as a fault:
 *
 *  1. **It aimed at classes that did not exist.** Four of its six selectors matched nothing on the
 *     whole site. Finding targets is now `targets.js`'s job, by what an element IS.
 *  2. **A ping with no target was a ping lost.** It rescheduled thirty to ninety seconds out and
 *     tried again, so a reader parked on a stretch with nothing eligible got silence for minutes.
 *     A miss now retries in seconds.
 *  3. **It fired into a hidden tab.** The background paused on `visibilitychange`; this did not, so
 *     pings were spent on a page nobody was looking at — and coming back meant waiting again.
 */

import { pickVisualMany } from './targets.js';
import { installRgbSplit } from './rgb-split.js';

const tune = {
    wave: [1, 3],           // how many elements a burst hits — sometimes one, which is the point
    stagger: 260,           // ms; the first is immediate, the others land within this
    spread: 0.28,           // minimum distance between two of them, as a fraction of the viewport
    speed: [300, 560],      // ms of animation, drawn per element
    chrome: 0.4,            // how much less often the nav, header and footer are hit (1 = as often)
    // ⚠ No interval here any more. When and how often is the orchestra's business (see
    // glitch/orchestra.js); this file decides only what one burst looks like.
    // ⚠ Floor on how long the class stays. The real duration is max(hold, speed + 60), because a
    // class removed mid-animation snaps the element back instead of letting it settle.
    hold: 1000,
};

const rand = (r) => r[0] + Math.random() * (r[1] - r[0]);
const busy = new Set();

/**
 * The CSS animation is one fixed sequence of six states, so left alone every ping looks like every
 * other ping. Two knobs on the way out fix that for nothing: a duration drawn per element, and a
 * coin toss on the direction — reversed, the six variants arrive in the opposite order, which reads
 * as a different fault rather than as the same one replayed.
 */
function play(el) {
    const ms = rand(tune.speed);
    el.style.animationDuration = `${Math.round(ms)}ms`;
    el.style.animationDirection = Math.random() < 0.5 ? 'reverse' : 'normal';
    el.classList.add('glitching');

    busy.add(el);
    // ⚠ The class has to outlast the animation it triggers, INCLUDING the slowest draw — cut short,
    // the element snaps back mid-flight instead of settling.
    setTimeout(() => {
        el.classList.remove('glitching');
        el.style.animationDuration = '';
        el.style.animationDirection = '';
        busy.delete(el);
    }, Math.max(tune.hold, ms + 60));
}

/** One burst: a few elements at once, spread across the view, offset from each other in time. */
/**
 * A failing lamp, not a switch.
 *
 * 🔴 One burst reads as an effect being applied. A lamp at the end of its life does not do that: it
 * catches once, dies, catches again a moment later, sometimes a third time, and the gaps are never
 * the same twice. That is what this does — the orchestra says WHEN something should flinch, and how
 * it flinches is decided here, freshly, every time.
 *
 * ⚠ The gaps are drawn from two ranges rather than one. A single range with a wide spread still
 * produces one characteristic rhythm; a short gap and a long one, picked between, produce the
 * stutter-then-pause a dying tube actually makes.
 */
function flicker() {
    const times = 1 + ((Math.random() * 3) | 0);      // one, two or three
    let at = 0;
    for (let k = 1; k < times; k++) {
        at += Math.random() < 0.55 ? 70 + Math.random() * 160 : 320 + Math.random() * 520;
        setTimeout(fire, at);
    }
    // ⚠ What the FIRST strike touched, not how many strikes were scheduled. The earlier version
    // counted the scheduled ones too, so on a screen where nothing may be touched it reported
    // hitting things it had not — which is worse than no number at all, because it is the number a
    // check would trust.
    return fire();
}

function fire() {
    const wanted = Math.max(1, Math.round(rand(tune.wave)));
    const picks = pickVisualMany(wanted, {
        minGap: Math.min(window.innerWidth, window.innerHeight) * tune.spread,
        accept: (el) => !busy.has(el),
        chromeWeight: tune.chrome,
    });
    if (!picks.length) return 0;

    picks.forEach((el, i) => {
        // The first lands at once so the burst has a leading edge; the rest scatter behind it.
        const delay = i === 0 ? 0 : Math.random() * tune.stagger;
        setTimeout(() => {
            if (!busy.has(el) && el.isConnected) play(el);
        }, delay);
    });

    return picks.length;
}

/**
 * Fire one burst now, installing the filters if this is the first time.
 *
 * 🔴 The only way this effect ever fires. The settings card uses it, and so does the glitch
 * orchestra, which owns the timing for all three effects — this file no longer has a clock. Three
 * effects each waiting on their own interval produced three regularities crossing each other, and
 * a second entry point would let one of them quietly acquire a fourth.
 */
export function fireNow() {
    // Installed on first use rather than at startup: a visitor who never lets a glitch run never
    // receives the markup.
    installRgbSplit();
    return flicker();
}

export function startPing() {
    // Console helper, in the same shape as window.ambient and window.testLingua: fires a burst now
    // and returns how many elements it hit, and lets the whole thing be tuned live — how often an
    // ambient effect should fire, and how many things at once, can only be judged by living with it
    // for a few minutes, not by reading a number.
    // ⚠ defineProperties, not Object.assign: assign would call the getter once and store the
    // result, so `.tune` would report the values this file started with and never the ones `set`
    // has since written — a live tuning knob that silently stops reporting what it tuned.
    window.testGlitch = Object.defineProperties(fireNow, {
        tune: { get() { return { ...tune }; } },
        set: { value: (key, v) => { if (key in tune) tune[key] = v; return { ...tune }; } },
    });
}
