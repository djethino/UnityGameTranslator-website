<?php

namespace Tests\Feature;

use App\Models\Translation;
use Illuminate\Foundation\Testing\Concerns\InteractsWithViews;
use Tests\TestCase;

/**
 * The colour key's shares — the PHP copy of the socle's `Composition.Shares`, which the mod and
 * the Manager call and this site cannot.
 *
 * The file that showed the drift (2026-09-18): 14 human, 15 validated, 2,498 AI read "99%" in
 * one window and "98%" in the other. The rule is one: every band is rounded on its own, and the
 * last band HOLDING anything takes what is left, so the key always adds up to 100.
 */
class QualityLegendTest extends TestCase
{
    use InteractsWithViews;

    private function legend(int $human, int $validated, int $ai, int $skipped = 0, int $capture = 0): string
    {
        $translation = new Translation([
            'human_count' => $human,
            'validated_count' => $validated,
            'ai_count' => $ai,
            'skipped_count' => $skipped,
            'capture_count' => $capture,
        ]);

        return (string) $this->blade('<x-quality-legend :translation="$translation" />', compact('translation'));
    }

    public function test_the_last_band_holding_anything_absorbs_the_rounding(): void
    {
        $html = $this->legend(14, 15, 2498);

        $this->assertStringContainsString('(1%)', $html);
        $this->assertStringContainsString('(98%)', $html);
        $this->assertStringNotContainsString('(99%)', $html);
    }

    public function test_an_empty_band_drawn_last_never_goes_below_zero(): void
    {
        // 1/8 and 7/8 round to 13 and 88; an empty AI band absorbing would read -1%.
        $html = $this->legend(1, 7, 0);

        $this->assertStringContainsString('(13%)', $html);
        $this->assertStringContainsString('(87%)', $html);
        $this->assertStringContainsString('(0%)', $html);
        $this->assertStringNotContainsString('-1%', $html);
    }
}
