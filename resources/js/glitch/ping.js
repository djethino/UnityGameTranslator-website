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
import { reducedMotion } from '../ambient/motion.js';

const tune = {
    wave: [1, 3],           // how many elements a burst hits — sometimes one, which is the point
    stagger: 260,           // ms; the first is immediate, the others land within this
    spread: 0.28,           // minimum distance between two of them, as a fraction of the viewport
    speed: [300, 560],      // ms of animation, drawn per element
    chrome: 0.4,            // how much less often the nav, header and footer are hit (1 = as often)
    every: [5000, 10000],   // between two bursts
    retry: [4000, 9000],    // nothing eligible on screen — come back soon, not in a minute
    first: [3000, 8000],   // let the page settle before the first one
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

function schedule(delay) {
    setTimeout(() => {
        // Not just cheaper — correct. Firing here would consume this turn against a page nobody can
        // see, and the reader would come back to a background that has just gone quiet.
        if (document.hidden) { schedule(rand(tune.retry)); return; }
        schedule(fire() ? rand(tune.every) : rand(tune.retry));
    }, delay);
}

export function startPing() {
    if (reducedMotion()) return;

    // The channel-splitting filters the animation refers to. Put in the page only now: a reader who
    // asked for less motion never receives the markup at all.
    installRgbSplit();
    schedule(rand(tune.first));

    // Console helper, in the same shape as window.ambient and window.testLingua: fires a burst now
    // and returns how many elements it hit, and lets the whole thing be tuned live — how often an
    // ambient effect should fire, and how many things at once, can only be judged by living with it
    // for a few minutes, not by reading a number.
    window.testGlitch = Object.assign(fire, {
        get tune() { return { ...tune }; },
        set(key, v) { if (key in tune) tune[key] = v; return { ...tune }; },
    });
}
