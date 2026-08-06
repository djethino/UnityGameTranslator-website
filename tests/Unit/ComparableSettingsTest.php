<?php

namespace Tests\Unit;

use App\Services\TranslationService;
use PHPUnit\Framework\TestCase;

/**
 * Settings turned into rows that can be compared between two files.
 *
 * The editors could say "the fonts differ" but not WHICH font, so nothing could be picked one
 * by one the way translation lines are. These tests protect the two properties that make the
 * comparison meaningful rather than merely possible:
 *
 * - a key must identify the SAME setting across two files, whatever moved around it;
 * - a file that carries no deliberate setting must produce no row, or every player of the same
 *   translation would appear to disagree simply because they walked through different screens.
 */
class ComparableSettingsTest extends TestCase
{
    private TranslationService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new TranslationService();
    }

    public function test_a_font_merely_met_in_game_produces_no_row(): void
    {
        $entries = $this->service->extractComparableSettings([
            '_fonts' => [
                // Recorded by the mod on sight — configures nothing
                'ArialSDF' => ['enabled' => true, 'fallback' => null, 'scale' => 1.0],
                'PixelFont' => ['enabled' => false, 'fallback' => null, 'scale' => 1.0],
            ],
        ]);

        $this->assertArrayNotHasKey('fonts:ArialSDF', $entries);
        $this->assertArrayHasKey('fonts:PixelFont', $entries);
    }

    public function test_a_font_row_states_what_was_configured(): void
    {
        $entries = $this->service->extractComparableSettings([
            '_fonts' => [
                'Title' => ['enabled' => true, 'fallback' => 'NotoSans', 'scale' => 1.4],
            ],
        ]);

        $this->assertSame('Title', $entries['fonts:Title']['label']);
        $this->assertStringContainsString('NotoSans', $entries['fonts:Title']['value']);
        $this->assertStringContainsString('140%', $entries['fonts:Title']['value']);
    }

    public function test_a_font_rule_keeps_its_identity_when_another_is_inserted_above(): void
    {
        $before = $this->service->extractComparableSettings([
            '_font_overrides' => [
                ['match' => 'Menu*', 'replacement' => 'NotoSans'],
            ],
        ]);

        $after = $this->service->extractComparableSettings([
            '_font_overrides' => [
                ['match' => 'Dialog*', 'replacement' => 'Roboto'],
                ['match' => 'Menu*', 'replacement' => 'NotoSans'],
            ],
        ]);

        // Keyed by index, inserting a rule above would report the Menu rule as changed even
        // though nobody touched it — and hide the rule that really was added.
        $this->assertArrayHasKey('font_rules:Menu*', $before);
        $this->assertArrayHasKey('font_rules:Menu*', $after);
        $this->assertArrayHasKey('font_rules:Dialog*', $after);
    }

    public function test_moving_a_font_rule_shows_up_on_that_rule(): void
    {
        $first = $this->service->extractComparableSettings([
            '_font_overrides' => [
                ['match' => 'Menu*', 'replacement' => 'NotoSans'],
                ['match' => 'Dialog*', 'replacement' => 'Roboto'],
            ],
        ]);

        $swapped = $this->service->extractComparableSettings([
            '_font_overrides' => [
                ['match' => 'Dialog*', 'replacement' => 'Roboto'],
                ['match' => 'Menu*', 'replacement' => 'NotoSans'],
            ],
        ]);

        // Rules are matched first-wins, so the order IS part of the setting and must be visible
        $this->assertNotSame(
            $first['font_rules:Menu*']['value'],
            $swapped['font_rules:Menu*']['value']
        );
    }

    public function test_each_game_option_gets_its_own_row(): void
    {
        $entries = $this->service->extractComparableSettings([
            '_settings' => [
                'typewriting_detection' => true,
                'concat_detection' => false,
            ],
        ]);

        // One row for the whole block would force an all-or-nothing choice on settings that
        // have nothing to do with each other
        $this->assertSame('on', $entries['game_settings:typewriting_detection']['value']);
        $this->assertSame('off', $entries['game_settings:concat_detection']['value']);
    }

    public function test_an_exclusion_is_identified_by_its_pattern(): void
    {
        $entries = $this->service->extractComparableSettings([
            '_exclusions' => ['Qapla\'', 'nuqneH'],
        ]);

        $this->assertArrayHasKey('exclusions:Qapla\'', $entries);
        $this->assertArrayHasKey('exclusions:nuqneH', $entries);
    }

    public function test_a_hand_written_file_cannot_break_the_comparison(): void
    {
        // The file is user-editable: any field the mod always writes as a string can arrive as
        // an array. A malformed entry is skipped, never fatal.
        $entries = $this->service->extractComparableSettings([
            '_fonts' => ['Broken' => 'not-an-object', 'Ok' => ['enabled' => false]],
            '_font_overrides' => ['not-an-object', ['match' => ['array']], ['match' => 'Fine']],
            '_image_replacements' => ['nope', ['sprite_name' => 'logo']],
            '_exclusions' => [['array']],
            '_variables' => ['nope', ['name' => 'gold', 'class' => 'Player', 'path' => 'coins']],
            '_settings' => ['nested' => ['a' => 1], 'ui_font' => 'Roboto'],
        ]);

        $this->assertArrayHasKey('fonts:Ok', $entries);
        $this->assertArrayHasKey('font_rules:Fine', $entries);
        $this->assertArrayHasKey('images:logo', $entries);
        $this->assertArrayHasKey('variables:gold', $entries);
        $this->assertArrayHasKey('game_settings:ui_font', $entries);
        $this->assertArrayNotHasKey('game_settings:nested', $entries);
        $this->assertArrayNotHasKey('fonts:Broken', $entries);
    }

    public function test_a_file_without_settings_produces_nothing(): void
    {
        $this->assertSame([], $this->service->extractComparableSettings([
            '_uuid' => 'abc',
            'Hello' => ['v' => 'Bonjour', 't' => 'H'],
        ]));
    }
}
