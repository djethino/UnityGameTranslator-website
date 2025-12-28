<?php

namespace App\Models;

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
        'human_count',
        'validated_count',
        'ai_count',
        'status',
        'visibility',
        'type',
        'notes',
        'file_path',
        'file_uuid',
        'file_hash',
    ];

    /**
     * Get the safe, validated file path.
     * Prevents path traversal attacks by ensuring the path stays within storage.
     */
    public function getSafeFilePath(): ?string
    {
        if (empty($this->file_path)) {
            return null;
        }

        // Files are stored in the public disk (storage/app/public/)
        $basePath = storage_path('app/public');
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
                $hashData[$key] = $value;
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
     * Get the current user's vote for this translation
     */
    public function userVote()
    {
        if (!auth()->check()) {
            return null;
        }
        return $this->votes()->where('user_id', auth()->id())->first();
    }

    /**
     * Vote on this translation
     */
    public function vote(int $value): void
    {
        $existingVote = $this->userVote();

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
                'user_id' => auth()->id(),
                'value' => $value,
            ]);
            $this->increment('vote_count', $value);
        }
    }

    public function isComplete(): bool
    {
        return $this->status === 'complete';
    }

    public function isFork(): bool
    {
        return $this->parent_id !== null;
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
    // Tag Extraction (HCA System)
    // =========================================

    /**
     * Extract HCA tag counts from JSON content.
     * Supports both old format (string values) and new format (object with v/t).
     *
     * @param array $json Parsed translation JSON
     * @return array ['human_count' => int, 'validated_count' => int, 'ai_count' => int]
     */
    public static function extractTagCounts(array $json): array
    {
        $human = 0;
        $validated = 0;
        $ai = 0;

        foreach ($json as $key => $value) {
            // Skip metadata keys
            if (str_starts_with($key, '_')) {
                continue;
            }

            // New format: {"v": "translation", "t": "A"}
            if (is_array($value) && isset($value['t'])) {
                match ($value['t']) {
                    'H' => $human++,
                    'V' => $validated++,
                    'A' => $ai++,
                    default => $ai++, // Fallback to AI
                };
            } else {
                // Old format (string value) = AI by default
                $ai++;
            }
        }

        return [
            'human_count' => $human,
            'validated_count' => $validated,
            'ai_count' => $ai,
        ];
    }
}
