/**
 * Every so often, several words on the page slip into other languages and slip back.
 *
 * This is the one effect on this site that no other site could copy without looking like it had
 * borrowed it: the whole place exists to translate games, so its decoration is made of translation.
 *
 * ── How it knows what it is allowed to touch ───────────────────────────────────────────────────
 * It does not guess, and it is not told. Each locale is served as an ordered list of its short
 * interface strings (see LanguageBankController), so index 412 is the same line in all twenty. We
 * build a reverse index for the language the page is being read in — sentence → index — then look
 * up whatever text is on screen. Found means it is one of OUR strings and we know its line number
 * in every other language.
 *
 * 🟢 That mechanism is also the safety, and it is worth being explicit about why nothing else is
 * needed: a game's name, someone's username, a line of a real translation inside an editor are not
 * in `lang/`, so they are not in the index, so they CANNOT be selected. There is no list of things
 * to protect that somebody has to keep up to date — the lookup simply fails.
 */

import { pickTextualMany } from './targets.js';
import { glitchInterval } from '../ambient/motion.js';

/**
 * A wave, not a word.
 *
 * ⚠ One word at a time was almost invisible on a page full of text — you had to be looking at the
 * right six characters at the right second. Several at once, scattered across the view and offset
 * from each other by a couple of tenths, reads as something passing OVER the page rather than as a
 * single word misbehaving. The stagger matters as much as the count: fired together they look like
 * a re-render, fired in sequence they look like a sweep.
 */
const tune = {
    wave: [3, 5],           // how many words a wave carries
    stagger: 420,           // ms; the first is immediate, the others land within this
    cue: 220,               // ms of cathode-ray stutter before the change, and again before the return
    shown: [900, 1500],     // how long one word stays in the other language
    every: [10000, 20000],  // between two waves
    spread: 0.22,           // minimum distance between two words, as a fraction of the viewport
    chrome: 0.3,            // how much less often the nav, header and footer are picked (1 = as often)
};

// Six others rather than three: a wave shows four or five words at once, and each takes a different
// language. With three, the same one comes up twice in the same wave and the effect reads as a bug.
// Six files, fetched at idle, is about fifty kilobytes.
const OTHERS = 6;

// ...and then it keeps widening. A visit that lasts picks up another language now and then, so the
// palette a reader sees is not decided once and for ever by the six drawn at load.
const MAX_LANGS = 12;
const TOP_UP_CHANCE = 0.34;

const banks = new Map();        // locale → string[]
let waiting = [];               // locales drawn but not fetched yet, in the order they will arrive
const busy = new Set();         // elements mid-swap, so two waves cannot overlap on one word
let index = null;               // normalized sentence → line number, for the page's own language
let here = null;

const rand = (r) => r[0] + Math.random() * (r[1] - r[0]);

// Collapse the whitespace differences between markup and JSON. Not a fuzzy match: the string still
// has to be the same string, or an unrelated line would be swapped in as if it were a translation.
const norm = (s) => s.replace(/\s+/g, ' ').trim();

async function load(locale) {
    if (banks.has(locale)) return banks.get(locale);
    try {
        const res = await fetch(`/lang-bank/${locale}.json`, { headers: { Accept: 'application/json' } });
        if (!res.ok) return null;
        const data = await res.json();
        const lines = Array.isArray(data.lines) ? data.lines : null;
        if (lines) banks.set(locale, lines);
        return lines;
    } catch {
        // Offline, or the request was cut short. The page is not waiting on this.
        return null;
    }
}

function buildIndex(lines) {
    const map = new Map();
    for (let i = 0; i < lines.length; i++) {
        const v = norm(lines[i]);
        // First occurrence wins. A word like "Games" can be the value of two keys, and either line
        // number leads to a correct translation, so there is nothing to arbitrate.
        if (v && !map.has(v)) map.set(v, i);
    }
    return map;
}

