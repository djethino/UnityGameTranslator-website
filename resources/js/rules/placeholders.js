/**
 * What a game will accept back from a person typing in the editor — the placeholder rule.
 *
 * 🔴 **A port of `common/Placeholders.cs`, held to the same cases.** The mod refuses an edit
 * that breaks a placeholder; until 2026-09-11 the site only warned, on one of the four token
 * families, compared to the wrong reference. The rule now lives here in the same shape, and
 * `resources/corpus/rules/placeholders.json` — the file the C# and the PHP answer too — is what
 * keeps the three from drifting again (`node --test resources/js/rules/`).
 *
 * ⚠ **Pure by contract**: strings in, answers out. No DOM, no Alpine, no import — that is what
 * lets Node run it as it is, and what keeps a rule from growing a screen. The editor imports it;
 * the messages a person reads are composed by the editor from the structured answers, in the
 * page's language; the English lines in `errors` are the corpus's, verbatim.
 *
 * A game's text carries technical placeholders — [!v*0] for a value, [!t*0] for a tag,
 * [!STR*0] for a nested string, [!nl] for a line break. The game substitutes them at runtime,
 * so one lost, duplicated or invented token is a line that breaks, whoever wrote it.
 */

/** Every frozen token: [!v*N], [!t*N], [!STR*N], [!nl]. One pattern, written once. */
const TOKEN = /\[!(?:v\*\d+|t\*\d+|STR\*\d+|nl)\]/g;

/** Every placeholder in a text, in order, repeats included. */
export function tokens(text) {
    if (!text) return [];
    return Array.from(text.matchAll(TOKEN), (m) => m[0]);
}

/** How many times each placeholder appears. */
export function tally(text) {
    const counts = {};
    for (const token of tokens(text)) counts[token] = (counts[token] ?? 0) + 1;
    return counts;
}

/**
 * Each placeholder together with the delimiters the game wrapped around it, e.g. "({[!v*0]})",
 * once each, in order of first appearance. Expanding left over opening characters only and right
 * over closing ones cannot swallow a neighbour, since every token starts with '[' and ends with
 * ']'. These sequences have to come back verbatim: keeping the token and losing the bracket the
 * game put around it still breaks the line.
 */
export function frozenSequences(source) {
    const sequences = [];
    if (!source) return sequences;
    for (const match of source.matchAll(TOKEN)) {
        let start = match.index;
        let end = match.index + match[0].length;
        // Outward one PAIR at a time: a bracket the game wrapped around the token sits on both
        // sides of it — "({[!v*0]})". A bracket on one side only belongs to the sentence:
        // "boltcutters ([!v*0] off)" wraps a phrase, which moves with the language.
        while (start > 0 && end < source.length && wraps(source[start - 1], source[end])) {
            start--;
            end++;
        }
        const sequence = source.slice(start, end);
        if (!sequences.includes(sequence)) sequences.push(sequence);
    }
    return sequences;
}

/** An opening bracket and the closing one that answers it. */
function wraps(before, after) {
    return (before === '(' && after === ')') || (before === '{' && after === '}') || (before === '[' && after === ']');
}

/**
 * The tokens an answer carries that its source never had, once each. Asked where the strict gate
 * is not: a source with no placeholder has nothing to keep, so nothing else checks its answer.
 */
export function invented(source, translation) {
    const found = [];
    if (!translation) return found;
    const inSource = tally(source ?? '');
    for (const token of tokens(translation)) {
        if (token in inSource) continue;
        if (!found.includes(token)) found.push(token);
    }
    return found;
}

/** Non-overlapping occurrences, like the C#'s IndexOf loop. */
function occurrences(text, sequence) {
    if (!sequence) return 0;
    let count = 0;
    let index = 0;
    while ((index = text.indexOf(sequence, index)) >= 0) {
        count++;
        index += sequence.length;
    }
    return count;
}

/**
 * What is wrong with a translation somebody typed here, as facts a screen can word in its own
 * language: the frozen sequences verbatim, then the tokens as the same multiset — nothing
 * missing, duplicated or invented. Empty when the edit is acceptable.
 *
 * ⚠ One check a MODEL is held to and a person is not: the count of brackets over the whole text.
 * It catches a model wrapping a placeholder in a pair of its own, which the frozen sequences
 * already cover for the placeholders; applied to a person it would refuse "Save" → "Save [F5]",
 * which is theirs to make and breaks nothing.
 *
 * ⚠ An empty edit has no problem whatever the source holds: it is a capture, not a translation —
 * the game shows its source and substitutes nothing, so there is no placeholder to keep. Held to
 * the rule, an untranslated line opened its modal on every marker "missing" before a word was typed.
 *
 * @returns {Array<{kind: 'sequence', sequence: string} | {kind: 'count', token: string, found: number, expected: number} | {kind: 'invented', token: string}>}
 */
export function editProblems(source, edited) {
    source ??= '';
    edited ??= '';
    const problems = [];
    if (edited === '') return problems;

    for (const sequence of frozenSequences(source)) {
        if (occurrences(edited, sequence) < occurrences(source, sequence)) {
            problems.push({ kind: 'sequence', sequence });
        }
    }

    const inSource = tally(source);
    const inAnswer = tally(edited);
    for (const [token, expected] of Object.entries(inSource)) {
        const found = inAnswer[token] ?? 0;
        if (found !== expected) problems.push({ kind: 'count', token, found, expected });
    }

    for (const token of invented(source, edited)) problems.push({ kind: 'invented', token });

    return problems;
}

/**
 * Whether a game would accept a translation somebody typed here — the gate the corpus holds,
 * with its lines worded as the C# words them (a model receives those verbatim; a person reads
 * the page's own wording, composed from `editProblems`).
 *
 * @returns {{accepted: boolean, errors: string[]}}
 */
export function acceptsEdit(source, edited) {
    const errors = editProblems(source, edited).map((problem) => {
        switch (problem.kind) {
            case 'sequence': return `the exact sequence "${problem.sequence}" is missing or altered`;
            case 'count': return `token ${problem.token} appears ${problem.found} time(s) instead of ${problem.expected}`;
            default: return `token ${problem.token} does not exist in the source`;
        }
    });
    return { accepted: errors.length === 0, errors };
}
