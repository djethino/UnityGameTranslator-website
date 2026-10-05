<?php

namespace App\Http\Controllers;

use App\Models\Game;
use App\Models\Report;
use App\Models\Translation;
use App\Services\AdultRating;
use Illuminate\Http\Request;

class ReportController extends Controller
{
    public function store(Request $request, Translation $translation)
    {
        $request->validate([
            'reason' => 'required|string|max:1000',
        ]);

        // Check if already reported by this user
        $existing = Report::where('translation_id', $translation->id)
            ->where('reporter_id', auth()->id())
            ->first();

        if ($existing) {
            return back()->with('error', __('report.already'));
        }

        Report::create([
            'translation_id' => $translation->id,
            'reporter_id' => auth()->id(),
            'reason' => $request->reason,
        ]);

        return back()->with('success', __('report.success'));
    }

    /**
     * Report a game card (2026-10-05).
     *
     * 🔴 **An adult-content report is settled by the stores when they can settle it.** Steam and
     * IGDB are asked at once (AdultRating — the same question the hourly pass asks). When their
     * answer now agrees with the report, the mark is theirs, already applied, and the report closes
     * on its own with what they said: the admin is not asked to confirm what a store states (user:
     * "traité automatiquement … sans embêter l'admin"). When they do not agree, or Steam cannot be
     * asked, the report waits for an admin WITH their answer beside it.
     *
     * ⚠ The stores can raise the mark and lower their own detection — never an admin's word or a
     * contributor's declaration (Game::refreshAdult). "Marked by mistake" on a game an admin or a
     * contributor marked therefore always reaches an admin.
     */
    public function storeGame(Request $request, Game $game, AdultRating $rating)
    {
        $request->validate([
            'kind' => ['required', 'in:' . implode(',', Report::GameKinds)],
            // Details are asked for when only a person can read the report; an adult-content one
            // is answered by the stores first.
            'reason' => [in_array($request->input('kind'), ['adult', 'not_adult'], true) ? 'nullable' : 'required', 'string', 'max:1000'],
        ]);

        $waiting = Report::where('game_id', $game->id)
            ->where('reporter_id', auth()->id())
            ->where('status', 'pending')
            ->exists();
        if ($waiting) {
            return back()->with('error', __('report.game_already'));
        }

        $kind = $request->input('kind');
        $storesAnswer = null;
        $settled = false;

        if (in_array($kind, ['adult', 'not_adult'], true)) {
            $asked = $rating->rate($game, quiet: true) !== null;

            $storesAnswer = !$asked ? 'not_asked' : ($game->adult_detected ? 'adult' : 'nothing');
            $settled = $asked && ($kind === 'adult' ? $game->adult : !$game->adult);
        }

        Report::create([
            'game_id' => $game->id,
            'kind' => $kind,
            'reporter_id' => auth()->id(),
            'reason' => (string) $request->input('reason', ''),
            'stores_answer' => $storesAnswer,
            'status' => $settled ? 'reviewed' : 'pending',
            'admin_notes' => $settled ? 'Settled by the stores: ' . Report::StoresAnswerLabels[$storesAnswer] . '.' : null,
            'reviewed_at' => $settled ? now() : null,
        ]);

        return back()->with('success', match (true) {
            $settled && $kind === 'adult' => __('report.game_marked'),
            $settled => __('report.game_unmarked'),
            default => __('report.success'),
        });
    }
}
