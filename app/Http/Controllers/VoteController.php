<?php

namespace App\Http\Controllers;

use App\Models\Translation;
use Illuminate\Http\Request;

class VoteController extends Controller
{
    public function vote(Request $request, Translation $translation)
    {
        // Public translations only, and never your own — see Translation::canBeVotedBy()
        if (!$translation->canBeVotedBy($request->user())) {
            abort(403, 'You cannot vote on this translation');
        }

        $request->validate([
            'value' => 'required|in:1,-1',
        ]);

        $translation->vote((int) $request->value);

        return response()->json([
            'vote_count' => $translation->fresh()->vote_count,
            'user_vote' => $translation->userVote()?->value,
        ]);
    }
}
