<?php

namespace Tests\Unit;

use App\Models\Translation;
use App\Services\TranslationService;
use PHPUnit\Framework\TestCase;

/**
 * The site's door against the contract: resources/spec/translation-file/cases.json, the documents
 * a Core may send and what the site must do with each at upload.
 *
 * 🔴 The cases are the specification, shared with the mod (tests/UnityGameTranslator.Core.Checks
 * reads the same file for the reader's half) and with the schema (check-spec.py). They travel as
 * a copy in resources/spec/, put there by sync-common.ps1, refused by check-spec.py when it
 * diverges — the same road as the catalogue.
 *
 * ⚠ `upload` is this side's verdict: `accepted` means parseAndValidate returns, `refused` means it
 * throws. A case with `written: false` and `upload: accepted` is a tolerance of this door, kept
 * for files written by older mods; it is listed on purpose, and closing one is a decision about
 * the mods still in the field.
 */
class TranslationFileSpecTest extends TestCase
{
    private static function cases(): array
    {
        $path = dirname(__DIR__, 2) . '/resources/spec/translation-file/cases.json';
        self::assertFileExists($path, 'the spec copy is missing — run ./sync-common.ps1');
        $doc = json_decode(file_get_contents($path), true, 32, JSON_THROW_ON_ERROR);

        return $doc['cases'];
    }

    public function test_every_case_is_accepted_or_refused_as_the_spec_says(): void
    {
        $service = new TranslationService();
        $seen = 0;

        foreach (self::cases() as $case) {
            $id = $case['id'];
            $content = json_encode($case['document'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

            try {
                $parsed = $service->parseAndValidate($content);
                $outcome = 'accepted';
            } catch (\InvalidArgumentException $e) {
                $parsed = null;
                $outcome = 'refused';
            }

            self::assertSame($case['upload'], $outcome, "$id — {$case['why']}");
            $seen++;

            // What this door derives, where the case says it (`site`, not `read`: the reader's
            // view is the mod's, after normalisation; this counts the file as sent).
            if ($parsed !== null && isset($case['site']) && is_array($case['site'])) {
                $site = $case['site'];
                if (isset($site['line_count'])) {
                    self::assertSame($site['line_count'], $parsed['line_count'], "$id — line count");
                }
                if (isset($site['tag_counts'])) {
                    $got = Translation::extractTagCounts($parsed['json']);
                    // The spec names the bands (human, validated, ai, capture, skipped); this
                    // side's columns are `<band>_count`.
                    foreach ($site['tag_counts'] as $tag => $count) {
                        self::assertArrayHasKey($tag . '_count', $got, "$id — tag $tag");
                        self::assertSame($count, $got[$tag . '_count'], "$id — tag $tag");
                    }
                }
            }
        }

        self::assertGreaterThanOrEqual(20, $seen, 'fewer cases than the spec holds: the file was mis-read');
    }
}
