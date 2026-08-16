<?php

namespace Tests\Feature;

use App\Models\Game;
use App\Models\Translation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Merge view tests, client-side era: the table (filters, search, sort,
 * windowing) lives in the shared translation-editor core, so the server
 * only has to (1) render the frame with mode + branch selection preserved,
 * (2) serve the data endpoint to the owner only, and (3) apply changes.
 */
class MergeViewStateTest extends TestCase
{
    use RefreshDatabase;

    private array $createdFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->createdFiles as $file) {
            @unlink($file);
        }
        parent::tearDown();
    }

    /**
     * Create a translation with a real JSON file in the private storage disk
     * (getSafeFilePath() resolves against storage/app/private directly).
     */
    private function makeTranslation(User $user, Game $game, string $uuid, string $visibility, array $content): Translation
    {
        $dir = storage_path('app/private/translations');
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }
        $relativePath = 'translations/test_' . uniqid('', true) . '.json';
        $fullPath = storage_path('app/private/' . $relativePath);
        file_put_contents($fullPath, json_encode($content, JSON_UNESCAPED_UNICODE));
        $this->createdFiles[] = $fullPath;

        $translation = new Translation();
        $translation->forceFill([
            'game_id' => $game->id,
            'user_id' => $user->id,
            'source_language' => 'English',
            'target_language' => 'French',
            'file_path' => $relativePath,
            'file_uuid' => $uuid,
            'visibility' => $visibility,
            'line_count' => count($content),
        ])->save();

        return $translation;
    }

    /**
     * Setup: a Main with a couple of keys and one branch from another user.
     *
     * @return array{0: User, 1: string, 2: Translation, 3: Translation} [owner, uuid, main, branch]
     */
    private function makeMergeView(): array
    {
        // refresh() loads DB defaults (is_admin=false) absent from factory attributes
        $owner = User::factory()->create()->refresh();
        $contributor = User::factory()->create()->refresh();
        $game = Game::forceCreate(['name' => 'Test Game', 'slug' => 'test-game-' . uniqid()]);
        $uuid = (string) \Illuminate\Support\Str::uuid();

        $main = $this->makeTranslation($owner, $game, $uuid, 'public', [
            '_uuid' => $uuid,
            'Shared' => ['v' => 'Main value', 't' => 'H'],
            'MainOnly' => ['v' => 'Main only', 't' => 'A'],
        ]);

        $branch = $this->makeTranslation($contributor, $game, $uuid, 'branch', [
            'Shared' => ['v' => 'Branch value', 't' => 'H'],
            'BranchOnly' => ['v' => 'Branch only', 't' => 'A'],
        ]);

        return [$owner, $uuid, $main, $branch];
    }

    public function test_the_merge_serves_the_settings_of_the_main_and_of_each_branch(): void
    {
        [$owner, $uuid, $main, $branch] = $this->makeMergeView();

        $main->forceFill(['file_path' => $main->file_path])->save();
        file_put_contents(storage_path('app/private/' . $main->file_path), json_encode([
            '_uuid' => $uuid,
            '_fonts' => ['Title' => ['enabled' => true, 'fallback' => 'NotoSans']],
            'Shared' => ['v' => 'Main value', 't' => 'H'],
        ]));
        file_put_contents(storage_path('app/private/' . $branch->file_path), json_encode([
            '_fonts' => ['Title' => ['enabled' => true, 'fallback' => 'Roboto']],
            'Shared' => ['v' => 'Branch value', 't' => 'H'],
        ]));

        $data = $this->actingAs($owner)->getJson(
            route('translations.merge.data', ['uuid' => $uuid]) . '?branches=' . $branch->id
        );

        $data->assertOk();
        // The Main could see THAT the fonts differed, never which one
        $this->assertStringContainsString('NotoSans', $data->json('main_settings.fonts:Title.value'));
        $this->assertStringContainsString('Roboto', $data->json('branches.0.settings.fonts:Title.value'));
    }

    public function test_a_setting_taken_from_a_branch_is_copied_into_the_main(): void
    {
        [$owner, $uuid, $main, $branch] = $this->makeMergeView();

        file_put_contents(storage_path('app/private/' . $main->file_path), json_encode([
            '_uuid' => $uuid,
            '_fonts' => ['Title' => ['enabled' => true, 'fallback' => 'NotoSans']],
            'Shared' => ['v' => 'Main value', 't' => 'H'],
        ]));
        file_put_contents(storage_path('app/private/' . $branch->file_path), json_encode([
            '_fonts' => ['Title' => ['enabled' => true, 'fallback' => 'Roboto', 'type' => 'TMP']],
            'Shared' => ['v' => 'Branch value', 't' => 'H'],
        ]));

        $this->actingAs($owner)->post(route('translations.merge.apply', ['uuid' => $uuid]), [
            'settings_json' => json_encode([$branch->id => ['fonts:Title' => true]]),
        ])->assertRedirect();

        $saved = json_decode(file_get_contents(storage_path('app/private/' . $main->file_path)), true);

        // Copied whole from the branch, including what the comparison never displayed
        $this->assertSame('Roboto', $saved['_fonts']['Title']['fallback']);
        $this->assertSame('TMP', $saved['_fonts']['Title']['type']);
        // ...and the lines are untouched, since none were selected
        $this->assertSame('Main value', $saved['Shared']['v']);
    }

    /**
     * What a contribution SAYS about its work, offered to the Main beside the work itself.
     *
     * 🔴 The merge dealt in lines and file settings only, so a contributor could write a clearer
     * description or link the fonts their contribution needs and nobody would ever see either.
     * The right to write them existed; the way to read them did not.
     */
    public function test_the_merge_serves_what_each_branch_says_about_its_work(): void
    {
        [$owner, $uuid, $main, $branch] = $this->makeMergeView();

        $main->forceFill(['notes' => 'The whole game.', 'resources_url' => null])->save();
        $branch->forceFill([
            'notes' => 'Menus reworded, and the credits.',
            'resources_url' => 'https://example.com/branch-fonts',
        ])->save();

        $data = $this->actingAs($owner)->getJson(
            route('translations.merge.data', ['uuid' => $uuid]) . '?branches=' . $branch->id
        );

        $data->assertOk()
            ->assertJsonPath('main_notes', 'The whole game.')
            ->assertJsonPath('branches.0.notes', 'Menus reworded, and the credits.')
            ->assertJsonPath('branches.0.resources_url', 'https://example.com/branch-fonts');
    }

    public function test_the_main_takes_a_description_in_its_own_final_wording(): void
    {
        // ⚠ A value, not "take branch N's": the screen pre-fills the contribution's wording and
        // lets the Main adjust it, exactly as it does for a translation line.
        [$owner, $uuid, $main, $branch] = $this->makeMergeView();

        $main->forceFill(['notes' => 'The whole game.'])->save();

        $this->actingAs($owner)->post(route('translations.merge.apply', ['uuid' => $uuid]), [
            'publication_json' => json_encode([
                'notes' => 'Menus and credits reworded.',
                'resources_url' => 'https://example.com/pack',
            ]),
        ])->assertRedirect();

        $main->refresh();
        $this->assertSame('Menus and credits reworded.', $main->notes);
        $this->assertSame('https://example.com/pack', $main->resources_url);
    }

    public function test_a_merge_never_takes_whether_a_translation_is_finished(): void
    {
        // Finished descends from the Main to its contributions and never travels back. The
        // screen offers no row for it; a forged field must not create one.
        [$owner, $uuid, $main, $branch] = $this->makeMergeView();

        $main->forceFill(['status' => 'in_progress'])->save();

        $this->actingAs($owner)->post(route('translations.merge.apply', ['uuid' => $uuid]), [
            'publication_json' => json_encode(['status' => 'complete', 'notes' => 'Reworded.']),
        ])->assertRedirect();

        $main->refresh();
        $this->assertSame('in_progress', $main->status);
        $this->assertSame('Reworded.', $main->notes);
    }

    public function test_a_link_that_is_not_a_web_address_is_refused(): void
    {
        // It goes on the Main's public page, and it arrives from a form.
        [$owner, $uuid, $main, $branch] = $this->makeMergeView();

        $this->actingAs($owner)->post(route('translations.merge.apply', ['uuid' => $uuid]), [
            'publication_json' => json_encode(['resources_url' => 'javascript:alert(1)']),
        ])->assertSessionHasErrors('error');

        $this->assertNull($main->refresh()->resources_url);
    }

    /**
     * A merge leaves a mark on the branch it took from.
     *
     * merged_at shipped with the lineage migration and nothing ever wrote it, so "this Main is
     * ignoring your work" could not be told apart from "this Main has not merged anything yet" —
     * the difference between being overlooked and being early, which is the whole point of the
     * question. Stamped on the branch, because that is the side asking.
     */
    public function test_merging_a_branch_records_that_it_was_taken_in(): void
    {
        [$owner, $uuid, $main, $branch] = $this->makeMergeView();

        $this->assertNull($branch->merged_at, 'Nothing has taken this work in yet.');
        $before = $branch->updated_at;

        $this->actingAs($owner)->post(route('translations.merge.apply', ['uuid' => $uuid]), [
            'selections_json' => json_encode([
                ['key' => 'Shared', 'value' => 'Branch value', 'tag' => 'H', 'source' => 'branch_' . $branch->id],
            ]),
        ])->assertRedirect();

        $branch->refresh();

        $this->assertNotNull($branch->merged_at);
        // Merging is not a content change: touching updated_at would move the branch in every
        // list ordered by freshness, for something its author did not do.
        $this->assertEquals($before, $branch->updated_at);
    }

    public function test_settings_of_a_translation_outside_this_lineage_are_ignored(): void
    {
        [$owner, $uuid, $main] = $this->makeMergeView();
        $stranger = User::factory()->create()->refresh();
        $otherGame = Game::forceCreate(['name' => 'Other', 'slug' => 'other-' . uniqid()]);
        $foreign = $this->makeTranslation($stranger, $otherGame, (string) \Illuminate\Support\Str::uuid(), 'branch', [
            '_fonts' => ['Title' => ['enabled' => false]],
        ]);

        // A branch id is a number in a form: it must be checked against THIS lineage
        $this->actingAs($owner)->post(route('translations.merge.apply', ['uuid' => $uuid]), [
            'settings_json' => json_encode([$foreign->id => ['fonts:Title' => true]]),
        ]);

        $saved = json_decode(file_get_contents(storage_path('app/private/' . $main->file_path)), true);
        $this->assertArrayNotHasKey('_fonts', $saved);
    }

    public function test_show_renders_for_owner_and_keeps_mode_in_switcher(): void
    {
        [$owner, $uuid] = $this->makeMergeView();

        $response = $this->actingAs($owner)->get(route('translations.merge', ['uuid' => $uuid, 'mode' => 'edit']));

        $response->assertOk();
        $html = $response->getContent();
        // The client editor container and its data URL carry the mode
        $this->assertStringContainsString('x-data="mergeView"', $html);
        $this->assertStringContainsString('mode=edit', html_entity_decode($html));
        // Mode switcher present (branches exist)
        $this->assertStringContainsString('mode=merge', html_entity_decode($html));
    }

    public function test_both_metadata_blocks_start_folded_on_the_lines_grid(): void
    {
        // 🔴 Two things at once, and both were wrong before. They are FOLDED, because the screen
        // is a line-by-line merge and a block sitting open above the table pushes the actual work
        // off the screen. And they are the SAME GRID as the lines: the widths are one stylesheet
        // rule per [data-col], so carrying the same column names is what makes them line up,
        // follow the same drag and freeze with the same pin.
        [$owner, $uuid] = $this->makeMergeView();

        $html = $this->actingAs($owner)
            ->get(route('translations.merge', ['uuid' => $uuid]))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('settingsOpen: false', $html);
        $this->assertStringContainsString('publicationOpen: false', $html);

        $this->assertStringContainsString(__('merge.block_file_settings'), $html);
        $this->assertStringContainsString(__('merge.block_description'), $html);

        // Three editor grids on the page now: the two folded blocks and the lines themselves.
        $this->assertSame(3, substr_count($html, 'class="editor-grid'));

        // No checkbox left in either: a value is taken by clicking its cell, as a line is.
        $this->assertStringNotContainsString('toggleSettingRow', $html);
        $this->assertStringNotContainsString('togglePublicationRow', $html);
    }

    public function test_choosing_a_version_highlights_it_and_rewrites_nothing(): void
    {
        // 🔴 The rule every grid on this site obeys, and the one this screen's metadata rows
        // broke: a selection lights the chosen cell, and a cell's TEXT changes on one occasion
        // only — a manual rewording, which is what the purple says. Copying a taken value into
        // the Main's cell destroys the wording being compared against, and makes the colour mean
        // two things at once.
        [$owner, $uuid] = $this->makeMergeView();

        $html = $this->actingAs($owner)
            ->get(route('translations.merge', ['uuid' => $uuid]))
            ->assertOk()
            ->getContent();

        // The Main's cell shows its own value or a rewording — never the taken one.
        $this->assertStringContainsString(
            'return this.publicationValues[row.id] ?? row.mineValue;', $html);

        // Purple on a rewording and on nothing else.
        $this->assertStringContainsString(
            "publicationPick[row.id] === 'manual' ? 'text-purple-300' : ''", $html);

        // The value is resolved when the merge is applied, not copied on click.
        $this->assertStringContainsString("pick === 'manual'", $html);
    }

    public function test_the_metadata_blocks_are_not_emptied_by_the_modified_filter(): void
    {
        // 🔴 The defect this locks: "modified only" defaults to on, and filtering these two
        // tables on "already picked" emptied BOTH of them at load — a screen showing nothing
        // and looking broken. They are built from differences, so every row in them is already
        // something to decide and the predicate is true of all of them.
        [$owner, $uuid] = $this->makeMergeView();

        $html = $this->actingAs($owner)
            ->get(route('translations.merge', ['uuid' => $uuid]))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('get visibleSettingsRows() {', $html);
        $this->assertStringNotContainsString('this.settingsPick[row.id] !== undefined);', $html);

        // What a contribution ADDS where the Main holds nothing is pre-taken, as a line is.
        // A disagreement is a tie with no tag to settle it, and a tie goes to the Main.
        $this->assertStringContainsString('applyMetadataDefaults()', $html);
        $this->assertStringContainsString('if (row.mineRaw) continue;', $html);
    }

    public function test_every_contested_line_arrives_already_answered(): void
    {
        // 🔴 Measured on a real lineage (2536 keys): 56 rows need a decision, and the screen now
        // arrives with 56 answers — 38 taken from a contribution, 18 settled on the Main, none
        // left blank. What is left blank is what never gets settled: the row vanishes from any
        // filtered view, the merge saves without it, and the contributor never learns whether
        // they were read.
        //
        // Three rules, and the third is the one that was missing:
        //   · a line only a contribution has  -> taken
        //   · both hold one, better tag wins  -> H over V over A
        //   · a tie                           -> the Main keeps it, AND IT IS RECORDED
        [$owner, $uuid] = $this->makeMergeView();

        $html = $this->actingAs($owner)
            ->get(route('translations.merge', ['uuid' => $uuid]))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('applySmartDefaults()', $html);
        // ⚠ The scale itself is no longer in the page: it went down to the shared core, because
        // two screens were ranking the same three letters from two hand-written maps and a
        // barème that decides who wins a merge is the last thing that should exist twice.
        $this->assertStringContainsString('this.tagRank(', $html);
        $this->assertStringContainsString("source: 'main',", $html);

        // ⚠ A contribution can be a TAG and not a word: reading the Main's machine translation
        // and marking it correct changes no text. Comparing values alone dropped every one of
        // them — seventeen on that lineage, none ever settled.
        $this->assertStringContainsString(
            'this.getTag(entry) === this.getTag(mainEntry)) continue;', $html);

        // ⚠ The button counts what it is about to answer with the SAME method that answers it.
        // Counting every unanswered key instead offered to settle 2480 lines both sides already
        // agree on — rows it would not have touched.
        $this->assertStringContainsString('bestContributionFor(key)', $html);
        $this->assertStringContainsString('suggestTheRest()', $html);

        // ⭐ Two contributions of equal quality on one line are separated by the stars the owner
        // already gave their authors, not by asking again line by line. Unrated or equal leaves
        // the first in front — the list is already ordered unreviewed, best rated, most recent.
        $this->assertStringContainsString('rating > best.rating', $html);

        // The screen opens on the categories, not on "only what is already picked": unticking a
        // row must not make it disappear from the review it belongs to.
        $this->assertStringContainsString('modifiedOnly: false', $html);
        $this->assertStringContainsString('catOther: false', $html);
    }

    public function test_the_merge_data_carries_what_the_owner_thinks_of_each_contribution(): void
    {
        // The stars are the tie-break, so they have to reach the client. They were rendered in
        // the branch selector and left out of the payload the defaults read.
        [$owner, $uuid, $main, $branch] = $this->makeMergeView();

        $branch->forceFill(['main_rating' => 4])->save();

        $this->actingAs($owner)
            ->getJson(route('translations.merge.data', ['uuid' => $uuid]) . '?branches=' . $branch->id)
            ->assertOk()
            ->assertJsonPath('branches.0.main_rating', 4);
    }

    public function test_the_description_block_opens_and_keeps_the_main_s_own_words(): void
    {
        // 🔴 Two rules, both of them the owner's voice.
        //
        // A description is not a line: it speaks in the Main owner's name, on their public page.
        // A contribution proposing one is proposing how somebody else's translation presents
        // itself. Measured on a real lineage, four contributions all proposed the same sentence
        // over a Main that had said nothing, and the defaults adopted the first — so the Main is
        // picked, the row shows as answered, and taking a contribution stays one click away.
        //
        // And the block OPENS when it holds something: it lists differences only, so a folded one
        // is a difference nobody sees — which is what the merge missed before it existed.
        [$owner, $uuid] = $this->makeMergeView();

        $html = $this->actingAs($owner)
            ->get(route('translations.merge', ['uuid' => $uuid]))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString("this.publicationPick[row.id] = 'main';", $html);
        // ⚠ Open on a DISAGREEMENT, not on having rows. Both tables now list what the file
        // carries — seven fonts, two exclusions, two variables on a real one — and "this
        // translation has settings" is true of nearly every file and not a reason to push the
        // lines down the page.
        $this->assertStringContainsString('this.publicationOpen = this.publicationDifferenceCount > 0;', $html);
        $this->assertStringContainsString('this.settingsOpen = this.settingsDifferenceCount > 0;', $html);

        // ⚠ The footer and the Save button carry the same word; they must carry the same number.
        $this->assertStringNotContainsString(
            '<span class="text-white font-bold" x-text="selectionCount"></span>', $html);
    }

    public function test_only_one_horizontal_bar_moves_all_three_grids(): void
    {
        // 🔴 Never two bars for one movement — the rule the mirrored scrollbar was built on,
        // one step further. Three tables on the same columns each scrolling on their own showed
        // three positions of the same thing, above a shared bar that agreed with none of them.
        [$owner, $uuid] = $this->makeMergeView();

        $html = $this->actingAs($owner)
            ->get(route('translations.merge', ['uuid' => $uuid]))
            ->assertOk()
            ->getContent();

        // Both metadata boxes follow, and neither draws a bar (see app.css).
        $this->assertSame(2, substr_count($html, 'data-hscroll-follow'));
    }

    public function test_the_tables_above_the_lines_carry_no_tag_column_of_their_own(): void
    {
        // 🔴 A setting has no tag and a description has no tag, so that column held a dash — an
        // empty stripe down both tables, in the one place where the lines below carry something.
        //
        // Kept as a COLUMN, because dropping it would shift everything after it and the tables
        // would stop lining up. Merged as a CELL, because there is nothing to put in it. And the
        // merged cell carries no data-col: the width rules are per column, and one of them would
        // squeeze the pair down to a single column's width.
        [$owner, $uuid] = $this->makeMergeView();

        $html = $this->actingAs($owner)
            ->get(route('translations.merge', ['uuid' => $uuid]))
            ->assertOk()
            ->getContent();

        // Exactly one tag cell in a body, and it belongs to the lines.
        $this->assertSame(1, substr_count($html, '<td data-col="mainTag"'));

        // ⚠ Widths are photographed at load when a following table is on screen. Without it each
        // table is laid out on its own content until somebody drags an edge, and a table of two
        // rows is narrower than one of six thousand — measured: 37px short on the Main column.
        $this->assertStringContainsString('alignGridsToEachOther()', $html);
    }

    public function test_the_tag_scale_exists_once(): void
    {
        // 🔴 The invariant behind moving it: ONE copy in the JavaScript. A second one is two
        // screens free to disagree about which contribution wins, and nothing would say so.
        $js = collect(glob(resource_path('js/components/*.js')))
            ->merge(glob(resource_path('views/**/*.blade.php')))
            ->merge(glob(resource_path('views/*/*.blade.php')));

        $copies = $js->filter(fn ($file) => str_contains(file_get_contents($file), "'H': 3, 'V': 2, 'A': 1"))
            ->map(fn ($file) => basename($file))
            ->values()
            ->all();

        $this->assertSame(['translation-editor.js'], $copies);
    }

    public function test_a_row_says_which_way_its_answer_is(): void
    {
        // 🔴 A row whose answer is off screen reads as a row nobody answered. Measured: the box
        // ended at 1444px and the third contribution began at 1450, so with four contributions
        // this is the ordinary case. The mark rides the last frozen cell on one side and a
        // zero-width rail frozen to the box's right edge on the other, floats over the rows
        // rather than taking width from them, and scrolls to the answer when clicked.
        [$owner, $uuid] = $this->makeMergeView();

        $html = $this->actingAs($owner)
            ->get(route('translations.merge', ['uuid' => $uuid]))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('refreshOffScreenSides()', $html);
        $this->assertStringContainsString('goToLineAnswer(key)', $html);
        $this->assertStringContainsString('class="answer-rail"', $html);
    }

    public function test_no_alpine_expression_uses_a_grammar_the_csp_build_refuses(): void
    {
        // 🔴 This site runs @alpinejs/csp, whose expression parser is deliberately restricted —
        // and an expression it cannot parse DOES NOT THROW: it evaluates to nothing. Both traps
        // were hit in one afternoon on this screen, and both looked identical from the outside:
        // every mark rendered, every one hidden, nothing in the console.
        //
        //   · optional chaining          selections[key]?.source
        //   · a call compared to a value  answerArrow(x) === 'left'
        //
        // The idiom the rest of the project uses is a bare call returning a boolean.
        $views = collect(glob(resource_path('views/**/*.blade.php')))
            ->merge(glob(resource_path('views/*/*.blade.php')))
            ->merge(glob(resource_path('views/*.blade.php')));

        $offenders = [];
        foreach ($views as $file) {
            $markup = explode('<script', file_get_contents($file))[0];

            if (preg_match('/(x-show|x-if|:class|x-text)="[^"]*\?\./', $markup)) {
                $offenders[] = basename($file) . ' (optional chaining)';
            }
            if (preg_match('/(x-show|x-if)="[^"]*\w\([^"]*\)\s*===/', $markup)) {
                $offenders[] = basename($file) . ' (call compared to a value)';
            }
        }

        $this->assertSame([], $offenders);
    }

    public function test_a_tag_cell_is_framed_whenever_the_tag_will_change(): void
    {
        // 🔴 The marker existed and only ever fired on an EXPLICIT change through the dropdown. A
        // tag also changes by being chosen — taking a contribution takes its tag, and a selection
        // promotes a machine translation to validated — and those rows changed tag with nothing
        // said. Measured on a real lineage: 24 of the 50 rendered rows. That silence is how
        // somebody reads a V beside the Main's value, concludes the Main was already validated,
        // and reports the arbitration as broken when it was right.
        //
        // The same rule and the same class name in all three editors: one gesture, one mark.
        [$owner, $uuid] = $this->makeMergeView();

        $html = $this->actingAs($owner)
            ->get(route('translations.merge', ['uuid' => $uuid]))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('mainTagCellClass(key)', $html);
        $this->assertStringNotContainsString("hasTagChange(key) ? 'tag-changed-cell'", $html);

        foreach ([
            'views/translations/merge-preview.blade.php' => 'localTagCellClass(key)',
            'views/edit-session/show.blade.php' => 'entryTagCellClass(key)',
        ] as $view => $needle) {
            $source = file_get_contents(resource_path($view));
            $this->assertStringContainsString($needle, $source, $view);
            $this->assertStringNotContainsString("hasTagChange(key) ? 'tag-changed-cell'", $source, $view);
        }

        // ⚠ And it carries the ring its siblings carry: a change said in a wash of colour, one
        // pixel from a change said with a frame, reads as nothing at all.
        $this->assertStringContainsString(
            'box-shadow: inset 0 0 0 2px rgb(168 85 247)',
            file_get_contents(resource_path('css/app.css')));
    }

    public function test_the_two_tables_mark_a_difference_the_way_a_line_does(): void
    {
        // 🔴 The lines have marked this from the start — green where the Main holds nothing,
        // yellow where the two disagree — and the settings and the description said nothing at
        // all. The same fact was worth a colour on one row of the screen and none three rows
        // above it, leaving a reader to work out for themselves what the grid underneath tells
        // them at a glance.
        [$owner, $uuid] = $this->makeMergeView();

        $html = $this->actingAs($owner)
            ->get(route('translations.merge', ['uuid' => $uuid]))
            ->assertOk()
            ->getContent();

        // The same two thresholds and the same two colours as branchCellTint / branchTextTint.
        $this->assertStringContainsString('metaCellTint(row, branch.id)', $html);
        $this->assertStringContainsString('metaTextTint(row, branch.id)', $html);
        $this->assertStringContainsString("'bg-green-900/20'", $html);
        $this->assertStringContainsString("'bg-yellow-900/20'", $html);
    }

    public function test_a_translation_shows_its_own_settings_with_no_contributions(): void
    {
        // 🔴 The rows were built from the contributions alone, so the edit mode — a translation
        // worked on by itself — listed nothing at all. Measured on a real file: seven fonts, an
        // image, two exclusions, two variables and a resources link, none of them anywhere on the
        // page, while its author was looking for exactly those.
        //
        // They are part of what a translation IS. The merge is one of the places they are read,
        // not the reason they exist.
        [$owner, $uuid] = $this->makeMergeView();

        $html = $this->actingAs($owner)
            ->get(route('translations.merge', ['uuid' => $uuid, 'mode' => 'edit']))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('for (const key of Object.keys(mainSettings)) row(key', $html);

        // ⚠ And nothing on those tables pretends to be a choice when there is nobody to take
        // from: no hand cursor, no hint describing a gesture that does not exist, no default
        // lighting the Main's cell as "chosen".
        $this->assertStringContainsString('canTakeContributions', $html);
        $this->assertStringContainsString("canTakeContributions && 'merge-cell'", $html);
        $this->assertStringContainsString('publicationOpen && canTakeContributions', $html);
    }

    public function test_show_is_owner_only(): void
    {
        [, $uuid] = $this->makeMergeView();
        $stranger = User::factory()->create()->refresh();

        $this->actingAs($stranger)
            ->get(route('translations.merge', ['uuid' => $uuid]))
            ->assertNotFound();
    }

    public function test_data_returns_main_and_selected_branches_to_owner(): void
    {
        [$owner, $uuid, , $branch] = $this->makeMergeView();

        $response = $this->actingAs($owner)->getJson(
            route('translations.merge.data', ['uuid' => $uuid]) . '?mode=merge&branches[]=' . $branch->id
        );

        $response->assertOk();
        $payload = $response->json();
        $this->assertSame('Main value', $payload['main']['Shared']['v']);
        // Metadata keys are stripped
        $this->assertArrayNotHasKey('_uuid', $payload['main']);
        $this->assertCount(1, $payload['branches']);
        $this->assertSame('Branch value', $payload['branches'][0]['content']['Shared']['v']);
    }

    public function test_data_ignores_branches_in_edit_mode(): void
    {
        [$owner, $uuid, , $branch] = $this->makeMergeView();

        $response = $this->actingAs($owner)->getJson(
            route('translations.merge.data', ['uuid' => $uuid]) . '?mode=edit&branches[]=' . $branch->id
        );

        $response->assertOk();
        $this->assertSame([], $response->json('branches'));
    }

    public function test_data_is_owner_only(): void
    {
        [, $uuid] = $this->makeMergeView();
        $stranger = User::factory()->create()->refresh();

        $this->actingAs($stranger)
            ->getJson(route('translations.merge.data', ['uuid' => $uuid]))
            ->assertNotFound();
    }

    public function test_apply_selections_deletions_and_tag_changes(): void
    {
        [$owner, $uuid, $main, $branch] = $this->makeMergeView();

        $response = $this->actingAs($owner)->post(route('translations.merge.apply', ['uuid' => $uuid]), [
            'mode' => 'merge',
            'branches' => [$branch->id],
            'selections_json' => json_encode([
                // Take the branch version of Shared (H stays H)
                ['key' => 'Shared', 'value' => 'Branch value', 'tag' => 'H', 'source' => 'branch_' . $branch->id],
                // Add the branch-only key (A selected by a human -> V)
                ['key' => 'BranchOnly', 'value' => 'Branch only', 'tag' => 'A', 'source' => 'branch_' . $branch->id],
            ]),
            'deletions_json' => json_encode(['MainOnly']),
            'tag_changes_json' => '',
        ]);

        $response->assertRedirect();

        $stored = json_decode(file_get_contents($main->fresh()->getSafeFilePath()), true);
        $this->assertSame(['v' => 'Branch value', 't' => 'H'], $stored['Shared']);
        $this->assertSame(['v' => 'Branch only', 't' => 'V'], $stored['BranchOnly']);
        $this->assertArrayNotHasKey('MainOnly', $stored);
        // Metadata untouched
        $this->assertSame($uuid, $stored['_uuid']);
    }

    public function test_apply_accepts_explicit_validate_tag_change(): void
    {
        [$owner, $uuid, $main] = $this->makeMergeView();

        // The tag dropdown offers V (validate), A (invalidate) and S (skip):
        // all three must be written as-is
        $response = $this->actingAs($owner)->post(route('translations.merge.apply', ['uuid' => $uuid]), [
            'mode' => 'edit',
            'selections_json' => '',
            'deletions_json' => '',
            'tag_changes_json' => json_encode([
                ['key' => 'MainOnly', 'tag' => 'V', 'value' => 'Main only'],
            ]),
        ]);

        $response->assertRedirect();
        $response->assertSessionDoesntHaveErrors();

        $stored = json_decode(file_get_contents($main->fresh()->getSafeFilePath()), true);
        $this->assertSame(['v' => 'Main only', 't' => 'V'], $stored['MainOnly']);
    }

    // ── Branch authors edit their own work too ───────────────────────────
    // Correcting one's own lines from the site is not a Main privilege. What
    // stays Main-only is the merge view: a branch never sees another branch.

    /** The branch author, not the Main owner. */
    private function branchAuthor(Translation $branch): User
    {
        return User::findOrFail($branch->user_id);
    }

    public function test_a_branch_author_can_edit_their_own_translation(): void
    {
        [, $uuid, , $branch] = $this->makeMergeView();

        $response = $this->actingAs($this->branchAuthor($branch))
            ->get(route('translations.merge', ['uuid' => $uuid, 'mode' => 'edit']));

        $response->assertOk();
        $this->assertStringContainsString('x-data="mergeView"', $response->getContent());
    }

    public function test_a_branch_author_never_reaches_the_merge_mode(): void
    {
        [, $uuid, , $branch] = $this->makeMergeView();
        $author = $this->branchAuthor($branch);

        // Asking for it explicitly must not leak the Main's other contributors
        $html = html_entity_decode(
            $this->actingAs($author)
                ->get(route('translations.merge', ['uuid' => $uuid, 'mode' => 'merge']))
                ->assertOk()
                ->getContent()
        );
        $this->assertStringContainsString('mode=edit', $html);
        $this->assertStringNotContainsString('mode=merge', $html);

        $payload = $this->actingAs($author)
            ->getJson(route('translations.merge.data', ['uuid' => $uuid, 'mode' => 'merge']))
            ->assertOk()
            ->json();
        $this->assertSame([], $payload['branches']);
        // And the content served is the branch's own, never the Main's
        $this->assertArrayHasKey('BranchOnly', $payload['main']);
    }

    public function test_a_branch_author_saves_into_their_own_file(): void
    {
        [, $uuid, $main, $branch] = $this->makeMergeView();

        $this->actingAs($this->branchAuthor($branch))
            ->post(route('translations.merge.apply', ['uuid' => $uuid]), [
                'mode' => 'edit',
                'selections_json' => json_encode([
                    ['key' => 'BranchOnly', 'value' => 'Corrected', 'tag' => 'A', 'source' => 'manual'],
                ]),
            ])
            ->assertRedirect()
            ->assertSessionDoesntHaveErrors();

        $branchContent = json_decode(file_get_contents($branch->fresh()->getSafeFilePath()), true);
        $mainContent = json_decode(file_get_contents($main->fresh()->getSafeFilePath()), true);

        $this->assertSame('Corrected', $branchContent['BranchOnly']['v']);
        $this->assertArrayNotHasKey('BranchOnly', $mainContent);
        $this->assertSame('Main value', $mainContent['Shared']['v']);
    }

    // ── Concurrent edits ─────────────────────────────────────────────────
    // The normal multi-device case: correcting on a laptop while the game
    // uploads captures from the desktop.

    public function test_a_line_changed_on_the_server_is_not_overwritten(): void
    {
        [$owner, $uuid, $main] = $this->makeMergeView();

        // The game uploaded while the page was open
        $path = $main->getSafeFilePath();
        $content = json_decode(file_get_contents($path), true);
        $content['Shared'] = ['v' => 'Uploaded by the game', 't' => 'A'];
        file_put_contents($path, json_encode($content, JSON_UNESCAPED_UNICODE));

        $response = $this->actingAs($owner)->post(route('translations.merge.apply', ['uuid' => $uuid]), [
            'mode' => 'edit',
            'selections_json' => json_encode([
                // base = what the page loaded, now stale
                ['key' => 'Shared', 'value' => 'My edit', 'tag' => 'H', 'source' => 'manual', 'base' => 'Main value'],
                // untouched by the game: must still apply
                ['key' => 'MainOnly', 'value' => 'Also mine', 'tag' => 'H', 'source' => 'manual', 'base' => 'Main only'],
            ]),
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('warning');

        $stored = json_decode(file_get_contents($main->fresh()->getSafeFilePath()), true);
        $this->assertSame('Uploaded by the game', $stored['Shared']['v'], 'The concurrent change must survive.');
        $this->assertSame('Also mine', $stored['MainOnly']['v'], 'One conflict must not cost the other lines.');
    }

    public function test_an_unchanged_line_still_applies_with_its_base(): void
    {
        [$owner, $uuid, $main] = $this->makeMergeView();

        $this->actingAs($owner)->post(route('translations.merge.apply', ['uuid' => $uuid]), [
            'mode' => 'edit',
            'selections_json' => json_encode([
                ['key' => 'Shared', 'value' => 'My edit', 'tag' => 'H', 'source' => 'manual', 'base' => 'Main value'],
            ]),
        ])->assertRedirect()->assertSessionMissing('warning');

        $stored = json_decode(file_get_contents($main->fresh()->getSafeFilePath()), true);
        $this->assertSame('My edit', $stored['Shared']['v']);
    }
}
