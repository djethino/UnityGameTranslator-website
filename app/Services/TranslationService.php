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
     * @return array{json: array, uuid: string, line_count: int, tag_counts: array, file_hash: string, content_hash: string}
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

        // 🔴 A file with no entry at all is refused here, for every caller at once.
        //
        // Until this, `{"_uuid": …, "_game": …}` passed: valid JSON, a uuid, and validateEntries
        // skips every key starting with an underscore, so there was nothing left to be wrong. A
        // game set up a minute ago holds exactly that file, and both the mod and the Manager
        // offered Publish on it — a catalogue row, a stored file and a lineage, for content that
        // does not exist.
        //
        // ⚠ NOT the same question as the capture warning in the web upload, and it must not
        // swallow it: that one is about a file whose lines are all UNTRANSLATED, which is a real
        // starting point only its author can judge, and which the grace period already handles.
        // countLines counts every non-metadata key, captures included — so a capture file still
        // passes here and still gets asked the other question.
        //
        // ⚠ Refused rather than asked, because there is nobody to ask: with no line at all, no
        // answer makes the upload worth keeping.
        $lineCount = $this->countLines($json);
        if ($lineCount === 0) {
            throw new \InvalidArgumentException(
                'This file has no translation line yet — there is nothing to publish.'
            );
        }

        return [
            'json' => $json,
            'uuid' => $json['_uuid'],
            'line_count' => $lineCount,
            'tag_counts' => Translation::extractTagCounts($json),
            'file_hash' => $this->computeHash($json),
            'content_hash' => $this->computeContentHash($json),
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

    /**
     * The tag a merged line ends up carrying, given the tag shown and how it was picked.
     *
     * Three rules, and each exists for a reason worth stating:
     * - an explicit tag change is written AS-IS, because promoting it would undo the very
     *   thing the user just asked for (marking an AI line back as unreviewed);
     * - M and S are never rewritten: they are states, not translation quality;
     * - a hand edit becomes H, and picking a line tagged A becomes V — a human just read it,
     *   which is exactly what V means.
     *
     * Written out at every merge endpoint until now: three copies of the same paragraph, which
     * is three chances for them to stop agreeing on what a validated line is.
     *
     * The live edit session deliberately does NOT use this: there, ticking a line is editing,
     * not reviewing a merge, so an A stays an A.
     */
    /**
     * The file settings of a translation, as rows that can be compared one by one.
     *
     * 🔴 Here rather than on a controller because more than one screen asks it. It lived on the
     * merge controller, so the reading screen — the one place somebody decides whether to
     * DOWNLOAD a translation — could not tell them which fonts or exclusions it carries, and the
     * only way to find out was to take the file and open it.
     *
     * Returns an empty list for a file that has gone: a translation whose file is missing is a
     * page that shows nothing, never an exception.
     */
    public function comparableSettingsOf(Translation $translation): array
    {
        $path = $translation->getSafeFilePath();
        if (!$path || !file_exists($path)) {
            return [];
        }

        $json = json_decode($this->normalizeContent(file_get_contents($path)), true);

        return is_array($json) ? $this->extractComparableSettings($json) : [];
    }

    /**
     * The tag a picked line is stored with.
     *
     * 🔴 **Picking promotes A to V, because picking means "I read this".** That is the rule these
     * screens turn on — the commonest contribution of all moves no text, and taking it has to
     * record that somebody stood behind the words.
     *
     * @param  bool  $claimed
     *   False for a row the screen answered on its own. Every contested row arrives answered so the
     *   contribution is not left hanging, but an answer nobody made may not claim a reading: on a
     *   real lineage, 18 machine lines would have been marked human-checked by opening the page and
     *   pressing Merge. Such a row keeps its tag until somebody clicks its column, which is the one
     *   gesture that says it deliberately.
     */
    public static function resolveMergedTag(string $tag, string $source, bool $claimed = true): string
    {
        if ($source === 'tagchange' || $tag === 'M' || $tag === 'S') {
            return $tag;
        }

        if ($source === 'manual') {
            return 'H';
        }

        return $tag === 'A' && $claimed ? 'V' : $tag;
    }

    /**
     * Is this a line OF THE GAME — the only kind a merge arbitrates and a count counts?
     *
     * 🔴 `M` is the mod's own interface. It travels in the same file today and will get one of its
     * own; until then it is carried, never weighed. Counting it would inflate every measure of what
     * a translation covers, and arbitrating it would have contributors trading each other's menus.
     */
    public static function isGameLine(?string $tag): bool
    {
        return $tag !== 'M';
    }

    /**
     * How good a line is, as one number.
     *
     * 🔴 **The scale belongs to the socle** — `common/Merge.PriorityOf`, read by the mod and the
     * Manager. This is its PHP reading, and the JS editors hold a third
     * (`translation-editor.js::priorityOf`). `MergeLadderTest` compares all three, because a barème
     * that decides who wins a merge must not be able to drift between two products.
     *
     * ⚠ Takes the VALUE too: an `H` with nothing in it is a captured line waiting for a
     * translation — the floor, not the top.
     *
     * ⚠ `S` sits WITH `H`. A refusal is a person ruling the line must not be translated, which is a
     * reading exactly as writing one is. Ranking it with the machine let a contribution overwrite a
     * Main's refusal with nobody asked.
     */
    public static function priorityOf(?string $tag, ?string $value): int
    {
        if ($tag === 'H' && ($value === null || $value === '')) {
            return 0;
        }

        return match ($tag) {
            'V' => 2,
            'H', 'S' => 3,
            // Includes a line with no tag at all: the older file format wrote none and meant this.
            default => 1,
        };
    }

    /**
     * Does a contribution offer a Main something it does not already have?
     *
     * 🔴 **Not a three-way merge, and the difference is the point.** A merge settles two versions of
     * one person's file with an ancestor to say who moved. This settles somebody else's proposal
     * against a published translation: no ancestor, and the sides are not equal — **the Main keeps
     * its own on any tie**. Ties are the common case, not the exception: H against H, H against S
     * and S against H all land here.
     *
     * ⚠ **A contribution can be a TAG and not a word.** Reading the Main's machine line and marking
     * it validated changes no text and is precisely the work this site asks for. Comparing values
     * alone drops every one of them — seventeen on the lineage this was first measured against.
     *
     * @param  array{v?: string, t?: string}|string|null  $main          null when the Main has no such key
     * @param  array{v?: string, t?: string}|string|null  $contribution
     */
    public static function contributionWins($main, $contribution): bool
    {
        $cValue = self::entryValue($contribution);
        $cTag = self::entryTag($contribution);

        if (!self::isGameLine($cTag)) {
            return false;
        }

        if ($contribution === null) {
            return false;
        }

        // The case with no question in it, and the only one won without outranking anything.
        if ($main === null) {
            return true;
        }

        $mValue = self::entryValue($main);
        $mTag = self::entryTag($main);

        if (!self::isGameLine($mTag)) {
            return false;
        }

        // Same words AND same tag: nothing changed hands.
        if ($mValue === $cValue && $mTag === $cTag) {
            return false;
        }

        return self::priorityOf($cTag, $cValue) > self::priorityOf($mTag, $mValue);
    }

    /**
     * Where a contributed line stands relative to the Main — the FIRST of the two axes a review
     * screen counts on, and the one this service could not answer.
     *
     * 🔴 **"38 lines" and "56 rows" are two true measures of one lineage**, and the two screens
     * that show them were built from different questions: the merge view counts what needs a
     * decision (new + differing), this service counted what would be TAKEN. Neither is wrong and
     * neither can be derived from the other — on the lineage they were first measured against, 56
     * rows to decide and 38 worth taking, the 18 in between being machine translations that
     * differ, where the Main keeps its own.
     *
     * Crossed with the contribution's TAG (see {@see contributionsWaiting}), this is what tells a
     * Main whether an evening of review is worth it: 21 new lines all written by hand is a very
     * different proposition from 21 the machine produced.
     *
     * @param  array{v?: string, t?: string}|string|null  $main          null when the Main has no such key
     * @param  array{v?: string, t?: string}|string|null  $contribution
     * @return 'new'|'differing'|null                     null when the two agree, or it is not a game line
     */
    public static function lineKind($main, $contribution): ?string
    {
        $cTag = self::entryTag($contribution);

        if ($contribution === null || !self::isGameLine($cTag)) {
            return null;
        }

        if ($main === null) {
            return 'new';
        }

        $mTag = self::entryTag($main);

        if (!self::isGameLine($mTag)) {
            return null;
        }

        // ⚠ Tag included, never the words alone: a contribution that only validated a line has
        // genuinely diverged from the Main, and that is the whole work this site asks for.
        return self::entryValue($main) === self::entryValue($contribution) && $mTag === $cTag
            ? null
            : 'differing';
    }

    /**
     * The keys one contribution offers a Main — the ones it would actually win.
     *
     * ⚠ Keys, not a tally: two contributions offering the same line are one line to recover, and
     * adding their counts would promise twice the work that exists.
     *
     * @param  array<string, mixed>  $main    the Main's file, decoded
     * @param  array<string, mixed>  $branch  the contribution's file, decoded
     * @return array<string, true>            the keys this contribution wins, as a set
     */
    public static function keysOfferedTo(array $main, array $branch): array
    {
        $offered = [];

        foreach ($branch as $key => $entry) {
            // Underscore keys are metadata (_uuid, _game, _fonts…), not translated lines.
            if (str_starts_with((string) $key, '_')) {
                continue;
            }

            if (self::contributionWins($main[$key] ?? null, $entry)) {
                $offered[$key] = true;
            }
        }

        return $offered;
    }

    /**
     * What is waiting on a Main: contributions it has NOT yet looked at, that are actually holding
     * something it could take.
     *
     * 🔴 **Two filters, and dropping either one produces noise.**
     *   · *not reviewed* alone counts a fresh contribution that offers nothing — the Main opens the
     *     merge screen and finds rows both sides already agree on;
     *   · *holding something* alone counts work already arbitrated and refused, which comes back
     *     every time and teaches the reader that the number means nothing.
     *
     * A counter that cries wolf is worse than no counter: it hides the times there IS something to
     * do. So this answers one question — *is there work here I have not seen* — and the screens
     * show it as such.
     *
     * ⚠ Weighed with the rule the merge screen pre-selects with, so the number on the button and
     * the rows behind it cannot disagree.
     *
     * ⚠ **Cached on the state, not on a clock.** The key carries the Main's hash and every
     * branch's, so it changes the moment any of them does — including when one is reviewed — and
     * stays valid the rest of the time. A timed cache would answer with yesterday's number for no
     * reason, and an invalidation hook would be one more thing to remember when a file is written.
     *
     * ⚠ Frozen branches are counted: closing a lineage stops NEW contributions, it does not throw
     * away the ones already received, and their Main may still merge them.
     *
     * 🔴 **Two measures, both true, and neither derivable from the other.** `lines` is what would
     * be TAKEN; `review` is what needs a decision — new lines plus lines both sides hold
     * differently, including those where the Main keeps its own. On the lineage this was measured
     * against: 56 to review, 38 to take, the 18 in between being two machine translations that
     * differ. A screen showing only one of them cannot answer both "is there work here for me" and
     * "how long will this take".
     *
     * ⚠ `lines` keeps its meaning to the byte: a published mod prints it as "N lines to take", and
     * handing it the larger figure would have every installed client lie in the same direction.
     *
     * Each of the two is broken down by the contribution's TAG, which is what says whether it is
     * worth an evening: 21 new lines all written by hand is not the same proposition as 21 the
     * machine produced. `new` and `differing` sum to `review`.
     *
     * @return array{branches: int, lines: int, review: int, new: array<string,int>, differing: array<string,int>}
     */
    public function contributionsWaiting(Translation $main): array
    {
        $branches = Translation::where('file_uuid', $main->file_uuid)
            ->branches()
            ->get(['id', 'file_path', 'file_hash', 'reviewed_hash'])
            // Never reviewed, or changed since it was: pushing new work to a contribution the Main
            // has already been through puts it back in front of them, which is the point.
            ->filter(fn (Translation $b) => !$b->reviewed_hash || $b->file_hash !== $b->reviewed_hash)
            ->values();

        if ($branches->isEmpty()) {
            return self::noContributionsWaiting();
        }

        $signature = $branches
            ->map(fn (Translation $b) => $b->id . ':' . ($b->file_hash ?? '') . ':' . ($b->reviewed_hash ?? ''))
            ->sort()
            ->implode('|');

        // ⚠ The version suffix is not decoration: entries cached before this answer carried three
        // more keys are still valid for a day, and a screen reading `['new']` off one of them would
        // read null and print a zero. Bumped whenever the SHAPE changes, never when the rule does —
        // a changed rule already changes the hashes it is keyed on.
        $cacheKey = 'contrib-waiting:v2:' . $main->file_uuid
            . ':' . ($main->file_hash ?? '')
            . ':' . md5($signature);

        return \Illuminate\Support\Facades\Cache::remember($cacheKey, now()->addDay(), function () use ($main, $branches) {
            $mainContent = $main->decodeFileContent() ?? [];

            $withWork = 0;
            $lines = [];

            // One pass over every contribution, two questions per line. Reading the files twice —
            // once for what is taken, once for what is reviewed — would double the cost of the one
            // expensive thing here for an answer already in hand.
            //
            // Per key: the kind (new or differing) and the BEST tag any contribution offers for it.
            // Best rather than first, because "two contributions offering the same line are one
            // line to recover" has to hold for the breakdown too, and reading a set of keys in file
            // order is not a decision anybody took.
            $kindOf = [];
            $tagOf = [];
            $rankOf = [];

            foreach ($branches as $branch) {
                $content = $branch->decodeFileContent();

                // ⚠ Not counted, and SAID. A contribution whose file cannot be read offers its
                // Main nothing — the merge screen would show an empty column — so counting it
                // would promise work that is not there. But a missing file is a storage fault,
                // not an empty contribution, and passing over it in silence is how one stays
                // unnoticed for months.
                if ($content === null) {
                    \Illuminate\Support\Facades\Log::warning(
                        'Contribution file unreadable, left out of the waiting count',
                        ['translation_id' => $branch->id, 'lineage' => $main->file_uuid]
                    );
                    continue;
                }

                foreach ($content as $key => $entry) {
                    if (str_starts_with((string) $key, '_')) {
                        continue;
                    }

                    $kind = self::lineKind($mainContent[$key] ?? null, $entry);

                    if ($kind === null) {
                        continue;
                    }

                    // ⚠ The rank is kept beside the letter, never recomputed from it: a captured
                    // line is an `H` with nothing in it and sits at the FLOOR of the ladder, so
                    // asking priorityOf() for a letter alone would rank it above every real
                    // translation a contributor offered.
                    $tag = self::entryTag($entry) ?? 'A';
                    $rank = self::priorityOf($tag, self::entryValue($entry));

                    $kindOf[$key] = $kind;

                    if (!isset($rankOf[$key]) || $rank > $rankOf[$key]) {
                        $tagOf[$key] = $tag;
                        $rankOf[$key] = $rank;
                    }
                }

                $offered = self::keysOfferedTo($mainContent, $content);
                if ($offered === []) {
                    continue;
                }

                $withWork++;
                $lines += $offered;
            }

            $tally = ['new' => [], 'differing' => []];

            foreach ($kindOf as $key => $kind) {
                $tag = $tagOf[$key];
                $tally[$kind][$tag] = ($tally[$kind][$tag] ?? 0) + 1;
            }

            return [
                'branches' => $withWork,
                'lines' => count($lines),
                'review' => count($kindOf),
                'new' => $tally['new'],
                'differing' => $tally['differing'],
            ];
        });
    }

    /**
     * The shape of "nothing is waiting", in one place.
     *
     * ⚠ Written out rather than left to callers: five keys, and a caller that forgets one produces
     * a screen reading a null as a zero — which is the one thing this answer must never do.
     *
     * @return array{branches: int, lines: int, review: int, new: array<string,int>, differing: array<string,int>}
     */
    public static function noContributionsWaiting(): array
    {
        return ['branches' => 0, 'lines' => 0, 'review' => 0, 'new' => [], 'differing' => []];
    }

    /**
     * The other direction: what THIS contribution is holding for its Main.
     *
     * ⚠ Its own question, and its own answer. A contributor needs to know whether their work has
     * anything left to give — not what the other contributions are doing, which is none of their
     * business and which `isReadableBy` keeps out of their reach anyway.
     */
    public function linesOfferedToMain(Translation $branch, ?Translation $main): int
    {
        if ($main === null) {
            return 0;
        }

        $cacheKey = 'contrib-offered:' . $branch->id
            . ':' . ($branch->file_hash ?? '')
            . ':' . ($main->file_hash ?? '');

        return \Illuminate\Support\Facades\Cache::remember($cacheKey, now()->addDay(), function () use ($branch, $main) {
            $branchContent = $branch->decodeFileContent();
            if ($branchContent === null) {
                return 0;
            }

            return count(self::keysOfferedTo($main->decodeFileContent() ?? [], $branchContent));
        });
    }

    /** The text of an entry, in either file format ({v,t} object or a bare string). */
    private static function entryValue($entry): string
    {
        if ($entry === null) {
            return '';
        }

        return is_array($entry) ? (string) ($entry['v'] ?? '') : (string) $entry;
    }

    /** The tag of an entry. A missing one reads as A, which is what the older format meant. */
    private static function entryTag($entry): string
    {
        if (is_array($entry) && isset($entry['t']) && $entry['t'] !== '') {
            return (string) $entry['t'];
        }

        return 'A';
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
        return $this->hashDocument($json, (string) ($json['_uuid'] ?? ''));
    }

    /**
     * The whole of what somebody made, fingerprinted so that two accounts holding it can be told
     * apart from two accounts holding the same thing.
     *
     * 🔴 **Answers a different question from computeHash**: not "did MY translation move" but "is
     * this file somebody else's". Two differences follow from that, and neither is cosmetic.
     *
     * ⚠ **The uuid is out.** A fork takes a new one, so file_hash sees two identical files as
     * different from the instant of the fork — which is precisely the case worth catching.
     *
     * ⚠ **The settings sections are IN** — fonts, font rules, image replacements, exclusions,
     * variables, game settings. Somebody who is not a translator can take a translation that
     * refuses contributions and rework its fonts and its images: that is work, and a file carrying
     * replacements the original never had is not the same file. Leaving them out would refuse that
     * person; it would also have been the thinner rule to argue for, since a check that ignores
     * half the file calls "identical" two things that are not.
     *
     * ⚠ **Sync and provenance metadata stay out** — `_source`, `_forked_from`, `_local_changes`,
     * `_game`. They differ between two people holding the very same file, so including them would
     * make the fingerprint unequal always, and the check would never fire on anything.
     *
     * ⚠ **Never compared to the mod's own fingerprint, and never issued as a file_hash.** Each side
     * compares its values only to its own, which is why neither has to serialise floats, key order
     * or unicode the way the other does — a cross-language byte agreement this would not survive
     * (a size multiplier of 1.0 alone breaks it).
     */
    public function computeContentHash(array $json): string
    {
        return $this->hashDocument($json, '', withSettings: true);
    }

    /** Where the settings that travel inside the file live. Same six as SETTING_SECTION_KEYS. */
    private const HASHED_SETTINGS_KEYS = [
        '_fonts', '_font_overrides', '_image_replacements',
        '_exclusions', '_variables', '_settings',
    ];

    /**
     * Sort every nested object so that key order cannot change a hash.
     *
     * ⚠ Lists keep their order: a font rule list is applied in sequence, so two files holding the
     * same rules in a different order are genuinely two different files. ksort over a list whose
     * keys are already 0..n leaves it exactly as it was.
     */
    private function canonicalize(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }

        $out = [];
        foreach ($value as $key => $inner) {
            $out[$key] = $this->canonicalize($inner);
        }
        ksort($out);

        return $out;
    }

    private function hashDocument(array $json, string $uuid, bool $withSettings = false): string
    {
        $hashData = [];
        foreach ($json as $key => $value) {
            // Translation keys only — the uuid is written below, at the value this call asks for.
            if (!str_starts_with($key, '_')) {
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

        // Written after the loop, so that the uuid this call was given is the one hashed — and so
        // that a file carrying no _uuid at all hashes like one carrying an empty string, rather
        // than producing a shorter document.
        $hashData['_uuid'] = $uuid;

        if ($withSettings) {
            foreach (self::HASHED_SETTINGS_KEYS as $key) {
                // Absent and empty must hash alike: the mod stops writing a section once its last
                // entry is removed, so a file that had fonts and no longer does would otherwise
                // read as different from one that never had any.
                $section = $json[$key] ?? null;
                if ($section === null || $section === [] || $section === (object) []) {
                    continue;
                }

                $hashData[$key] = $this->canonicalize($section);
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
     * Remove a translation: its row and the file behind it.
     *
     * 🔴 **One entry point, because four callers had written this out for themselves and one of
     * them got it wrong.** Handling a report deleted the row and left the JSON on disk — for ever,
     * on the one path where the content is being removed *because somebody complained about it*.
     * The others each rebuilt the same two lines, so the mistake was invisible: nothing to compare
     * against.
     *
     * ⚠ File first. A row without its file is a page that shows nothing; a file without its row is
     * bytes nobody can reach and nobody will ever remove. If one of the two has to fail, it must be
     * the one that leaves the site consistent.
     *
     * ⚠ Branches keep their own rows and their own files. `parent_id` is "on delete set null", so
     * removing a Main orphans its contributions rather than destroying work that is not ours.
     */
    public function deleteTranslation(Translation $translation): void
    {
        $this->deleteFile($translation->file_path);
        $translation->delete();
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
            // 🔴 **"No Main" is two different situations, and only one of them is a new
            // translation.** A uuid nobody has ever published is somebody starting out. A uuid whose
            // Main has been deleted is a lineage that has lost its head — and until 2026-08-26 the
            // next upload silently took it over, inheriting a following it never earned and leaving
            // the other contributors as branches of a stranger.
            //
            // The branches ARE the trace: they carry the same uuid and outlive the Main, whose
            // removal only nulls their parent_id.
            //
            // ⚠ A deleted Main with no branches leaves no trace at all, and that is deliberate:
            // nobody else was in that lineage, the sender holds the file, and refusing would kill a
            // uuid for no one's benefit.
            if (Translation::where('file_uuid', $uuid)->exists()) {
                return [
                    'visibility' => null,
                    'parent_id' => null,
                    'original' => null,
                    'refused' => self::MAIN_GONE,
                    // ⚠ A code beside the sentence, because the two have different readers. The
                    // sentence is for the mod, which is translated into nothing and shows it
                    // as-is; the site is translated into twenty languages and must pick its own
                    // wording. Sending the English text to a Blade view would put untranslated
                    // prose on a translated page.
                    'refused_code' => 'main_gone',
                ];
            }

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

        // 🔴 **A Main whose owner is gone takes nothing, however willing it looks.**
        //
        // Erasing an account keeps the translations — the work stays published, which is the point
        // — so the Main is still there, still downloadable, and still says it accepts branches. But
        // there is nobody left to read one, and a contribution nobody can ever merge is worse than
        // a refusal: it is a refusal that takes months to notice.
        //
        // ⚠ Deleted, NOT banned. A ban is a decision that can be undone and the account is still
        // somebody's; only an erasure is final.
        if ($original->user?->isDeletedAccount()) {
            return [
                'visibility' => null,
                'parent_id' => null,
                'original' => $original,
                'refused' => self::MAIN_ABANDONED,
                'refused_code' => 'main_abandoned',
            ];
        }

        // 🔴 A branch, but only if the Main takes them.
        //
        // Refused here rather than in a controller, because determineOwnership is called from TWO
        // of them — Api\TranslationController and TranslationController — and a check placed in
        // one leaves the other wide open.
        //
        // ⚠ **A refusal is a refusal, never a quiet substitution.** Turning this into a fork would
        // be the tempting answer and the wrong one: a fork takes a NEW uuid, so the file on
        // somebody's disk would stop matching what the site holds, decided by a server that cannot
        // see that file. The client offers the fork; we only ever say no.
        if (!$original->accepts_branches) {
            return [
                'visibility' => null,
                'parent_id' => null,
                'original' => $original,
                'refused' => self::BRANCHES_REFUSED,
                'refused_code' => 'branches_refused',
            ];
        }

        return [
            'visibility' => 'branch',
            'parent_id' => $original->id,
            'original' => $original,
        ];
    }

    /**
     * Why an upload was refused, in words a mod that predates this feature can show as-is.
     *
     * ⚠ **This sentence is the whole interface for those versions.** The website goes live before
     * any release of the mod, so an older mod will read is_owner:false, announce a contribution,
     * and only meet this on the click.
     *
     * 🔴 **It points at Fork, in the mod — not at the website.** Forking is something the mod does:
     * it is where the file lives and where the button is, and it has had one for a long time, so
     * even the versions this text exists for can act on it. The site can promote a branch too, but
     * that is not the road anybody is on when they read this — they are in a game, having just
     * pressed publish.
     */
    public const BRANCHES_REFUSED =
        'This translation does not accept contributions. Your lines are safe: use Fork in the mod '
        . 'to publish your own version of it instead.';

    /**
     * The Main is still here; the account that owned it is not.
     *
     * ⚠ Says what became of the work before saying no. Somebody reading this has just been refused
     * on a translation they can still see and download, and the first question is whether it is
     * still safe to use — it is, nothing was withdrawn.
     */
    public const MAIN_ABANDONED =
        'The account that owned this translation has been deleted, so contributions can no longer '
        . 'be reviewed. The translation itself stays available. Your lines are safe: use Fork in '
        . 'the mod to publish them as your own version.';

    /**
     * The Main itself is gone, and its contributors are still holding branches of it.
     *
     * ⚠ Same ending as above and a different beginning, because what somebody has to understand is
     * not the same: here the translation they were contributing to no longer exists at all, so
     * "still available" would be a lie and their own copy is now the only one they have.
     */
    public const MAIN_GONE =
        'The translation this contributes to has been removed by its author, so there is nothing '
        . 'left to contribute to. Your own copy is untouched, and it is now the only one: use Fork '
        . 'in the mod to publish it as your own version.';

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
