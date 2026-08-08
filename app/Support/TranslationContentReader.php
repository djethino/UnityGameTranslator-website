<?php

namespace App\Support;

use App\Models\Translation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/**
 * Reads a translation file for a screen that only wants to LOOK at it: filter, search, sort,
 * paginate.
 *
 * It lived inline in the admin controller, and the moment a second screen needed the same thing
 * the choice was to copy ninety lines or to move them here. Copying would have meant two
 * definitions of what "sort by tag" means on the same data, drifting from the day after.
 *
 * Read-only by construction: nothing here writes, and the file is never handed out whole — a
 * caller gets one page of keys and the decoded content to read them from.
 */
class TranslationContentReader
{
    public const PER_PAGE = 100;

    /**
     * @return array{jsonContent: ?array, metadata: array, pagedKeys: array, filters: array,
     *               page: int, perPage: int, totalKeys: int, totalPages: int}
     */
    public static function read(Translation $translation, Request $request): array
    {
        [$jsonContent, $metadata, $allKeys] = self::load($translation);

        $filters = [
            'human' => $request->boolean('human'),
            'validated' => $request->boolean('validated'),
            'ai' => $request->boolean('ai'),
            'mod_ui' => $request->boolean('mod_ui'),
            'skipped' => $request->boolean('skipped'),
        ];

        $keys = self::applyTagFilters($allKeys, $jsonContent, $filters);
        $keys = self::applySearch($keys, $jsonContent, $request->input('search'));
        $keys = self::applySort($keys, $jsonContent, $request->input('sort', 'key'), $request->input('dir', 'asc'));

        $totalKeys = count($keys);
        $totalPages = max(1, (int) ceil($totalKeys / self::PER_PAGE));
        $page = min(max(1, (int) $request->input('page', 1)), $totalPages);

        return [
            'jsonContent' => $jsonContent,
            'metadata' => $metadata,
            'pagedKeys' => array_slice($keys, ($page - 1) * self::PER_PAGE, self::PER_PAGE),
            'filters' => $filters,
            'page' => $page,
            'perPage' => self::PER_PAGE,
            'totalKeys' => $totalKeys,
            'totalPages' => $totalPages,
        ];
    }

    /**
     * Decode the file and split the metadata off.
     *
     * A missing or corrupted file yields an empty read, never an exception: a translation whose
     * file has gone is a page that says so, not a 500.
     *
     * @return array{0: ?array, 1: array, 2: array}
     */
    private static function load(Translation $translation): array
    {
        if (!$translation->file_path) {
            return [null, [], []];
        }

        try {
            $jsonContent = json_decode(Storage::disk('local')->get($translation->file_path), true);
        } catch (\Exception $e) {
            return [null, [], []];
        }

        if (!is_array($jsonContent)) {
            return [null, [], []];
        }

        $metadata = [];
        $allKeys = [];
        foreach ($jsonContent as $key => $value) {
            // Underscore-prefixed keys are the file's own bookkeeping (_uuid, _game, _settings…),
            // never a line of the game
            if (str_starts_with((string) $key, '_')) {
                $metadata[$key] = $value;
            } else {
                $allKeys[] = $key;
            }
        }

        return [$jsonContent, $metadata, $allKeys];
    }

    /** An entry is either a bare string (old files) or {v, t, i}. */
    public static function valueOf(?array $jsonContent, string $key): string
    {
        $entry = $jsonContent[$key] ?? null;

        return (string) (is_array($entry) ? ($entry['v'] ?? '') : $entry);
    }

    /** Untagged entries are AI, which is what the mod produced before tags existed. */
    public static function tagOf(?array $jsonContent, string $key): string
    {
        $entry = $jsonContent[$key] ?? null;

        return is_array($entry) ? ($entry['t'] ?? 'A') : 'A';
    }

    private static function applyTagFilters(array $keys, ?array $jsonContent, array $filters): array
    {
        // No box ticked means no filtering, not an empty list: a bar where nothing is selected
        // reads as "show everything", never as "show nothing"
        if (!array_filter($filters)) {
            return $keys;
        }

        $wanted = array_keys(array_filter([
            'H' => $filters['human'],
            'V' => $filters['validated'],
            'A' => $filters['ai'],
            'M' => $filters['mod_ui'],
            'S' => $filters['skipped'],
        ]));

        return array_values(array_filter(
            $keys,
            fn ($key) => in_array(self::tagOf($jsonContent, $key), $wanted, true)
        ));
    }

    private static function applySearch(array $keys, ?array $jsonContent, ?string $search): array
    {
        if (!$search) {
            return $keys;
        }

        return array_values(array_filter($keys, function ($key) use ($search, $jsonContent) {
            return mb_stripos($key, $search) !== false
                || mb_stripos(self::valueOf($jsonContent, $key), $search) !== false;
        }));
    }

    private static function applySort(array $keys, ?array $jsonContent, string $column, string $direction): array
    {
        $multiplier = ($direction === 'desc') ? -1 : 1;

        usort($keys, function ($a, $b) use ($jsonContent, $column, $multiplier) {
            switch ($column) {
                case 'tag':
                    $valA = self::tagOf($jsonContent, $a);
                    $valB = self::tagOf($jsonContent, $b);
                    break;
                case 'value':
                    $valA = mb_strtolower(self::valueOf($jsonContent, $a));
                    $valB = mb_strtolower(self::valueOf($jsonContent, $b));
                    break;
                case 'key':
                default:
                    $valA = mb_strtolower($a);
                    $valB = mb_strtolower($b);
                    break;
            }

            return strcmp($valA, $valB) * $multiplier;
        });

        return $keys;
    }
}
