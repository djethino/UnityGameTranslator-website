/**
 * A letter, turned into something five bobs can paint.
 *
 * 🔴 No alphabet is written down here, on purpose. Hard-coding "these are the Thai characters"
 * would be exactly the kind of per-script logic this project refuses everywhere else, and it would
 * rot the day a locale is added. Instead the candidates come from TEXT WE ALREADY HAVE — the
 * language bank when it has loaded (which is how a Korean glyph can appear on a French page), and
 * failing that the words on the page itself. Both are real sentences in real languages, so whatever
 * comes out is a genuine letter of a genuine script, and nobody had to enumerate one.
 *
 * The bobs cannot follow strokes: reconstructing stroke order from an outline is a research
 * problem, and we have five brushes and no font parsing. So they do what a pen plotter does — each
 * owns a horizontal band and sweeps it left, right, left, with its ink turned up where the glyph is
 * solid. The letter appears line by line. It is also, conveniently, the most demoscene answer
 * available: a raster effect.
 */

// Sampling grid for the ink lookup. Finer than the number of sweeps, because the sweeps read a
// continuous position along each row and want a smooth answer rather than a staircase.
const GRID = 64;

// How many scan lines the five brushes actually lay down (5 bobs × 5 rows). The filter below has
// to be measured against THIS number, not against the sampling grid: it is the real resolution.
const SCANS = 25;

// A glyph has to hold enough ink to be worth painting. Under this it is a full stop.
const INK_MIN = 0.07;

// 🔴 And it has to be simple enough for five brushes to express.
//
// ⚠ Ink density was the obvious measure and it is the WRONG one — measured on the real language
// bank, it rejected 9 Japanese characters out of 523 and 5 Chinese out of 646, i.e. it let 98 % of
// the kanji through. A complex character is not a dark one; its strokes are thin, so its coverage
// is ordinary. What defeats a plotter is how many times a single sweep has to cross from ink to
// paper and back.
//
// Measured over every character of all twenty languages, that number separates the scripts cleanly:
// a median of 0.76–0.88 segments per sweep for Latin, Cyrillic, Arabic, Hebrew, Devanagari and
// Thai, against 1.52 for Japanese and 1.76 for Chinese. These two thresholds keep 75–84 % of the
// alphabetic scripts (every common letter), 40 % of Japanese and 24 % of Chinese — the kana and the
// open characters like 全, 人, 文, 目 stay, 憎, 編 and 翻 go. Every writing system is still
// represented; none of them arrives as a smudge.
const SEGMENTS_MEAN_MAX = 1.35;
const SEGMENTS_PEAK_MAX = 5;

const canvas = document.createElement('canvas');
canvas.width = canvas.height = GRID;
const ctx = canvas.getContext('2d', { willReadFrequently: true });

/**
 * Rasterize one character and return its ink map, or null if it is not paintable.
 *
 * ⚠ The font stack is left to the browser on purpose (`sans-serif` after the two families that
 * carry the widest coverage): asking for a specific face is how you end up with tofu on the one
 * machine that lacks it.
 */
function rasterize(ch) {
    ctx.clearRect(0, 0, GRID, GRID);
    ctx.fillStyle = '#fff';
    ctx.textAlign = 'center';
    ctx.textBaseline = 'middle';
    ctx.font = `${Math.round(GRID * 0.78)}px "Noto Sans", "Segoe UI", sans-serif`;
    ctx.fillText(ch, GRID / 2, GRID / 2);

    const data = ctx.getImageData(0, 0, GRID, GRID).data;
    const ink = new Uint8Array(GRID * GRID);
    let total = 0;
    let interior = 0;
    let border = 0;

    for (let i = 0, p = 0; i < ink.length; i++, p += 4) {
        const a = data[p + 3];
        ink[i] = a;
        if (a > 40) {
            total++;
            const x = i % GRID;
            const y = (i / GRID) | 0;
            const edge = x < GRID * 0.12 || x > GRID * 0.88 || y < GRID * 0.12 || y > GRID * 0.88;
            if (edge) border++; else interior++;
        }
    }

    const density = total / ink.length;
    if (density < INK_MIN) return null;

    // ⚠ Tofu rejection. A missing glyph renders as .notdef — a hollow rectangle — so nearly all of
    // its ink sits on the border and the middle is empty. Without this, a page whose fonts do not
    // cover Devanagari would proudly paint a box. Real letters always put ink in the middle.
    if (interior < border * 0.55) return null;

    // What one brush actually has to draw: how many separate runs of ink it crosses on its row.
    // Counted on the sweeps the pattern will really perform, so the measure and the rendering
    // cannot disagree.
    let segmentTotal = 0;
    let segmentPeak = 0;
    for (let r = 0; r < SCANS; r++) {
        const y = Math.round(((r + 0.5) / SCANS) * (GRID - 1));
        let crossings = 0;
        let prev = false;
        for (let x = 0; x < GRID; x++) {
            const on = ink[y * GRID + x] > 60;
            if (on !== prev) crossings++;
            prev = on;
        }
        const runs = Math.ceil(crossings / 2);
        segmentTotal += runs;
        if (runs > segmentPeak) segmentPeak = runs;
    }

    if (segmentTotal / SCANS > SEGMENTS_MEAN_MAX || segmentPeak > SEGMENTS_PEAK_MAX) return null;

    return { ink, size: GRID, density };
}

