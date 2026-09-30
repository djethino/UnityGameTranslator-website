/**
 * `node --test resources/js/rules/` — a highlight never cuts what a reader sees as one character.
 */
import test from 'node:test';
import assert from 'node:assert/strict';
import { snapToGraphemes } from './graphemes.js';

const slice = (text, span) => text.slice(span.start, span.end);

test('a match inside a Hindi conjunct highlights the whole conjunct', () => {
    const text = 'क्षमा करें';
    // "क" is the first code unit of क्ष: the mark would cut the conjunct in two
    assert.equal(slice(text, snapToGraphemes(text, 0, 1)), 'क्ष');
    // the virama alone, in the middle
    assert.equal(slice(text, snapToGraphemes(text, 1, 2)), 'क्ष');
});

test('a match on whole characters is left as it is', () => {
    const text = 'क्षमा करें';
    const at = text.indexOf('करें');
    assert.deepEqual(snapToGraphemes(text, at, at + 'करें'.length), { start: at, end: at + 'करें'.length });
    assert.deepEqual(snapToGraphemes('Hello world', 6, 11), { start: 6, end: 11 });
});

test('a Thai vowel over its consonant goes with it', () => {
    const text = 'ที่นี่';
    // the tone mark alone is part of its cluster
    const span = snapToGraphemes(text, 2, 3);
    assert.equal(slice(text, span), 'ที่');
});

test('an emoji made of several code points is never split', () => {
    const text = 'ok 👍🏽 go';
    const at = text.indexOf('👍');
    assert.equal(slice(text, snapToGraphemes(text, at, at + 2)), '👍🏽');
});
