/**
 * Real channel separation, for the ambient glitch.
 *
 * ── Why a filter and not a shadow ──────────────────────────────────────────────────────────────
 * 🔴 The first version faked this with `drop-shadow(3px 0 cyan) drop-shadow(-3px 0 magenta)`. That
 * produces TINTED COPIES OF THE SILHOUETTE, all moving together — on a heading it reads as a
 * coloured outline, and on a photograph it is worse: a drop-shadow of a rectangular image is a
 * rectangle, so you get coloured bars beside the picture rather than any aberration in it.
 *
 * A signal that has lost channel sync does something else entirely: the red, green and blue images
 * drift APART, each by its own amount, and what you see between them is the picture itself, three
 * times, misregistered. There is exactly one way to get that in a browser — take the rendered
 * element apart with `feColorMatrix`, displace each channel with `feOffset`, and screen them back
 * together. It works the same on a photograph and on a line of text, because the filter operates on
 * what was drawn, not on what it was drawn from.
 *
 * ── Why several filters instead of one animated filter ─────────────────────────────────────────
 * Animating the primitives inside a filter means SMIL, which is deprecated in one of the two
 * browsers we care about. So the variants are STATIC and the CSS animation switches between them:
 * `filter: url(…)` is not interpolatable, so it snaps from one to the next — which is precisely the
 * behaviour wanted. A desynchronised signal does not ease between states.
 *
 * ⚠ The ids here and the ones in `@keyframes glitch-ping` (app.css) have to agree, and nothing can
 * check that for us: a `filter: url(#…)` pointing at nothing is not an error, it renders as no
 * filter at all. If the glitch ever goes flat, look here first.
 */

const NS = 'http://www.w3.org/2000/svg';

/**
 * Six states, read as a sequence: a first slip, a bigger one the other way, a vertical tear, a
 * recovery that fails, the widest split, then settling. Amplitudes are in CSS pixels.
 * Each row is [redX, redY, greenX, greenY, blueX, blueY].
 */
const VARIANTS = [
    [4, -1, 0, 1, -3, 0],
    [-5, 0, 1, -1, 4, 1],
    [2, 3, -2, 0, 1, -3],
    [-1, 0, 3, 1, -4, -1],
    [6, 1, 0, 0, -6, -1],
    [-2, -1, 1, 0, 2, 1],
];

export const FILTER_PREFIX = 'ugt-rgb-';

const KEEP_RED = '1 0 0 0 0  0 0 0 0 0  0 0 0 0 0  0 0 0 1 0';
const KEEP_GREEN = '0 0 0 0 0  0 1 0 0 0  0 0 0 0 0  0 0 0 1 0';
const KEEP_BLUE = '0 0 0 0 0  0 0 0 0 0  0 0 1 0 0  0 0 0 1 0';

function primitive(doc, name, attrs) {
    const el = doc.createElementNS(NS, name);
    for (const [k, v] of Object.entries(attrs)) el.setAttribute(k, v);
    return el;
}

function buildFilter(doc, index, [rx, ry, gx, gy, bx, by]) {
    const filter = primitive(doc, 'filter', {
        id: FILTER_PREFIX + index,
        // The channels move outside the element's own box, so the filter region has to be larger or
        // the displaced ones are clipped away and the split silently loses its edges.
        x: '-20%', y: '-20%', width: '140%', height: '140%',
        // Without this the maths happens in linear light and the recombination comes out darker
        // than the original — the glitch would dim the element as well as split it.
        'color-interpolation-filters': 'sRGB',
    });

    filter.append(
        primitive(doc, 'feColorMatrix', { type: 'matrix', values: KEEP_RED, result: 'r' }),
        primitive(doc, 'feColorMatrix', { in: 'SourceGraphic', type: 'matrix', values: KEEP_GREEN, result: 'g' }),
        primitive(doc, 'feColorMatrix', { in: 'SourceGraphic', type: 'matrix', values: KEEP_BLUE, result: 'b' }),
        primitive(doc, 'feOffset', { in: 'r', dx: rx, dy: ry, result: 'ro' }),
        primitive(doc, 'feOffset', { in: 'g', dx: gx, dy: gy, result: 'go' }),
        primitive(doc, 'feOffset', { in: 'b', dx: bx, dy: by, result: 'bo' }),
        primitive(doc, 'feBlend', { in: 'ro', in2: 'go', mode: 'screen', result: 'rg' }),
        primitive(doc, 'feBlend', { in: 'rg', in2: 'bo', mode: 'screen' }),
    );
    return filter;
}

let installed = false;

/**
 * Put the filter definitions in the page. Called once, and only when the glitch is going to run —
 * a reader who asked for less motion never gets this markup at all.
 */
export function installRgbSplit() {
    if (installed || document.getElementById(FILTER_PREFIX + '0')) return;
    installed = true;

    const svg = document.createElementNS(NS, 'svg');
    svg.setAttribute('aria-hidden', 'true');
    svg.setAttribute('focusable', 'false');
    // Zero-sized and out of the way: this element is a definition, never a picture.
    svg.setAttribute('width', '0');
    svg.setAttribute('height', '0');
    svg.style.position = 'absolute';

    const defs = document.createElementNS(NS, 'defs');
    VARIANTS.forEach((v, i) => defs.appendChild(buildFilter(document, i, v)));
    svg.appendChild(defs);
    document.body.appendChild(svg);
}

export const VARIANT_COUNT = VARIANTS.length;
