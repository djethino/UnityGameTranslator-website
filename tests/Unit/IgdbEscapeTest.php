<?php

namespace Tests\Unit;

use App\Services\GameSearchService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * What a title becomes before it is put between the quotes of an IGDB query.
 *
 * 🔴 **Against the query language, never against a language** (2026-10-05). Until that day only
 * latin letters were kept, so a game titled only in Chinese never reached IGDB — by search, by the
 * adult check, or by a card's store proposals. Each row below is a real shape: a script must come
 * out whole, and what could leave the quoted string must not come out at all.
 */
class IgdbEscapeTest extends TestCase
{
    public static function scripts(): array
    {
        return [
            'chinese' => ['侠影录', '侠影录'],
            'japanese' => ['ドラゴンクエスト', 'ドラゴンクエスト'],
            'korean' => ['메이플스토리', '메이플스토리'],
            'russian' => ['Мор. Утопия', 'Мор. Утопия'],
            'arabic' => ['لعبة', 'لعبة'],
            // Marks that complete a letter: losing them changes the word.
            'hindi' => ['राज', 'राज'],
            'thai' => ['เกม', 'เกม'],
            'latin with accents' => ['Pokémon: Épée', 'Pokémon: Épée'],
            'mixed' => ['The Adventures of Fei Duanmu 端木斐异闻录', 'The Adventures of Fei Duanmu 端木斐异闻录'],
            'sequel' => ["Baldur's Gate 3", "Baldur's Gate 3"],
        ];
    }

    #[DataProvider('scripts')]
    public function test_a_title_in_any_script_is_asked_whole(string $title, string $asked): void
    {
        $this->assertSame($asked, GameSearchService::escapeIGDBQuery($title));
    }

    public static function attacks(): array
    {
        return [
            // Closing the string and adding a statement of one's own.
            'quote and statement' => ['Doom"; fields *; where id > 0; limit 500; "', 'Doom fields where id 0 limit 500 '],
            'backslash escape' => ['Doom\\"', 'Doom'],
            // A line break or a tab is whitespace, never a way to start another line.
            'control characters' => ["Doom\n\twhere id = 1", 'Doom where id 1'],
            'operators' => ['a | b & c', 'a b c'],
        ];
    }

    #[DataProvider('attacks')]
    public function test_nothing_can_leave_the_quoted_string(string $title, string $asked): void
    {
        $safe = GameSearchService::escapeIGDBQuery($title);

        $this->assertSame($asked, $safe);
        $this->assertStringNotContainsString('"', $safe);
        $this->assertStringNotContainsString('\\', $safe);
        $this->assertStringNotContainsString(';', $safe);
    }

    public function test_bytes_that_are_not_utf8_ask_nothing(): void
    {
        // Callers read an empty answer as "nothing to ask" and send no search.
        $this->assertSame('', GameSearchService::escapeIGDBQuery("\xC3\x28"));
    }
}
