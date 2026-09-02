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

        // ⚠ The fold state lives in the shared module now, not in the page, and the page decides
        // it from the difference counts once the data has loaded.
        $this->assertStringContainsString('this.settingsOpen = this.settingsDifferenceCount() > 0;', $html);
        $this->assertStringContainsString('this.publicationOpen = this.publicationDifferenceCount() > 0;', $html);

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

        // ⚠ A method, not a getter: it moved next to the shared module's default, and that module
        // is SPREAD into the core — a spread evaluates a getter and copies what it returned.
        $this->assertStringContainsString('visibleSettingsRows() {', $html);
        $this->assertStringNotContainsString('this.settingsPick[row.id] !== undefined);', $html);

        // What a contribution ADDS where the Main holds nothing is pre-taken, as a line is.
        // A disagreement is a tie with no tag to settle it, and a tie goes to the Main.
        $this->assertStringContainsString('applyMetadataDefaults()', $html);

        // 🔴 And "a tie goes to the Main" is now SAID rather than implied by silence.
        //
        // The tie used to be left with no entry at all, which wrote the same file and cost the
        // screen the only thing it had to show: a row the owner had settled on their own value and
        // a row nobody had opened were the same state, and clicking the Main's cell deleted an
        // entry and lit nothing. Every row carries its answer now, `main` included — the shape the
        // descriptions in this same view and the settings block on the comparison screen already
        // had.
        $this->assertStringContainsString("this.settingsPick[row.id] = branch ? branch.id : 'main';", $html);
        $this->assertStringContainsString("if (branchId === undefined || branchId === 'main') continue;", $html);
    }

    public function test_every_contested_line_arrives_answered_and_only_some_are_claimed(): void
    {
        // 🔴 Measured on a real lineage (2536 keys): 56 rows need a decision, and all 56 arrive
        // answered — leaving any of them blank means the contribution is never replied to and the
        // row vanishes from every filtered view. 38 are taken from a contribution that outranks
        // what the Main holds; the other 18 keep the Main's own machine line.
        //
        // 🔴 **And those 18 are held WITHOUT being claimed.** Taking a version is written back with
        // A promoted to V (TranslationService::resolveMergedTag) — picking means "I read this". So
        // an answer the screen made on its own may not carry that promotion: opening the page and
        // pressing Merge used to mark 18 machine lines human-checked with nobody having read one,
        // and the file's quality bar rose by itself.
        //
        // They arrive with `auto`, drawn paler and dashed, and one click on the same column turns
        // the hold into an ordinary pick — which is what validating is, said deliberately by
        // somebody with both versions in front of them.
        //
        // ⚠ A refusal stands LEVEL with a hand-written line. So H/H, H/S and S/H are ties too, and
        // the ladder used to rank S with the machine — which let a contribution overwrite a Main's
        // refusal with nobody asked.
        [$owner, $uuid] = $this->makeMergeView();

        $html = $this->actingAs($owner)
            ->get(route('translations.merge', ['uuid' => $uuid]))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('applySmartDefaults()', $html);
        // ⚠ The scale itself is no longer in the page: it went down to the shared core, because
        // two screens were ranking the same three letters from two hand-written maps and a
        // barème that decides who wins a merge is the last thing that should exist twice.
        // ⚠ The whole ENTRY is weighed, never the letter alone: a captured line is an H with
        // nothing in it, and ranking it on its tag had a blank capture on the Main outrank every
        // real translation a contributor offered.
        $this->assertStringContainsString('this.priorityOf(entry)', $html);
        $this->assertStringContainsString('this.priorityOf(mainEntry)', $html);
        $this->assertStringNotContainsString('this.tagRank(', $html);

        // 🔴 **The defaults never write a pick pointing at the Main.** That is the whole rule
        // above, and the one line of code that would break it is this one — so it is asserted
        // absent rather than described in a comment nobody re-reads. A click still produces it;
        // `select()` builds its own object, which is why this string is unique to the defaults.
        $this->assertStringContainsString('if (best.rank > mainTag) {', $html);

        // ⚠ The Main's side of the defaults goes through defaultSelection, which is also what an
        // undo returns to — see the test below. Written inline here once, it drifted from the undo
        // the first time either was touched.
        $this->assertStringContainsString('const held = this.defaultSelection(key);', $html);

        // ⚠ And the gesture itself is the CORE's, not this page's: the preview screen runs the
        // identical one on the identical grid, and two copies of it is how the same promotion
        // stayed open on one screen after being closed on the other. The page keeps only what
        // legitimately differs — its guard against a pick that would write nothing.
        $this->assertStringContainsString(
            'if (this.advancePick(key, source)) return;',
            file_get_contents(resource_path('js/components/translation-editor.js')));
        $this->assertStringNotContainsString('select(key, source) {', $html);
        $this->assertStringContainsString('pickIsWorthRecording(key, source, picked) {', $html);

        // And the cell previews what will actually be written: an unclaimed hold keeps its A.
        $this->assertStringContainsString("sel.tag === 'A' && !sel.auto ? 'V' : sel.tag", $html);

        // ⚠ The mod's own interface is not a line of the game: out of the arbitration on both
        // sides. It travels in the same file today and will get one of its own.
        $this->assertStringContainsString('this.isGameLine(entry)) continue;', $html);

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
        // ⚠ The boxes are named after the core's situations (`catSame` folds `onlyOnTarget` in
        // here); they used to carry names this screen invented.
        $this->assertStringContainsString('modifiedOnly: false', $html);
        $this->assertStringContainsString('catSame: false', $html);
    }

    /**
     * 🔴 A merge can sit open for hours while the files move under it — another tab of the same
     * person, the mod uploading captures, a contribution updated by its author. Until now the only
     * thing that noticed was the per-line guard at save time, which is late: the reading was already
     * done against a file that had moved.
     *
     * ⚠ Every file it reads, not only the Main: a merge weighs several, and any of them moving
     * changes what the screen is proposing.
     */
    public function test_a_merge_can_be_asked_whether_its_files_have_moved(): void
    {
        [$owner, $uuid, $main, $branch] = $this->makeMergeView();

        $this->actingAs($owner)
            ->getJson(route('translations.merge.state', ['uuid' => $uuid]))
            ->assertOk()
            ->assertJsonPath('file_hash', $main->fresh()->file_hash)
            ->assertJsonPath('branches.' . $branch->id, $branch->fresh()->file_hash);
    }

    /** Same ownership rule as everything else here — a state is still somebody's business. */
    public function test_the_state_of_a_merge_is_owner_only(): void
    {
        [, $uuid] = $this->makeMergeView();

        $this->actingAs(User::factory()->create())
            ->getJson(route('translations.merge.state', ['uuid' => $uuid]))
            ->assertNotFound();
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
        $this->assertStringContainsString('this.publicationOpen = this.publicationDifferenceCount() > 0;', $html);
        $this->assertStringContainsString('this.settingsOpen = this.settingsDifferenceCount() > 0;', $html);

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

        $copies = $js->filter(fn ($file) => str_contains(file_get_contents($file), "'H': 3, 'S': 3, 'V': 2, 'A': 1"))
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
        // The same rule and the same class name in all three editors: one gesture, one mark — and
        // since 2026-08-20 it is not merely the same rule but the SAME CODE, `tagCellClass` in the
        // shared core. Three private copies under three names is three chances to drift, on the
        // one cell a merge is read from.
        [$owner, $uuid] = $this->makeMergeView();

        $html = $this->actingAs($owner)
            ->get(route('translations.merge', ['uuid' => $uuid]))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('tagCellClass(key)', $html);
        $this->assertStringNotContainsString("hasTagChange(key) ? 'tag-changed-cell'", $html);

        // ⚠ The comparison screen declares its cells in x-editor.side-cells, which it renders twice
        // — once per side. The markup left the page; the rule did not.
        foreach ([
            'views/components/editor/side-cells.blade.php',
            'views/edit-session/show.blade.php',
        ] as $view) {
            $source = file_get_contents(resource_path($view));
            $this->assertStringContainsString('tagCellClass(key)', $source, $view);
            $this->assertStringNotContainsString("hasTagChange(key) ? 'tag-changed-cell'", $source, $view);
        }

        // ⚠ And it is defined once. A page that reintroduced its own would pass every assertion
        // above while being exactly what this consolidation removed.
        $this->assertStringContainsString(
            'tagCellClass(key) {',
            file_get_contents(resource_path('js/components/translation-editor.js')));

        foreach (['mainTagCellClass', 'localTagCellClass', 'entryTagCellClass'] as $gone) {
            foreach ([
                'views/merge/show.blade.php',
                'views/translations/merge-preview.blade.php',
                'views/edit-session/show.blade.php',
            ] as $view) {
                $this->assertStringNotContainsString(
                    $gone . '(', file_get_contents(resource_path($view)),
                    "$view brought back a private copy of the tag marker");
            }
        }

        // ⚠ And it carries the ring its siblings carry: a change said in a wash of colour, one
        // pixel from a change said with a frame, reads as nothing at all.
        $this->assertStringContainsString(
            'box-shadow: inset 0 0 0 2px rgb(168 85 247)',
            file_get_contents(resource_path('css/app.css')));
    }

    /**
     * The frame says a tag WILL change. This says what it changes FROM.
     *
     * 🔴 **The commonest contribution of all moves no text.** Somebody re-reads the Main's machine
     * translation, marks it correct, and offers that: same words, better tag. The cell showed the
     * tag the save would produce and nothing else, so the screen put a V beside a V, the same
     * sentence twice, and asked its owner to settle a line that read as already settled — the very
     * report this test was written from. What was being offered WAS the tag, and the tag was the
     * one thing the screen kept to itself.
     *
     * ⚠ **All three editors, from one component.** The live editor previews a tag change too — a
     * rewritten line becomes human, a validated one becomes V — so a reader there had the same
     * blind spot for the same reason.
     */
    public function test_a_changing_tag_shows_the_one_it_replaces(): void
    {
        [$owner, $uuid] = $this->makeMergeView();

        $html = $this->actingAs($owner)
            ->get(route('translations.merge', ['uuid' => $uuid]))
            ->assertOk()
            ->getContent();

        // Two real chips and the arrow between them, rendered into the page.
        $this->assertStringContainsString("'tag-' + tagOnFile(key)", $html, 'the merge view hides the tag on file');
        $this->assertStringContainsString("'tag-' + tagAfterSave(key)", $html);
        $this->assertStringContainsString('tag-arrow', $html);
        $this->assertStringContainsString('tagWillChange(key)', $html);

        // ⚠ One component, used by every editor — not three renderings of one idea.
        foreach ([
            'views/merge/show.blade.php',
            'views/components/editor/side-cells.blade.php',
            'views/edit-session/show.blade.php',
        ] as $view) {
            $this->assertStringContainsString(
                '<x-editor-tag-cell', file_get_contents(resource_path($view)), $view);
        }

        // The chips are the ones the rest of the site uses; only the arrow is new.
        $css = file_get_contents(resource_path('css/app.css'));
        $this->assertMatchesRegularExpression('/\.tag-arrow\s*\{/', $css);
        $this->assertMatchesRegularExpression('/\.tag-transition\s*\{[^}]*nowrap/s', $css,
            'the pair may wrap, which would stack two chips and double every row');
    }

    /**
     * 🔴 A tag on a row the page does not hold is an ARRIVAL, and reads as one.
     *
     * Printed on its own, `H` says "this line is hand-written". The line is not there at all: what
     * is true is that it WILL BE one. Reported from the merge view, on a line only a contribution
     * had, where writing one's own translation showed a bare `H` beside three columns that
     * disagreed about whether the row existed.
     *
     * ⚠ The same arrow the rest of the cell already uses, with nothing on its left — there is no
     * chip to leave from. And the frame goes round it like any other cell whose content is not what
     * will be stored.
     */
    public function test_a_tag_arriving_where_the_page_holds_none_reads_as_an_arrival(): void
    {
        $core = file_get_contents(resource_path('js/components/translation-editor.js'));

        // Its own question, kept apart from tagWillChange: that one needs a stored tag to be about.
        $this->assertStringContainsString('tagArrives(key) {', $core);
        $this->assertStringContainsString(
            "return this.tagOnFile(key) === null && this.tagAfterSave(key) != null;", $core);

        // Both mean "not what will be saved", so both carry the frame.
        $this->assertStringContainsString(
            'this.tagWillChange(key) || this.tagArrives(key)', $core);

        // The arrow is drawn for it, in the one component every editor uses.
        $cell = file_get_contents(resource_path('views/components/editor-tag-cell.blade.php'));
        $this->assertStringContainsString('x-if="tagArrives(key)"', $cell);
        $this->assertStringContainsString('tag-arrow', $cell);

        // 🔴 And the cell is RENDERED on such a row. It was wrapped in a guard on the page holding
        // the line, so the arriving tag had nowhere to go: the cell took the frame that says "this
        // is not what will be stored" and then showed a grey dash.
        //
        // ⚠ The comparison screen asks it of `entryOnFile`, not of a column by name: its target
        // swaps with the direction, and naming one side would have guarded the wrong one every
        // time somebody published.
        foreach ([
            'views/merge/show.blade.php' => 'mainData',
            'views/components/editor/side-cells.blade.php' => 'entryOnFile(key)',
        ] as $view => $side) {
            $held = str_ends_with($side, ')') ? $side : "{$side}[key]";
            $html = file_get_contents(resource_path($view));
            $this->assertStringContainsString(
                "x-if=\"{$held} !== undefined || tagArrives(key)\"", $html, $view);
            $this->assertStringContainsString(
                "x-if=\"{$held} === undefined && !tagArrives(key)\"", $html, $view);
        }
    }

    /**
     * 🔴 Both arbitrating screens run ONE mechanic, and neither keeps a copy of it.
     *
     * They show the same grid, the same columns and the same gesture, and each had written its own
     * answer to "what is this row on" — one an object, the other a bare string. So a rule fixed in
     * one went on being wrong in the other: the A → V promotion was closed on the merge view and
     * stayed open here, marking machine lines human-checked in a player's own file.
     *
     * ⚠ What legitimately differs is which columns exist and which one the result is built from,
     * and that is exactly what the two hooks carry — nothing else.
     */
    public function test_both_arbitrating_screens_share_one_selection_mechanic(): void
    {
        $preview = file_get_contents(resource_path('views/translations/merge-preview.blade.php'));
        $merge = file_get_contents(resource_path('views/merge/show.blade.php'));

        $core = file_get_contents(resource_path('js/components/translation-editor.js'));

        // 🔴 **The gesture, the cell's colour and "cancel everything" live in the core — once.**
        // They were written twice and had drifted: the comparison decided a rewording belonged to
        // the `local` column, which is the target only one way round, so publishing painted a
        // hand-written line in the colour of an ordinary pick.
        foreach (['select(key, source) {', 'getCellClass(key, source) {', 'clearAll() {'] as $shared) {
            $this->assertStringContainsString($shared, $core, "the core should own {$shared}");

            foreach (['merge view' => $merge, 'merge preview' => $preview] as $name => $html) {
                $this->assertStringNotContainsString($shared, $html, "{$name} kept its own {$shared}");
            }
        }

        // ⚠ A rewording belongs to the TARGET, whichever column that is — the whole point of the
        // consolidation, and the line that was wrong before it. It shows there either way: as the
        // answer, or as typing a pick has set aside and the save will not take.
        $this->assertStringContainsString(
            'if (source === this.targetSource() && this.isEdited(key)) {', $core);
        $this->assertStringContainsString(
            "return this.editIsHeld(key) ? 'selected-manual' : 'edit-set-aside';", $core);

        foreach (['merge view' => $merge, 'merge preview' => $preview] as $name => $html) {
            $this->assertStringContainsString('targetSource() {', $html, $name);
        }
        $this->assertStringContainsString('selection-unclaimed', $core);

        // 🔴 The preview runs BOTH ways, and its two roles swap with the direction: comparing into
        // the game builds its result from the player's file, publishing from the server's. Asking
        // one hook both questions held the wrong side the moment somebody published.
        $this->assertStringContainsString("return this.toLocal ? 'local' : 'online';", $preview);
        $this->assertStringContainsString("return [this.toLocal ? 'online' : 'local'];", $preview);

        // ⚠ And every screen answers "what does this column hold" in one place, so that nothing
        // reading a side has to know whether it is a branch, an upload or a server file.
        foreach (['merge view' => $merge, 'merge preview' => $preview] as $name => $html) {
            $this->assertStringContainsString('entryOf(key, id) {', $html, $name);
            $this->assertStringContainsString('sourceIds() {', $html, $name);
        }

        // And nothing reads the selection's shape directly any more.
        $this->assertStringNotContainsString("this.selections[key] === 'local'", $preview);
        $this->assertStringNotContainsString("this.selections[key] = 'local'", $preview);
        $this->assertStringNotContainsString("this.selections[key] = 'online'", $preview);

        // ⚠ A draft saved before selections became objects still opens. Dropping it would lose a
        // review somebody had half finished.
        $this->assertStringContainsString("if (typeof sel !== 'string')", $preview);

        // 🔴 A tie goes to the side the RESULT is built from, which is not the same column in both
        // directions. Written as one column either way it was right one time in two — and the wrong
        // way round it swaps a line for another nobody preferred.
        $this->assertStringContainsString('if (localPriority === onlinePriority) {', $preview);
        $this->assertStringContainsString('const target = this.targetSource();', $preview);

        // And the cell previews what the save writes: an unclaimed hold keeps its A here too.
        // ⚠ Asked of the picked side, not of the local column — the tag being previewed is the
        // TARGET's, and the target is the server's file when publishing.
        $this->assertStringContainsString(
            "return (tag === 'A' && sent && !this.isUnclaimed(key)) ? 'V' : tag;", $preview);
        $this->assertStringContainsString('const written = this.tagOnFile(key);', $preview);

        // 🔴 And the promotion also needs the save to be WRITING that row. Publishing, a value
        // already on the server is sent by nobody, so announcing a V there promised a reading the
        // file would never record. One statement of it, read by the chip AND by the counter — they
        // each had their own, and the counter offered to save nine lines it would not have moved.
        $this->assertStringContainsString('const sent = this.willWriteFromSource(key);', $preview);
        $this->assertStringContainsString('return this.willWriteFromSource(key);', $preview);
        $this->assertStringContainsString(
            "if (picked === null || picked === this.targetSource()) return false;", $preview);
    }

    /**
     * 🔴 Cancelling and proposing are two acts, on both screens.
     *
     * The preview's Cancel emptied every answer and re-applied the defaults in the same breath,
     * putting back exactly what it had just cleared. On a screen where every contested row arrives
     * answered, the two operations cancel out — so the button looked inert, and the only thing it
     * really undid was the handful of edits somebody had typed. Reported as "cancel changes ne fait
     * rien"; the merge view has had the two as separate buttons since it existed.
     */
    public function test_cancelling_and_proposing_are_two_buttons_on_both_screens(): void
    {
        $preview = file_get_contents(resource_path('views/translations/merge-preview.blade.php'));
        $merge = file_get_contents(resource_path('views/merge/show.blade.php'));

        $actions = file_get_contents(resource_path('views/components/editor/editor-actions.blade.php'));

        // The two buttons and their guards are the shared component's — written per screen, they
        // were also written for ONE of the two bars, and the workbench covers the other.
        $this->assertStringContainsString('@click="{{ $suggest }}"', $actions);
        $this->assertStringContainsString('@click="{{ $cancel }}"', $actions);
        // Shown only while something is left to answer: a button that does nothing teaches nothing.
        $this->assertStringContainsString('x-show="undecidedCount > 0"', $actions);
        $this->assertStringContainsString('x-show="totalChanges > 0"', $actions);

        foreach (['merge view' => $merge, 'merge preview' => $preview] as $name => $html) {
            $this->assertStringContainsString('suggestTheRest() {', $html, $name);
            $this->assertStringContainsString('suggest="suggestTheRest()"', $html, $name);
            $this->assertStringContainsString('cancel="clearAll()"', $html, $name);

            // 🔴 BOTH bars. The bottom one is covered by the workbench grid (z-40 under z-50), so
            // an action offered only there cannot be clicked at all while the workbench is on.
            $this->assertSame(2, substr_count($html, '<x-editor.editor-actions'),
                "$name offers its actions in only one of the two bars");
            $this->assertStringContainsString('<x-slot:actions>', $html, $name);
        }

        // 🔴 The one line that made Cancel inert. Asserted absent rather than described in a
        // comment nobody re-reads.
        $this->assertStringNotContainsString(
            "this.clearPendingState();\n                this.applySmartDefaults();", $preview);
    }

    /**
     * 🔴 On a screen with nobody to answer, there is nothing to hold either.
     *
     * "Held, not claimed" says a contribution was dealt with without being validated. The merge view
     * opened in edit mode shows one file and no contribution — somebody correcting their own work —
     * so undoing a validation there means undoing it, not falling back to a state whose subject
     * does not exist. Reported: the dashes came back on a row nobody had proposed anything about.
     */
    public function test_a_screen_with_no_second_side_holds_nothing(): void
    {
        $core = file_get_contents(resource_path('js/components/translation-editor.js'));
        $merge = file_get_contents(resource_path('views/merge/show.blade.php'));

        $this->assertStringContainsString('if (!this.arbitratesAnotherSide()) return null;', $core);

        // 🔴 **Derived from the roles, not declared.** A screen with no source column has nobody to
        // answer — that is the whole of it, and it covers the merge view opened in edit mode
        // without that screen having to remember to say so. Written as its own flag, it was one
        // more thing to keep in step with the columns.
        $this->assertStringContainsString(
            'arbitratesAnotherSide() { return this.sourceIds().length > 0; }', $core);

        // The merge view's edit mode has no contributions at all, so its source list is empty.
        $this->assertStringContainsString("return this.branches.map(branch => 'branch_' + branch.id);", $merge);
    }

    /**
     * 🔴 Two kinds of work arrive on this screen, and the chip tells them apart.
     *
     * A line taken from a contribution exactly as offered, and a line somebody wrote themselves
     * over what was offered. Side by side in a column of new keys, that difference is what says how
     * much of the page is yours — and the chip is where the eye already is.
     *
     * ⚠ Its own question, never isCaptureRow. That one's faded chip means "an H with nothing in
     * it", a line the mod captured and nobody translated; it was doing this job by accident,
     * because it read the value on file and found none — which also had it call a contributor's
     * real translation empty.
     */
    public function test_an_arriving_row_says_whether_anybody_reworded_it(): void
    {
        $core = file_get_contents(resource_path('js/components/translation-editor.js'));

        // ⚠ editIsHeld, not isEdited: typing a pick has set aside will not be written, so the row
        // does arrive as it was offered. "There is a draft" and "the draft is the answer" are two
        // questions, and every chip and count here asks the second.
        $this->assertStringContainsString('tagArrivesUntouched(key) {', $core);
        $this->assertStringContainsString(
            'return this.tagArrives(key) && !this.editIsHeld(key);', $core);

        // isCaptureRow keeps its own meaning, and stops answering for rows it cannot see.
        $this->assertStringContainsString(
            'if (this.entryOnFile(key) === undefined && !this.editIsHeld(key)) return false;', $core);

        $cell = file_get_contents(resource_path('views/components/editor-tag-cell.blade.php'));
        $this->assertStringContainsString(
            'isCaptureRow(key) || tagArrivesUntouched(key)', $cell);
    }

    /**
     * 🔴 Undoing a pick puts the row back where it started, never to blank.
     *
     * Blank and "the Main keeps its own" write the identical file, so a row the Main holds has no
     * third state. Reported: undoing a pick on the Main brought the dashes back, undoing one on a
     * contribution left the row bare — two gestures, two outcomes, for one idea.
     *
     * ⚠ And the default is defined ONCE, read by the smart defaults when the page opens and by the
     * undo when somebody steps back, so the row a page opens on and the row an undo lands on are
     * the same row.
     */
    public function test_undoing_a_pick_returns_to_the_row_s_own_default(): void
    {
        [$owner, $uuid] = $this->makeMergeView();

        $html = $this->actingAs($owner)
            ->get(route('translations.merge', ['uuid' => $uuid]))
            ->assertOk()
            ->getContent();

        // 🔴 The mechanic lives in the CORE, so both arbitrating screens run it. Each supplies only
        // what differs: which column already holds its rows.
        $core = file_get_contents(resource_path('js/components/translation-editor.js'));

        $this->assertStringContainsString('defaultSelection(key) {', $core);
        $this->assertStringContainsString('const back = this.defaultSelection(key);', $core);
        $this->assertStringContainsString('advancePick(key, source, value, tag) {', $core);

        // `auto` is set exactly where the save promotes — on an A and nowhere else. Wider, it is a
        // half-meant state on rows that never needed one; narrower, a machine line is marked
        // human-checked by a page load.
        $this->assertStringContainsString("auto: auto === null ? tag === 'A' : auto", $core);

        // 🔴 A CONTESTED row is held whatever its tag, and this replaced "only an `A` is held".
        //
        // The old rule was right about the file and wrong about the screen. Where a contribution
        // proposes something else and the tags tie — H against H — nothing was marked, so the answer
        // that would be written was invisible, and clicking the Main did nothing: there was no held
        // pick for `advancePick` to claim, and the guard refused to record one. Reported as "clicking
        // it does nothing; I have to select another column and come back".
        //
        // ⚠ It forges nothing, and that is why it is allowed: the server promotes only a CLAIMED
        // `A` (`claimed: !auto`), so a held Main on an H row writes H over H.
        $this->assertStringContainsString(
            "if (tag !== 'A' && !this.rowIsContested(key)) return null;", $core);

        // ⚠ And `auto` is stated rather than left to `pick`'s shorthand, which reads `tag === 'A'`:
        // inferred, a held Main on an H row would come back CLAIMED, saying somebody decided it and
        // turning the click into an erase instead of a claim.
        $this->assertStringContainsString(
            'return this.pick(this.targetSource(), this.getValue(own), tag, true);', $core);

        // Each screen decides what "contested" means; a screen with no other side says nothing is.
        $this->assertStringContainsString('rowIsContested(key) { return false; }', $core);
        $this->assertStringContainsString(
            'return this.bestContributionFor(key) !== null;',
            file_get_contents(resource_path('views/merge/show.blade.php')));

        // The page opens on the same definition the undo returns to.
        $this->assertStringContainsString('const held = this.defaultSelection(key);', $html);

        // And each screen names its own home column rather than the core guessing.
        foreach ([
            'views/merge/show.blade.php' => "targetSource() { return 'main'; }",
            'views/translations/merge-preview.blade.php' => 'targetSource() {',
        ] as $view => $needle) {
            $this->assertStringContainsString($needle, file_get_contents(resource_path($view)), $view);
        }
    }

    /**
     * A tag column has to be wide enough for two chips, and they all have to agree.
     *
     * 🔴 **A declared width stops being a hint the moment anything is dragged.** The grids switch
     * to `table-layout: fixed` then, where a class width is honoured to the pixel and a cell that
     * needs more simply spills over its neighbour — which is exactly what `A → V` did in a 3rem
     * column. Reported from the live editor, invisible on a grid nobody had resized.
     *
     * ⚠ And the metadata tables above the lines align on the SAME column name, so their tag column
     * carries the same class or the whole block sits half a chip off.
     */
    public function test_every_tag_column_fits_a_transition_and_they_agree(): void
    {
        $tagColumnViews = [
            'views/merge/show.blade.php',
            // The comparison screen's header, rendered once per side. Only the target's tag column
            // gets the wide class — the other never shows a transition — so the ternary below is
            // what carries it.
            'views/components/editor/side-head.blade.php',
            'views/edit-session/show.blade.php',
            'views/components/editor/metadata-grid.blade.php',
        ];

        foreach ($tagColumnViews as $view) {
            $source = file_get_contents(resource_path($view));

            // Whole opening tags, then filtered — an attribute order is not something to assert.
            preg_match_all('/<th\b[^>]*>/s', $source, $headers);

            $tagHeaders = array_filter($headers[0], fn ($th) =>
                str_contains($th, 'data-col="mainTag"')
                || str_contains($th, 'data-col="localTag"')
                || str_contains($th, 'data-col="{{ $tagCol }}"')
                || str_contains($th, "toggleSort('tag')"));

            $this->assertNotEmpty($tagHeaders, "$view: no tag column found — has the markup moved?");

            foreach ($tagHeaders as $th) {
                $this->assertMatchesRegularExpression('/\bw-20\b/', $th,
                    "$view declares a tag column too narrow for a transition: $th");
            }
        }
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
        // ⚠ Keyed on the OTHER SIDE now, whatever it is: the block learned its columns so that
        // three editors that name them differently can all line up with their own lines.
        $this->assertStringContainsString('metaCellTint(row, other.id)', $html);
        $this->assertStringContainsString('metaTextTint(row, other.id)', $html);
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

        // The rows are built in the shared module, from the file rather than from contributions.
        $this->assertStringContainsString(
            'for (const key of Object.keys(mainSettings)) row(key',
            file_get_contents(resource_path('js/components/editor-metadata.js')));

        // ⚠ And nothing on those tables pretends to be a choice when there is nobody to take
        // from: no hand cursor, no hint describing a gesture that does not exist, no default
        // lighting the Main's cell as "chosen".
        $this->assertStringContainsString("canTakeContributions() && 'merge-cell'", $html);
        $this->assertStringContainsString('publicationOpen && canTakeContributions()', $html);
    }

    public function test_the_two_blocks_are_one_component_used_by_every_screen(): void
    {
        // 🔴 They were the same table written twice in one file — same columns, same gestures,
        // same marks — and each copy was free to drift from the other. One did: the pin froze one
        // table's header and not the next one's body, for weeks, because a rule written per
        // column could not reach a cell that had been merged in only one of them.
        $component = resource_path('views/components/editor/metadata-grid.blade.php');
        $this->assertFileExists($component);

        foreach ([
            'views/merge/show.blade.php',
            'views/components/editor/readonly-grid.blade.php',
        ] as $view) {
            $source = file_get_contents(resource_path($view));
            $this->assertStringContainsString('<x-editor.metadata-grid name="settings"', $source, $view);
            $this->assertStringContainsString('<x-editor.metadata-grid name="publication"', $source, $view);
        }

        // ⚠ These two show the settings and NOT the description, and each for its own reason: an
        // edit session is anonymous and has no published row to describe; the mod comparison's
        // contract is "your file against the published lines", and a page an anonymous token
        // holder can open does not widen it in silence.
        foreach ([
            'views/edit-session/show.blade.php',
            'views/translations/merge-preview.blade.php',
        ] as $view) {
            $source = file_get_contents(resource_path($view));
            $this->assertStringContainsString('<x-editor.metadata-grid name="settings"', $source, $view);
            $this->assertStringNotContainsString('name="publication"', $source, $view);
        }
    }

    public function test_showing_a_second_side_is_not_the_right_to_take_from_it(): void
    {
        // 🔴 One question was doing the work of two. canTakeContributions() answered
        // "metaOtherColumns().length > 0", which coincided everywhere it had been used — so it
        // worked by accident. The mod comparison breaks the coincidence in one direction and the
        // reading screens in the other, and getting it wrong offers clicks a screen must not have.
        $module = file_get_contents(resource_path('js/components/editor-metadata.js'));

        // The shared default grants nothing.
        $this->assertMatchesRegularExpression(
            '/canTakeContributions\(\) \{\s*return false;\s*\},/', $module);

        // And a screen that arbitrates says so itself.
        foreach ([
            'views/merge/show.blade.php',
            'views/translations/merge-preview.blade.php',
        ] as $view) {
            $this->assertStringContainsString(
                'canTakeContributions() {', file_get_contents(resource_path($view)), $view);
        }
    }

    public function test_a_page_wrapper_never_answers_to_a_shared_method_s_name(): void
    {
        // 🔴 The mod comparison wrapped the shared builder in a method of the SAME name, and the
        // shared buildMetadataRows calls that name: the two called each other until the stack ran
        // out, on the first fetch. Caught by running the page, not by a test — so here is the test.
        $view = file_get_contents(resource_path('views/translations/merge-preview.blade.php'));

        $this->assertStringContainsString('settingsFromBothSides(local, online) {', $view);
        $this->assertStringNotContainsString('buildSettingsRows(local, online) {', $view);
    }

    public function test_a_reading_screen_says_what_the_file_carries(): void
    {
        // 🔴 This is where somebody decides whether to TAKE a translation, and it could not tell
        // them which fonts it replaces, which lines it leaves alone or where the images it needs
        // live. The only way to find out was to download the file and open it — which is the
        // decision they came here to make.
        $owner = User::factory()->create()->refresh();
        $game = Game::forceCreate(['name' => 'Read Game', 'slug' => 'read-game-' . uniqid()]);
        $uuid = (string) \Illuminate\Support\Str::uuid();

        $translation = $this->makeTranslation($owner, $game, $uuid, 'public', [
            '_uuid' => $uuid,
            '_fonts' => ['Title' => ['enabled' => true, 'fallback' => 'NotoSans']],
            'Hello' => ['v' => 'Bonjour', 't' => 'H'],
        ]);
        $translation->forceFill([
            'notes' => 'Covers the whole story.',
            'resources_url' => 'https://example.com/fonts',
        ])->save();

        $this->actingAs($owner)
            ->getJson(route('translations.view.data', $translation))
            ->assertOk()
            ->assertJsonPath('main_notes', 'Covers the whole story.')
            ->assertJsonPath('main_resources_url', 'https://example.com/fonts')
            ->assertJsonPath('main_settings.fonts:Title.section', 'fonts');
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

    /**
     * 🔴 An answer the screen made on its own does not claim a reading.
     *
     * `auto` is what tells a row somebody picked from a row that merely arrived answered. Without
     * it, opening the merge view and pressing the button marked every machine line the Main keeps
     * as human-checked — 18 of them on the lineage this was measured against — and the file's
     * quality bar rose with nobody having read a word.
     */
    public function test_an_unclaimed_hold_keeps_its_machine_tag(): void
    {
        [$owner, $uuid, $main, $branch] = $this->makeMergeView();

        $response = $this->actingAs($owner)->post(route('translations.merge.apply', ['uuid' => $uuid]), [
            'mode' => 'merge',
            'branches' => [$branch->id],
            'selections_json' => json_encode([
                // Held by the screen: the row is answered, the tag is not touched.
                ['key' => 'MainOnly', 'value' => 'Main only', 'tag' => 'A',
                 'source' => 'main', 'auto' => true],
                // Picked by hand on the same page: that one IS a reading.
                ['key' => 'BranchOnly', 'value' => 'Branch only', 'tag' => 'A',
                 'source' => 'branch_' . $branch->id, 'auto' => false],
            ]),
            'deletions_json' => '',
            'tag_changes_json' => '',
        ]);

        $response->assertRedirect();
        $response->assertSessionDoesntHaveErrors();

        $stored = json_decode(file_get_contents($main->fresh()->getSafeFilePath()), true);

        $this->assertSame(['v' => 'Main only', 't' => 'A'], $stored['MainOnly'],
            'nobody read this one, so it is still the machine\'s');
        $this->assertSame(['v' => 'Branch only', 't' => 'V'], $stored['BranchOnly'],
            'this one was picked, and picking is what validating means here');
    }

    /**
     * ⚠ A client that predates `auto` only ever sent rows somebody had picked, so its silence must
     * read as "claimed" — the behaviour it has always had.
     */
    public function test_a_selection_with_no_auto_flag_is_treated_as_picked(): void
    {
        [$owner, $uuid, $main, $branch] = $this->makeMergeView();

        $this->actingAs($owner)->post(route('translations.merge.apply', ['uuid' => $uuid]), [
            'mode' => 'merge',
            'branches' => [$branch->id],
            'selections_json' => json_encode([
                ['key' => 'BranchOnly', 'value' => 'Branch only', 'tag' => 'A',
                 'source' => 'branch_' . $branch->id],
            ]),
            'deletions_json' => '',
            'tag_changes_json' => '',
        ])->assertRedirect();

        $stored = json_decode(file_get_contents($main->fresh()->getSafeFilePath()), true);
        $this->assertSame(['v' => 'Branch only', 't' => 'V'], $stored['BranchOnly']);
    }

    public function test_apply_accepts_explicit_validate_tag_change(): void
    {
        [$owner, $uuid, $main] = $this->makeMergeView();

        // ⚠ The OLD channel, kept readable for a tab still running the previous script when the
        // one-entry-per-row format shipped. Dropping it would lose that person's work in silence.
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

    /**
     * 🔴 A tag set by hand rides in its ROW's entry, and is written exactly as chosen.
     *
     * It used to travel in a channel of its own, applied after the picks — so the file was right
     * only because of the order our code happened to run in, and the same row was counted twice on
     * the way out. `source: 'tagchange'` is what tells `resolveMergedTag` to write the tag as it
     * stands: no `H` forcing on an edited line, no `A → V` promotion on a claimed one.
     */
    public function test_a_hand_set_tag_travels_with_its_row_and_is_written_as_chosen(): void
    {
        [$owner, $uuid, $main] = $this->makeMergeView();

        $response = $this->actingAs($owner)->post(route('translations.merge.apply', ['uuid' => $uuid]), [
            'mode' => 'edit',
            'selections_json' => json_encode([
                // Claimed (auto false) AND tagged A: the promotion must not fire.
                ['key' => 'MainOnly', 'value' => 'Main only', 'tag' => 'A',
                 'source' => 'tagchange', 'auto' => false, 'base' => 'Main only'],
            ]),
            'deletions_json' => '',
        ]);

        $response->assertRedirect();
        $response->assertSessionDoesntHaveErrors();

        $stored = json_decode(file_get_contents($main->fresh()->getSafeFilePath()), true);
        $this->assertSame(['v' => 'Main only', 't' => 'A'], $stored['MainOnly']);
    }

    /** The dropdown offers three gestures; the guard moved with them, it was not lost. */
    public function test_a_hand_set_tag_is_refused_outside_the_three_gestures(): void
    {
        [$owner, $uuid] = $this->makeMergeView();

        $this->actingAs($owner)->post(route('translations.merge.apply', ['uuid' => $uuid]), [
            'mode' => 'edit',
            'selections_json' => json_encode([
                ['key' => 'MainOnly', 'value' => 'Main only', 'tag' => 'H',
                 'source' => 'tagchange', 'auto' => false, 'base' => 'Main only'],
            ]),
            'deletions_json' => '',
        ])->assertSessionHasErrors();
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
