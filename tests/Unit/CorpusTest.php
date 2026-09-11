<?php

namespace Tests\Unit;

use App\Models\Translation;
use App\Services\TranslationService;
use App\Support\Placeholders;
use PHPUnit\Framework\TestCase;

/**
 * The site's executor of the shared corpus: resources/corpus/, the rules every product must
 * answer alike, as JSON cases — the mod and the Manager answer them in C#, the site answers the
 * ones it re-implements here, in PHP.
 *
 * 🔴 **Which ones is the manifest's business, not this file's.** `sides.site` in
 * corpus/manifest.json lists the operations the site must hold; this test fails when one of them
 * has no dispatch below, and when a dispatch exists that the manifest does not list. A rule
 * absent from one side is the defect no case can see — the placeholder rule was one — and the
 * manifest is the one place that says what the site holds.
 *
 * ⚠ The copy is put here by sync-common.ps1 and refused by check-spec.py when it diverges. The
 * cases are the specification: a PHP answer that differs is wrong even if the site has always
 * given it, and then the case, or the code, is changed on purpose — see corpus/README.md.
 */
class CorpusTest extends TestCase
{
    /**
     * rule/op → what the SITE answers. One line per operation, calling site code and nothing
     * else: this table is the only PHP that knows the corpus exists.
     *
     * @return array<string, callable(array): mixed>
     */
    private static function operations(): array
    {
        return [
            'sync/content_hash' => fn (array $in) => (new TranslationService())
                ->computeHash(($in['lines'] ?? []) + ['_uuid' => $in['uuid'] ?? '']),
            'settings/all' => fn (array $in) => Translation::SETTINGS_SECTIONS,
            'settings/json_key' => fn (array $in) => TranslationService::sectionKey($in['section']),
            'settings/section_of' => fn (array $in) => TranslationService::sectionOf($in['json_key']),
            'merge/priority_of' => fn (array $in) => TranslationService::priorityOf($in['tag'] ?? null, $in['value'] ?? null),
            'placeholders/frozen_sequences' => fn (array $in) => Placeholders::frozenSequences($in['source'] ?? ''),
            'placeholders/accepts_edit' => fn (array $in) => Placeholders::acceptsEdit($in['source'] ?? '', $in['edited'] ?? ''),
            'placeholders/tokens' => fn (array $in) => Placeholders::tokens($in['text'] ?? ''),
            'placeholders/invented' => fn (array $in) => Placeholders::invented($in['source'] ?? '', $in['translation'] ?? null),
            'placeholders/tally' => fn (array $in) => Placeholders::tally($in['text'] ?? ''),
        ];
    }

    private static function corpusPath(string $relative): string
    {
        return dirname(__DIR__, 2) . '/resources/corpus/' . $relative;
    }

    private static function load(string $relative): array
    {
        $path = self::corpusPath($relative);
        self::assertFileExists($path, "$relative is missing — run ./sync-common.ps1");

        return json_decode(file_get_contents($path), true, 32, JSON_THROW_ON_ERROR);
    }

    public function test_the_manifest_and_the_dispatch_name_the_same_operations(): void
    {
        $manifest = self::load('manifest.json');
        $listed = [];
        foreach ($manifest['sides']['site'] ?? [] as $rule => $ops) {
            self::assertContains($rule, $manifest['rules'], "the site lists rule '$rule', which the corpus does not carry");
            foreach ($ops as $op) {
                $listed[] = "$rule/$op";
            }
        }
        $held = array_keys(self::operations());
        sort($listed);
        sort($held);

        self::assertSame(
            array_values(array_diff($listed, $held)), [],
            'LISTED FOR THE SITE, NOT HELD: the manifest says the site answers these and nothing here does'
        );
        self::assertSame(
            array_values(array_diff($held, $listed)), [],
            'HELD, NOT LISTED: an operation answered here that the manifest does not know the site holds'
        );
        self::assertNotEmpty($listed, 'the site holds nothing: the manifest was mis-read');
    }

    public function test_every_case_of_every_operation_the_site_holds(): void
    {
        $manifest = self::load('manifest.json');
        $operations = self::operations();
        $ran = 0;

        foreach ($manifest['sides']['site'] ?? [] as $rule => $ops) {
            $file = self::load("rules/$rule.json");
            $byId = [];
            foreach ($file['cases'] as $case) {
                $byId[$case['id']] = $case;
            }

            foreach ($ops as $op) {
                $id = "$rule/$op";
                $call = $operations[$id] ?? null;
                self::assertNotNull($call, "$id has no dispatch");
                $cases = array_values(array_filter($file['cases'], fn ($c) => $c['op'] === $op));
                self::assertNotEmpty($cases, "$id has no case in the corpus");

                foreach ($cases as $case) {
                    $this->runCase($rule, $case, $byId, $operations);
                    $ran++;
                }
            }
        }

        self::assertGreaterThanOrEqual(20, $ran, 'fewer cases than the corpus holds: a file was mis-read');
    }

