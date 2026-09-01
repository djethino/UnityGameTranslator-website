/**
 * What is visible and worth touching, right now.
 *
 * 🔴 This replaces a hand-written list of CSS classes, and the replacement is the whole point. That
 * list had six selectors and FOUR OF THEM MATCHED NOTHING on the entire site — `img.game-card-image`
 * and `.badge` named classes that do not exist, `.translation-card img` named a Blade component as
 * if it were a class, and `[data-glitch-target]` was an extension point nobody ever used. Nothing
 * could catch that: a selector matching nothing does not fail, it just quietly does nothing, and
 * the effect had been dead on every page but three for months.
 *
 * So there is no allowlist here. Candidates are found by **what an element is** — a picture, a
 * heading, a line of text — using tag names and measured geometry, never a class name someone has
 * to remember to add. A screen written next year is covered on the day it is written.
 *
 * What IS written down is the refusal list, and it is short because refusals are rare: the
 * editors, form controls, and anything a template marks `data-no-glitch`. Annotating the exceptions
 * is maintainable; annotating the rule is not.
 *
 * Three callers share this one scan: the visual glitch, the language glitch, and the bob that goes
 * off to circle something. One question, one answer — three scanners would drift.
 */

// Anything under one of these is out of bounds. `[data-no-glitch]` is the escape hatch a template
// can use; the rest are structural — a translation editor showing real user data must never appear
// to corrupt it, and a form field must never have its contents rewritten under someone's hands.
const FORBIDDEN = 'input, textarea, select, option, [contenteditable], [data-no-glitch], [aria-live], dialog, [role="dialog"]';

// Things that are visually substantial by nature, not by class.
const VISUAL = 'img, svg, picture, figure, h1, h2, h3, h4, [role="img"]';

// Things that plausibly hold one short label or sentence.
const TEXTUAL = 'h1, h2, h3, h4, h5, h6, a, button, span, strong, em, label, th, td, li, p, dt, dd, summary, figcaption';

const MIN_W = 40, MIN_H = 22;

/** Longer than this and it stops being a wink and starts being a paragraph rearranging itself. */
const MAX_TEXT = 40;

let cache = null;
let cacheAt = 0;

/**
 * How long a scan stays usable — a backstop, not the real rule.
 *
 * ⚠ It used to be 400 ms, which meant a scan warmed ahead of time had gone stale before it was
 * needed. But time is not what invalidates this: the page's text does not move on its own. **What
 * moves it is the reader scrolling**, and that is an event we can listen for. So the age limit is
 * now only there to catch what we do not observe — a menu opening, an image finishing loading —
 * and the real invalidation is below.
 */
const CACHE_MS = 3000;

let quiet = null;
function stale() {
    cache = null;
    // Re-warm once the movement stops, rather than on every scroll event: mid-scroll the answer
    // would be wrong again by the next frame.
    clearTimeout(quiet);
    quiet = setTimeout(warm, 220);
}
window.addEventListener('scroll', stale, { passive: true });
window.addEventListener('resize', stale, { passive: true });

/**
 * Do the scan NOW if it is about to be needed, so that whoever needs it does not pay for it.
 *
 * 🔴 Measured on the documentation page (12 897 elements): a glitch that finds the cache warm costs
 * **0.2 ms**, and one that has to scan costs **11.3 ms** — a whole frame at 60 Hz, dropped, every
 * time. And the cache never WAS warm: it lives four hundred milliseconds and the glitches are
 * twenty seconds apart, so every single one paid full price.
 *
 * ⚠ The scan cannot be made cheap — it walks the document and calls `getClientRects`, which forces
 * the browser to lay the page out there and then. What it can be is **early**: the orchestra knows
 * when it intends to fire and calls this a second or so beforehand, in idle time, where eleven
 * milliseconds cost nobody anything. That is the whole trick, and it is the oldest one there is —
 * do the work in the blanking interval, not while the beam is drawing.
 *
 * ⚠ Never on a hidden page: the scan would be laying out a document nobody is looking at, and the
 * positions would be stale by the time anyone was.
 */
