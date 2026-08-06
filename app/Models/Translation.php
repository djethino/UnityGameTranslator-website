<?php

namespace App\Models;

use App\Services\TranslationService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class Translation extends Model
{
    protected $fillable = [
        'game_id',
        'user_id',
        'parent_id',
        'source_language',
        'target_language',
        'line_count',
        'capture_count',
        'human_count',
        'validated_count',
        'ai_count',
        'main_rating',
        'reviewed_hash',
        'status',
        'visibility',
        // 'type' is now a computed attribute from HVASM stats
        'notes',
        'resources_url',
        'file_path',
        'file_uuid',
        'file_hash',
        'font_config',
        'settings_summary',
    ];

    protected $casts = [
        'id' => 'integer',
        'game_id' => 'integer',
        'user_id' => 'integer',
        'parent_id' => 'integer',
        'line_count' => 'integer',
        'capture_count' => 'integer',
        'human_count' => 'integer',
        'validated_count' => 'integer',
        'ai_count' => 'integer',
        'main_rating' => 'integer',
        'download_count' => 'integer',
        'vote_count' => 'integer',
        'font_config' => 'array',
        'settings_summary' => 'array',
        'content_updated_at' => 'datetime',
    ];

    /**
     * Boot the model and register event listeners.
     */
    protected static function booted(): void
    {
        // Stamp when the translation itself changed. Deliberately keyed on
        // file_hash and not on updated_at: increment('vote_count') and
        // increment('download_count') touch updated_at, and a vote is not a
        // change to the translation.
        static::saving(function (Translation $translation) {
            if ($translation->isDirty('file_hash')) {
                $translation->content_updated_at = now();
            }
        });

        // Reset main_rating when a branch's file_hash changes (content was modified)
        static::updating(function (Translation $translation) {
            if ($translation->isDirty('file_hash') && $translation->main_rating !== null) {
                $translation->main_rating = null;
                $translation->reviewed_hash = null;
            }
        });

        // When public content changes, refresh games.updated_at (sitemap <lastmod>)
        // and ping IndexNow search engines. Covers every path: web upload, API
        // upload, merge apply, edit/delete, admin delete.
        $syncSearchEngines = function (Translation $translation) {
            $game = $translation->game;
            if ($game) {
                $game->touch();
                \App\Jobs\SubmitGameToIndexNow::dispatch($game->id);
            }
        };
        static::created(function (Translation $translation) use ($syncSearchEngines) {
            if ($translation->visibility === 'public') {
                $syncSearchEngines($translation);
            }
        });
        static::deleted(function (Translation $translation) use ($syncSearchEngines) {
            if ($translation->visibility === 'public') {
                $syncSearchEngines($translation);
            }
        });
        static::updated(function (Translation $translation) use ($syncSearchEngines) {
            // Only content-level changes matter to search engines - not vote or
            // download counter increments, which fire 'updated' constantly.
            // getOriginal() still holds pre-save values inside this event, so a
            // public -> branch unpublish (page content changed too) is caught.
            if (!$translation->wasChanged(['file_hash', 'notes', 'status', 'visibility'])) {
                return;
            }
            if ($translation->visibility === 'public' || $translation->getOriginal('visibility') === 'public') {
                $syncSearchEngines($translation);
            }
        });
    }

    /**
     * Get the safe, validated file path.
     * Prevents path traversal attacks by ensuring the path stays within storage.
     */
    public function getSafeFilePath(): ?string
    {
        if (empty($this->file_path)) {
            return null;
        }

        // Files are stored in the private disk (storage/app/private/)
        $basePath = storage_path('app/private');
        $requestedPath = $basePath . '/' . $this->file_path;
        $fullPath = realpath($requestedPath);

        // Validate that the resolved path is within the storage/app/public directory
        // realpath() returns false for non-existent files, so also check the parent directory
        if (!$fullPath) {
            // File doesn't exist yet, validate the directory
            $dirPath = realpath(dirname($requestedPath));
            $realBasePath = realpath($basePath);
            if (!$dirPath || !$realBasePath || !Str::startsWith($dirPath, $realBasePath)) {
                return null;
            }
            return $requestedPath;
        }

        $realBasePath = realpath($basePath);
        if (!$realBasePath || !Str::startsWith($fullPath, $realBasePath)) {
            // Path traversal detected
            return null;
        }

        return $fullPath;
    }

    /**
     * Compute SHA256 hash of the translation content (normalized with sorted keys).
     * This ensures the hash is deterministic regardless of JSON key order.
     *
     * IMPORTANT: Must match C# ComputeContentHash() exactly:
     * - Include only translations (non-underscore keys) + _uuid
     * - Exclude other metadata (_game, _local_changes, etc.)
     */
    public function computeHash(): ?string
    {
        $safePath = $this->getSafeFilePath();
        if (!$safePath || !file_exists($safePath)) {
            return null;
        }

        $content = file_get_contents($safePath);
        if ($content === false) {
            return null;
        }

        // Parse JSON
        $data = json_decode($content, true);
        if (!is_array($data)) {
            return null;
        }

        // Filter to only include translations + _uuid (same as C# ComputeContentHash)
        // Exclude other metadata like _game, _local_changes, etc.
        $hashData = [];
        foreach ($data as $key => $value) {
            // Include _uuid and non-metadata keys (translations)
            if ($key === '_uuid' || !str_starts_with($key, '_')) {
                // Only v/t are content — the ordering index "i" must not
                // affect the hash (see TranslationService::hashableEntry)
                $hashData[$key] = TranslationService::hashableEntry($value);
            }
        }

        // Sort keys for deterministic hash
        ksort($hashData);
        // Use same flags as C# Newtonsoft.Json: no unicode escaping, no slash escaping
        $normalized = json_encode($hashData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return hash('sha256', $normalized);
    }

    /**
     * Update the file hash from current file content
     */
    public function updateHash(): void
    {
        $this->file_hash = $this->computeHash();
        $this->save();
    }

    public const TYPES = [
        'ai' => 'Full AI Translation',
        'human' => 'Human Translation',
        'ai_corrected' => 'AI + Human Correction',
    ];

    public const VISIBILITY = [
        'public' => 'Public',
        'branch' => 'Branch (Private)',
    ];

    public const STATUSES = [
        'in_progress' => 'In Progress',
        'complete' => 'Complete',
    ];

    /**
     * Compute type from HVASM stats.
     * This replaces the stored 'type' column with a derived value.
     *
     * @return string 'human', 'ai_corrected', or 'ai'
     */
    public function getTypeAttribute(): string
    {
        $total = $this->human_count + $this->validated_count + $this->ai_count;

        if ($total === 0) {
            return 'ai'; // Default for empty/capture-only files
        }

        // If more than 50% is human-translated, it's a human translation
        if ($this->human_count > $total * 0.5) {
            return 'human';
        }

        // If there are validated or human entries, it's been human-reviewed
        if ($this->validated_count > 0 || $this->human_count > 0) {
            return 'ai_corrected';
        }

        // Otherwise it's pure AI
        return 'ai';
    }

    public function getTypeLabel(): string
    {
        return self::TYPES[$this->type] ?? $this->type;
    }

    public function getVisibilityLabel(): string
    {
        return self::VISIBILITY[$this->visibility] ?? $this->visibility;
    }

    public function game()
    {
        return $this->belongsTo(Game::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function parent()
    {
        return $this->belongsTo(Translation::class, 'parent_id');
    }

    public function forks()
    {
        return $this->hasMany(Translation::class, 'parent_id');
    }

    public function votes()
    {
        return $this->hasMany(Vote::class);
    }

    /**
     * Get the effective resources URL: own URL if set, otherwise parent's URL.
     * Forks inherit the parent's resources URL.
     */
    public function getEffectiveResourcesUrl(): ?string
    {
        if (!empty($this->resources_url)) {
            return $this->resources_url;
        }
        if ($this->parent_id && $this->parent) {
            return $this->parent->resources_url;
        }
        return null;
    }

    /**
     * When the translation itself last changed. Falls back to updated_at for
     * rows written before the column existed.
     */
    public function contentChangedAt(): \Illuminate\Support\Carbon
    {
        return $this->content_updated_at ?? $this->updated_at;
    }

    /**
     * Has this translation been worked on since it was first published?
     * A minute of slack absorbs the gap between the row's creation and the
     * stamp written in the same request.
     */
    public function hasBeenUpdatedSincePublication(): bool
    {
        if (!$this->created_at) {
            return false;
        }

        // Raw timestamps, not diffInSeconds(): Carbon 3 returns a SIGNED
        // difference, so the intuitive reading of a->diffInSeconds(b) is
        // negative here and the check silently never fired.
        return $this->contentChangedAt()->getTimestamp() - $this->created_at->getTimestamp() > 60;
    }

    /**
     * Decode this translation's stored file, or null when it is missing or
     * unreadable. Deliberately separate from computeHash(), which keeps its
     * own reading path: its output is compared against hashes already stored
     * in the wild and must not shift.
     */
    public function decodeFileContent(): ?array
    {
        $safePath = $this->getSafeFilePath();
        if (!$safePath || !file_exists($safePath)) {
            return null;
        }

        $content = file_get_contents($safePath);
        if ($content === false) {
            return null;
        }

        $data = json_decode($content, true);

        return is_array($data) ? $data : null;
    }

    /**
     * Host of the effective resources URL, for showing where a link leads
     * without making the reader parse a long URL. Null when there is no link.
     */
    public function getResourcesHost(): ?string
    {
        $url = $this->getEffectiveResourcesUrl();
        if (!$url) {
            return null;
        }

        return parse_url($url, PHP_URL_HOST) ?: null;
    }

    /**
     * Number of entries in one settings section of the summary, 0 when absent.
     * Reads the stored count, which is exact even when the preview is bounded.
     */
    public function settingsCount(string $section): int
    {
        return (int) ($this->settings_summary[$section]['count'] ?? 0);
    }

    /**
     * The settings sections, in display order. Shared by every screen that
     * lists or compares them so they never drift apart.
     */
    public const SETTINGS_SECTIONS = [
        'fonts',
        'font_rules',
        'images',
        'exclusions',
        'variables',
        'game_settings',
    ];

    /**
     * How many entries this translation carries in each settings section.
     * Reads the stored columns only — never the file.
     *
     * @return array<string,int> section => count (0 when absent)
     */
    /**
     * Is this font entry a deliberate setting, or just a font the mod happened
     * to meet in-game?
     *
     * FontManager records EVERY font it encounters, with default values. So
     * font_config is part settings, part discovery inventory — and the
     * inventory depends only on which screens the player walked through.
     * Counting it whole overstates what the author actually configured, and
     * makes two copies of the same translation look different for no reason.
     */
    public static function isDeliberateFontSetting(array $settings): bool
    {
        return ($settings['enabled'] ?? true) === false
            || !empty($settings['fallback'])
            || abs(($settings['scale'] ?? 1.0) - 1.0) > 0.001;
    }

    /** Fonts the author actually configured (see isDeliberateFontSetting). */
    public function configuredFonts(): array
    {
        return array_filter(
            $this->font_config ?? [],
            fn ($settings) => is_array($settings) && self::isDeliberateFontSetting($settings)
        );
    }

    /** Fonts merely met in-game, carrying no setting of their own. */
    public function detectedFontCount(): int
    {
        return count($this->font_config ?? []) - count($this->configuredFonts());
    }

    public function settingsSectionCounts(): array
    {
        return [
            'fonts' => count($this->configuredFonts()),
            'font_rules' => $this->settingsCount('font_overrides'),
            'images' => $this->settingsCount('image_replacements'),
            'exclusions' => $this->settingsCount('exclusions'),
            'variables' => $this->settingsCount('variables'),
            'game_settings' => count($this->settings_summary['game_settings'] ?? []),
        ];
    }

    /**
     * Does this translation carry anything beyond its lines? Fonts live in
     * their own column, the rest in the summary.
     */
    public function hasSettings(): bool
    {
        return !empty($this->font_config) || !empty($this->settings_summary);
    }

    /**
     * Do the two translations carry different settings? Section counts alone
     * would miss a swap (one font replaced by another keeps the count), so
     * fonts are compared on their configured entries, not just their number.
     */
    public function settingsDifferFrom(self $other): bool
    {
        if ($this->configuredFonts() != $other->configuredFonts()) {
            return true;
        }

        return $this->settingsSectionCounts() !== $other->settingsSectionCounts();
    }

    /**
     * Image replacements point at PNG files that do NOT travel in the JSON:
     * the mod reads them from <mod folder>/images/. So a file declaring
     * replacements without a resources link is incomplete for whoever
     * downloads it — worth saying plainly rather than letting them find out
     * in-game.
     */
    public function hasUnreachableImageAssets(): bool
    {
        return $this->settingsCount('image_replacements') > 0
            && empty($this->getEffectiveResourcesUrl());
    }

    /**
     * Get the current user's vote for this translation
     */
    /**
     * Get the current user's vote for this translation.
     * Works with both web (auth()) and API (explicit user) contexts.
     */
    public function userVote()
    {
        if (!auth()->check()) {
            return null;
        }
        return $this->votes()->where('user_id', auth()->id())->first();
    }

    /**
     * Get a specific user's vote for this translation.
     * Used by API controllers where auth() may not be set.
     */
    public function userVoteFor(\App\Models\User $user)
    {
        return $this->votes()->where('user_id', $user->id)->first();
    }

    /**
     * Vote on this translation.
     * Accepts an optional user parameter for API context where auth() is not available.
     */
    public function vote(int $value, ?\App\Models\User $user = null): void
    {
        $userId = $user ? $user->id : auth()->id();

        \DB::transaction(function () use ($value, $userId) {
            $existingVote = $this->votes()
                ->where('user_id', $userId)
                ->lockForUpdate()
                ->first();

            if ($existingVote) {
                if ($existingVote->value === $value) {
                    // Same vote = remove it
                    $existingVote->delete();
                    $this->decrement('vote_count', $value);
                } else {
                    // Different vote = change it
                    $existingVote->update(['value' => $value]);
                    $this->increment('vote_count', $value * 2); // -1 to 1 = +2, 1 to -1 = -2
                }
            } else {
                // New vote
                $this->votes()->create([
                    'user_id' => $userId,
                    'value' => $value,
                ]);
                $this->increment('vote_count', $value);
            }
        });
    }

    public function isComplete(): bool
    {
        return $this->status === 'complete';
    }

    /**
     * A Fork is a Main translation that was derived from another Main.
     * (Not a branch - branches are contributions to someone else's Main)
     */
    public function isFork(): bool
    {
        return $this->parent_id !== null && $this->isMain();
    }

    public function incrementDownloads()
    {
        $this->increment('download_count');
    }

    /**
     * Get the root translation of this lineage (first upload with this UUID)
     */
    public function getLineageRoot(): ?Translation
    {
        if (!$this->file_uuid) {
            return null;
        }

        return static::where('file_uuid', $this->file_uuid)
            ->orderBy('created_at', 'asc')
            ->first();
    }

    /**
     * Get all translations in the same lineage
     */
    public function lineage()
    {
        if (!$this->file_uuid) {
            return collect([$this]);
        }

        return static::where('file_uuid', $this->file_uuid)
            ->orderBy('created_at', 'asc')
            ->get();
    }

    /**
     * Check if this is the root translation of its lineage
     */
    public function isLineageRoot(): bool
    {
        $root = $this->getLineageRoot();
        return $root && $root->id === $this->id;
    }

    // =========================================
    // Main/Branch/Fork System
    // =========================================

    /**
     * Check if this translation is a branch (private contributor)
     */
    public function isBranch(): bool
    {
        return $this->visibility === 'branch';
    }

    /**
     * Check if this translation is a Main (public + lineage root)
     */
    public function isMain(): bool
    {
        return $this->visibility === 'public' && $this->isLineageRoot();
    }

    /**
     * Get the Main translation of this lineage
     */
    public function getMain(): ?Translation
    {
        if (!$this->file_uuid) {
            return null;
        }

        return static::where('file_uuid', $this->file_uuid)
            ->where('visibility', 'public')
            ->orderBy('created_at', 'asc')
            ->first();
    }

    /**
     * Get all branches of this Main translation
     */
    public function getBranches()
    {
        if (!$this->file_uuid || !$this->isMain()) {
            return collect();
        }

        return static::where('file_uuid', $this->file_uuid)
            ->where('visibility', 'branch')
            ->get();
    }

    // =========================================
    // Scopes
    // =========================================

    /**
     * Scope to filter only public translations (Main/Fork)
     */
    public function scopePublic($query)
    {
        return $query->where('visibility', 'public');
    }

    /**
     * Scope to filter only branch translations
     */
    public function scopeBranches($query)
    {
        return $query->where('visibility', 'branch');
    }

    /**
     * Scope to filter translations visible to a specific user.
     * A user can see: public translations, their own translations,
     * or branches of translations they own as Main.
     */
    public function scopeVisibleToUser($query, $userId)
    {
        return $query->where(function ($q) use ($userId) {
            $q->where('visibility', 'public')
                ->orWhere('user_id', $userId)
                ->orWhereIn('file_uuid', function ($sub) use ($userId) {
                    $sub->select('file_uuid')
                        ->from('translations')
                        ->where('user_id', $userId)
                        ->where('visibility', 'public');
                });
        });
    }

    // =========================================
    // Tag Extraction (HVA System: Human/Validated/AI)
    // =========================================

    /**
     * Extract HVA tag counts from JSON content.
     * Supports both old format (string values) and new format (object with v/t).
     *
     * H entries with empty/null value are "capture only" and counted separately.
     * They are excluded from quality scoring as they represent untranslated captures.
     *
     * @param array $json Parsed translation JSON
     * @return array ['human_count' => int, 'validated_count' => int, 'ai_count' => int, 'capture_count' => int]
     */
    public static function extractTagCounts(array $json): array
    {
        $human = 0;
        $validated = 0;
        $ai = 0;
        $capture = 0;

        foreach ($json as $key => $value) {
            // Skip metadata keys
            if (str_starts_with($key, '_')) {
                continue;
            }

            // New format: {"v": "translation", "t": "A"}
            if (is_array($value) && isset($value['t'])) {
                $tag = $value['t'];
                $val = $value['v'] ?? '';

                // H with empty value = capture only (excluded from scoring)
                if ($tag === 'H' && ($val === '' || $val === null)) {
                    $capture++;
                } else {
                    match ($tag) {
                        'H' => $human++,
                        'V' => $validated++,
                        'A' => $ai++,
                        'M', 'S' => null, // Mod UI and Skipped are not counted
                        default => $ai++, // Fallback to AI
                    };
                }
            } else {
                // Old format (string value) = AI by default
                $ai++;
            }
        }

        return [
            'human_count' => $human,
            'validated_count' => $validated,
            'ai_count' => $ai,
            'capture_count' => $capture,
        ];
    }

    // =========================================
    // Computed Attributes for Scoring
    // =========================================

    /**
     * Get effective lines count (excludes capture, S, M).
     * These are the lines that actually have translations.
     */
    public function getEffectiveLinesAttribute(): int
    {
        return $this->human_count + $this->validated_count + $this->ai_count;
    }

    /**
     * Get quality score based on translation source quality.
     * Formula: (H*3 + V*2 + A*1) / effective_lines
     * Returns 0-3 scale where 3 = all human translations.
     */
    public function getQualityScoreAttribute(): float
    {
        $effective = $this->effective_lines;
        if ($effective === 0) {
            return 0.0;
        }

        $weighted = ($this->human_count * 3) + ($this->validated_count * 2) + ($this->ai_count * 1);
        return $weighted / $effective;
    }

    /**
     * Get fork bonus multiplier.
     * Active forks of abandoned translations get a +20% boost.
     *
     * Conditions for bonus:
     * - This translation has a parent (is a fork)
     * - Parent hasn't been updated in 180+ days (abandoned)
     * - This translation was updated within 30 days (active)
     */
    public function getForkBonusAttribute(): float
    {
        if (!$this->parent_id) {
            return 1.0;
        }

        $parent = $this->parent;
        if (!$parent) {
            return 1.0;
        }

        $parentInactive = $parent->updated_at->diffInDays(now()) > 180;
        $selfActive = $this->updated_at->diffInDays(now()) < 30;

        if ($parentInactive && $selfActive) {
            return 1.2; // +20% bonus
        }

        return 1.0;
    }

    /**
     * Get full ranking score for sorting translations.
     * Combines quality, freshness, and engagement metrics.
     *
     * Formula: (quality_score * 10 + engagement) * freshness * fork_bonus
     *
     * Components:
     * - quality_score: 0-3 based on H/V/A distribution
     * - engagement: vote_count + log(download_count + 1)
     * - freshness: 1.0 for recent, decays over time (90 day half-life)
     * - fork_bonus: 1.2 for active forks of abandoned translations
     */
    public function getRankingScoreAttribute(): float
    {
        // Base quality (0-30 range)
        $quality = $this->quality_score * 10;

        // Engagement: votes + logarithmic downloads
        $engagement = $this->vote_count + log10($this->download_count + 1);

        // Freshness decay (90-day half-life)
        $daysSinceUpdate = $this->updated_at->diffInDays(now());
        $freshness = pow(0.5, $daysSinceUpdate / 90);

        // Fork bonus
        $bonus = $this->fork_bonus;

        return ($quality + $engagement) * $freshness * $bonus;
    }

    /**
     * Check if this branch was modified since the Main owner reviewed it.
     */
    public function wasModifiedSinceReview(): bool
    {
        if (!$this->reviewed_hash || !$this->file_hash) {
            return false;
        }

        return $this->file_hash !== $this->reviewed_hash;
    }
}
