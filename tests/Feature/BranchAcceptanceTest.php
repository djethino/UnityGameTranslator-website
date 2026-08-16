<?php

namespace Tests\Feature;

use App\Models\Game;
use App\Models\Translation;
use App\Models\User;
use App\Services\TranslationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * A Main decides whether contributions are accepted, and nothing goes round that decision.
 *
 * 🔴 The whole point of these tests is the WAYS ROUND. The rule itself is one boolean; what has to
 * be proved is that neither door is open — a new contribution, and an update to a branch that
 * already existed when the Main was still open. The second one skips determineOwnership entirely,
 * which is exactly how it would have been missed.
 */
class BranchAcceptanceTest extends TestCase
{
    use RefreshDatabase;

    private function main(User $owner, bool $open): Translation
    {
        $game = Game::forceCreate(['name' => 'Some Game', 'slug' => 'some-game']);

        $t = new Translation();
        $t->forceFill([
            'game_id' => $game->id,
            'user_id' => $owner->id,
            'source_language' => 'English',
            'target_language' => 'French',
            'file_path' => 'translations/none.json',
            'file_uuid' => (string) Str::uuid(),
            'visibility' => 'public',
            'accepts_branches' => $open,
            'line_count' => 10,
        ])->save();

        return $t->refresh();
    }

    public function test_a_closed_main_refuses_a_new_contribution(): void
    {
        $owner = User::factory()->create();
        $main = $this->main($owner, open: false);
        $other = User::factory()->create();

        $ownership = app(TranslationService::class)->determineOwnership($main->file_uuid, $other->id);

        $this->assertArrayHasKey('refused', $ownership);
        $this->assertNull($ownership['visibility']);
    }

    public function test_an_open_main_still_takes_contributions(): void
    {
        $owner = User::factory()->create();
        $main = $this->main($owner, open: true);
        $other = User::factory()->create();

        $ownership = app(TranslationService::class)->determineOwnership($main->file_uuid, $other->id);

        $this->assertArrayNotHasKey('refused', $ownership);
        $this->assertSame('branch', $ownership['visibility']);
        $this->assertSame($main->id, $ownership['parent_id']);
    }

    public function test_the_owner_is_never_refused_their_own_update(): void
    {
        // ⚠ A closed Main updating their own translation is not a contribution to themselves.
        // Reading the flag before the ownership test would have locked somebody out of their own
        // work the moment they chose to work alone.
        $owner = User::factory()->create();
        $main = $this->main($owner, open: false);

        $ownership = app(TranslationService::class)->determineOwnership($main->file_uuid, $owner->id);

        $this->assertArrayNotHasKey('refused', $ownership);
        $this->assertSame('public', $ownership['visibility']);
    }

    public function test_a_branch_is_frozen_once_its_main_closes(): void
    {
        $owner = User::factory()->create();
        $main = $this->main($owner, open: true);
        $contributor = User::factory()->create();

        $branch = new Translation();
        $branch->forceFill([
            'game_id' => $main->game_id,
            'user_id' => $contributor->id,
            'parent_id' => $main->id,
            'source_language' => 'English',
            'target_language' => 'French',
            'file_path' => 'translations/branch.json',
            'file_uuid' => $main->file_uuid,
            'visibility' => 'branch',
            'line_count' => 3,
        ])->save();

        $this->assertFalse($branch->refresh()->isFrozenBranch());

        $main->update(['accepts_branches' => false]);

        // 🔴 The door that skips determineOwnership: this branch already exists, so an upload
        // reads it directly. Without this the Main would go on receiving updates after closing.
        $this->assertTrue($branch->refresh()->isFrozenBranch());
    }

