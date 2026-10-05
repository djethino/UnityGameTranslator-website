<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Report extends Model
{
    protected $fillable = [
        'translation_id',
        'game_id',
        'kind',
        'reporter_id',
        'reason',
        'stores_answer',
        'status',
        'reviewed_by',
        'admin_notes',
        'reviewed_at',
    ];

    protected function casts(): array
    {
        return [
            'reviewed_at' => 'datetime',
        ];
    }

    /**
     * What can be wrong with a game card (2026-10-05). The first two are about the adult mark, and
     * the stores are asked about them at once (ReportController::storeGame); the others are read
     * by an admin.
     */
    public const GameKinds = ['adult', 'not_adult', 'wrong_info', 'other'];

    /** What an admin reads for each kind — the admin screens are English. */
    public const GameKindLabels = [
        'adult' => 'Adult content, not marked',
        'not_adult' => 'Marked for adults by mistake',
        'wrong_info' => 'Wrong name, cover or store link',
        'other' => 'Something else',
    ];

    /** What the stores said when the report was sent (`stores_answer`), as an admin reads it. */
    public const StoresAnswerLabels = [
        'adult' => 'the stores mark it for adults only',
        'nothing' => 'the stores found nothing',
        'not_asked' => 'Steam could not be asked',
    ];

    public function translation()
    {
        return $this->belongsTo(Translation::class);
    }

    public function game()
    {
        return $this->belongsTo(Game::class);
    }

    /** A report about a game card rather than a translation. */
    public function isAboutGame(): bool
    {
        return $this->game_id !== null;
    }

    public function reporter()
    {
        return $this->belongsTo(User::class, 'reporter_id');
    }

    public function reviewer()
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function isPending(): bool
    {
        return $this->status === 'pending';
    }

    public function markAsReviewed(User $admin, string $status, ?string $notes = null): void
    {
        $this->update([
            'status' => $status,
            'reviewed_by' => $admin->id,
            'admin_notes' => $notes,
            'reviewed_at' => now(),
        ]);
    }
}
