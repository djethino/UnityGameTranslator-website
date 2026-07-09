<?php

namespace Tests\Feature;

use App\Models\Game;
use App\Models\Translation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Regression tests for merge-view state preservation: every navigation vector
 * (pagination links, sort headers, mode switcher, GET forms for filters /
 * branches / search) must carry the full view state (mode, branches, filters,
 * search, sort/dir), overriding only the parameter it changes.
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
     * Setup: a Main with 150 human-tagged keys (2 pages at 100/page) and one
     * branch from another user, so the branch form and mode switcher render.
     *
     * @return array{0: User, 1: string} [Main owner, uuid]
     */
    private function makeMergeView(): array
    {
        // refresh() loads DB defaults (is_admin=false) absent from factory attributes
        $owner = User::factory()->create()->refresh();
        $contributor = User::factory()->create()->refresh();
        $game = Game::forceCreate(['name' => 'Test Game', 'slug' => 'test-game-' . uniqid()]);
        $uuid = (string) \Illuminate\Support\Str::uuid();

        $content = [];
        for ($i = 1; $i <= 150; $i++) {
            $content[sprintf('Key %03d', $i)] = ['v' => "Value {$i}", 't' => 'H'];
        }
        $this->makeTranslation($owner, $game, $uuid, 'public', $content);

        $branchContent = ['Key 001' => ['v' => 'Branch value', 't' => 'H']];
        $this->makeTranslation($contributor, $game, $uuid, 'branch', $branchContent);

        return [$owner, $uuid];
    }

    /**
     * Extract the first href containing the given fragment, entity-decoded.
     */
    private function findLink(string $html, string $fragment): ?string
    {
        if (!preg_match_all('/href="([^"]*)"/', $html, $matches)) {
            return null;
        }
        foreach ($matches[1] as $href) {
            $decoded = html_entity_decode($href);
            if (str_contains($decoded, $fragment)) {
                return $decoded;
            }
        }
        return null;
    }

    public function test_pagination_links_preserve_search_sort_filters_and_mode(): void
    {
        [$owner, $uuid] = $this->makeMergeView();

        $response = $this->actingAs($owner)->get(route('translations.merge', [
            'uuid' => $uuid,
            'mode' => 'merge',
            'search' => 'Key',
            'scope' => 'keys',
            'sort' => 'mainValue',
            'dir' => 'desc',
            'human' => 1,
        ]));
        $response->assertOk();
        $html = $response->getContent();

        $nextLink = $this->findLink($html, 'page=2');
        $this->assertNotNull($nextLink, 'Next-page link not found');
        $this->assertStringContainsString('search=Key', $nextLink);
        $this->assertStringContainsString('scope=keys', $nextLink);
        $this->assertStringContainsString('sort=mainValue', $nextLink);
        $this->assertStringContainsString('dir=desc', $nextLink);
        $this->assertStringContainsString('human=1', $nextLink);
        $this->assertStringContainsString('mode=merge', $nextLink);
    }

    public function test_get_forms_carry_hidden_state_inputs(): void
    {
        [$owner, $uuid] = $this->makeMergeView();

        $response = $this->actingAs($owner)->get(route('translations.merge', [
            'uuid' => $uuid,
            'mode' => 'merge',
            'search' => 'Key',
            'sort' => 'key',
            'dir' => 'asc',
            'human' => 1,
        ]));
        $response->assertOk();
        $html = $response->getContent();

        // Branch form + filter form + merge (save) form each preserve the search;
        // the search form itself uses the visible input instead.
        $this->assertGreaterThanOrEqual(
            3,
            substr_count($html, '<input type="hidden" name="search" value="Key">'),
            'Hidden search inputs missing from GET forms'
        );
        // Branch form + search form + merge form preserve the active filter.
        $this->assertGreaterThanOrEqual(
            3,
            substr_count($html, '<input type="hidden" name="human" value="1">'),
            'Hidden filter inputs missing from GET forms'
        );
        // All forms preserve the mode.
        $this->assertGreaterThanOrEqual(
            4,
            substr_count($html, '<input type="hidden" name="mode" value="merge">'),
            'Hidden mode inputs missing from GET forms'
        );
        // Sort is preserved as hidden inputs too.
        $this->assertGreaterThanOrEqual(
            3,
            substr_count($html, '<input type="hidden" name="sort" value="key">'),
            'Hidden sort inputs missing from GET forms'
        );
    }

    public function test_reset_filters_link_keeps_search_sort_and_mode(): void
    {
        [$owner, $uuid] = $this->makeMergeView();

        $response = $this->actingAs($owner)->get(route('translations.merge', [
            'uuid' => $uuid,
            'mode' => 'merge',
            'search' => 'Key',
            'sort' => 'mainTag',
            'dir' => 'asc',
            'human' => 1,
        ]));
        $response->assertOk();
        $html = $response->getContent();

        // The reset-filters link is the one keeping search+sort WITHOUT any filter param.
        $found = false;
        preg_match_all('/href="([^"]*)"/', $html, $matches);
        foreach ($matches[1] as $href) {
            $decoded = html_entity_decode($href);
            if (
                str_contains($decoded, 'search=Key')
                && str_contains($decoded, 'sort=mainTag')
                && str_contains($decoded, 'mode=merge')
                && !str_contains($decoded, 'human=')
                && !str_contains($decoded, 'page=')
            ) {
                $found = true;
                break;
            }
        }
        $this->assertTrue($found, 'Reset-filters link dropping only the filters not found');
    }

    public function test_search_scope_restricts_matches_to_keys_or_values(): void
    {
        $owner = User::factory()->create()->refresh();
        $game = Game::forceCreate(['name' => 'Scope Game', 'slug' => 'scope-game-' . uniqid()]);
        $uuid = (string) \Illuminate\Support\Str::uuid();
        $this->makeTranslation($owner, $game, $uuid, 'public', [
            'AlphaKey' => ['v' => 'BetaValue', 't' => 'H'],
            'GammaKey' => ['v' => 'DeltaValue', 't' => 'H'],
        ]);

        $show = fn(array $params) => $this->actingAs($owner)
            ->get(route('translations.merge', array_merge(['uuid' => $uuid, 'mode' => 'edit'], $params)));

        // NOTE: the searched term is echoed back in the search input, so a row's
        // presence is asserted via its OTHER half (key when searching the value,
        // value when searching the key).

        // Default (both): a value term matches its row, a key term too.
        $show(['search' => 'BetaValue'])->assertOk()->assertSee('AlphaKey')->assertDontSee('GammaKey');
        $show(['search' => 'AlphaKey'])->assertOk()->assertSee('BetaValue')->assertDontSee('GammaKey');

        // Keys only: value terms no longer match, key terms still do.
        $show(['search' => 'BetaValue', 'scope' => 'keys'])->assertOk()->assertDontSee('AlphaKey');
        $show(['search' => 'AlphaKey', 'scope' => 'keys'])->assertOk()->assertSee('BetaValue');

        // Values only: key terms no longer match, value terms still do.
        $show(['search' => 'AlphaKey', 'scope' => 'values'])->assertOk()->assertDontSee('BetaValue');
        $show(['search' => 'BetaValue', 'scope' => 'values'])->assertOk()->assertSee('AlphaKey');

        // Invalid scope falls back to 'both'.
        $show(['search' => 'BetaValue', 'scope' => 'nonsense'])->assertOk()->assertSee('AlphaKey');
    }

    public function test_branch_relative_filters_hidden_and_neutralized_in_edit_mode(): void
    {
        [$owner, $uuid] = $this->makeMergeView();

        // Edit mode with stale branch-relative filters in the URL (e.g. kept by
        // the mode switcher): they must be ignored (rows still listed) and their
        // checkboxes must not be rendered.
        $response = $this->actingAs($owner)->get(route('translations.merge', [
            'uuid' => $uuid,
            'mode' => 'edit',
            'new_keys' => 1,
            'difference' => 1,
        ]));
        $response->assertOk()->assertSee('Key 001');
        $html = $response->getContent();
        $this->assertStringNotContainsString('name="new_keys"', $html);
        $this->assertStringNotContainsString('name="difference"', $html);

        // Merge mode still renders them.
        $response = $this->actingAs($owner)->get(route('translations.merge', [
            'uuid' => $uuid,
            'mode' => 'merge',
        ]));
        $response->assertOk();
        $html = $response->getContent();
        $this->assertStringContainsString('name="new_keys"', $html);
        $this->assertStringContainsString('name="difference"', $html);
    }

    public function test_edit_mode_survives_pagination_and_mode_switcher_keeps_state(): void
    {
        [$owner, $uuid] = $this->makeMergeView();

        $response = $this->actingAs($owner)->get(route('translations.merge', [
            'uuid' => $uuid,
            'mode' => 'edit',
            'search' => 'Key',
            'sort' => 'key',
            'dir' => 'desc',
        ]));
        $response->assertOk();
        $html = $response->getContent();

        // Pagination must keep mode=edit (otherwise the controller falls back to merge mode).
        $nextLink = $this->findLink($html, 'page=2');
        $this->assertNotNull($nextLink, 'Next-page link not found in edit mode');
        $this->assertStringContainsString('mode=edit', $nextLink);
        $this->assertStringContainsString('search=Key', $nextLink);

        // The switcher link to merge mode keeps search and sort.
        $mergeModeLink = $this->findLink($html, 'mode=merge');
        $this->assertNotNull($mergeModeLink, 'Mode switcher link not found');
        $this->assertStringContainsString('search=Key', $mergeModeLink);
        $this->assertStringContainsString('sort=key', $mergeModeLink);
    }
}
