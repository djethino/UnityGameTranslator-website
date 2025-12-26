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
        'status',
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

        // Parse JSON and sort keys for deterministic hash
        $data = json_decode($content, true);
        if (!is_array($data)) {
            return null;
        }

        ksort($data);
        $normalized = json_encode($data, JSON_UNESCAPED_UNICODE);

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

    public function getTypeLabel(): string
    {
        return self::TYPES[$this->type] ?? $this->type;
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
}
