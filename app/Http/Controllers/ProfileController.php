<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class ProfileController extends Controller
{
    public function edit()
    {
        return view('profile.edit', [
            'user' => auth()->user(),
        ]);
    }

    public function update(Request $request)
    {
        $supportedLocales = array_keys(config('locales.supported', []));

        $request->validate([
            'name' => 'required|string|min:2|max:50|regex:/^[a-zA-Z0-9_\-]+$/',
            'locale' => 'nullable|string|in:' . implode(',', $supportedLocales),
        ], [
            'name.regex' => 'Username can only contain letters, numbers, underscores and hyphens.',
        ]);

        $user = auth()->user();
        $user->update([
            'name' => $request->name,
            'locale' => $request->locale,
        ]);

        // Update session locale immediately
        if ($request->locale) {
            session(['locale' => $request->locale]);
            app()->setLocale($request->locale);
        }

        return redirect()->route('profile.edit')
            ->with('success', __('profile.saved'));
    }

    /**
     * Export user data as JSON (GDPR)
     */
    public function export()
    {
        $user = auth()->user();

        $data = [
            'account' => [
                'name' => $user->name,
                'email' => $user->email,
                'provider' => $user->provider,
                'created_at' => $user->created_at->toIso8601String(),
                'locale' => $user->locale,
            ],
            'translations' => $user->translations()->with('game')->get()->map(function ($t) {
                return [
                    'id' => $t->id,
                    'game' => $t->game->name ?? null,
                    'source_language' => $t->source_language,
                    'target_language' => $t->target_language,
                    'type' => $t->type,
                    'line_count' => $t->line_count,
                    'download_count' => $t->download_count,
                    'vote_count' => $t->vote_count,
                    'notes' => $t->notes,
                    'created_at' => $t->created_at->toIso8601String(),
                    'updated_at' => $t->updated_at->toIso8601String(),
                ];
            }),
            'votes' => $user->votes()->with('translation.game')->get()->map(function ($v) {
                return [
                    'translation_game' => $v->translation->game->name ?? null,
                    'value' => $v->value,
                    'created_at' => $v->created_at->toIso8601String(),
                ];
            }),
            'reports' => $user->reports()->get()->map(function ($r) {
                return [
                    'reason' => $r->reason,
                    'status' => $r->status,
                    'created_at' => $r->created_at->toIso8601String(),
                ];
            }),
            'exported_at' => now()->toIso8601String(),
        ];

        $filename = 'unitygametranslator-data-' . $user->id . '-' . now()->format('Y-m-d') . '.json';

        return response()->json($data, 200, [
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    }

    /**
     * Delete/anonymize user account (GDPR)
     */
    public function destroy(Request $request)
    {
        $user = auth()->user();

        // Verify confirmation
        if ($request->confirm_name !== $user->name) {
            return back()->withErrors(['confirm_name' => __('profile.delete_name_mismatch')]);
        }

        // Anonymize user data (keep translations)
        $user->update([
            'name' => '[Deleted]',
            'email' => 'deleted-' . $user->id . '@deleted.local',
            'avatar' => null,
            'provider_id' => 'deleted-' . $user->id,
            'banned_at' => now(), // Prevent re-login with same OAuth
            'ban_reason' => 'Account deleted by user',
        ]);

        // Delete votes and reports (personal actions)
        $user->votes()->delete();
        $user->reports()->delete();

        // Logout
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect('/')->with('success', __('profile.account_deleted'));
    }
}
