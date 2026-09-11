/**
 * The editor's executor of the shared corpus — `node --test resources/js/rules/`, no framework,
 * like every other Checks project here.
 *
 * 🔴 Which operations the editor must hold is the manifest's business (`sides.editor` in
 * resources/corpus/manifest.json): this file fails when a listed operation has no dispatch, and
 * when a dispatch is not listed. The cases are the specification; the C# and the PHP answer the
 * same file. See common/corpus/README.md.
 */
import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import * as placeholders from './placeholders.js';

const here = path.dirname(fileURLToPath(import.meta.url));
const corpus = path.resolve(here, '..', '..', 'corpus');
const read = (relative) => JSON.parse(fs.readFileSync(path.join(corpus, relative), 'utf8'));

/** rule/op → what the EDITOR answers. One line per operation, calling the rule and nothing else. */
const held = {
    'placeholders/frozen_sequences': (i) => placeholders.frozenSequences(i.source ?? ''),
    'placeholders/accepts_edit': (i) => placeholders.acceptsEdit(i.source ?? '', i.edited ?? ''),
    'placeholders/tokens': (i) => placeholders.tokens(i.text ?? ''),
    'placeholders/invented': (i) => placeholders.invented(i.source ?? '', i.translation),
    'placeholders/tally': (i) => placeholders.tally(i.text ?? ''),
};

const manifest = read('manifest.json');
const listed = [];
for (const [rule, ops] of Object.entries(manifest.sides?.editor ?? {})) {
    for (const op of ops) listed.push(`${rule}/${op}`);
}

test('the manifest and the dispatch name the same operations', () => {
    const dispatched = Object.keys(held);
    assert.deepEqual(listed.filter((id) => !dispatched.includes(id)), [],
        'LISTED FOR THE EDITOR, NOT HELD: the manifest says the editor answers these and nothing here does');
    assert.deepEqual(dispatched.filter((id) => !listed.includes(id)), [],
        'HELD, NOT LISTED: an operation answered here that the manifest does not know the editor holds');
    assert.ok(listed.length > 0, 'the editor holds nothing: the manifest was mis-read');
});

/**
 * The corpus's comparison, as the C# executor does it: scalars exact, lists exact in length and
 * order, objects PARTIAL — the keys the case names must match, the others are not judged.
 */
function agrees(expected, actual) {
    if (expected === null) return actual === null ? '' : `expected null, got ${show(actual)}`;
    if (Array.isArray(expected)) {
        if (!Array.isArray(actual)) return `expected a list, got ${show(actual)}`;
        if (actual.length !== expected.length) return `expected ${expected.length} item(s), got ${actual.length}: ${show(actual)}`;
        for (let i = 0; i < expected.length; i++) {
            const inner = agrees(expected[i], actual[i]);
            if (inner) return `item ${i}: ${inner}`;
        }
        return '';
    }
    if (typeof expected === 'object') {
        if ('approx' in expected) {
            const tol = expected.tol ?? 1e-9;
            return Math.abs(actual - expected.approx) <= tol ? '' : `expected ≈${expected.approx}, got ${show(actual)}`;
        }
        if (actual === null || typeof actual !== 'object' || Array.isArray(actual)) return `expected an object, got ${show(actual)}`;
        for (const [key, value] of Object.entries(expected)) {
            if (!(key in actual)) return `no '${key}' in ${show(actual)}`;
            const inner = agrees(value, actual[key]);
            if (inner) return `'${key}': ${inner}`;
        }
        return '';
    }
    if (typeof expected === 'number') {
        return typeof actual === 'number' && Math.abs(actual - expected) < 1e-9 ? '' : `expected ${expected}, got ${show(actual)}`;
    }
    return actual === expected ? '' : `expected ${show(expected)}, got ${show(actual)}`;
}

const show = (value) => JSON.stringify(value);

for (const [rule, ops] of Object.entries(manifest.sides?.editor ?? {})) {
    const file = read(`rules/${rule}.json`);
    const byId = Object.fromEntries(file.cases.map((c) => [c.id, c]));

    for (const op of ops) {
        const id = `${rule}/${op}`;
        const call = held[id];
        const cases = file.cases.filter((c) => c.op === op);

        test(`${id} has a dispatch and cases`, () => {
            assert.ok(call, `${id} has no dispatch`);
            assert.ok(cases.length > 0, `${id} has no case in the corpus`);
        });
        if (!call) continue;

        for (const c of cases) {
            test(c.id, () => {
                assert.ok('out' in c, `${c.id}: the case has no 'out'`);
                const actual = call(c.in ?? {});
                const out = c.out;

                if (out && typeof out === 'object' && !Array.isArray(out) && 'same_as' in out) {
                    const other = byId[out.same_as];
                    assert.ok(other, `${c.id}: same_as names an unknown case '${out.same_as}'`);
                    const otherCall = held[`${rule}/${other.op}`];
                    assert.ok(otherCall, `${c.id}: same_as case '${other.id}' is an operation the editor does not hold`);
                    const detail = agrees(otherCall(other.in ?? {}), actual);
                    assert.equal(detail, '', `${c.id} — expected the answer of ${other.id}: ${detail} — ${c.why}`);
                    return;
                }
                if (out && typeof out === 'object' && !Array.isArray(out)) {
                    for (const relation of ['above', 'below', 'level']) {
                        if (!(relation in out)) continue;
                        const rhs = call(out[relation]);
                        const holds = relation === 'above' ? actual > rhs : relation === 'below' ? actual < rhs : actual === rhs;
                        assert.ok(holds, `${c.id} — expected ${actual} ${relation} ${rhs} — ${c.why}`);
                        return;
                    }
                }

                const detail = agrees(out, actual);
                assert.equal(detail, '', `${c.id} — ${detail} — ${c.why}`);
            });
        }
    }
}
