<?php

namespace Tests\Feature;

use App\Models\Game;
use App\Models\Translation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * "Recently updated" on the front page: translations carried on after they were published
 * (asked 2026-09-30). The "Latest translations" list is ordered on publication, so work done on an
 * older translation used to appear nowhere. A publication is NOT an update — it has its own list
 * right above — and no card is shown twice.
 */
class HomeRecentlyUpdatedTest extends TestCase
{
    use RefreshDatabase;

    /** A published translation with translated lines, published and last changed when told. */
    private function translation(string $game, string $publishedAgo, ?string $changedAgo): Translation
    {
        $translation = Translation::create([
            'user_id' => User::factory()->create()->id,
            'game_id' => Game::create(['name' => $game])->id,
            'title' => 'A translation',
            'source_language' => 'English',
            'target_language' => 'French',
            'file_path' => 'translations/' . uniqid() . '.json',
            'file_hash' => hash('sha256', uniqid()),
            'file_uuid' => (string) Str::uuid(),
            'visibility' => 'public',
        ]);

        $translation->timestamps = false;
        $translation->forceFill([
            'human_count' => 10,
            'line_count' => 20,
            'created_at' => now()->sub($publishedAgo),
            'updated_at' => now()->sub($publishedAgo),
            'content_updated_at' => $changedAgo === null ? now()->sub($publishedAgo) : now()->sub($changedAgo),
        ])->saveQuietly();

        return $translation;
    }

    /**
     * The game names on the cards of each translation list, by list (data-home-list). The popular
     * games above share the card title's markup, so the page is read list by list.
     *
     * @return array<string, string[]>
     */
    private function lists(): array
    {
        $html = $this->get('/en')->assertOk()->getContent();
        $lists = [];

        foreach (preg_split('/data-home-list="/', $html) as $i => $chunk) {
            if ($i === 0) {
                continue;
            }
            $slug = strstr($chunk, '"', true);
            preg_match_all('/truncate">([^<]+)<\/h3>/', $chunk, $m);
            // A chunk runs to the next list, and nothing after the last one carries this title.
            $lists[$slug] = array_map('trim', $m[1]);
        }

        return $lists;
    }

    public function test_a_translation_carried_on_after_publication_is_listed(): void
    {
        // Four newer publications fill "Latest translations" (three cards), so the one below can
        // only reach the front page through the new list.
        foreach (['New One', 'New Two', 'New Three', 'New Four'] as $name) {
            $this->translation($name, '1 day', null);
        }
        $this->translation('Worked On Game', '5 months', '2 hours');

        $this->assertSame(['Worked On Game'], $this->lists()['updated'] ?? null);
        $this->get('/en')->assertSee(route('games.index', ['sort' => 'updated']), false);
    }

    public function test_a_translation_never_touched_since_publication_is_not_an_update(): void
    {
        foreach (['New One', 'New Two', 'New Three'] as $name) {
            $this->translation($name, '1 hour', null);
        }
        // Older than the three above, so not in "Latest translations" either — and never changed.
        $this->translation('Untouched Game', '3 months', null);

        $this->assertArrayNotHasKey('updated', $this->lists(), 'nothing was carried on: no list');
    }

    public function test_no_card_appears_in_two_lists(): void
    {
        // Published recently AND changed since: it belongs to "Latest translations" only.
        $this->translation('Both Game', '3 days', '1 hour');

        $lists = $this->lists();

        $this->assertSame(['Both Game'], $lists['latest'] ?? null);
        $this->assertArrayNotHasKey('updated', $lists);
    }
}