/**
 * A point drawn ON the ink, rather than guessed at until it lands there.
 *
 * 🔴 Rejection sampling was the obvious way and it fails exactly where it hurts. A caller tries a
 * few random spots, keeps the first that hits ink, and parks the point somewhere harmless when none
 * do — and a letter covers a tenth of its own box, so "none do" is common. Measured over 2200 draws
 * with 24 tries each, the share of points that never found ink: `i` 35 %, `l` 31 %, `a` 13 %, `e`
 * 10 %. Those points went to a small box at the centre, where a third of a cloud became a dense
 * ball sitting on top of the letter it was supposed to be drawing. Reported as "spheres with a dark
 * one in the middle".
 *
 * ⚠ The list, not a cumulative table over the whole grid: 64×64 floats per glyph is 16 KB, and the
 * memo holds up to three thousand. The lit pixels are a tenth of that and fit in a Uint16Array.
 *
 * ⚠ Every lit pixel weighs the same, antialiased edges included. Weighting by alpha would keep the
 * edge feathered; at a letter a hundred pixels tall, drawn with soft points, the difference is not
 * visible, and the cost of the difference is a second array.
 */
function picker(map) {
    const n = map.size;
    const lit = [];
    for (let i = 0; i < map.ink.length; i++) if (map.ink[i] > 40) lit.push(i);
    const idx = Uint16Array.from(lit);

    return (out) => {
        const at = idx[(Math.random() * idx.length) | 0];
        // Jittered inside its own pixel, or the glyph would read as a 64×64 grid of dots.
        out[0] = (((at % n) + Math.random()) / n - 0.5) * 2;
        out[1] = ((((at / n) | 0) + Math.random()) / n - 0.5) * 2;
    };
}

/** Ink at a continuous position, u and v in 0…1, bilinear so a sweep reads smoothly. */
function sampler(map) {
    const n = map.size;
    return (u, v) => {
        if (u < 0 || u > 1 || v < 0 || v > 1) return 0;
        const fx = u * (n - 1);
        const fy = v * (n - 1);
        const x0 = fx | 0, y0 = fy | 0;
        const x1 = x0 + 1 < n ? x0 + 1 : x0;
        const y1 = y0 + 1 < n ? y0 + 1 : y0;
        const tx = fx - x0, ty = fy - y0;
        const a = map.ink[y0 * n + x0], b = map.ink[y0 * n + x1];
        const c = map.ink[y1 * n + x0], d = map.ink[y1 * n + x1];
        return ((a + (b - a) * tx) * (1 - ty) + (c + (d - c) * tx) * ty) / 255;
    };
}

/**
 * Candidate characters, harvested from whatever text we can reach.
 *
 * `\p{L}` keeps letters and drops digits, punctuation and spaces across every script at once —
 * one rule, no table. Combining marks are excluded (`\p{M}`) because a mark drawn on its own is
 * not a letter, it is a floating accent.
 */
/**
 * 🔴 Everything derived from a source of strings is memoised against that source BY REFERENCE.
 *
 * Measured: entering the word or letter pattern cost **9 to 32 ms** — one to two dropped frames —
 * and it was not the rasterising, which is cached one level down. It was this: three thousand
 * strings split into tokens, with two Unicode property regexes run on **every character**, about a
 * hundred and eighty thousand tests, redone from nothing every single time a figure started.
 *
 * ⚠ None of it changes between two runs. The letters available change when a language bank lands,
 * and at no other moment — which is why the caller hands out a stable array rather than a fresh
 * copy, and why one weak reference is enough to know when this is stale.
 */
const derived = new WeakMap();

function derive(strings) {
    let d = derived.get(strings);
    if (d) return d;
    d = { chars: null, tokens: null };
    derived.set(strings, d);
    return d;
}

function harvest(strings) {
    const d = derive(strings);
    if (d.chars) return d.chars;

    const seen = new Set();
    for (const s of strings) {
        for (const ch of s) {
            if (/\p{L}/u.test(ch) && !/\p{M}/u.test(ch)) seen.add(ch);
        }
    }
    d.chars = [...seen];
    return d.chars;
}

let pageChars = null;

function fromPage() {
    if (pageChars) return pageChars;
    const text = (document.body.innerText || '').slice(0, 4000);
    pageChars = harvest([text]);
    return pageChars;
}

