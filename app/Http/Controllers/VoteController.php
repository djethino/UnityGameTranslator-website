<?php

namespace App\Http\Controllers;

use App\Models\Translation;
use Illuminate\Http\Request;

class VoteController extends Controller
{
    public function vote(Request $request, Translation $translation)
    {
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
