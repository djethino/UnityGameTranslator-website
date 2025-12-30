<?php

namespace App\Services;

use App\Models\Translation;
use Illuminate\Support\Facades\Storage;

class TranslationService
{
    private const VALID_TAGS = ['H', 'V', 'A', 'M', 'S'];

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

        // Parse JSON (depth 3: root → key → {v, t})
        $json = json_decode($content, true, 3);

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
                'hint' => 'Each entry must be: {"key": {"v": "value" or null, "t": "H|V|A|M|S"}}',
            ]));
        }

        return [
            'json' => $json,
            'uuid' => $json['_uuid'],
            'line_count' => $this->countLines($json),
            'tag_counts' => Translation::extractTagCounts($json),
            'file_hash' => $this->computeHash($json),
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

                $hashData[$normalizedKey] = $value;
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
        Storage::disk('public')->put($fileName, $normalized);

        return $fileName;
    }

    /**
     * Delete translation file from disk.
     */
    public function deleteFile(?string $filePath): bool
    {
        if ($filePath && Storage::disk('public')->exists($filePath)) {
            return Storage::disk('public')->delete($filePath);
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