export function warm() {
    if (document.hidden) return;
    const idle = window.requestIdleCallback || ((fn) => setTimeout(fn, 0));
    idle(() => {
        if (document.hidden) return;
        cache = null;      // force it: a cache 399 ms old would be refused a moment from now
        scan();
    });
}

function inViewport(rect) {
    return rect.width > 0 && rect.height > 0
        && rect.top < window.innerHeight && rect.bottom > 0
        && rect.left < window.innerWidth && rect.right > 0;
}

function allowed(el) {
    return !el.closest(FORBIDDEN);
}

function scan() {
    const now = performance.now();
    if (cache && now - cacheAt < CACHE_MS) return cache;

    const visual = [];
    const textual = [];

    for (const el of document.querySelectorAll(VISUAL)) {
        const rect = el.getBoundingClientRect();
        if (rect.width < MIN_W || rect.height < MIN_H) continue;
        if (!inViewport(rect) || !allowed(el)) continue;
        visual.push(el);
    }

    // 🔴 Text NODES, not elements, and this is the difference between working and not working.
    //
    // The first version took only leaf elements, on the reasoning that rewriting the text of a
    // container would destroy the markup inside it. True — and it excluded most of the site.
    // Measured on /docs: 5 candidates in the body of the page against 16 in the menus, because
    // almost every heading there carries an icon:
    //
    //     <h3><i class="fas…"></i> Install a mod loader</h3>   →  not a leaf
    //     <p>… <a>a link</a> …</p>                             →  not a leaf
    //
    // The right unit was never the element. In that heading the string `Install a mod loader` is a
    // text node of its own, and it is exactly one of our translated values — so we take the node,
    // leave its siblings alone, and the icon never knows. Body candidates went from 5 to 13.
    const range = document.createRange();
    for (const el of document.querySelectorAll(TEXTUAL)) {
        if (!allowed(el)) continue;
        for (const node of el.childNodes) {
            if (node.nodeType !== Node.TEXT_NODE) continue;
            const text = node.nodeValue.trim();
            if (!text || text.length > MAX_TEXT) continue;

            range.selectNodeContents(node);
            const rects = range.getClientRects();
            // ⚠ One rect means one line. A run that wraps gets two, and the overlay — positioned
            // against a single box — would sit across both. Cheaper and more exact than measuring
            // line heights, and it is the same call that gives us the geometry.
            if (rects.length !== 1) continue;

            const rect = rects[0];
            if (!inViewport(rect) || rect.width < 8) continue;

            // Landmarks, from the HTML itself rather than from a class name we hope is there. The
            // navigation is dense with short labels, every one of them a translated string, and it
            // stays on screen while the article scrolls past — so a uniform draw lands on it far
            // more often than on what the reader is actually reading. Flagged here, weighted below.
            const chrome = !!el.closest('nav, header, footer');
            textual.push({ node, text, rect, chrome });
        }
    }

    cache = { visual, textual };
    cacheAt = now;
    return cache;
}

/**
 * Is this element actually the thing you would touch at that point, or is something on top of it?
 *
 * ⚠ Geometry is not visibility. An element can sit squarely inside the viewport and be behind a
 * modal, clipped by an `overflow: hidden` ancestor, or covered by a sticky header — and the old
 * code, which tested only its rectangle, would happily glitch it where nobody could see. One
 * hit-test answers all three at once, and doing it on the CHOSEN candidate rather than on every
 * candidate keeps it to a handful of calls.
 */
function onTop(el, rect) {
    const r = rect || el.getBoundingClientRect();
    const x = Math.min(window.innerWidth - 1, Math.max(0, r.left + r.width / 2));
    const y = Math.min(window.innerHeight - 1, Math.max(0, r.top + r.height / 2));
    const hit = document.elementFromPoint(x, y);
    return !!hit && (hit === el || el.contains(hit) || hit.contains(el));
}

/**
 * Shuffle, optionally biasing against the page chrome.
 *
 * Sorting on random^(1/w) descending draws without replacement in exact proportion to w — one
 * expression, no rejection loop — so a weight below 1 thins the navigation, header and footer out
 * instead of banning them. They should still be touched sometimes; they should not be most of it.
 */
function shuffle(pool, chromeWeight, isChrome) {
    return pool
        .map((item) => ({ item, key: Math.pow(Math.random(), 1 / (isChrome(item) ? chromeWeight : 1)) }))
        .sort((a, b) => b.key - a.key)
        .map((x) => x.item);
}

