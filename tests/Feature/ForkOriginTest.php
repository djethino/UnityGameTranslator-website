<?php

namespace Tests\Feature;

use App\Models\ApiToken;
use App\Models\Game;
use App\Models\Translation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Forking used to erase the credit.
 *
 * The mod severs its sync with the original — it has to, or it would keep offering to merge from
 * a lineage it just left — and severed the provenance with it. The fork reached the site as a
 * brand-new translation, and whoever wrote the first thousands of lines lost every trace.
 *
 * The pointer is verified server-side; the counts are declared, because how much the original
 * held at the instant of the fork cannot be recomputed afterwards.
 */
class ForkOriginTest extends TestCase
{
    use RefreshDatabase;

    /** The shape the site fingerprints a file in: sixty-four hex digits. */
    private const ORIGIN_HASH = '5f2b7c0d9e1a4b6c8d3e2f1a0b9c8d7e6f5a4b3c2d1e0f9a8b7c6d5e4f3a2b1c';

    private function upload(User $user, array $extra = []): \Illuminate\Testing\TestResponse
    {
        $token = ApiToken::createForUser($user, 'test')->plain_token;

        return $this->withHeaders(['Authorization' => 'Bearer ' . $token])
            ->postJson('/api/v1/translations', array_merge([
                'game_name' => 'Origin Game',
                'source_language' => 'English',
                'target_language' => 'French',
                'status' => 'in_progress',
                'content' => json_encode([
                    '_uuid' => 'uuid-' . uniqid(),
                    'Hello' => ['v' => 'Bonjour', 't' => 'H'],
                ]),
            ], $extra));
    }

    private function original(User $author): Translation
    {
        $game = Game::firstOrCreate(['slug' => 'origin-game'], ['name' => 'Origin Game']);
        $t = new Translation();
        $t->forceFill([
            'game_id' => $game->id,
            'user_id' => $author->id,
            'source_language' => 'English',
            'target_language' => 'French',
            'file_path' => 'translations/not-read-by-these-tests.json',
            'file_uuid' => 'origin-uuid',
            'visibility' => 'public',
            'file_hash' => self::ORIGIN_HASH,
            'human_count' => 3000,
        ])->save();

        return $t->refresh();
    }

    /** A contribution to $main, held by $author: readable by the two of them and nobody else. */
    private function branchOf(Translation $main, User $author): Translation
    {
        $t = new Translation();
        $t->forceFill([
            'game_id' => $main->game_id,
            'user_id' => $author->id,
            'source_language' => 'English',
            'target_language' => 'French',
            'file_path' => 'translations/not-read-by-these-tests.json',
            'file_uuid' => $main->file_uuid,
            'visibility' => 'branch',
            'parent_id' => $main->id,
            'file_hash' => 'branch-hash',
            'human_count' => 40,
        ])->save();

        return $t->refresh();
    }

    public function test_a_fork_records_who_it_came_from(): void
    {
        $author = User::factory()->create();
        $source = $this->original($author);

        $this->upload(User::factory()->create(), [
            'forked_from_id' => $source->id,
            'forked_from_hash' => self::ORIGIN_HASH,
            'forked_from_lines' => 3000,
        ])->assertSuccessful();

        $fork = Translation::latest('id')->first();
        $this->assertSame($source->id, $fork->origin_translation_id);
        $this->assertSame($author->id, $fork->origin_user_id);
        $this->assertSame(3000, $fork->origin_resolved_lines);
        $this->assertSame(self::ORIGIN_HASH, $fork->origin_file_hash);
        $this->assertTrue($fork->hasOrigin());
        $this->assertTrue($source->publicForks()->where('id', $fork->id)->exists());
    }

    /**
     * 🔴 **An origin is something the caller HOLDS, not any row whose id it can guess.** It used to
     * be enough that the id resolved: an account could name somebody's private branch — or a
     * translation it had never seen — and be credited "forked from @them", while their page listed
     * the stranger's upload among their own community forks.
     */
    public function test_a_stranger_cannot_claim_a_private_branch_as_its_origin(): void
    {
        $mainOwner = User::factory()->create();
        $main = $this->original($mainOwner);
        $branch = $this->branchOf($main, User::factory()->create());

        $this->upload(User::factory()->create(), [
            'forked_from_id' => $branch->id,
            'forked_from_hash' => self::ORIGIN_HASH,
            'forked_from_lines' => 40,
        ])->assertSuccessful();

        $fork = Translation::latest('id')->first();
        $this->assertNull($fork->origin_translation_id, 'a branch you cannot read credits nobody');
        $this->assertNull($fork->origin_user_id);
        $this->assertNull($fork->origin_resolved_lines);
        $this->assertFalse($fork->hasOrigin());
    }

    /**
     * The Main's owner can READ a branch sent to them — that is what reviewing it means — but a
     * fork is made of something one holds as one's own copy, and a contribution received is not
     * that. Merging is how it is taken; forking it would be crediting oneself with a stranger's
     * work under the name of a stranger.
     */
    public function test_the_main_owner_cannot_declare_a_received_branch_as_an_origin(): void
    {
        $mainOwner = User::factory()->create();
        $main = $this->original($mainOwner);
        $branch = $this->branchOf($main, User::factory()->create());

        $this->upload($mainOwner, ['forked_from_id' => $branch->id])->assertSuccessful();

        $this->assertNull(Translation::latest('id')->first()->origin_translation_id);
    }

