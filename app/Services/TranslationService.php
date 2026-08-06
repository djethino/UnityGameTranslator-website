<?php

namespace App\Services;

use App\Models\Translation;
use Illuminate\Support\Facades\Storage;

class TranslationService
{
    private const VALID_TAGS = ['H', 'V', 'A', 'M', 'S'];

    /** Max value for the optional per-entry ordering index "i" (JS Number.MAX_SAFE_INTEGER). */
    public const MAX_ORDER_INDEX = 9007199254740991;

    /** How many entries of each settings section are previewed in the summary. */
    public const SETTINGS_PREVIEW_LIMIT = 40;

    /** Max length of a single label stored in the settings summary. */
    public const SETTINGS_LABEL_MAX_LENGTH = 120;

    /**
     * Normalize line endings to Unix format (\n).
     * Converts \r\n (Windows) and \r (old Mac) to \n.
     * This ensures consistent keys across platforms.
     */
    public function normalizeLineEndings(string $text): string
    {
        // Order is important: first \r\n, then \r
        // Otherwise \r\n would become \n\n
        return str_replace(["\r\n", "\r"], "\n", $text);
    }

    /**
     * Normalize content by converting line endings to Unix format.
     * Alias for normalizeLineEndings for backward compatibility.
     */
    public function normalizeContent(string $content): string
    {
        return $this->normalizeLineEndings($content);
    }

    /**
     * Parse and validate JSON content.
     * Returns parsed data with metadata or throws exception.
     *
     * @return array{json: array, uuid: string, line_count: int, tag_counts: array, file_hash: string}
     * @throws \InvalidArgumentException with error details
     */
    public function parseAndValidate(string $content): array
    {
        // Normalize line endings first
        $content = $this->normalizeContent($content);

        // Parse JSON (depth 6: max actual depth is 4, +2 margin for future nested structures)
        $json = json_decode($content, true, 6);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \InvalidArgumentException('Invalid JSON content: ' . json_last_error_msg());
        }

        if (!is_array($json)) {
            throw new \InvalidArgumentException('Content must be a JSON object');
        }

        // UUID is required
        if (!isset($json['_uuid']) || !is_string($json['_uuid'])) {
            throw new \InvalidArgumentException('Missing _uuid in translation file');
        }

        // Validate translation entries format
        $errors = $this->validateEntries($json);
        if (!empty($errors)) {
            $errorCount = count($errors);
            $sample = array_slice($errors, 0, 10);
            throw new \InvalidArgumentException(json_encode([
                'error' => "Invalid translation format: {$errorCount} entries have errors",
                'details' => $sample,
                'hint' => 'Each entry must be: {"key": {"v": "value" or null, "t": "H|V|A|M|S", "i": optional positive integer}}',
            ]));
        }

