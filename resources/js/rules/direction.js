/**
 * Which way a line of a game reads — the rule the editor shows every key and value by.
 *
 * 🔴 **The mod's rule, not a new one: `RtlText.IsStrongRtl` / `NeedsPresentation`**
 * (UnityGameTranslator.Core/TextShaping/RtlText.cs). One strong right-to-left letter in the line
 * and the whole line reads right to left. The game showed an Arabic line correctly while the
 * editor showed the same stored text with its halves swapped: the cells had no direction of their
 * own, so they took the PAGE's, which follows the site's interface language — and no interface
 * language can be right for a table mixing an English key and an Arabic value. The direction is
 * therefore decided per line, from its content, whatever language the site is shown in.
 *
 * ⚠ Not `dir="auto"`: it goes by the FIRST strong character, which is the "v" of `[!v*0]` when a
 * line opens with a placeholder — the very case that exposed the bug.
 *
 * ⚠ Blocks, never languages, and the same blocks as the mod: Hebrew, Arabic and its supplements.
 * Kept identical on purpose so the two products cannot disagree about one line. Syriac, Thaana
 * and N'Ko are right-to-left too and are left out by the mod as well — widening one side alone
 * would recreate the disagreement; widen both, or neither (TODO.md).
 *
 * ⚠ **Pure by contract** like `placeholders.js`: strings in, answers out, no DOM, no import.
 */

/** A strong right-to-left letter in base form (UTF-16 code unit), block by block as the mod. */
export function isStrongRtl(code) {
    if (code < 0x0590) return false;                     // fast path: Latin & co.
    if (code <= 0x05FF) return true;                     // Hebrew
    if (code >= 0x0600 && code <= 0x06FF) return true;   // Arabic
    if (code >= 0x0750 && code <= 0x077F) return true;   // Arabic Supplement
    if (code >= 0x0870 && code <= 0x08FF) return true;   // Arabic Extended-B + A
    return false;
}

/**
 * 'rtl' when the text holds one strong right-to-left letter, 'ltr' otherwise.
 *
 * ⚠ The mod also counts the presentation forms (FB1D–FDFF, FE70–FEFF) as "already shaped, do not
 * shape again" — a question about shaping that a browser never asks. For DIRECTION they are right
 * to left all the same, so they are included here: an already-shaped line must not be shown
 * backwards either.
 */
export function directionOf(text) {
    if (!text) return 'ltr';
    const value = String(text);
    for (let i = 0; i < value.length; i++) {
        const code = value.charCodeAt(i);
        if (isStrongRtl(code)) return 'rtl';
        if ((code >= 0xFB1D && code <= 0xFDFF) || (code >= 0xFE70 && code <= 0xFEFF)) return 'rtl';
    }
    return 'ltr';
}

/**
 * The game's markup tags as written in a line — `<color=#8C8C8C>`, `</b>`, `<sprite="x" name=y>`.
 * Each is shown as a left-to-right island, like a placeholder: inside an Arabic line its letters
 * would otherwise be reordered around the words.
 */
const MARKUP = /<\/?[A-Za-z][^<>]*>/g;

export function markupSpans(text) {
    const spans = [];
    if (!text) return spans;
    for (const match of String(text).matchAll(MARKUP)) spans.push({ start: match.index, end: match.index + match[0].length });
    return spans;
}