    /** One's own branch, taken off on its own: that is precisely a fork. */
    public function test_an_author_can_fork_their_own_branch(): void
    {
        $main = $this->original(User::factory()->create());
        $contributor = User::factory()->create();
        $branch = $this->branchOf($main, $contributor);

        $this->upload($contributor, [
            'forked_from_id' => $branch->id,
            'forked_from_lines' => 40,
        ])->assertSuccessful();

        $fork = Translation::latest('id')->first();
        $this->assertSame($branch->id, $fork->origin_translation_id);
        $this->assertSame($contributor->id, $fork->origin_user_id);
    }

    /**
     * ⚠ Left out, never refused: nothing in these fields may fail an upload. But what is stored
     * has a shape — a hash is sixty-four hex digits, a count is a number the column can hold —
     * and anything else is simply not recorded.
     */
    public function test_a_malformed_hash_or_count_is_left_out_and_the_pointer_kept(): void
    {
        $source = $this->original(User::factory()->create());

        $this->upload(User::factory()->create(), [
            'forked_from_id' => $source->id,
            'forked_from_hash' => str_repeat('z', 255),
            'forked_from_lines' => 99999999999,
        ])->assertSuccessful();

        $fork = Translation::latest('id')->first();
        $this->assertSame($source->id, $fork->origin_translation_id, 'the pointer is real');
        $this->assertNull($fork->origin_file_hash, 'that is not a fingerprint');
        $this->assertNull($fork->origin_resolved_lines, 'and that is not a count the column holds');
    }

    public function test_an_origin_pointing_at_nothing_is_dropped_whole(): void
    {
        $this->upload(User::factory()->create(), [
            'forked_from_id' => 999999,
            'forked_from_lines' => 3000,
        ])->assertSuccessful();

        $fork = Translation::latest('id')->first();
        $this->assertNull($fork->origin_translation_id, 'a pointer to nothing credits nobody');
        $this->assertNull($fork->origin_resolved_lines, 'and leaves no number behind either');
        $this->assertFalse($fork->hasOrigin());
    }

    public function test_an_upload_without_these_fields_behaves_as_before(): void
    {
        // Every released version of the mod: the columns simply stay empty.
        $this->upload(User::factory()->create())->assertSuccessful();

        $translation = Translation::latest('id')->first();
        $this->assertNull($translation->origin_translation_id);
        $this->assertFalse($translation->hasOrigin());
    }

    /**
     * The credit was recorded and then never left the website.
     *
     * The mod's community list and the Manager's translation window are where somebody actually
     * chooses between translations of a game, and a fork is a Main in every other respect: nothing
     * else in either screen tells one apart from a translation written from scratch.
     */
    public function test_the_listing_credits_a_fork_source(): void
    {
        $author = User::factory()->create(['name' => 'sourcewriter']);
        $source = $this->original($author);

        $this->upload(User::factory()->create(), [
            'forked_from_id' => $source->id,
            'forked_from_hash' => self::ORIGIN_HASH,
            'forked_from_lines' => 3000,
        ])->assertSuccessful();

        $fork = Translation::latest('id')->first();

        $response = $this->getJson('/api/v1/translations?game=origin-game&lang=French');
        $response->assertSuccessful();

        $row = collect($response->json('translations'))->firstWhere('id', $fork->id);
        $this->assertNotNull($row, 'the fork is listed');
        $this->assertSame('sourcewriter', $row['origin']['author']);
        $this->assertSame(3000, $row['origin']['lines']);
    }

    /**
     * The column carries no foreign key on purpose, so the credit outlives the account it names.
     * What must not happen is the whole block vanishing: "forked from somebody who left" is still
     * a truer answer than "written from scratch".
     */
    public function test_the_credit_survives_the_account_it_names(): void
    {
        $author = User::factory()->create(['name' => 'sourcewriter']);
        $source = $this->original($author);

        $this->upload(User::factory()->create(), [
            'forked_from_id' => $source->id,
            'forked_from_hash' => self::ORIGIN_HASH,
            'forked_from_lines' => 3000,
        ])->assertSuccessful();

        $fork = Translation::latest('id')->first();
        $author->delete();

        $row = collect($this->getJson('/api/v1/translations?game=origin-game&lang=French')
            ->json('translations'))->firstWhere('id', $fork->id);

        $this->assertNotNull($row['origin'], 'the block stays');
        $this->assertNull($row['origin']['author'], 'without a name');
        $this->assertSame(3000, $row['origin']['lines'], 'and the snapshot is still true');
    }

    /**
     * Silence for anything nobody forked. A present block whose members are null would read as a
     * fork whose source is unknown, which is a different — and false — statement.
     */
    public function test_a_translation_nobody_forked_carries_no_origin_block(): void
    {
        $this->upload(User::factory()->create())->assertSuccessful();
        $translation = Translation::latest('id')->first();

        $row = collect($this->getJson('/api/v1/translations?q=Origin&lang=French')
            ->json('translations'))->firstWhere('id', $translation->id);

        $this->assertNotNull($row, 'the translation is listed');
        $this->assertNull($row['origin']);
    }
}
