/**
 * `node --test resources/js/rules/` — the direction rule the editor shows lines by. Cases taken
 * from real files; the blocks are the mod's (RtlText.IsStrongRtl), so a case that fails here is a
 * line the two products would show differently.
 */
import test from 'node:test';
import assert from 'node:assert/strict';
import { directionOf, isStrongRtl, markupSpans } from './direction.js';
import { frozenSpans, frozenSequences } from './placeholders.js';

test('one right-to-left letter makes the line right to left', () => {
    assert.equal(directionOf('كنتُ في [!v*0] من العمر عندما توفيتَ.'), 'rtl');
    // a line OPENING with a placeholder: the first strong letter is the "v" of [!v*0], which is
    // exactly where dir="auto" goes wrong
    assert.equal(directionOf('[!v*0] نقطة (ضربة جانبية).'), 'rtl');
    assert.equal(directionOf('<color=#8C8C8C>שלום</color>'), 'rtl');
    assert.equal(directionOf('ﻣﺮﺣﺒﺎ'), 'rtl', 'already-shaped Arabic reads right to left too');
});

test('everything else stays left to right', () => {
    for (const text of ['I was [!v*0] years old when you died.', '[!v*0] points (side hit).', 'ฝรั่งเศส',
        'हिन्दी', '简体中文', 'Привет', '', null, undefined]) {
        assert.equal(directionOf(text), 'ltr', String(text));
    }
});

test('the blocks are the mod\'s', () => {
    assert.equal(isStrongRtl(0x05D0), true);   // Hebrew alef
    assert.equal(isStrongRtl(0x0627), true);   // Arabic alef
    assert.equal(isStrongRtl(0x0750), true);   // Arabic Supplement
    assert.equal(isStrongRtl(0x08A0), true);   // Arabic Extended-A
    assert.equal(isStrongRtl(0x0710), false);  // Syriac: left out, as in the mod
    assert.equal(isStrongRtl(0x0041), false);
});

test('markup tags are found whole', () => {
    const text = '<color=#8C8C8C>劳土全</color> <sprite="buff" name=sum>';
    assert.deepEqual(markupSpans(text).map(({ start, end }) => text.slice(start, end)),
        ['<color=#8C8C8C>', '</color>', '<sprite="buff" name=sum>']);
    assert.deepEqual(markupSpans('a < b'), []);
});

test('frozen spans are the frozen sequences, every occurrence', () => {
    const text = 'Add {[!v*0]} Power, then {[!v*0]} more, and [!nl]';
    assert.deepEqual(frozenSpans(text).map(({ start, end }) => text.slice(start, end)), ['{[!v*0]}', '{[!v*0]}', '[!nl]']);
    assert.deepEqual(frozenSequences(text), ['{[!v*0]}', '[!nl]']);
});
