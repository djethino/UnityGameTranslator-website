<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * The editors' inline scripts, checked for faults no rendered-HTML test can see.
 *
 * 🔴 **Written after `settingsTakenCount()()` shipped and lived four days in production.** The
 * double call made a getter throw on every evaluation, and a getter that throws inside Alpine takes
 * its binding down without a word: the merge screen showed "Save (0)" and its button stayed
 * disabled, so no Main could take in a single contribution. Every test was green — they assert on
 * the markup the server renders, and never execute the script inside it.
 *
 * ⚠ This does not run the code either. It looks for shapes that are ALWAYS a mistake here, which is
 * the cheap half of the problem; the expensive half needs a browser. Cheap and mechanical beats
 * nothing at all, and this class of typo is now caught before a human meets it.
 */
class EditorScriptSanityTest extends TestCase
{
    /** The three screens that carry a large inline Alpine component. */
    private const SCREENS = [
        'resources/views/merge/show.blade.php',
        'resources/views/translations/merge-preview.blade.php',
        'resources/views/edit-session/show.blade.php',
    ];

    private function sources(): array
    {
        $out = [];

        foreach (self::SCREENS as $relative) {
            $path = base_path($relative);
            $this->assertFileExists($path, "$relative has moved — update this test");
            $out[$relative] = file_get_contents($path);
        }

        foreach (glob(base_path('resources/js/components/*.js')) as $path) {
            $out['resources/js/components/' . basename($path)] = file_get_contents($path);
        }

        return $out;
    }

    /**
     * `something()()` — calling whatever the first call returned.
     *
     * ⚠ An immediately-invoked function expression is written `(function(){})()` or `(() => {})()`,
     * so it ends in `})()` and never in a bare identifier followed by two empty pairs. The pattern
     * below therefore cannot match a legitimate IIFE.
     */
    public function test_no_result_is_called_as_if_it_were_a_function(): void
    {
        foreach ($this->sources() as $file => $source) {
            // Drop whole-line comments, so the note that documents the bug does not report itself.
            // ⚠ Only lines that ARE a comment — cutting at the first `//` anywhere would also cut
            // at `https://`, and take real code with it.
            $code = preg_replace('#^\s*(//|\*).*$#m', '', $source);

            $this->assertDoesNotMatchRegularExpression(
                '/[A-Za-z_$][\w$]*\(\)\(\)/',
                $code,
                "$file calls the result of a call — the mistake that disabled the merge screen"
            );
        }
    }

    /**
     * A getter used by the workbench bar must exist on every screen that binds to it.
     *
     * The bar is shared; a screen that forgets one of these renders a control bound to nothing,
     * which reads as "there is nothing to save" rather than as a fault.
     */
    public function test_every_editor_defines_the_counter_its_toolbar_reads(): void
    {
        foreach (self::SCREENS as $relative) {
            $source = file_get_contents(base_path($relative));

            if (!str_contains($source, 'totalChanges')) {
                continue;
            }

            $this->assertMatchesRegularExpression(
                '/get\s+totalChanges\s*\(\s*\)\s*\{/',
                $source,
                "$relative reads totalChanges without defining it"
            );
        }
    }

    /**
     * 🔴 Picking a version drops a pending rewording — from ANY column, the target's included.
     *
     * ⚠ What a conditional `delete` costs is not a lost keystroke, it is a screen that STATES one
     * thing and DOES another: the row goes on showing the reworded text (`x-show="isEdited(key)"`)
     * and painted as reworded, while the save writes the version that was picked. Nothing warns,
     * because both halves are individually correct.
     *
     * The condition that caused it read `source !== this.targetSource()` and came from the
     * comparison screen, where `advancePick` swallows a click on the column already held so it
     * never ran. Moved into the core it reached the merge view, where a rewording holds the row as
     * `manual` and the click does get through. A guard that never fires where it was written is
     * exactly the kind that survives review and breaks somewhere else.
     */
    public function test_taking_a_version_drops_a_pending_rewording_from_any_column(): void
    {
        foreach ($this->sources() as $file => $source) {
            foreach (explode("\n", $source) as $number => $line) {
                if (!str_contains($line, 'delete this.editedValues[key]')) {
                    continue;
                }

                $this->assertSame(
                    'delete this.editedValues[key];',
                    trim($line),
                    "$file:" . ($number + 1) . ' conditions the drop of a rewording on which column '
                    . 'the value came from; the row would then show one value and save another'
                );
            }
        }

        $this->assertStringContainsString(
            'delete this.editedValues[key];',
            file_get_contents(base_path('resources/js/components/translation-editor.js')),
            'the core stopped dropping the rewording when a version is taken'
        );
    }
}
