<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

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
    ];

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
