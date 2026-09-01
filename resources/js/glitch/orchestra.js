/**
 * L'orchestre des glitchs — when anything is allowed to flinch, and what flinches with what.
 *
 * 🔴 Why one place. Three effects — the background's break-up, the visual flinch, the words that
 * slip into another language — each used to wait on its own interval. That does not add up to
 * unpredictability: it adds up to **three regularities crossing each other**, which is the worst of
 * both worlds. Each one arrived on a metronome you could learn, and their coincidences were
 * accidents nobody had composed. Together they were also simply too much — something happened every
 * two or three seconds, and a thing that happens every two seconds is not a glitch, it is a texture.
 *
 * ── What replaces it ───────────────────────────────────────────────────────────────────────────
 * **Episodes**, not events. An episode is one to three hits with their own small offsets, drawn
 * from a repertoire that includes combinations as first-class entries — the background tearing
 * *and* a word slipping, a fifth of a second apart, is a thing somebody wrote down, not a collision.
 *
 * **Lulls of three different lengths.** Most are ordinary, some are long enough that you forget the
 * page does this, and a few are short enough that the last episode has not finished being a
 * surprise. Drawing the short one repeatedly is what produces a flurry of two or three; drawing the
 * long one is what produces the minute of silence. Neither is a special case in the code.
 *
 * ⚠ **Nothing here is a mean interval with jitter around it.** That is the shape people reach for
 * and it is exactly what it feels like: a metronome with a wobble. A mixture of three very
 * different lulls has no centre to hear.
 */

import { glitchInterval } from '../ambient/motion.js';
import { warm } from './targets.js';

const rnd = (a, b) => a + Math.random() * (b - a);

/**
 * The silences, and the whole character of the thing.
 *
 * `odds` must sum to 1. Seconds, before the visitor's own frequency setting scales them.
 */
const LULLS = [
    { id: 'enchaine', span: [1.0, 3.5], odds: 0.22 },   // the flurry: the same draw twice makes three
    { id: 'ordinaire', span: [7, 20], odds: 0.60 },
    { id: 'long', span: [38, 75], odds: 0.18 },         // long enough to forget it happens
];

/**
 * The repertoire. `after` is milliseconds since the previous hit in the same episode.
 *
 * ⚠ Solos appear more than once on purpose: the deck below deals uniformly, so the only way to make
 * a plain single flinch commoner than a three-part combination is to put more of them in.
 */
const REPERTOIRE = [
    { id: 'fond', hits: [['fond']] },
    { id: 'fond2', hits: [['fond']] },
    { id: 'visuel', hits: [['visuel']] },
    { id: 'visuel2', hits: [['visuel']] },
    // 🔴 Language appears three times against two for the others, and that is a statement about
    // what this site is. It translates games; a word quietly becoming Polish or Korean in the middle
    // of a sentence says so better than any paragraph, and it is the one effect here that carries
    // meaning rather than atmosphere.
    { id: 'langue', hits: [['langue']] },
    { id: 'langue2', hits: [['langue']] },
    { id: 'langue3', hits: [['langue']] },
    // The lamp gives out, and what comes back is in another language.
    { id: 'lampe', hits: [['visuel'], ['langue', 260, 620]] },

    // The background tears and something on the page flinches with it — near enough to read as one
    // event, far enough apart that you can tell there were two.
    { id: 'interference', hits: [['fond'], ['visuel', 60, 240]] },
    // A word slips, and the light behind it goes with it.
    { id: 'contagion', hits: [['langue'], ['fond', 90, 380]] },
    // Two flinches and a tear: the closest this gets to a real fault.
    { id: 'decharge', hits: [['visuel'], ['fond', 70, 200], ['visuel', 120, 340]] },
    // The rarest, and the only one where all three speak.
    { id: 'panne', hits: [['fond'], ['visuel', 90, 260], ['langue', 140, 420]] },
];

/**
 * Deal without replacement, so no episode is starved and none repeats until every other has had a
 * turn. Same reasoning as the figures' deck one level up — uniform in the long run says nothing
 * about the short one, and the short run is the whole of a visit.
 */
function dealer(pool) {
    let deck = [];
    return () => {
        if (!deck.length) {
            deck = pool.slice();
            for (let i = deck.length - 1; i > 0; i--) {
                const j = (Math.random() * (i + 1)) | 0;
                [deck[i], deck[j]] = [deck[j], deck[i]];
            }
        }
        return deck.pop();
    };
}

function drawLull() {
    let r = Math.random();
    for (const l of LULLS) { if ((r -= l.odds) <= 0) return l; }
    return LULLS[1];
}

/**
 * @param {Object} voices  `{ fond, visuel, langue }` — each a function that fires once and returns
 *                         something truthy if it managed to. A missing voice is simply skipped, so
 *                         a machine with no WebGL loses the background's part and keeps the rest.
 */
export function startOrchestra(voices) {
    const drawEpisode = dealer(REPERTOIRE);
    const log = [];

    function play(episode) {
        // ⚠ One tempo for the whole episode, drawn per episode: the same combination played twice
        // is not the same combination. It is the humanizer the bobs use, applied to time instead of
        // to space.
        const tempo = rnd(0.7, 1.45);
        let at = 0;
        for (const [what, lo, hi] of episode.hits) {
            const voice = voices[what];
            if (!voice) continue;
            if (lo !== undefined) at += rnd(lo, hi) * tempo;
            if (at === 0) voice();
            else setTimeout(voice, at);
        }
        log.push({ at: Math.round(performance.now() / 100) / 10, episode: episode.id });
        if (log.length > 40) log.shift();
    }

    function next(delay) {
        setTimeout(() => {
            // ⚠ Read at every tick, never captured at startup: somebody turning glitches back on
            // from the profile screen expects the next one to arrive, not to have to reload. Off
            // means come back and ask again, not stop for ever.
            const scale = glitchInterval();
            if (!scale) { next(4000); return; }

            // Firing at a page nobody can see spends the turn and leaves the reader coming back to
            // a background that has just gone quiet.
            if (document.hidden) { next(3000); return; }

            play(drawEpisode());

            const lull = drawLull();
            const wait = rnd(lull.span[0], lull.span[1]) * 1000 * scale;
            // 🔴 Look at the page BEFORE we need to look at it. The scan costs eleven milliseconds
            // on a large document — a whole frame — and it is the same eleven milliseconds whether
            // it happens while the field is being drawn or a second earlier with nothing else going
            // on. Doing the expensive part in the quiet before the effect is the oldest trick in
            // the book, and the only reason it was not being done is that nobody had measured which
            // part was expensive.
            if (wait > 2000) setTimeout(warm, wait - 1400);
            next(wait);
        }, delay);
    }

    // A first episode soon enough to say what kind of page this is, late enough that it does not
    // compete with the first render.
    next(rnd(4000, 11000));

    // Console helper, in the same shape as `window.ambient`: what has fired, and how recently.
    //
    // 🔴 defineProperties, not Object.assign — the third time this project has been caught by it.
    // Assign INVOKES a getter and stores what it returned, so `recent` would be frozen on the empty
    // log it held at startup and would report that nothing has ever fired, for ever. A diagnostic
    // that lies is worse than none: without it you know you do not know.
    window.testOrchestra = Object.defineProperties(() => play(drawEpisode()), {
        recent: { get() { return [...log]; } },
        repertoire: { get() { return REPERTOIRE.map((e) => e.id); } },
    });
}
