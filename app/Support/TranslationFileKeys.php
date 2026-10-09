<?php

namespace App\Support;

/**
 * Which keys of a translations.json are metadata, which are lines of the game's text, and which
 * are neither.
 *
 * 🔴 A port of `common/TranslationFileKeys.cs`, held to the same cases:
 * resources/corpus/rules/translation_file_keys.json, answered by the C# (mod, Manager), by this
 * class (tests/Unit/CorpusTest.php) and by the editor's JavaScript (resources/js/rules/translation-file.js).
 *
 * 🔴 **Not « starts with an underscore »** (2026-10-09). A game's text can begin with one — a
 * published translation holds `_One-quarter Brick Triangular Wall` — and the prefix rule made it
 * metadata everywhere: never counted, never shown, never hashed, and the mod sent it to the model
 * again at every launch.
 *
 * | key | is |
 * |---|---|
 * | one of METADATA — the `properties` of resources/spec/translation-file/schema.json | metadata |
 * | any other name not starting with « _ » | a line, whatever its value |
 * | any other « _ » name whose value is shaped like a line (an object with a `v`) | a line |
 * | any other « _ » name, any other value | neither — a newer writer's metadata, left alone |
 *
 * tests/Unit/TranslationFileKeysTest.php holds METADATA equal to the schema and to the JavaScript copy.
 */
final class TranslationFileKeys
{
    /** The names the tools write ABOUT a file. */
    public const METADATA = [
        '_engine_version', '_uuid', '_source_language', '_target_language', '_local_changes',
        '_metadata_dirty', '_game', '_source', '_forked_from', '_fonts', '_font_overrides',
        '_image_replacements', '_exclusions', '_variables', '_settings',
    ];

    /** Is this key one of the names the tools write about a file? */
    public static function isMetadata(int|string $key): bool
    {
        // PHP turns a numeric-looking key ("42") into an int: that is never metadata.
        return is_string($key) && in_array($key, self::METADATA, true);
    }

    /** Is this value shaped like a line — a JSON object with a `v` member? */
    public static function lineShaped(mixed $value): bool
    {
        return is_array($value) && array_key_exists('v', $value) && !array_is_list($value);
    }

    /** Is a key a line of the game's text, its value being (or not) shaped like one? The C#'s IsLine. */
    public static function isLine(int|string $key, bool $lineShaped): bool
    {
        if (self::isMetadata($key)) {
            return false;
        }
        $key = (string) $key;

        // An empty key stays what it always was: a line (nothing writes one).
        return $key === '' || $key[0] !== '_' || $lineShaped;
    }

    /** Is this entry of a decoded file a line of the game's text? */
    public static function isLineEntry(int|string $key, mixed $value): bool
    {
        return self::isLine($key, self::lineShaped($value));
    }

    /** The keys of a file that are lines.
     * @param array<int|string, mixed> $json
     * @return list<int|string> */
    public static function lineKeys(array $json): array
    {
        $keys = [];
        foreach ($json as $key => $value) {
            if (self::isLineEntry($key, $value)) {
                $keys[] = $key;
            }
        }

        return $keys;
    }

    /**
     * May a browser write a line under this key into this file? Never over metadata, and never
     * over a newer writer's metadata already there — a {v, t} written on it would destroy it.
     * @param array<int|string, mixed> $content
     */
    public static function writable(int|string $key, array $content): bool
    {
        if (self::isMetadata($key)) {
            return false;
        }

        return !array_key_exists($key, $content) || self::isLineEntry($key, $content[$key]);
    }
}
