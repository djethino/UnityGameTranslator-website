<?php

namespace App\Support;

use Illuminate\Http\Request;

/**
 * Which format of translation file a client reads.
 *
 * 🔴 A port of `common/TranslationFormat.cs`, held to the same cases:
 * resources/corpus/rules/translation_format.json (tests/Unit/CorpusTest.php).
 *
 * 🔴 **No table of versions** (decided 2026-10-22). Every mod and Manager released before this
 * rule says nothing and reads the format 1 (UNANNOUNCED); from it on, each client says what it
 * reads — the `X-UGT-Format` header, or `?format=` on the sync stream, whose relay asks this site
 * on the client's behalf. A translation beyond it is not listed for that client, not announced
 * to it as an update, and not served to it.
 *
 * The format is the file's `_engine_version` (resources/spec/translation-file/schema.json): the
 * form its placeholders are written in. 0 — the old `[vN]` — is migrated by every reader and
 * counts as 1.
 */
final class TranslationFormat
{
    /** What a client that says nothing reads: everything released before this rule. */
    public const UNANNOUNCED = 1;

    public const HEADER = 'X-UGT-Format';

    public const QUERY = 'format';

    /** Said by the site when it refuses a client a file past what it reads. Plain English, like every API message. */
    public const TOO_NEW = 'This translation was made for a newer UGT Mod. Update UGT Mod to use it.';

    /** The format a client announced; UNANNOUNCED when absent or not a whole number of at least 1. */
    public static function announced(?string $value): int
    {
        $value = $value === null ? '' : trim($value);
        if ($value === '' || !ctype_digit($value)) {
            return self::UNANNOUNCED;
        }
        $n = (int) $value;

        return $n >= 1 ? $n : self::UNANNOUNCED;
    }

    /** A file's format from its `_engine_version`: absent, 0 or less read as 1. */
    public static function ofFile(?int $engineVersion): int
    {
        return $engineVersion !== null && $engineVersion > 1 ? $engineVersion : 1;
    }

    public static function reads(int $readsUpTo, int $fileFormat): bool
    {
        return $fileFormat <= $readsUpTo;
    }

    /**
     * What the client behind this request reads: the header, else the query parameter (a stream's
     * relay forwards it), else UNANNOUNCED. The one place the site asks.
     */
    public static function of(Request $request): int
    {
        $header = $request->header(self::HEADER);

        return self::announced(is_string($header) && trim($header) !== '' ? $header : $request->query(self::QUERY));
    }
}
