<?php

namespace Tests\Unit;

use App\Support\TranslationFileKeys;
use PHPUnit\Framework\TestCase;

/**
 * The site's two copies of the metadata list — PHP and the editor's JavaScript — against the
 * contract they come from: the `properties` of resources/spec/translation-file/schema.json.
 *
 * ⚠ The corpus (CorpusTest, sync/is_metadata_key) holds the ANSWERS; this holds the LIST, which a
 * case cannot: a metadata key added to the schema and forgotten here would turn it into a line on
 * every screen of the site, and no case names it yet.
 */
class TranslationFileKeysTest extends TestCase
{
    private static function root(): string
    {
        return dirname(__DIR__, 2);
    }

    /** @return list<string> */
    private static function sorted(array $keys): array
    {
        $keys = array_values($keys);
        sort($keys);

        return $keys;
    }

    public function test_the_php_list_is_the_schema_properties(): void
    {
        $path = self::root() . '/resources/spec/translation-file/schema.json';
        self::assertFileExists($path, 'the contract copy is missing — run ./sync-common.ps1');
        $schema = json_decode(file_get_contents($path), true, 64, JSON_THROW_ON_ERROR);

        self::assertSame(
            self::sorted(array_keys($schema['properties'] ?? [])),
            self::sorted(TranslationFileKeys::METADATA),
            'TranslationFileKeys::METADATA must name exactly the properties of the translation-file schema'
        );
    }

    public function test_the_javascript_list_is_the_php_list(): void
    {
        $source = file_get_contents(self::root() . '/resources/js/rules/translation-file.js');
        self::assertIsString($source);
        self::assertSame(1, preg_match('/METADATA_KEYS\s*=\s*Object\.freeze\(\[(.*?)\]\)/s', $source, $m),
            'METADATA_KEYS not found in resources/js/rules/translation-file.js');
        preg_match_all("/'([^']+)'/", $m[1], $names);

        self::assertSame(
            self::sorted(TranslationFileKeys::METADATA),
            self::sorted($names[1]),
            'the editor\'s METADATA_KEYS must equal TranslationFileKeys::METADATA'
        );
    }

    public function test_a_line_starting_with_an_underscore_is_a_line(): void
    {
        $json = [
            '_uuid' => 'u',
            '_One-quarter Brick Triangular Wall' => ['v' => 'x', 't' => 'A'],
            '_something_newer' => ['x' => 1],
            'Hello' => ['v' => 'y', 't' => 'A'],
            '42' => ['v' => 'quarante-deux', 't' => 'A'],
        ];

        self::assertSame(['_One-quarter Brick Triangular Wall', 'Hello', 42], TranslationFileKeys::lineKeys($json),
            'a {v} under an underscore name is a line; a newer writer\'s metadata is not; a numeric key (an int in PHP) is a line');
    }

    public function test_nothing_is_written_over_metadata_or_a_newer_writers_metadata(): void
    {
        $content = ['_uuid' => 'u', '_something_newer' => ['x' => 1], '_Old line' => ['v' => 'a', 't' => 'A']];

        self::assertFalse(TranslationFileKeys::writable('_uuid', $content));
        self::assertFalse(TranslationFileKeys::writable('_something_newer', $content));
        self::assertTrue(TranslationFileKeys::writable('_Old line', $content));
        self::assertTrue(TranslationFileKeys::writable('_A new line', $content));
        self::assertTrue(TranslationFileKeys::writable('Hello', $content));
    }
}
