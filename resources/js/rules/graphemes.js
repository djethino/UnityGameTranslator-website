/**
 * Where a highlight may start and end inside a line: never in the middle of what the reader sees as
 * one character. A search match can fall inside a Hindi conjunct (क inside क्ष), a Thai vowel stack or
 * an emoji; a <mark> cut there splits the cluster into two pieces of HTML, and the browser may then
 * draw the pieces apart (the letters unjoined, the virama visible). So a match is widened to the whole
 * user-perceived characters it touches — the extended grapheme clusters of Unicode, which since 15.1
 * hold an Indic conjunct together — the same unit the mod's input fields move the caret by
 * (analyse/ecritures-complexes-etat-reel.md, "Conventions").
 *
 * Intl.Segmenter is in every browser the site supports; where it is missing, the match is kept as is.
 */

let segmenter;
function graphemes() {
    if (segmenter === undefined) {
        segmenter = typeof Intl !== 'undefined' && Intl.Segmenter
            ? new Intl.Segmenter(undefined, { granularity: 'grapheme' })
            : null;
    }
    return segmenter;
}

/** The start index of every grapheme cluster of `text`, and its length. */
export function graphemeBoundaries(text) {
    const s = graphemes();
    const value = String(text ?? '');
    if (!s) return null;
    const bounds = [];
    for (const piece of s.segment(value)) bounds.push(piece.index);
    bounds.push(value.length);
    return bounds;
}

/**
 * [start, end) widened to the grapheme clusters it touches: start moves back to the start of its
 * cluster, end forward to the end of the cluster holding its last character.
 */
export function snapToGraphemes(text, start, end) {
    const bounds = graphemeBoundaries(text);
    if (!bounds || end <= start) return { start, end };
    let s = start, e = end;
    for (const b of bounds) {
        if (b <= start) s = b;
        if (b >= end) { e = b; break; }
    }
    return { start: s, end: e };
}
