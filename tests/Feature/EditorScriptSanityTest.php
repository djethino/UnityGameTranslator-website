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
     * 🔴 **A capacity of the core is wired by the core, in every screen at once.**
     *
     * The off-screen marks — "your answer is two columns that way" — were wired by ONE template:
     * a single `@scroll` on the merge view's grid, and the four `lineAnswer*` helpers written in
     * that page's script. Two consequences, and neither is visible from the screen that has them.
     * The comparison, whose grid is just as wide and whose rows hold answers just the same, had no
     * marks at all. And scrolling is not the only thing that moves a column across an edge: the
     * workbench takes the window, the browser is resized, a filter shortens the table.
     *
     * ⚠ The same reasoning is already written a module away, in `initEditorColumns`: an observer
     * catches every cause at once, where a hook per cause misses the next one. What this test adds
     * is the other half — a screen must not be able to be the one that forgot to plug it in.
     */
    public function test_the_off_screen_marks_are_wired_by_the_core(): void
    {
        $module = file_get_contents(base_path('resources/js/components/editor-offscreen.js'));

        $this->assertStringContainsString('initOffScreen() {', $module);
        $this->assertStringContainsString(
            'this.initOffScreen();',
            file_get_contents(base_path('resources/js/components/translation-editor.js')),
            'the core builds every editor but never starts the off-screen marks'
        );

        // A row's answer lives in `selections`, which every arbitrating screen has — so asking
        // where it is belongs to the module, not to one page.
        foreach (['lineAnswerLeft(key) {', 'lineAnswerRight(key) {', 'goToLineAnswer(key) {'] as $shared) {
            $this->assertStringContainsString($shared, $module);
        }

        foreach ($this->sources() as $file => $source) {
            if (str_contains($file, 'editor-offscreen.js')) {
                continue;
            }

            $this->assertStringNotContainsString('lineAnswerLeft(key) {', $source,
                "$file answers for itself where the core already answers for everyone");
            $this->assertStringNotContainsString('@scroll="refreshOffScreenSides()"', $source,
                "$file wires the marks to scrolling only — the workbench and a resize move them too");
        }

        // And the mark is drawn by the shared key cell, not passed in: it was a slot, and only one
        // screen ever filled it.
        $this->assertStringContainsString(
            'x-show="lineAnswerLeft(key)"',
            file_get_contents(base_path('resources/views/components/editor/cell-key.blade.php')),
            'the shared key cell no longer draws the off-screen mark itself'
        );
    }

    /**
     * 🔴 **Everything the save can send is counted by the button.**
     *
     * The settings grid at the top is not lines. Taking the other side's font without touching a
     * single line IS something to send — `submitResult` has always built those hidden inputs and
     * counted them in its own guard — but the comparison's `totalChanges` only walked the rows, so
     * the button stayed disabled at (0) and that pick could not be sent at all. The two halves of
     * the screen disagreed, and the one nobody could reach was the working one. The merge view had
     * already met this and fixed it (`rows + settingsTakenCount() + publicationTakenCount()`).
     *
     * ⚠ Counted through the same test the send uses, never a second spelling of it: counting the
     * picks would have counted "keep mine", which writes nothing — wrong in the other direction.
     */
    public function test_the_button_counts_the_settings_the_save_would_send(): void
    {
        $preview = file_get_contents(base_path('resources/views/translations/merge-preview.blade.php'));

        $this->assertStringContainsString('return count + this.settingsTakenCount();', $preview);
        $this->assertStringContainsString('settingsWillWrite(row) {', $preview);

        // One test, read by both — the counter and the loop that builds the hidden inputs.
        $this->assertSame(2, substr_count($preview, 'this.settingsWillWrite(row)'),
            'the counter and the send should read the same test');

        $merge = file_get_contents(base_path('resources/views/merge/show.blade.php'));
        $this->assertStringContainsString(
            'return rows + this.settingsTakenCount() + this.publicationTakenCount();', $merge);
    }

    /**
     * 🔴 **Picking a column never destroys typing.** It sets it aside; reverting the row removes it.
     *
     * ⚠ The cost of getting this wrong is not one keystroke: fifty reworded lines go to a single
     * press of "take everything from this side", with no undo. So neither the gesture that takes a
     * version (`select`) nor the one that sweeps a whole column (`selectAllFrom`) may delete from
     * `editedValues` — what decides the file is the SELECTION, read through `editIsHeld`.
     *
     * ⚠ This does not forbid the deletion outright: `revertRow`, `toggleDelete`, `stageEdit`
     * (typing the original value back) and the third click of `advancePick` all legitimately drop
     * it, and each is a gesture aimed at that one row.
     */
    public function test_picking_a_column_sets_typing_aside_instead_of_destroying_it(): void
    {
        $forbidden = ['select(key, source) {', 'selectAllFrom(source) {'];

        foreach ($this->sources() as $file => $source) {
            foreach ($forbidden as $signature) {
                $at = strpos($source, $signature);
                if ($at === false) {
                    continue;
                }

                // The body up to the next method at the same indentation
                $body = substr($source, $at, strpos($source, "\n        },", $at) - $at);

                $this->assertStringNotContainsString(
                    'delete this.editedValues[key]',
                    $body,
                    "$file: $signature destroys a pending rewording; a pick must only set it aside"
                );
            }
        }

        // 🔴 And "is the typing the answer" is ONE test, in the core — which is only possible
        // because every arbitrating screen answers a rewording the same way. A screen that names
        // the target's column instead reads identically until this question, and then its click on
        // that column runs through advancePick and deletes the typing.
        foreach (['merge/show', 'translations/merge-preview'] as $screen) {
            $source = file_get_contents(base_path("resources/views/$screen.blade.php"));

            if (!str_contains($source, 'onEditStaged(key) {')) {
                continue;
            }

            $body = substr($source, strpos($source, 'onEditStaged(key) {'));
            $body = substr($body, 0, strpos($body, "\n        },"));

            $this->assertStringContainsString("'manual'", $body,
                "$screen answers a rewording with something other than 'manual'");
        }

        $core = file_get_contents(base_path('resources/js/components/translation-editor.js'));

        $this->assertStringContainsString(
            "return picked === null || picked === 'manual';",
            $core,
            'the core no longer decides in one place whether a rewording is the answer'
        );

        // ⚠ And the way back. Set aside, the typing is what the cell SHOWS, so clicking it takes it
        // back — reported as "nothing happens, and nothing on the row looks selected" when the
        // click fell through to advancePick, which answers for the column instead.
        $this->assertStringContainsString(
            'if (source === this.targetSource() && this.isEdited(key) && !this.editIsHeld(key)) {',
            $core,
            'clicking typing that a pick set aside no longer takes it back'
        );
    }
}
