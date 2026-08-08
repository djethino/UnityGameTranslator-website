<?php

namespace Tests\Feature;

use App\Models\Game;
use App\Models\Report;
use App\Models\Translation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Moderating a branch.
 *
 * A Main can report a contribution it did not ask for, so moderation has to be able to read one
 * — and branches are private to their Main. The rule chosen is narrow on purpose: an admin reads
 * everything THROUGH THE ADMIN SCREENS, and is an ordinary user everywhere else. What these
 * tests really guard is that boundary, because the easy fix (an admin exception inside
 * Translation::isReadableBy) would have turned every public route into an admin back door.
 *
 * Before this, the report screen read the file straight off the disk and displayed a hundred
 * lines of it — the same access, granted silently, while the download button beside it answered
 * 403 on the very same file.
 */
class ModerationAccessTest extends TestCase
{
    use RefreshDatabase;

    private function makeTranslation(User $owner, string $visibility, string $uuid): Translation
    {
        $game = Game::firstOrCreate(['slug' => 'moderated-game'], ['name' => 'Moderated Game']);

        $path = 'translations/test-' . uniqid() . '.json';
        Storage::disk('local')->put($path, json_encode([
            '_uuid' => $uuid,
            'Hello' => ['v' => 'Bonjour', 't' => 'H'],
        ], JSON_UNESCAPED_UNICODE));

        $translation = new Translation();
        $translation->forceFill([
            'game_id' => $game->id,
            'user_id' => $owner->id,
            'source_language' => 'English',
            'target_language' => 'French',
            'visibility' => $visibility,
            'file_uuid' => $uuid,
            'file_path' => $path,
            'file_hash' => 'hash-' . uniqid(),
            'line_count' => 1,
            'human_count' => 1,
        ])->save();

        return $translation->refresh();
    }

    public function test_an_admin_reads_a_branch_through_the_admin_screens(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $branch = $this->makeTranslation(User::factory()->create(), 'branch', 'shared-uuid');

        $this->actingAs($admin)
            ->getJson(route('admin.translations.data', $branch))
            ->assertOk()
            ->assertJsonPath('content.Hello.v', 'Bonjour');

        $this->actingAs($admin)
            ->get(route('admin.translations.show', $branch))
            ->assertOk();

        $this->actingAs($admin)
            ->get(route('admin.translations.download', $branch))
            ->assertOk();
    }

    /**
     * The same admin, on the routes everyone else uses, is nobody special. This is the half of
     * the rule that is easy to lose: it costs nothing to widen isReadableBy, and then a link
     * pasted into a chat opens someone's unpublished work for one account and not another.
     */
    public function test_the_same_admin_is_an_ordinary_user_on_the_public_routes(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $branch = $this->makeTranslation(User::factory()->create(), 'branch', 'shared-uuid');

        $this->actingAs($admin)->get(route('translations.view', $branch))->assertForbidden();
        $this->actingAs($admin)->getJson(route('translations.view.data', $branch))->assertForbidden();
        $this->actingAs($admin)->get(route('translations.download', $branch))->assertForbidden();
    }

    public function test_a_non_admin_cannot_reach_the_admin_endpoints(): void
    {
        $stranger = User::factory()->create();
        $branch = $this->makeTranslation(User::factory()->create(), 'branch', 'shared-uuid');

        $this->actingAs($stranger)->getJson(route('admin.translations.data', $branch))->assertForbidden();
        $this->actingAs($stranger)->get(route('admin.translations.download', $branch))->assertForbidden();
    }

    /**
     * From a report, one click to the file it accuses — and one to the Main it was contributed
     * to, which is the context of the complaint.
     */
    public function test_a_report_on_a_branch_links_to_the_branch_and_to_its_main(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $mainOwner = User::factory()->create();

        $main = $this->makeTranslation($mainOwner, 'public', 'shared-uuid');
        $branch = $this->makeTranslation(User::factory()->create(), 'branch', 'shared-uuid');

        $report = Report::create([
            'translation_id' => $branch->id,
            'reporter_id' => $mainOwner->id,
            'reason' => 'Machine output pasted in bulk',
        ]);

        $html = $this->actingAs($admin)->get(route('admin.reports.show', $report))->assertOk()->getContent();

        $this->assertStringContainsString(route('admin.translations.show', $branch), $html);
        $this->assertStringContainsString(route('admin.translations.show', $main), $html);
        $this->assertStringContainsString(__('translation.role_branch'), $html);
    }

    /** The Main owner keeps their own access, which is what the branch was published for. */
    public function test_the_main_owner_still_reads_their_branches(): void
    {
        $mainOwner = User::factory()->create();
        $this->makeTranslation($mainOwner, 'public', 'shared-uuid');
        $branch = $this->makeTranslation(User::factory()->create(), 'branch', 'shared-uuid');

        $this->actingAs($mainOwner)->get(route('translations.view', $branch))->assertOk();
    }
}
