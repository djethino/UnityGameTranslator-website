<?php

namespace Tests\Unit;

use App\Services\TranslationService;
use PHPUnit\Framework\TestCase;

/**
 * The summary a game page reads instead of opening every translation file.
 *
 * Two properties matter and are easy to break: the COUNT must stay exact even
 * though the preview is bounded (a truncated list that reports its own length
 * would silently under-report what a file carries), and nothing stored here
 * may grow without bound — exclusion patterns are game text and can be huge.
 */
class SettingsSummaryTest extends TestCase
{
    private TranslationService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new TranslationService();
    }

    public function test_a_file_without_settings_summarizes_to_null(): void
    {
        $summary = $this->service->extractSettingsSummary([
            '_uuid' => 'abc',
            'Hello' => ['v' => 'Bonjour', 't' => 'H'],
        ]);

        $this->assertNull($summary);
    }

    public function test_fonts_are_left_to_their_own_column(): void
    {
        $summary = $this->service->extractSettingsSummary([
            '_fonts' => ['Arial' => ['enabled' => true]],
        ]);

        // _fonts belongs to font_config; duplicating it here would let the two
        // drift apart with no way to tell which one the page should believe
        $this->assertNull($summary);
    }

    public function test_every_section_is_summarized(): void
    {
        $summary = $this->service->extractSettingsSummary([
            '_font_overrides' => [
                ['match' => 'Arial*', 'replacement' => 'NotoSans', 'size_multiplier' => 1.2],
                ['match' => 'Impact', 'enabled' => false],
            ],
            '_image_replacements' => [
                ['sprite_name' => 'ui_logo', 'original_width' => 512, 'original_height' => 256, 'file' => 'ui_logo.png'],
            ],
            '_exclusions' => ['^DEBUG', 'v1.0.3'],
            '_variables' => [
                ['id' => 0, 'name' => 'playerName', 'class' => 'Player', 'path' => 'displayName'],
            ],
            '_settings' => ['typewriting_detection' => false, 'ui_font' => 'NotoSans'],
        ]);

        $this->assertSame(2, $summary['font_overrides']['count']);
        $this->assertSame('Arial*', $summary['font_overrides']['items'][0]['match']);
        $this->assertSame('NotoSans', $summary['font_overrides']['items'][0]['replacement']);
        // Absent 'enabled' means enabled: the mod only writes the key when false
        $this->assertTrue($summary['font_overrides']['items'][0]['enabled']);
        $this->assertFalse($summary['font_overrides']['items'][1]['enabled']);

        $this->assertSame(1, $summary['image_replacements']['count']);
        $this->assertSame('ui_logo', $summary['image_replacements']['items'][0]['name']);
        $this->assertSame(512, $summary['image_replacements']['items'][0]['width']);

        $this->assertSame(2, $summary['exclusions']['count']);
        $this->assertSame('^DEBUG', $summary['exclusions']['items'][0]);

        $this->assertSame(1, $summary['variables']['count']);
        $this->assertSame('Player.displayName', $summary['variables']['items'][0]['source']);

        $this->assertFalse($summary['game_settings']['typewriting_detection']);
        $this->assertSame('NotoSans', $summary['game_settings']['ui_font']);
    }

    public function test_the_count_stays_exact_when_the_preview_is_bounded(): void
    {
        $many = TranslationService::SETTINGS_PREVIEW_LIMIT + 25;
        $summary = $this->service->extractSettingsSummary([
            '_exclusions' => array_map(fn ($i) => "pattern-$i", range(1, $many)),
        ]);

        $this->assertSame($many, $summary['exclusions']['count']);
        $this->assertCount(TranslationService::SETTINGS_PREVIEW_LIMIT, $summary['exclusions']['items']);
    }

    public function test_long_labels_are_truncated_at_storage_time(): void
    {
        $summary = $this->service->extractSettingsSummary([
            '_exclusions' => [str_repeat('a', 400)],
        ]);

        $stored = $summary['exclusions']['items'][0];
        $this->assertSame(TranslationService::SETTINGS_LABEL_MAX_LENGTH + 1, mb_strlen($stored));
        $this->assertStringEndsWith('…', $stored);
    }

    public function test_multiline_patterns_are_flattened(): void
    {
        $summary = $this->service->extractSettingsSummary([
            '_exclusions' => ["line one\r\nline two"],
        ]);

        // Rendered in single-line rows; a raw newline would break the layout
        $this->assertSame('line one line two', $summary['exclusions']['items'][0]);
    }

    public function test_malformed_entries_are_skipped_but_still_counted(): void
    {
        $summary = $this->service->extractSettingsSummary([
            '_font_overrides' => [
                ['match' => 'Arial*'],
                ['replacement' => 'NoMatchKey'],  // unusable: no pattern
                'not-an-object',
            ],
        ]);

        // The count describes the file, not what we managed to read from it
        $this->assertSame(3, $summary['font_overrides']['count']);
        $this->assertCount(1, $summary['font_overrides']['items']);
    }

    public function test_hand_written_nonsense_never_breaks_an_upload(): void
    {
        // translations.json is user-editable, and this summary runs on EVERY
        // upload. A field the mod always writes as a string can arrive as an
        // array — that must skip the entry, not 500 a valid translation.
        $summary = $this->service->extractSettingsSummary([
            '_font_overrides' => [
                ['match' => ['not', 'a', 'string']],
                ['match' => 'Arial*', 'replacement' => ['nope'], 'size_multiplier' => 'huge'],
            ],
            '_image_replacements' => [
                ['sprite_name' => ['array'], 'original_width' => 512],
                ['sprite_name' => 'ok', 'original_width' => 'wide', 'original_height' => null],
            ],
            '_variables' => [
                ['name' => ['x'], 'class' => ['y'], 'path' => 'z'],
            ],
            '_settings' => ['ui_font' => ['a font']],
            '_exclusions' => 'not-even-a-list',
        ]);

        $this->assertSame(2, $summary['font_overrides']['count']);
        $this->assertCount(1, $summary['font_overrides']['items']);
        $this->assertSame('Arial*', $summary['font_overrides']['items'][0]['match']);
        $this->assertNull($summary['font_overrides']['items'][0]['replacement']);
        $this->assertNull($summary['font_overrides']['items'][0]['size_multiplier']);

        $this->assertCount(1, $summary['image_replacements']['items']);
        $this->assertSame('ok', $summary['image_replacements']['items'][0]['name']);
        $this->assertNull($summary['image_replacements']['items'][0]['width']);

        $this->assertSame('', $summary['variables']['items'][0]['name']);
        $this->assertSame('z', $summary['variables']['items'][0]['source']);

        // ui_font was the only game setting, and it was unusable
        $this->assertArrayNotHasKey('game_settings', $summary);
        // A section that is not a list at all is simply absent
        $this->assertArrayNotHasKey('exclusions', $summary);
    }

    public function test_numeric_labels_are_accepted(): void
    {
        // A sprite named "42" is legitimate; only arrays and objects are not
        $summary = $this->service->extractSettingsSummary([
            '_image_replacements' => [['sprite_name' => 42]],
        ]);

        $this->assertSame('42', $summary['image_replacements']['items'][0]['name']);
    }

    public function test_unknown_game_settings_are_dropped(): void
    {
        $summary = $this->service->extractSettingsSummary([
            '_settings' => ['made_up_option' => true],
        ]);

        // An unknown key has no label, and would render as raw text
        $this->assertNull($summary);
    }
}
