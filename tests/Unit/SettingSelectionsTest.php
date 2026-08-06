<?php

namespace Tests\Unit;

use App\Services\TranslationService;
use PHPUnit\Framework\TestCase;

/**
 * Applying a per-setting choice, once a merge has been arbitrated.
 *
 * Two properties matter more than the rest:
 *
 * - the winning entry is COPIED from its file, never rebuilt from what was displayed. The
 *   comparison shows a readable summary that drops fields it does not render, and rebuilding
 *   from it would silently strip them;
 * - font rules keep a meaningful ORDER. The first rule that matches wins, which is how a
 *   specific rule is made to beat a general one, so a result assembled in the wrong order would
 *   quietly change what the game renders.
 */
class SettingSelectionsTest extends TestCase
{
    private TranslationService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new TranslationService();
    }

    public function test_a_chosen_entry_keeps_the_fields_the_comparison_never_showed(): void
    {
        $online = ['_fonts' => ['Title' => ['enabled' => true, 'fallback' => 'NotoSans']]];
        $local = ['_fonts' => ['Title' => [
            'enabled' => true, 'fallback' => 'Roboto',
            // Never rendered in the comparison — and must survive it
            'type' => 'TMP', 'origin' => 'game', 'scale_auto' => true, 'scale' => 1.82,
        ]]];

        $result = $this->service->applySettingSelections($online, $local, ['fonts:Title' => 'local']);

        $this->assertSame($local['_fonts']['Title'], $result['_fonts']['Title']);
    }

    public function test_fonts_nobody_arbitrated_are_left_alone(): void
    {
        // _fonts doubles as an inventory of every font met in game: rebuilding the section from
        // the choices alone would erase everything the players simply never configured
        $online = ['_fonts' => [
            'Title' => ['enabled' => true, 'fallback' => 'NotoSans'],
            'MetOnce' => ['enabled' => true, 'type' => 'Unknown'],
        ]];
        $local = ['_fonts' => ['Title' => ['enabled' => false]]];

        $result = $this->service->applySettingSelections($online, $local, ['fonts:Title' => 'local']);

        $this->assertArrayHasKey('MetOnce', $result['_fonts']);
    }

    public function test_picking_the_side_that_does_not_have_it_removes_it(): void
    {
        $online = ['_exclusions' => ['Qapla\'', 'nuqneH']];
        $local = ['_exclusions' => ['Qapla\'']];

        // "nuqneH" exists online only; choosing local means "I do not want it"
        $result = $this->service->applySettingSelections($online, $local, ['exclusions:nuqneH' => 'local']);

        $this->assertSame(['Qapla\''], array_values($result['_exclusions']));
    }

    public function test_an_entry_only_the_local_side_has_can_be_brought_in(): void
    {
        $online = ['_exclusions' => ['Qapla\'']];
        $local = ['_exclusions' => ['Qapla\'', 'nuqneH']];

        $result = $this->service->applySettingSelections($online, $local, ['exclusions:nuqneH' => 'local']);

        $this->assertContains('nuqneH', $result['_exclusions']);
    }

    public function test_a_kept_local_rule_lands_before_the_general_one_it_specialises(): void
    {
        // The player's case: a sub-menu needs its own font, so its rule must be met FIRST —
        // the general rule would otherwise swallow it
        $online = ['_font_overrides' => [
            ['match' => 'path:Menu/**', 'replacement' => 'FontA'],
        ]];
        $local = ['_font_overrides' => [
            ['match' => 'path:Menu/Sub*/Z', 'replacement' => 'FontB'],
            ['match' => 'path:Menu/**', 'replacement' => 'FontA'],
        ]];

        $result = $this->service->applySettingSelections($online, $local, [
            'font_rules:path:Menu/Sub*/Z' => 'local',
        ]);

        $matches = array_column($result['_font_overrides'], 'match');
        $this->assertSame(['path:Menu/Sub*/Z', 'path:Menu/**'], $matches);
    }

    public function test_the_online_order_is_the_backbone(): void
    {
        $online = ['_font_overrides' => [
            ['match' => 'a', 'replacement' => 'X'],
            ['match' => 'b', 'replacement' => 'X'],
            ['match' => 'c', 'replacement' => 'X'],
        ]];
        $local = ['_font_overrides' => [
            ['match' => 'c', 'replacement' => 'LOCAL'],
            ['match' => 'a', 'replacement' => 'LOCAL'],
        ]];

        // Taking c's content from the local side must not move c to the front
        $result = $this->service->applySettingSelections($online, $local, ['font_rules:c' => 'local']);

        $this->assertSame(['a', 'b', 'c'], array_column($result['_font_overrides'], 'match'));
        $this->assertSame('LOCAL', $result['_font_overrides'][2]['replacement']);
    }

    public function test_a_rule_dropped_from_a_list_leaves_the_others_in_place(): void
    {
        $online = ['_font_overrides' => [
            ['match' => 'a'], ['match' => 'b'], ['match' => 'c'],
        ]];
        $local = ['_font_overrides' => [['match' => 'a'], ['match' => 'c']]];

        $result = $this->service->applySettingSelections($online, $local, ['font_rules:b' => 'local']);

        $this->assertSame(['a', 'c'], array_column($result['_font_overrides'], 'match'));
    }

    public function test_a_section_emptied_by_the_choices_disappears(): void
    {
        $online = ['_exclusions' => ['only']];
        $local = ['_exclusions' => []];

        $result = $this->service->applySettingSelections($online, $local, ['exclusions:only' => 'local']);

        // An empty key would claim the file carries a section it no longer has
        $this->assertArrayNotHasKey('_exclusions', $result);
    }

    public function test_untouched_sections_are_not_rewritten(): void
    {
        $online = ['_settings' => ['typewriting_detection' => true], '_variables' => [['name' => 'gold']]];
        $local = ['_settings' => ['typewriting_detection' => false]];

        $result = $this->service->applySettingSelections($online, $local, ['fonts:Absent' => 'local']);

        $this->assertSame($online['_settings'], $result['_settings']);
        $this->assertSame($online['_variables'], $result['_variables']);
    }
}