/**
 * Take `count` of them, spread across the view.
 *
 * ⚠ `minGap` is the whole reason this is not a loop around a single-pick function. Drawn
 * independently, four picks land wherever the candidates happen to be densest — which reads as one
 * corner of the page misbehaving rather than as something happening across it. Rejecting a
 * candidate within `minGap` of one already chosen costs a distance check and buys the spread.
 *
 * 🟢 Shared by both glitches on purpose: "several things at once, far enough apart, not hidden
 * behind anything" is one question, and two copies of it would drift.
 */
function chooseSpread(pool, count, { minGap = 0, accept, rectOf, elOf }) {
    const chosen = [];
    const spots = [];

    for (const item of pool) {
        if (chosen.length >= count) break;
        if (accept && !accept(item)) continue;

        // 🔴 Asked again, at the moment of choosing. The scan decided this element was allowed up
        // to three seconds ago, and three seconds is long enough for a page to have opened a
        // dialog over it, started an `aria-live` region, or turned itself into a quiet screen. The
        // scan's job is to find candidates cheaply; deciding that a candidate may still be touched
        // is a different question and it has to be asked now.
        //
        // ⚠ It costs one `closest()` per candidate considered — a handful per glitch, against a
        // full re-scan of the document. That is why the cache may be long-lived at all.
        const el = elOf(item);
        if (!el || !el.isConnected || !allowed(el)) continue;

        const r = rectOf(item);
        const cx = r.left + r.width / 2;
        const cy = r.top + r.height / 2;
        if (spots.some((s) => Math.hypot(s.x - cx, s.y - cy) < minGap)) continue;

        // Left last: it is the only expensive test, so it runs on what survived everything else.
        if (!onTop(el, r)) continue;

        chosen.push(item);
        spots.push({ x: cx, y: cy });
    }

    return chosen;
}

const isChromeEl = (el) => !!el.closest('nav, header, footer');

/** A picture or a heading, on screen and not hidden behind anything. */
export function pickVisual() {
    return pickVisualMany(1)[0] || null;
}

/** Several of them at once, spread across the view — the elements themselves, not wrappers. */
export function pickVisualMany(count, { minGap = 0, accept, chromeWeight = 1 } = {}) {
    return chooseSpread(shuffle(scan().visual, chromeWeight, isChromeEl), count, {
        minGap,
        accept,
        rectOf: (el) => el.getBoundingClientRect(),
        elOf: (el) => el,
    });
}

/**
 * Several runs of text at once — `{ node, text, rect, chrome }` — spread across the view.
 *
 * ⚠ `minGap` is the whole reason this is not a loop around a single-pick function. Drawn
 * independently, four picks land in whatever paragraph happens to hold the most candidates — which
 * reads as one glitchy paragraph rather than as something happening across the page. Rejecting a
 * candidate within `minGap` of one already chosen costs a distance check and buys the spread.
 *
 * `accept` lets the caller refuse a candidate for its OWN reasons — the language glitch only wants
 * text it can find in its index — without this file having to know what those reasons are.
 *
 * `chromeWeight` below 1 thins out the navigation, header and footer. See the scan for why they
 * would otherwise dominate.
 */
export function pickTextualMany(count, { minGap = 0, accept, chromeWeight = 1 } = {}) {
    return chooseSpread(shuffle(scan().textual, chromeWeight, (item) => item.chrome), count, {
        minGap,
        accept,
        rectOf: (item) => item.rect,
        elOf: (item) => item.node.parentElement,
    });
}

/** Something for a bob to circle. Same pool as the visual glitch: it is the same question. */
export function pickAnchor() {
    return pickVisual();
}

/** Everything currently readable on screen, for harvesting glyph candidates. */
/**
 * ⚠ Attached to the scan so the SAME array comes back while the scan stands. The letter patterns
 * memoise their tokenising against this array by reference, and a fresh copy each call would look
 * harmless while quietly making every cache downstream useless.
 */
export function visibleStrings() {
    const s = scan();
    if (!s.strings) s.strings = s.textual.map((t) => t.text);
    return s.strings;
}