/**
 * Stutter, become another language, stutter, come back.
 *
 * ── 🔴 Why the original text never leaves the page ─────────────────────────────────────────────
 * It stays exactly where it was, made transparent, and the foreign word is drawn on an ABSOLUTELY
 * POSITIONED layer above it. The layout therefore cannot move, and that is not an optimisation — an
 * earlier version pinned the element's measured WIDTH and the menu still breathed, because a Korean
 * or Arabic word is rendered by a different fallback font whose taller metrics grow the line box.
 * Only the original text has the original metrics, so only the original text can hold the line.
 *
 * It is also what makes the tremor possible: `transform` does not apply to a non-replaced inline
 * element, so a word in a nav bar cannot be shaken in place. A layer out of flow can be.
 *
 * ── The sequence ───────────────────────────────────────────────────────────────────────────────
 * The stutter runs BEFORE the change and again before the return, so the eye is called a fifth of a
 * second early and the swap is seen rather than merely having happened.
 *
 * ── A screen reader keeps hearing the truth ────────────────────────────────────────────────────
 * The transparent original is still in the accessibility tree — `color: transparent` rather than
 * `visibility: hidden`, which would have removed it — and only the lying layer carries
 * `aria-hidden`. Someone who cannot see the effect is not told about it at all, which is right:
 * there is nothing there for them to act on.
 */
function swap(node, text, ms) {
    const parent = node.parentNode;
    if (!parent) return;

    // Claimed until it is put back. Without this, a wave arriving while the previous one is still
    // showing could pick the same run and restore it to the OTHER language's text — the original
    // would be lost for the rest of the visit.
    busy.add(node);

    // ⚠ The original TEXT NODE is never rewritten, only moved. It goes on holding the line with its
    // own font and metrics — put back at the end by moving it home again, so whatever markup
    // surrounded it (an icon, a link, the rest of a sentence) is untouched throughout.
    const box = document.createElement('span');
    box.className = 'lingua-box';
    // The two spans about to exist hold short text: precisely what the picker looks for. Without
    // this, the next wave would translate the ghost of the previous one.
    box.setAttribute('data-no-glitch', '');

    const under = document.createElement('span');
    under.className = 'lingua-under';

    const ghost = document.createElement('span');
    ghost.className = 'lingua-ghost lingua-cue';
    ghost.setAttribute('aria-hidden', 'true');
    ghost.textContent = node.nodeValue.trim();

    parent.insertBefore(box, node);
    under.appendChild(node);          // moves the original text node, intact
    box.append(under, ghost);

    let done = false;
    const finish = () => {
        if (done) return;
        done = true;
        if (box.isConnected) box.replaceWith(node);
        busy.delete(node);
    };

    const cue = tune.cue;

    setTimeout(() => {
        if (done) return;
        if (!box.isConnected) return finish();
        ghost.classList.remove('lingua-cue');
        ghost.textContent = text;
        ghost.classList.add('lingua-swap');
    }, cue);

    setTimeout(() => {
        if (done) return;
        if (!box.isConnected) return finish();
        ghost.classList.remove('lingua-swap');
        // Reading a layout property between removing and adding restarts the animation; without it
        // the class change is coalesced and the second stutter never plays.
        void ghost.offsetWidth;
        ghost.classList.add('lingua-cue');
    }, cue + ms);

    setTimeout(finish, cue + ms + cue);
}

/**
 * Can this run be taken? Asked by the picker, which knows about geometry but not about language.
 *
 * 🟢 There used to be a third test here, refusing table cells and list items: the old mechanism
 * turned the element itself into an inline-block to pin its width, which broke a table and dropped
 * a bullet. Wrapping the text node in a span instead touches neither, so those two are eligible now
 * — the restriction went away with its cause rather than being worked around.
 */
function usable(item) {
    return !busy.has(item.node) && index.has(norm(item.text));
}

/** One wave: several words, spread across the view, each in a different language, offset in time. */
function fire() {
    if (!index || banks.size < 2) return 0;

    const wanted = Math.max(1, Math.round(rand(tune.wave)));
    const picks = pickTextualMany(wanted, {
        minGap: Math.min(window.innerWidth, window.innerHeight) * tune.spread,
        accept: usable,
        chromeWeight: tune.chrome,
    });
    if (!picks.length) return 0;

    // A different language per word where the bank allows it. Rotating the starting point per word
    // rather than drawing at random is what stops the same language turning up twice in one wave,
    // which reads as a repetition rather than as a survey.
    const langs = [...banks.keys()].filter((l) => l !== here);
    for (let i = langs.length - 1; i > 0; i--) {
        const j = (Math.random() * (i + 1)) | 0;
        [langs[i], langs[j]] = [langs[j], langs[i]];
    }

    let fired = 0;
    picks.forEach((item, i) => {
        const line = index.get(norm(item.text));
        const from = i % langs.length;

        const lang = [...langs.slice(from), ...langs.slice(0, from)].find((l) => {
            const v = banks.get(l)[line];
            // The same word in two languages is not a swap, it is a flicker.
            return v && norm(v) !== norm(item.text);
        });
        if (!lang) return;

        fired++;
        // The first lands at once so the wave has a leading edge; the rest scatter behind it.
        const delay = i === 0 ? 0 : Math.random() * tune.stagger;
        setTimeout(() => {
            // The reader may have scrolled it away, or another wave may have claimed it, in the
            // fraction of a second since it was chosen.
            if (!busy.has(item.node) && item.node.isConnected) {
                swap(item.node, banks.get(lang)[line], rand(tune.shown));
            }
        }, delay);
    });

    return fired;
}