    /**
     * The website's own publication form.
     *
     * 🔴 It carried the question nowhere until it was audited: a Main publishing from the site
     * had no way to say it, so every translation born there was closed by a decision nobody made.
     * The mod and the Manager both asked; the site did not.
     */
    public function test_the_website_publication_form_records_the_decision(): void
    {
        Storage::fake('local');

        $user = User::factory()->create();
        $game = Game::forceCreate(['name' => 'Form Game', 'slug' => 'form-game']);

        $file = UploadedFile::fake()->createWithContent('t.json', json_encode([
            '_uuid' => (string) Str::uuid(),
            'Hello' => ['v' => 'Bonjour', 't' => 'H'],
        ]));

        $this->actingAs($user)->post(route('translations.store'), [
            'game_id' => $game->id,
            'source_language' => 'English',
            'target_language' => 'French',
            'status' => 'in_progress',
            'accepts_branches' => '1',
            'file' => $file,
        ])->assertRedirect();

        $this->assertTrue((bool) Translation::where('user_id', $user->id)->firstOrFail()->accepts_branches);
    }

    /**
     * ⚠ And the default when the box is left alone. An unticked checkbox sends nothing, so the
     * form carries a hidden companion — without it the box could be ticked and never unticked.
     */
    public function test_the_website_publication_form_defaults_to_solo_work(): void
    {
        Storage::fake('local');

        $user = User::factory()->create();
        $game = Game::forceCreate(['name' => 'Quiet Game', 'slug' => 'quiet-game']);

        $file = UploadedFile::fake()->createWithContent('t.json', json_encode([
            '_uuid' => (string) Str::uuid(),
            'Hello' => ['v' => 'Bonjour', 't' => 'H'],
        ]));

        $this->actingAs($user)->post(route('translations.store'), [
            'game_id' => $game->id,
            'source_language' => 'English',
            'target_language' => 'French',
            'status' => 'in_progress',
            'accepts_branches' => '0',
            'file' => $file,
        ])->assertRedirect();

        $this->assertFalse((bool) Translation::where('user_id', $user->id)->firstOrFail()->accepts_branches);
    }

    /**
     * 🔴 The mod already in players' hands knows nothing about this field.
     *
     * The server goes live before any mod release, so an old mod will ask check-uuid, be told
     * `is_owner: false`, announce "BRANCH" and upload — and only then be refused. That is not a
     * hole (the server decides), but **the refusal message is the only thing that person will
     * ever see**, and their mod cannot offer the fork. So it has to stand on its own: what
     * happened, and where to go. This test freezes both the status and the fact that the message
     * lands in `error`, which is the field every shipped mod reads.
     */
    public function test_an_old_mod_is_refused_with_a_message_it_can_show_as_is(): void
    {
        Storage::fake('local');

        $owner = User::factory()->create();
        $main = $this->main($owner, open: false);
        $other = User::factory()->create();

        $token = \App\Models\ApiToken::createForUser($other, 'old mod')->plain_token;

        $response = $this->withHeaders(['Authorization' => 'Bearer ' . $token])
            ->postJson('/api/v1/translations', [
                // Exactly what a mod that predates the field sends: no accepts_branches at all.
                'game_name' => 'Some Game',
                'source_language' => 'English',
                'target_language' => 'French',
                'content' => json_encode(['_uuid' => $main->file_uuid, 'Hi' => ['v' => 'Salut', 't' => 'H']]),
            ]);

        $response->assertStatus(403);

        $error = $response->json('error');
        $this->assertIsString($error);
        $this->assertStringContainsString('does not accept contributions', $error);
        // It must say where to go, not only that the door is shut.
        $this->assertStringContainsString('publish your own version', $error);
    }

    public function test_a_fork_is_never_frozen(): void
    {
        // The way out has to stay open, whatever the Main decides — a fork left the lineage and
        // leads its own.
        $owner = User::factory()->create();
        $main = $this->main($owner, open: false);

        $forkOwner = User::factory()->create();
        $fork = new Translation();
        $fork->forceFill([
            'game_id' => $main->game_id,
            'user_id' => $forkOwner->id,
            'origin_translation_id' => $main->id,
            'source_language' => 'English',
            'target_language' => 'French',
            'file_path' => 'translations/fork.json',
            'file_uuid' => (string) Str::uuid(),
            'visibility' => 'public',
            'line_count' => 12,
        ])->save();

        $this->assertFalse($fork->refresh()->isFrozenBranch());
    }
}