        return [
            'json' => $json,
            'uuid' => $json['_uuid'],
            'line_count' => $this->countLines($json),
            'tag_counts' => Translation::extractTagCounts($json),
            'file_hash' => $this->computeHash($json),
            'font_config' => $this->extractFontConfig($json),
            'settings_summary' => $this->extractSettingsSummary($json),
            'normalized_content' => $content,
        ];
    }

    /**
     * Validate translation entries format: {v: string, t: H|V|A|M|S}
     *
     * @return array List of validation errors (empty if valid)
     */
    public function validateEntries(array $json): array
    {
        $errors = [];

        foreach ($json as $key => $value) {
            // Skip metadata keys
            if (str_starts_with($key, '_')) {
                continue;
            }

            // Must be {v: string, t: tag}
            if (!is_array($value)) {
                $errors[] = "Key '$key': expected {v, t} object, got " . gettype($value);
                continue;
            }

            if (!array_key_exists('v', $value)) {
                $errors[] = "Key '$key': missing 'v' (value) field";
                continue;
            }

            if (!array_key_exists('t', $value)) {
                $errors[] = "Key '$key': missing 't' (tag) field";
                continue;
            }

            // 'v' can be string (including empty) or null
            if (!is_string($value['v']) && $value['v'] !== null) {
                $errors[] = "Key '$key': 'v' must be a string or null, got " . gettype($value['v']);
            }

            if (!is_string($value['t']) || !in_array($value['t'], self::VALID_TAGS, true)) {
                $errors[] = "Key '$key': 't' must be one of H, V, A, M, S, got '{$value['t']}'";
            }

            // 'i' (ordering index) is optional; when present it must be a
            // positive integer within JavaScript's safe range — the web
            // editor is the consumer. Larger values decode as float in PHP
            // and fail is_int, which is intended.
            if (array_key_exists('i', $value)
                && (!is_int($value['i']) || $value['i'] < 1 || $value['i'] > self::MAX_ORDER_INDEX)) {
                $errors[] = "Key '$key': 'i' must be a positive integer <= 2^53-1 when present";
            }
        }

        return $errors;
    }

    /**
     * Count translation lines (excluding metadata keys).
     */
    public function countLines(array $json): int
    {
        return count(array_filter(
            array_keys($json),
            fn($k) => !str_starts_with($k, '_')
        ));
    }

    /**
     * Extract font configuration from _fonts metadata.
     * Returns null if no font config present, or an associative array of font settings.
     */
    public function extractFontConfig(array $json): ?array
    {
        if (!isset($json['_fonts']) || !is_array($json['_fonts'])) {
            return null;
        }

        $config = [];
        foreach ($json['_fonts'] as $fontName => $settings) {
            if (!is_array($settings)) {
                continue;
            }
            $config[$fontName] = [
                'enabled' => $settings['enabled'] ?? true,
                'fallback' => $settings['fallback'] ?? null,
                'type' => $settings['type'] ?? null,
                'scale' => $settings['scale'] ?? 1.0,
            ];
        }

        return !empty($config) ? $config : null;
    }

    /**
     * Summarize the translation settings that travel in the file alongside the
     * lines but are NOT lines: font overrides, image replacements, exclusions,
     * variables and game settings. Fonts have their own column (font_config).
     *
     * Why store a summary instead of reading the file on demand: a game page
     * renders every translation of the game, and opening each file to count its
     * exclusions would turn one page view into dozens of disk reads.
     *
     * The preview list is bounded (count is always exact): these lists can hold
     * thousands of entries and the page only needs enough to say what the file
     * carries. The full detail stays in the downloadable file.
     *
     * @return array|null null when the file carries no settings at all
     */
    public function extractSettingsSummary(array $json): ?array
    {
        $summary = [];

        $overrides = $this->summarizeList($json['_font_overrides'] ?? null, function ($rule) {
            if (!is_array($rule)) {
                return null;
            }
            $match = $this->asLabel($rule['match'] ?? null);
            if ($match === null) {
                return null;
            }

            return [
                'match' => $match,
                'replacement' => $this->asLabel($rule['replacement'] ?? null),
                'size_multiplier' => isset($rule['size_multiplier']) && is_numeric($rule['size_multiplier'])
                    ? (float) $rule['size_multiplier']
                    : null,
                // Absent means enabled: the mod only writes the key when false
                'enabled' => ($rule['enabled'] ?? true) == true,
            ];
        });
        if ($overrides) {
            $summary['font_overrides'] = $overrides;
        }

        $images = $this->summarizeList($json['_image_replacements'] ?? null, function ($entry) {
            if (!is_array($entry)) {
                return null;
            }
            $name = $this->asLabel($entry['sprite_name'] ?? null);
            if ($name === null) {
                return null;
            }

            return [
                'name' => $name,
                'width' => is_numeric($entry['original_width'] ?? null) ? (int) $entry['original_width'] : null,
                'height' => is_numeric($entry['original_height'] ?? null) ? (int) $entry['original_height'] : null,
            ];
        });
        if ($images) {
            $summary['image_replacements'] = $images;
        }

        $exclusions = $this->summarizeList($json['_exclusions'] ?? null, function ($pattern) {
            if (!is_string($pattern) || $pattern === '') {
                return null;
            }

            return $this->shortenLabel($pattern);
        });
        if ($exclusions) {
            $summary['exclusions'] = $exclusions;
        }

        $variables = $this->summarizeList($json['_variables'] ?? null, function ($def) {
            if (!is_array($def)) {
                return null;
            }

            $source = trim(($this->asLabel($def['class'] ?? null) ?? '')
                . '.' . ($this->asLabel($def['path'] ?? null) ?? ''), '.');

            return [
                'name' => $this->asLabel($def['name'] ?? null) ?? '',
                'source' => $source !== '' ? $this->shortenLabel($source) : null,
            ];
        });
        if ($variables) {
            $summary['variables'] = $variables;
        }

        // Game settings are a flat object the mod writes only when non-default,
        // so its mere presence is the information. Values are copied as-is but
        // filtered to the keys we know: an unknown key would render as raw text.
        if (isset($json['_settings']) && is_array($json['_settings'])) {
            $known = array_intersect_key($json['_settings'], array_flip([
                'disable_eventsystem_override',
                'typewriting_detection',
                'concat_detection',
                'ui_font',
            ]));
            if (isset($known['ui_font'])) {
                $uiFont = $this->asLabel($known['ui_font']);
                if ($uiFont === null) {
                    unset($known['ui_font']);
                } else {
                    $known['ui_font'] = $uiFont;
                }
            }
            if (!empty($known)) {
                $summary['game_settings'] = $known;
            }
        }

        return !empty($summary) ? $summary : null;
    }

    /**
     * The settings of a file as COMPARABLE entries, keyed by a stable id.
     *
     * The editors could already say that a section differed; they could not say which font, or
     * which exclusion, so nothing could be picked one by one the way translation lines are. This
     * turns each setting into one row: a key that identifies it across two files, and a text
     * value that shows what it is set to.
     *
     * Section names are Translation::SETTINGS_SECTIONS — the same six names, in the same order,
     * as the mod's SettingsSection. Do not invent a third naming here.
     *
     * @return array<string, array{section: string, label: string, value: string}>
     */
    public function extractComparableSettings(array $json): array
    {
        $entries = [];

        // Fonts: DELIBERATE ones only. _fonts doubles as an inventory — the mod records every
        // font it meets in game — so comparing it whole would report a difference between two
        // players of the same translation purely because they walked through different screens.
        foreach (($json['_fonts'] ?? []) as $name => $settings) {
            $label = $this->asLabel($name);
            if ($label === null || !is_array($settings)) {
                continue;
            }
            if (!Translation::isDeliberateFontSetting($settings)) {
                continue;
            }

            $parts = [];
            if (($settings['enabled'] ?? true) === false) {
                $parts[] = 'not translated';
            }
            $fallback = $this->asLabel($settings['fallback'] ?? null);
            if ($fallback !== null) {
                $parts[] = 'fallback: ' . $fallback;
            }
            $scale = $settings['scale'] ?? 1.0;
            if (is_numeric($scale) && abs((float) $scale - 1.0) > 0.001) {
                $parts[] = 'size: ' . round((float) $scale * 100) . '%';
            }

            $entries['fonts:' . $label] = [
                'section' => 'fonts',
                'label' => $label,
                'value' => implode(' · ', $parts),
            ];
        }

        // Font rules are matched first-wins, so their POSITION is part of the setting. Keyed by
        // the pattern rather than by index: an index would shift when a rule is inserted above,
        // and every rule below would read as changed. The position travels in the value instead,
        // so moving a rule shows up as a difference on that rule alone.
        $position = 0;
        foreach (($json['_font_overrides'] ?? []) as $rule) {
            if (!is_array($rule)) {
                continue;
            }
            $match = $this->asLabel($rule['match'] ?? null);
            if ($match === null) {
                continue;
            }
            $position++;

            $parts = ['#' . $position];
            $replacement = $this->asLabel($rule['replacement'] ?? null);
            if ($replacement !== null) {
                $parts[] = '→ ' . $replacement;
            }
            if (isset($rule['size_multiplier']) && is_numeric($rule['size_multiplier'])
                && abs((float) $rule['size_multiplier'] - 1.0) > 0.001) {
                $parts[] = 'size: ' . round((float) $rule['size_multiplier'] * 100) . '%';
            }
            if (($rule['enabled'] ?? true) == false) {
                $parts[] = 'disabled';
            }

            $entries['font_rules:' . $match] = [
                'section' => 'font_rules',
                'label' => $match,
                'value' => implode(' ', $parts),
            ];
        }

        foreach (($json['_image_replacements'] ?? []) as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $name = $this->asLabel($entry['sprite_name'] ?? null);
            if ($name === null) {
                continue;
            }

            $size = is_numeric($entry['original_width'] ?? null) && is_numeric($entry['original_height'] ?? null)
                ? ((int) $entry['original_width']) . '×' . ((int) $entry['original_height'])
                : '';

            $entries['images:' . $name] = [
                'section' => 'images',
                'label' => $name,
                'value' => $size,
            ];
        }

        // An exclusion IS its pattern: presence or absence is the whole setting
        foreach (($json['_exclusions'] ?? []) as $pattern) {
            $label = $this->asLabel($pattern);
            if ($label === null) {
                continue;
            }

            $entries['exclusions:' . $label] = [
                'section' => 'exclusions',
                'label' => $label,
                'value' => 'excluded',
            ];
        }

        foreach (($json['_variables'] ?? []) as $def) {
            if (!is_array($def)) {
                continue;
            }
            $name = $this->asLabel($def['name'] ?? null);
            if ($name === null) {
                continue;
            }

            $source = trim(($this->asLabel($def['class'] ?? null) ?? '')
                . '.' . ($this->asLabel($def['path'] ?? null) ?? ''), '.');

            $entries['variables:' . $name] = [
                'section' => 'variables',
                'label' => $name,
                'value' => $source,
            ];
        }

        // One row per option rather than one for the whole block: they are independent, and a
        // single row would force an all-or-nothing choice on unrelated settings.
        foreach (($json['_settings'] ?? []) as $key => $value) {
            $label = $this->asLabel($key);
            if ($label === null || is_array($value)) {
                continue;
            }

            $entries['game_settings:' . $label] = [
                'section' => 'game_settings',
                'label' => $label,
                'value' => is_bool($value) ? ($value ? 'on' : 'off') : (string) $value,
            ];
        }

        return $entries;
    }

    /** Where each comparable section lives in the file. */
    private const SETTING_SECTION_KEYS = [
        'fonts' => '_fonts',
        'font_rules' => '_font_overrides',
        'images' => '_image_replacements',
        'exclusions' => '_exclusions',
        'variables' => '_variables',
        'game_settings' => '_settings',
    ];

    /** Sections stored as an object keyed by name; the others are ordered lists. */
    private const SETTING_OBJECT_SECTIONS = ['fonts', 'game_settings'];

    /**
     * Apply per-setting choices, taking each winning entry FROM ITS SOURCE FILE.
     *
     * The entries are copied, never rebuilt: what the browser displayed is a readable summary
     * ("fallback: NotoSans · size 140%") that has dropped fields it does not show — type, origin,
     * scale_auto. Reconstructing from it would quietly strip them. So the caller hands over both
     * decoded files and only the DECISIONS travel from the browser, which is also why nothing the
     * client sends can dictate what gets written.
     *
     * A choice on an entry only one side has means "keep it" or "drop it": picking the side that
     * does not have it removes it, exactly as choosing an empty side does for a translation line.
     *
     * @param array $target Destination content (modified copy is returned)
     * @param array $local The other side's content, as decoded from its own file
     * @param array $selections ['fonts:Title' => 'local'|'online', ...]
     */
    public function applySettingSelections(array $target, array $local, array $selections): array
    {
        foreach (self::SETTING_SECTION_KEYS as $section => $jsonKey) {
            $sectionChoices = [];
            foreach ($selections as $key => $side) {
                if (str_starts_with((string) $key, $section . ':')) {
                    $sectionChoices[substr((string) $key, strlen($section) + 1)] = $side;
                }
            }

            if (empty($sectionChoices)) {
                continue;
            }

            $target[$jsonKey] = in_array($section, self::SETTING_OBJECT_SECTIONS, true)
                ? $this->mergeSettingObject($section, $target[$jsonKey] ?? [], $local[$jsonKey] ?? [], $sectionChoices)
                : $this->mergeSettingList($section, $target[$jsonKey] ?? [], $local[$jsonKey] ?? [], $sectionChoices);

            if ($target[$jsonKey] === [] || $target[$jsonKey] === null) {
                unset($target[$jsonKey]);
            }
        }

        return $target;
    }

    /**
     * Sections stored as an object (fonts, game settings). Untouched keys survive: _fonts doubles
     * as an inventory of every font met in game, and rebuilding it from the choices alone would
     * erase everything the two players simply never configured.
     */
    private function mergeSettingObject(string $section, mixed $target, mixed $local, array $choices): array
    {
        $result = is_array($target) ? $target : [];
        $local = is_array($local) ? $local : [];

        foreach ($choices as $id => $side) {
            if ($side === 'local') {
                if (array_key_exists($id, $local)) {
                    $result[$id] = $local[$id];
                } else {
                    unset($result[$id]);
                }
            } elseif (!array_key_exists($id, $target ?: [])) {
                // Online was chosen but does not have it: the entry goes away
                unset($result[$id]);
            }
        }

        return $result;
    }

    /**
     * Sections stored as an ordered list.
     *
     * Order matters for font rules — the first one that matches wins, which is how a specific
     * rule is made to take precedence over a general one. So the result is not concatenated at
     * random: it follows the ONLINE order, and entries kept from the local side are re-inserted
     * behind the neighbour they followed locally. Deterministic, and it preserves the
     * "specific before general" intent on both sides.
     */
    private function mergeSettingList(string $section, mixed $target, mixed $local, array $choices): array
    {
        $target = is_array($target) ? array_values($target) : [];
        $local = is_array($local) ? array_values($local) : [];

        $byIdOnline = $this->indexSettingEntries($section, $target);
        $byIdLocal = $this->indexSettingEntries($section, $local);

        // What survives, and where its content comes from
        $kept = [];
        foreach ($choices as $id => $side) {
            $source = $side === 'local' ? $byIdLocal : $byIdOnline;
            if (array_key_exists($id, $source)) {
                $kept[$id] = $source[$id];
            }
        }
        // Entries nobody had to arbitrate (identical on both sides) stay as they are
        foreach ($byIdOnline as $id => $entry) {
            if (!array_key_exists($id, $choices)) {
                $kept[$id] = $entry;
            }
        }

        $result = [];
        $placed = [];
        foreach (array_keys($byIdOnline) as $id) {
            if (array_key_exists($id, $kept)) {
                $result[] = $kept[$id];
                $placed[$id] = true;
            }
        }

        // Local-only survivors, anchored after the entry they followed locally
        foreach (array_keys($byIdLocal) as $position => $id) {
            if (!array_key_exists($id, $kept) || isset($placed[$id])) {
                continue;
            }

            $anchor = null;
            for ($i = $position - 1; $i >= 0; $i--) {
                $previous = array_keys($byIdLocal)[$i];
                if (isset($placed[$previous])) {
                    $anchor = $previous;
                    break;
                }
            }

            $at = 0;
            if ($anchor !== null) {
                foreach ($result as $index => $entry) {
                    if ($this->settingIdentifier($section, $entry, $index) === $anchor) {
                        $at = $index + 1;
                        break;
                    }
                }
            }

            array_splice($result, $at, 0, [$kept[$id]]);
            $placed[$id] = true;
        }

        return $result;
    }

    /** Entries of a list section, keyed by the identifier used in the comparison. */
    private function indexSettingEntries(string $section, array $entries): array
    {
        $indexed = [];
        foreach ($entries as $index => $entry) {
            $id = $this->settingIdentifier($section, $entry, $index);
            if ($id !== null) {
                $indexed[$id] = $entry;
            }
        }

        return $indexed;
    }

    /**
     * What identifies an entry across two files. Must agree with the keys built by
     * extractComparableSettings — they name the same things on both ends of the round trip.
     */
    private function settingIdentifier(string $section, mixed $entry, int|string $index): ?string
    {
        if ($section === 'exclusions') {
            return $this->asLabel($entry);
        }

        if (!is_array($entry)) {
            return null;
        }

        return match ($section) {
            'font_rules' => $this->asLabel($entry['match'] ?? null),
            'images' => $this->asLabel($entry['sprite_name'] ?? null),
            'variables' => $this->asLabel($entry['name'] ?? null),
            default => null,
        };
    }

    /**
     * Turn a raw metadata list into {count, items} with a bounded preview.
     * The mapper returns null for malformed entries, which are skipped but
     * still counted: the count reflects the file, not what we could read.
     */
    private function summarizeList(mixed $list, callable $mapper): ?array
    {
        if (!is_array($list) || empty($list)) {
            return null;
        }

        $items = [];
        foreach ($list as $raw) {
            if (count($items) >= self::SETTINGS_PREVIEW_LIMIT) {
                break;
            }
            $mapped = $mapper($raw);
            if ($mapped !== null) {
                $items[] = $mapped;
            }
        }

        return ['count' => count($list), 'items' => $items];
    }

    /**
     * A displayable label, or null when the value is not one.
     *
     * The file is user-editable and can be hand-written, so a field the mod
     * always fills with a string may arrive as an array. This summary is
     * decoration: a malformed entry must be skipped, never turn a perfectly
     * valid translation upload into a 500.
     */
    private function asLabel(mixed $value): ?string
    {
        // Scalars coerce cleanly (ints, floats); arrays and objects do not
        if (!is_scalar($value)) {
            return null;
        }

        $label = $this->shortenLabel((string) $value);

        return $label === '' ? null : $label;
    }

    /**
     * Exclusion patterns and sprite names come from game text and can be long.
     * Truncation happens at storage time so the column never grows unbounded.
     */
    private function shortenLabel(string $label): string
    {
        $label = $this->normalizeLineEndings(trim($label));
        // A pattern spanning lines would break the single-line rows it renders in
        $label = str_replace("\n", ' ', $label);

        return mb_strlen($label) > self::SETTINGS_LABEL_MAX_LENGTH
            ? mb_substr($label, 0, self::SETTINGS_LABEL_MAX_LENGTH) . '…'
            : $label;
    }

    /**
     * Reduce a translation entry to its content fields (v/t) for hashing.
     * Presentation metadata like the ordering index "i" must NOT affect the
     * content hash: mods compare hashes to detect real content changes, and
     * "i" legitimately differs across devices for identical content.
     * Must match C# ComputeContentHash(), which hashes {"v", "t"} only.
     * Strictly neutral for entries without extra fields (order preserved).
     */
    public static function hashableEntry(mixed $value): mixed
    {
        if (is_array($value)) {
            return array_intersect_key($value, ['v' => true, 't' => true]);
        }

        return $value;
    }

    /**
     * Build a rewritten {v, t} entry, carrying over the ordering index "i"
     * from the previous entry when present. Server-side rewrites must never
     * silently drop "i": the mod assigns it at capture time and the editors
     * use it to sort entries chronologically.
     */
    public static function rebuildEntry(mixed $previous, ?string $value, string $tag): array
    {
        $entry = ['v' => $value, 't' => $tag];
        if (is_array($previous) && isset($previous['i'])) {
            $entry['i'] = $previous['i'];
        }

        return $entry;
    }

    /**
     * Compute normalized SHA256 hash for a translation file.
     * Used to detect changes between versions.
     * Keys and values are normalized for cross-platform consistency.
     */
    public function computeHash(array $json): string
    {
        $hashData = [];
        foreach ($json as $key => $value) {
            // Include _uuid and all translation keys, exclude other metadata
            if ($key === '_uuid' || !str_starts_with($key, '_')) {
                // Normalize keys for cross-platform consistency
                $normalizedKey = $this->normalizeLineEndings($key);

                // Normalize values (for translation entries with {v, t} format)
                if (is_array($value) && isset($value['v']) && is_string($value['v'])) {
                    $value['v'] = $this->normalizeLineEndings($value['v']);
                } elseif (is_string($value)) {
                    $value = $this->normalizeLineEndings($value);
                }

                $hashData[$normalizedKey] = self::hashableEntry($value);
            }
        }
        ksort($hashData);
        $normalized = json_encode($hashData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return hash('sha256', $normalized);
    }

    /**
     * Store translation file to disk with normalized content.
     *
     * @return string The file path
     */
    public function storeFile(string $content, string $uuid): string
    {
        $normalized = $this->normalizeContent($content);
        $fileName = 'translations/' . uniqid() . '_' . $uuid . '.json';
        Storage::disk('local')->put($fileName, $normalized);

        return $fileName;
    }

    /**
     * Delete translation file from disk.
     */
    public function deleteFile(?string $filePath): bool
    {
        if ($filePath && Storage::disk('local')->exists($filePath)) {
            return Storage::disk('local')->delete($filePath);
        }

        return false;
    }

    /**
     * Find existing translation for this user with the same UUID.
     */
    public function findUserTranslation(string $uuid, int $userId): ?Translation
    {
        return Translation::where('file_uuid', $uuid)
            ->where('user_id', $userId)
            ->first();
    }

    /**
     * Find the original (Main) translation for a UUID.
     */
    public function findMainTranslation(string $uuid): ?Translation
    {
        return Translation::where('file_uuid', $uuid)
            ->where('visibility', 'public')
            ->orderBy('created_at', 'asc')
            ->first();
    }

    /**
     * Determine visibility and parent based on UUID ownership.
     *
     * @return array{visibility: string, parent_id: int|null, original: Translation|null}
     */
    public function determineOwnership(string $uuid, int $userId): array
    {
        $original = $this->findMainTranslation($uuid);

        if (!$original) {
            // New translation - user becomes Main owner
            return [
                'visibility' => 'public',
                'parent_id' => null,
                'original' => null,
            ];
        }

        if ($original->user_id === $userId) {
            // Same user owns Main - this is an update scenario
            return [
                'visibility' => 'public',
                'parent_id' => null,
                'original' => $original,
            ];
        }

        // Different user - this is a branch
        return [
            'visibility' => 'branch',
            'parent_id' => $original->id,
            'original' => $original,
        ];
    }

    /**
     * Resolve final languages based on operation type.
     * - UPDATE: Keep existing translation's languages
     * - BRANCH: Use Main's languages
     * - NEW: Use provided languages (validated)
     *
     * @param string $sourceLanguage Requested source language
     * @param string $targetLanguage Requested target language
     * @param Translation|null $existingTranslation User's existing translation (UPDATE case)
     * @param Translation|null $originalTranslation Main translation (BRANCH case)
     * @return array{source: string, target: string}
     * @throws \InvalidArgumentException if NEW translation has invalid languages
     */
    public function resolveLanguages(
        string $sourceLanguage,
        string $targetLanguage,
        ?Translation $existingTranslation,
        ?Translation $originalTranslation
    ): array {
        if ($existingTranslation) {
            // UPDATE: Use existing languages (ignore request)
            return [
                'source' => $existingTranslation->source_language,
                'target' => $existingTranslation->target_language,
            ];
        }

        if ($originalTranslation) {
            // BRANCH: Use Main's languages (ignore request)
            return [
                'source' => $originalTranslation->source_language,
                'target' => $originalTranslation->target_language,
            ];
        }

        // NEW: Validate languages
        if (strtolower($sourceLanguage) === 'auto' || strtolower($targetLanguage) === 'auto') {
            throw new \InvalidArgumentException('Language cannot be "auto" for new translations. Please select specific languages.');
        }

        if ($sourceLanguage === $targetLanguage) {
            throw new \InvalidArgumentException('Source and target languages must be different.');
        }

        return [
            'source' => $sourceLanguage,
            'target' => $targetLanguage,
        ];
    }

    /**
     * Get the role name for a translation based on visibility.
     */
    public function getRole(string $visibility): string
    {
        return $visibility === 'public' ? 'main' : 'branch';
    }
}