/**
 * Pick a glyph worth painting, trying at most `tries` candidates.
 *
 * `strings` is whatever the caller can offer — the language bank hands us sentences in several
 * languages, which is what makes a glyph from another alphabet turn up. When it has nothing yet we
 * fall back to the page, so the very first trace still works.
 */
export function pickGlyph(strings = [], tries = 14) {
    const pool = strings.length ? harvest(strings) : fromPage();
    if (!pool.length) return null;

    for (let i = 0; i < tries; i++) {
        const ch = pool[(Math.random() * pool.length) | 0];
        const glyph = glyphFor(ch);
        if (glyph) return glyph;
    }
    return null;
}

/**
 * Rasterised characters, kept.
 *
 * 🔴 The single most expensive thing this background ever did, and it was invisible because it hides
 * inside a pattern change rather than inside a frame loop. `rasterize` ends with `getImageData`,
 * which forces the 2D canvas pipeline to flush and hands the pixels back to the CPU — about a
 * millisecond a character. `pickWord` tries up to twenty tokens of up to five letters, so entering
 * the word pattern could ask for a hundred of those: **9 to 32 ms measured, every single time it
 * ran**, which is one to two dropped frames at 60 Hz.
 *
 * ⚠ And nothing about it needed repeating: the same character, the same font and the same grid give
 * the same ink map for the life of the page. **Failures are cached too** — a character this font
 * cannot paint is exactly the one the pickers keep drawing again out of the same pool.
 */
const glyphs = new Map();
/** Enough for every alphabet on the page several times over; a bound so a CJK-heavy page cannot
 *  grow this without limit. */
const GLYPH_MEMO_MAX = 3000;

function glyphFor(ch) {
    if (glyphs.has(ch)) return glyphs.get(ch);
    const map = rasterize(ch);
    const glyph = map
        ? { char: ch, inkAt: sampler(map), pointOn: picker(map), density: map.density }
        : null;
    // Dropping the oldest is right rather than clearing: the characters in play on a page are a
    // small set, and the ones that fall out are the ones nobody has asked for in a long time.
    if (glyphs.size >= GLYPH_MEMO_MAX) glyphs.delete(glyphs.keys().next().value);
    glyphs.set(ch, glyph);
    return glyph;
}

/**
 * Rasterise characters nobody has asked for yet, while the page has nothing better to do.
 *
 * ⚠ The memo above makes the SECOND word cheap; this is what makes the first one cheap. It spends a
 * strict budget and stops, so it can be called from an idle callback without becoming the jank it
 * exists to prevent.
 */
export function warmGlyphs(strings, budgetMs = 6) {
    const until = performance.now() + budgetMs;
    const pool = harvest(strings.length ? strings : [document.body.innerText || '']);
    for (let i = 0; i < pool.length; i++) {
        if (performance.now() > until) return false;
        glyphFor(pool[(Math.random() * pool.length) | 0]);
    }
    return true;
}

/**
 * A short run of letters that were written NEXT TO EACH OTHER in a real sentence.
 *
 * ⚠ Not the same thing as calling `pickGlyph` several times, and the difference is the whole point:
 * five unrelated characters read as debris, five consecutive ones read as a word. It will often be
 * a fragment rather than a whole word — a token gets trimmed to what five brushes can hold, and a
 * script without spaces has no tokens to speak of — but it is always a real sequence from a real
 * language, which is what the eye responds to.
 *
 * Characters that fail the paintability test are dropped rather than replaced, so the run stays
 * contiguous: skipping one and taking the next would silently rewrite the word.
 */
export function pickWord(strings = [], max = 5, tries = 20) {
    const source = strings.length ? strings : [(document.body.innerText || '').slice(0, 4000)];
    const d = derive(source);
    let tokens = d.tokens;
    if (!tokens) {
        tokens = [];
        for (const s of source) {
            for (const t of s.split(/\s+/)) {
                const letters = [...t].filter((c) => /\p{L}/u.test(c) && !/\p{M}/u.test(c));
                if (letters.length >= 2) tokens.push(letters);
            }
        }
        d.tokens = tokens;
    }
    if (!tokens.length) return null;

    for (let i = 0; i < tries; i++) {
        const token = tokens[(Math.random() * tokens.length) | 0];
        // A long token is cut at a random point rather than always at the start, so a language
        // without spaces does not always show the same opening syllables.
        const room = Math.min(max, token.length);
        const from = token.length > room ? (Math.random() * (token.length - room + 1)) | 0 : 0;

        const run = [];
        for (const ch of token.slice(from, from + room)) {
            const glyph = glyphFor(ch);
            if (!glyph) break;
            run.push(glyph);
        }
        if (run.length >= 2) return run;
    }
    return null;
}