/** Now and then, quietly widen the palette. One more file, at idle, between two waves. */
function topUp() {
    if (banks.size >= MAX_LANGS || !waiting.length) return;
    if (Math.random() > TOP_UP_CHANCE) return;
    load(waiting.shift());
}

/**
 * Fetch the language banks, once, the first time one is actually needed.
 *
 * ⚠ Deferred rather than done at startup, and the reason is a defect I nearly shipped: the setting
 * can be off, and downloading fifty kilobytes of translations for a visitor who has turned the
 * effect off is exactly the kind of cost nobody sees. The promise is kept so a second caller waits
 * for the first fetch instead of starting another.
 */
let ready = null;

function ensureBanks() {
    if (ready) return ready;

    ready = (async () => {
        const all = (document.body.dataset.locales || '').split(',').filter(Boolean);
        if (!here || all.length < 2) return false;

        const mine = await load(here);
        if (!mine) return false;
        index = buildIndex(mine);

        // 🔴 Fisher-Yates, not sort(() => Math.random() - 0.5). That comparator is inconsistent, so
        // the result is not a uniform sample: V8's sort leaves early elements near the front, and
        // the six languages would have been drawn mostly from the top of config/locales.php —
        // Arabic, German, Spanish over and over, Vietnamese and Chinese almost never. Measured on
        // the real list: 44 % for the first entries against 26 % for the last. It looks random and
        // is not.
        waiting = all.filter((l) => l !== here);
        for (let i = waiting.length - 1; i > 0; i--) {
            const j = (Math.random() * (i + 1)) | 0;
            [waiting[i], waiting[j]] = [waiting[j], waiting[i]];
        }
        await Promise.all(waiting.splice(0, OTHERS).map(load));

        return banks.size >= 2;
    })();

    return ready;
}

function schedule(delay) {
    setTimeout(async () => {
        // ⚠ Read at every tick, never captured at startup — see the same note in ping.js. Off means
        // come back and ask again, so turning it on from the profile screen takes effect without a
        // reload.
        if (glitchInterval() === 0) { schedule(4000); return; }

        if (await ensureBanks()) { fire(); topUp(); }
        schedule(rand(tune.every) * glitchInterval());
    }, delay);
}

/** Fire one wave now, fetching what it needs if this is the first time. Used by the settings card. */
export async function fireNow() {
    return (await ensureBanks()) ? fire() : 0;
}

export function startLingua() {
    here = document.documentElement.lang;
    schedule(rand(tune.every) * Math.max(glitchInterval(), 1));

    // Console helper, the counterpart of `window.testGlitch` and `window.ambient`: fires a wave now
    // instead of somewhere in the next forty seconds, and returns how many words it managed to
    // swap. `loaded` says which languages this page happens to be holding, so a swap that looks
    // wrong can be traced to a language rather than to the mechanism; `set` tunes the wave live,
    // since its size and stagger are things that can only be judged by watching them.
    window.testLingua = Object.assign(fireNow, {
        loaded: () => [...banks.keys()],
        get tune() { return { ...tune }; },
        set(key, v) { if (key in tune) tune[key] = v; return { ...tune }; },
    });
}

/** Everything we hold, in every language loaded — what the trace pattern draws its letters from. */
export function bankStrings() {
    const out = [];
    for (const lines of banks.values()) {
        for (let i = 0; i < lines.length; i += 7) if (lines[i]) out.push(lines[i]);
    }
    return out;
}
