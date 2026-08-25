<?php

namespace Tests\Feature\Api;

use App\Models\ApiToken;
use App\Models\Game;
use App\Models\Translation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The same file, sent again under another account, is refused.
 *
 * 🔴 **file_hash cannot see this, by construction.** It hashes the uuid alongside the lines, so two
 * byte-identical files hash differently the moment one is forked — and a fork taking a new uuid is
 * exactly how a copy gets republished under another name. Hence content_hash, the same content with
 * the uuid held constant.
 *
 * ⚠ The refusal is deliberately narrow, and each of these tests defends one edge of it: creation
 * only, another account only, and a real difference is enough to pass.
 */
class DuplicateContentUploadTest extends TestCase
{
    use RefreshDatabase;

    private const LINES = [
        'Hello' => ['v' => 'Bonjour', 't' => 'H'],
        'Play' => ['v' => 'Jouer', 't' => 'H'],
        'Options' => ['v' => 'Options', 't' => 'V'],
    ];

    /**
     * ⚠ The game is created here and addressed by steam_id, so findOrCreateGame matches it on the
     * first branch and never reaches the external game search. Sending a name instead makes every
     * upload in this file an outbound HTTP call, and the second one then collides on the slug the
     * first invented.
     */
    private function upload(User $user, array $content): \Illuminate\Testing\TestResponse
    {
        Game::firstOrCreate(
            ['steam_id' => '999001'],
            ['name' => 'Duplicate Content Game', 'slug' => 'duplicate-content-game']
        );

        $token = ApiToken::createForUser($user, 'test')->plain_token;

        return $this->withHeaders(['Authorization' => 'Bearer ' . $token])
            ->postJson('/api/v1/translations', [
                'steam_id' => '999001',
                'source_language' => 'English',
                'target_language' => 'French',
                'content' => json_encode($content),
            ]);
    }

    private function file(string $uuid, array $lines = self::LINES): array
    {
        return array_merge(['_uuid' => $uuid], $lines);
    }

    public function test_another_account_cannot_republish_the_same_file(): void
    {
        $author = User::factory()->create();
        $this->upload($author, $this->file('uuid-original'))->assertSuccessful();

        // A new uuid: this is what a fork looks like on the wire, and what file_hash cannot catch.
        $response = $this->upload(User::factory()->create(), $this->file('uuid-forked'));

        $response->assertStatus(422);
        $this->assertStringContainsString('identical', $response->json('error') ?? '');
        $this->assertSame(1, Translation::count(), 'nothing may be stored for a refused upload');
    }

    public function test_a_branch_that_carries_nothing_new_is_refused_too(): void
    {
        $author = User::factory()->create();
        $this->upload($author, $this->file('uuid-lineage'))->assertSuccessful();
        Translation::latest('id')->first()->update(['accepts_branches' => true]);

        // Same uuid this time — contributing to the lineage rather than leaving it. Identical
        // content means a contribution containing nothing.
        $this->upload(User::factory()->create(), $this->file('uuid-lineage'))
            ->assertStatus(422);

        $this->assertSame(1, Translation::count());
    }

    public function test_one_changed_line_is_enough(): void
    {
        $author = User::factory()->create();
        $this->upload($author, $this->file('uuid-original'))->assertSuccessful();

        $mine = self::LINES;
        $mine['Options'] = ['v' => 'Réglages', 't' => 'H'];

        $this->upload(User::factory()->create(), $this->file('uuid-forked', $mine))
            ->assertSuccessful();

        $this->assertSame(2, Translation::count());
    }

    public function test_a_changed_tag_alone_is_enough(): void
    {
        // Reviewing a line IS work. The tag travels in the hashed content, so a file whose lines
        // read the same but have been validated is not the same file.
        $author = User::factory()->create();
        $this->upload($author, $this->file('uuid-original'))->assertSuccessful();

        $reviewed = self::LINES;
        $reviewed['Hello'] = ['v' => 'Bonjour', 't' => 'V'];

        $this->upload(User::factory()->create(), $this->file('uuid-forked', $reviewed))
            ->assertSuccessful();

        $this->assertSame(2, Translation::count());
    }

    public function test_the_same_account_may_hold_the_same_content_twice(): void
    {
        // Forking one's own translation, or publishing the same file for a second game entry.
        // Nobody is taking anybody's work here.
        $author = User::factory()->create();
        $this->upload($author, $this->file('uuid-one'))->assertSuccessful();
        $this->upload($author, $this->file('uuid-two'))->assertSuccessful();

        $this->assertSame(2, Translation::count());
    }

    public function test_updating_your_own_translation_is_never_refused(): void
    {
        // The row already exists and belongs to the sender: re-uploading it unchanged is a no-op,
        // not a copy of somebody else's work. Proved with a second account holding the same
        // content, so the check would fire if it looked at updates.
        $author = User::factory()->create();
        $this->upload($author, $this->file('uuid-mine'))->assertSuccessful();

        $stranger = Translation::latest('id')->first()->replicate();
        $stranger->user_id = User::factory()->create()->id;
        $stranger->file_uuid = 'uuid-stranger';
        $stranger->visibility = 'public';
        $stranger->save();

        $this->upload($author, $this->file('uuid-mine'))->assertSuccessful();

        $this->assertSame(2, Translation::count());
    }

    public function test_a_row_with_no_fingerprint_yet_refuses_nothing(): void
    {
        // Everything published before content_hash existed carries none until the recalculate
        // command has run. Unknown is not "duplicate": refusing over a value nobody ever computed
        // would be a refusal nobody could explain.
        $author = User::factory()->create();
        $this->upload($author, $this->file('uuid-original'))->assertSuccessful();
        Translation::latest('id')->first()->update(['content_hash' => null]);

        $this->upload(User::factory()->create(), $this->file('uuid-forked'))
            ->assertSuccessful();

        $this->assertSame(2, Translation::count());
    }

    public function test_the_author_is_named_only_when_their_translation_is_public(): void
    {
        // A branch is visible to its author and the Main's owner alone. Naming them would report
        // the existence of a private contribution to a stranger.
        $author = User::factory()->create(['name' => 'PublicAuthor']);
        $this->upload($author, $this->file('uuid-original'))->assertSuccessful();

        $response = $this->upload(User::factory()->create(), $this->file('uuid-forked'));
        $this->assertStringContainsString('PublicAuthor', $response->json('error') ?? '');

        Translation::latest('id')->first()->update(['visibility' => 'branch']);

        $response = $this->upload(User::factory()->create(), $this->file('uuid-third'));
        $response->assertStatus(422);
        $this->assertStringNotContainsString('PublicAuthor', $response->json('error') ?? '');
    }
}
