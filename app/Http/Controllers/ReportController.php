<?php

namespace App\Http\Controllers;

use App\Models\Report;
use App\Models\Translation;
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
            return back()->with('error', 'You have already reported this translation.');
        }

        Report::create([
            'translation_id' => $translation->id,
            'reporter_id' => auth()->id(),
            'reason' => $request->reason,
        ]);

        return back()->with('success', 'Report submitted. An admin will review it.');
    }
}