    /**
     * @param array<string, array> $byId
     * @param array<string, callable> $operations
     */
    private function runCase(string $rule, array $case, array $byId, array $operations): void
    {
        $id = $case['id'];
        $why = $case['why'] ?? '';
        $call = $operations["$rule/{$case['op']}"];
        $in = $case['in'] ?? [];

        self::assertArrayHasKey('out', $case, "$id: the case has no 'out'");
        $out = $case['out'];
        $actual = $call($in);

        if (is_array($out) && array_key_exists('same_as', $out)) {
            $other = $byId[$out['same_as']] ?? null;
            self::assertNotNull($other, "$id: same_as names an unknown case '{$out['same_as']}'");
            $otherCall = $operations["$rule/{$other['op']}"] ?? null;
            self::assertNotNull($otherCall, "$id: same_as case '{$other['id']}' is an operation the site does not hold");
            $expected = $otherCall($other['in'] ?? []);
            self::assertTrue(
                self::agrees($expected, $actual, $detail),
                "$id — expected the answer of {$other['id']}: $detail — $why"
            );
            $this->symmetricHolds($case, $call);

            return;
        }

        if (is_array($out)) {
            foreach (['above', 'below', 'level'] as $relation) {
                if (!array_key_exists($relation, $out)) {
                    continue;
                }
                $rhs = $call($out[$relation]);
                self::assertTrue(is_int($actual) && is_int($rhs), "$id: '$relation' compares numbers");
                $holds = match ($relation) {
                    'above' => $actual > $rhs,
                    'below' => $actual < $rhs,
                    'level' => $actual === $rhs,
                };
                self::assertTrue($holds, "$id — expected $actual $relation $rhs — $why");

                return;
            }
        }

        self::assertTrue(self::agrees($out, $actual, $detail), "$id — $detail — $why");
        $this->symmetricHolds($case, $call);
    }

    /** A signed comparison must hold the other way round with the first two inputs swapped. */
    private function symmetricHolds(array $case, callable $call): void
    {
        if (!($case['symmetric'] ?? false)) {
            return;
        }
        $keys = array_keys($case['in']);
        self::assertGreaterThanOrEqual(2, count($keys), "{$case['id']}: symmetric needs two inputs to swap");
        $swapped = $case['in'];
        [$swapped[$keys[0]], $swapped[$keys[1]]] = [$case['in'][$keys[1]], $case['in'][$keys[0]]];
        self::assertSame(-$case['out'], $call($swapped), "{$case['id']}: and the reverse does not hold");
    }

    /**
     * The corpus's comparison, as the C# executor does it: scalars exact, lists exact in length
     * and order, objects PARTIAL (the keys the case names must match, the others are not judged),
     * {"approx": x, "tol": t} within tolerance.
     */
    private static function agrees(mixed $expected, mixed $actual, ?string &$detail): bool
    {
        $detail = '';
        if ($expected === null) {
            if ($actual === null) {
                return true;
            }
            $detail = 'expected null, got ' . self::show($actual);

            return false;
        }
        if (is_bool($expected) || is_string($expected)) {
            if ($actual === $expected) {
                return true;
            }
            $detail = 'expected ' . self::show($expected) . ', got ' . self::show($actual);

            return false;
        }
        if (is_int($expected) || is_float($expected)) {
            if ((is_int($actual) || is_float($actual)) && abs($actual - $expected) < 1e-9) {
                return true;
            }
            $detail = 'expected ' . self::show($expected) . ', got ' . self::show($actual);

            return false;
        }
        if (is_array($expected) && array_key_exists('approx', $expected)) {
            $tol = $expected['tol'] ?? 1e-9;
            if ((is_int($actual) || is_float($actual)) && abs($actual - $expected['approx']) <= $tol) {
                return true;
            }
            $detail = 'expected ≈' . $expected['approx'] . ", got " . self::show($actual);

            return false;
        }
        if (is_array($expected) && array_is_list($expected)) {
            if (!is_array($actual) || !array_is_list($actual)) {
                $detail = 'expected a list, got ' . self::show($actual);

                return false;
            }
            if (count($actual) !== count($expected)) {
                $detail = 'expected ' . count($expected) . ' item(s), got ' . count($actual) . ': ' . self::show($actual);

                return false;
            }
            foreach ($expected as $i => $item) {
                if (!self::agrees($item, $actual[$i], $inner)) {
                    $detail = "item $i: $inner";

                    return false;
                }
            }

            return true;
        }
        if (is_array($expected)) {
            if (!is_array($actual)) {
                $detail = 'expected an object, got ' . self::show($actual);

                return false;
            }
            foreach ($expected as $key => $value) {
                if (!array_key_exists($key, $actual)) {
                    $detail = "no '$key' in " . self::show($actual);

                    return false;
                }
                if (!self::agrees($value, $actual[$key], $inner)) {
                    $detail = "'$key': $inner";

                    return false;
                }
            }

            return true;
        }
        $detail = 'unsupported expectation ' . self::show($expected);

        return false;
    }

    private static function show(mixed $value): string
    {
        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
