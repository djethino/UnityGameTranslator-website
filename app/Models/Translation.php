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
        'skipped_count',
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
        'skipped_count' => 'integer',
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
    /**
     * May this user read the file at all?
     *
     * Public translations are readable by anyone — that is what the download endpoint already
     * grants without an account. A branch is private to the Main owner it was submitted to:
     * it is someone's work-in-progress contribution, not a published version.
     *
     * One definition, because the rule was written out at every point that needed it and a
     * fourth copy would be one more place to forget when it changes.
     */
    public function isReadableBy(?User $user): bool
    {
        if ($this->visibility !== 'branch') {
            return true;
        }

        $main = $this->getMain();

        return $user !== null && $main !== null && (int) $main->user_id === (int) $user->id;
    }

    public static function isDeliberateFontSetting(array $settings): bool
    {
        // Turned off, or given a fallback: unambiguously someone's decision
        if (($settings['enabled'] ?? true) === false || !empty($settings['fallback'])) {
            return true;
        }

        // Size is the subtle one. "scale" is the MATERIALIZED product — the automatic
        // design-scale times the deliberate percent — so a font the mod rescaled on its own
        // carries a scale != 1 that nobody chose. Only "size_percent" records the human choice.
        if (array_key_exists('size_percent', $settings)) {
            return abs(((float) $settings['size_percent']) - 1.0) > 0.001;
        }

        // Older files predate that split. Back then "scale" DID hold the deliberate percent —
        // but only when the automatic scaling was off, otherwise it is polluted by it and says
        // nothing about intent.
        if (!empty($settings['scale_auto'])) {
            return false;
        }

        return abs(((float) ($settings['scale'] ?? 1.0)) - 1.0) > 0.001;
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
     * May this user vote on this translation?
     *
     * Two refusals, and the second was missing entirely: nothing stopped an author from
     * upvoting their own work. It costs one request, it is invisible, and it feeds
     * ranking_score directly — in a catalogue whose highest score is a single vote, one
     * self-vote outranks every translation nobody thought to vote for.
     *
     * Branches are refused because they are private contributions, not published work.
     *
     * Lives here rather than in the two controllers that had a copy each: the mod and the
     * site must refuse the same things, and one of the two copies would drift.
     */
    public function canBeVotedBy(?\App\Models\User $user): bool
    {
        if (!$user || $this->visibility !== 'public') {
            return false;
        }

        return $this->user_id !== $user->id;
    }

    /**
     * Everything the mod needs to show a vote on this translation, and nothing it should have
     * to work out for itself.
     *
     * Two endpoints answer "what does the site know about this uuid" — sync/state, which feeds
     * the mod's current-translation card, and check-uuid, which the upload flow asks. They must
     * not describe a vote differently, so the block is built here once.
     */
    public function voteStateFor(?\App\Models\User $user): array
    {
        return [
            'target_id' => $this->id,
            'count' => $this->vote_count,
            // Their own vote, so the arrow is already coloured when the panel opens rather
            // than only after they vote a second time
            'user_vote' => $user ? $this->userVoteFor($user)?->value : null,
            'can_vote' => $this->canBeVotedBy($user),
        ];
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
     * S entries (marked as not to translate) are counted on their own: they belong to
     * neither the composition bar nor the score, but they say something about the care
     * put into the file. M entries (mod UI) are technical noise and counted nowhere.
     *
     * @param array $json Parsed translation JSON
     * @return array ['human_count' => int, 'validated_count' => int, 'ai_count' => int, 'capture_count' => int, 'skipped_count' => int]
     */
    public static function extractTagCounts(array $json): array
    {
        $human = 0;
        $validated = 0;
        $ai = 0;
        $capture = 0;
        $skipped = 0;

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
                        'S' => $skipped++,  // reported on its own, never in the bar or the score
                        'M' => null,        // mod UI: technical noise, counted nowhere
                        default => $ai++,   // Fallback to AI
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
            'skipped_count' => $skipped,
        ];
    }

    // =========================================
    // Computed Attributes for Scoring
    // =========================================

    /**
     * Lines whose final state a human settled: written (H), accepted (V), or deliberately kept
     * as they were (S).
     *
     * S belongs here. In a Star Trek game translated to Japanese, the Klingon has to stay
     * Klingon, and the author who took the trouble to rule on those lines did better work than
     * the one who let the machine run over everything. Nothing else in the file records that
     * effort — and since the mod stopped writing S for texts it simply could not handle, the
     * tag has no non-human origin left in a default install.
     */
    public function getReviewedLinesAttribute(): int
    {
        return $this->human_count + $this->validated_count + $this->skipped_count;
    }

    /**
     * Every line that has a settled state, human or machine. The denominator of both rates
     * below. Captured-but-untouched lines stay out: the bar already shows them in grey, and
     * folding them in here would turn a quality measure into a progress measure.
     */
    public function getResolvedLinesAttribute(): int
    {
        return $this->reviewed_lines + $this->ai_count;
    }

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
     *
     * @deprecated Superseded by reviewRate(). Kept only until the API stops reporting it —
     *             see the docs section on how the three algorithms work.
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
     * How much of the file a human settled, plainly: reviewed / resolved.
     *
     * No weighting here on purpose — this is the number the STAGE is read from, and the stage is
     * shown to everyone. It answers one question and states a fact.
     *
     * Null when nothing is translated: a capture-only file has no coverage, not a coverage of
     * zero. A file made ENTIRELY of kept-as-is lines is the same case — every line was settled,
     * but there is no translation to have an opinion about.
     */
    public function reviewCoverage(): ?float
    {
        if ($this->effective_lines === 0) {
            return null;
        }

        return $this->reviewed_lines / $this->resolved_lines;
    }

    /**
     * Largest resolved-line count among this game's translations, when the caller already knows
     * it. Set it before reading ranking_score on a page that lists several translations — the
     * accessor takes no arguments, and without this each card asks the database its own MAX.
     */
    public ?int $gameMaxHint = null;

    /** A validation counts at least this much, even with nothing to corroborate it. */
    private const VALIDATION_FLOOR = 0.8;

    /** One intervention in ten is enough to credit validations in full. */
    private const INTERVENTION_TARGET = 0.10;

    /**
     * The same coverage, weighted by how well evidenced the validations are.
     *
     * Accepting the machine's output IS review, and a small game where the AI got everything
     * right is a real case — nobody should have to retype correct text to score well. But
     * bulk-validating a file without reading it produces exactly the same tags as reading it
     * carefully, and something has to separate them.
     *
     * What separates them is the rest of the file: someone who genuinely read two thousand AI
     * lines changed some of them. So validations count in full as soon as the file shows one
     * intervention in ten, and never fall below VALIDATION_FLOOR otherwise. The floor is
     * deliberately high: the formula is public and stays generous, and deliberate gaming — one
     * token edit per ten lines buys full credit — is a matter for the admin anomaly detector,
     * not for a number shown to people.
     *
     * Owner-facing and feeds the ranking; the public sees the stage, not this.
     */
    public function reviewRate(): ?float
    {
        if ($this->effective_lines === 0) {
            return null;
        }

        $reviewed = $this->reviewed_lines;
        $interventions = $this->human_count + $this->skipped_count;

        $factor = self::VALIDATION_FLOOR;
        if ($reviewed > 0) {
            $rate = $interventions / $reviewed;
            $factor += (1 - self::VALIDATION_FLOOR)
                * min(1.0, $rate / self::INTERVENTION_TARGET);
        }

        $credited = $interventions + ($factor * $this->validated_count);

        return $credited / $this->resolved_lines;
    }

    /**
     * How much of the game this translation actually reaches, from 0 to 1.
     *
     * A game's real line count is unknowable — text is captured as it is met, and nobody walks
     * every branch. But the OTHER translations of the same game know something about it: whatever
     * the target language, the same keys turn up as players get further in. The largest of them
     * is therefore an honest lower bound on the game's size, and it corrects itself upwards as
     * people play further.
     *
     * Counts resolved lines, not the file's line count: five thousand captured-but-untranslated
     * lines would otherwise claim to cover the whole game while translating none of it.
     *
     * Pass $gameMax when several translations are being measured at once — the caller has them
     * all in hand, and asking the database once per card is how a game page ends up with fifty
     * queries. Null when nothing anywhere is translated yet.
     */
    public function gameCoverage(?int $gameMax = null): ?float
    {
        $mine = $this->resolved_lines;
        $max = $gameMax ?? $this->gameMaxHint ?? static::maxResolvedLinesForGame($this->game_id);

        if ($max <= 0) {
            return null;
        }

        return min(1.0, $mine / $max);
    }

    /**
     * The largest resolved-line count among a game's PUBLISHED translations.
     *
     * Public only: a branch is someone's unfinished contribution, and letting it set the bar
     * would measure everyone against work nobody has offered.
     */
    public static function maxResolvedLinesForGame(int $gameId): int
    {
        return (int) static::where('game_id', $gameId)
            ->where('visibility', 'public')
            ->selectRaw('MAX(human_count + validated_count + skipped_count + ai_count) as m')
            ->value('m');
    }

    /**
     * Same thing for a whole page of translations, in one query: game id => largest count.
     */
    public static function maxResolvedLinesByGame(iterable $gameIds): array
    {
        $ids = collect($gameIds)->filter()->unique()->values();
        if ($ids->isEmpty()) {
            return [];
        }

        return static::whereIn('game_id', $ids)
            ->where('visibility', 'public')
            ->groupBy('game_id')
            ->selectRaw('game_id, MAX(human_count + validated_count + skipped_count + ai_count) as m')
            ->pluck('m', 'game_id')
            ->map(fn ($m) => (int) $m)
            ->toArray();
    }

    /**
     * Where the file stands, as a STEP rather than a mark.
     *
     * Replaces the 0-3 score for display, and the reason is arithmetic rather than taste. That
     * score answers "where does each line come from", when what a downloader asks is "has anyone
     * read this". It also cannot be read: 100% of unreviewed AI scores 1.0 — a third of a scale
     * shown out of 3 — while a file reviewed line by line stops at 2.0 unless its author retyped
     * by hand what the AI had already got right. In practice everything lands between 1.0 and
     * 2.5, and three quarters of the real catalogue sit in a single band.
     *
     * Steps also carry no verdict. Every translation starts as raw machine output, since that is
     * how the mod works; naming that "Raw AI" on a scale ending at "Excellent" tells every
     * newcomer their starting point is worthless.
     *
     * Thresholds: nothing / started / well under way / finished. Deliberately coarse — the real
     * catalogue is far too small to justify finer ones, and 75-99% was empty.
     */
    public function reviewStage(): ?string
    {
        $coverage = $this->reviewCoverage();
        if ($coverage === null) {
            return null;
        }

        if ($coverage >= 1.0) {
            return 'reviewed';
        }
        if ($coverage >= 0.4) {
            return 'advanced';
        }
        if ($coverage > 0) {
            return 'started';
        }

        return 'machine';
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

        // contentChangedAt, not updated_at: a download or a vote touches updated_at, so the
        // parent looked ALIVE simply because people were still downloading it — and the bonus
        // was refused to the fork that had picked up an abandoned translation, which is the
        // one case it exists for.
        $parentInactive = $parent->contentChangedAt()->diffInDays(now()) > 180;
        $selfActive = $this->contentChangedAt()->diffInDays(now()) < 30;

        if ($parentInactive && $selfActive) {
            return 1.2; // +20% bonus
        }

        return 1.0;
    }

    /**
     * What "just being there" is worth against "having been reviewed", from 0 to 1.
     *
     * An editorial choice, not a technical one, which is why it is named and published rather
     * than buried in an expression. At 0.5, a complete but unreviewed translation is worth half
     * of a complete and reviewed one — and still ranks above a beautifully reviewed file that
     * only covers a fifth of the game. Someone about to play the whole thing needs the text to
     * exist first.
     */
    public const COVERAGE_BASE = 0.5;

    /**
     * How useful this translation is to someone about to play THIS game, from 0 to 1.
     *
     * Coverage sets the ceiling, review lifts within it. Deliberately not a product of the two:
     * that would send a complete but unreviewed translation to zero, when raw machine output
     * over a whole game is precisely what the mod exists to produce and is genuinely usable.
     *
     * This is the answer to "which one do I take", and it is the only place the two rates are
     * combined. The stage answers "has anyone read it", the review rate answers "how well" —
     * neither of them knows how much of the game is in the file.
     */
    public function usefulness(?int $gameMax = null): float
    {
        $coverage = $this->gameCoverage($gameMax) ?? 0.0;
        $rate = $this->reviewRate() ?? 0.0;

        return $coverage * (self::COVERAGE_BASE + (1 - self::COVERAGE_BASE) * $rate);
    }

    /**
     * Get full ranking score for sorting translations.
     *
     * Formula: (usefulness * 30 + engagement) * freshness * fork_bonus
     *
     * Components:
     * - usefulness: how much of the game is covered, lifted by how much of it was reviewed
     * - engagement: vote_count + log(download_count + 1)
     * - freshness: 1.0 for recent, decays over time (90 day half-life)
     * - fork_bonus: 1.2 for active forks of abandoned translations
     *
     * The quality term used to be quality_score * 10, a 0-3 average of where each line came
     * from. It could not see how much of the game a file reached: two hundred lines reviewed to
     * the last comma outranked four thousand lines at sixty per cent, though the second is what
     * someone playing the game actually needs.
     */
    public function getRankingScoreAttribute(): float
    {
        // Usefulness on the same 0-30 range the old quality term occupied, so the engagement
        // and freshness terms keep the weight they were tuned against.
        $quality = $this->usefulness() * 30;

        // Engagement: votes + logarithmic downloads
        $engagement = $this->vote_count + log10($this->download_count + 1);

        // Freshness decay (90-day half-life), on the date the TRANSLATION changed, and ONLY for
        // work still declared in progress.
        //
        // "Finished" and "abandoned" have the same signature in time — nothing moves in either —
        // so a decay applied to both drove a finished translation to 6% of its score within a
        // year and out of sight, however good it was. The author's own declared status is what
        // separates them, and it is the only thing that can.
        //
        // A finished translation is not left unranked for it: its rank follows its coverage, and
        // that falls when another translation of the same game goes further. Which is a better
        // measure of going stale than the calendar, because it is driven by text somebody
        // actually met rather than by time passing over a game that may never have changed.
        //
        // The date read is contentChangedAt, never updated_at: increment('vote_count') and
        // incrementDownloads() both move updated_at, so a downvote used to reset the decay to
        // 1.0 for the price of one engagement point — on a year-old abandoned file that raised
        // the score roughly sevenfold. Downvoting an abandoned upload promoted it.
        $freshness = 1.0;
        if (!$this->isComplete()) {
            $daysSinceUpdate = $this->contentChangedAt()->diffInDays(now());
            $freshness = pow(0.5, $daysSinceUpdate / 90);
        }

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
