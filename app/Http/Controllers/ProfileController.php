<?php

namespace App\Http\Controllers;

use App\Models\AccountDeletion;
use App\Models\DeviceCode;
use App\Models\MergePreviewToken;
use App\Models\RecoveryCode;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class ProfileController extends Controller
{
    /**
     * One-shot username prompt (shown once to existing users after the
     * rename feature ships): mark seen, optionally jump to the profile.
     */
    public function usernamePromptSeen(Request $request)
    {
        $request->user()->forceFill(['username_prompt_seen_at' => now()])->save();

        if ($request->input('action') === 'change') {
            return redirect()->route('profile.edit');
        }

        return back();
    }

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
            // ⚠ Validated against the CATALOGUE, not against the interface locales: the
            // two lists overlap without matching, and ninety languages can be played in
            // while twenty can be read in.
            'game_language' => 'nullable|string|in:'
                . implode(',', array_keys(\App\Services\CatalogStore::languageChoices())),
        ], [
            'name.regex' => 'Username can only contain letters, numbers, underscores and hyphens.',
        ]);

        $user = auth()->user();

        // Display-name change: 30-day cooldown (anti-impersonation; the ASCII charset above blocks
        // homoglyphs).
        //
        // ⚠ **A history of past display names used to be written here and is not any more.** Its
        // stated purpose was answering "who has borne this name before" — a search BY NAME, which
        // an account id cannot answer, so the table was not redundant. But nothing ever read it:
        // no admin screen, no query, in four months. A log nobody exploits has no purpose, and a
        // purpose is what a lawful basis is made of.
        //
        // 🔴 And it held exactly what this feature exists to hide: the prompt offering the rename
        // says OAuth names sometimes expose real ones, so the name somebody removes in order to
        // protect themselves was the name we filed away for ever. Not collecting it is the only
        // version of that promise that holds.
        if ($request->name !== $user->name) {
            // 🔴 **Free before allowed.** The delay is about this account; availability is about the
            // name, and only one of the two can be worked around by waiting. Telling somebody to
            // come back in three weeks for a name that will still be taken is sending them away
            // twice.
            if (User::displayNameTaken($request->name, $user->id)) {
                $free = User::suggestDisplayNames($request->name);

                return back()->withErrors([
                    // ⚠ The way out comes with the refusal. A name is refused perhaps once in
                    // somebody's life here, and being told only "no" is where people stop.
                    'name' => $free === []
                        ? __('profile.name_taken')
                        : __('profile.name_taken_try', ['names' => implode(', ', $free)]),
                ]);
            }

            if ($user->name_changed_at && $user->name_changed_at->addDays(30)->isFuture()) {
                return back()->withErrors([
                    'name' => __('profile.name_cooldown', [
                        'date' => $user->name_changed_at->addDays(30)->toDateString(),
                    ]),
                ]);
            }

            $user->forceFill(['name_changed_at' => now()])->save();
        }

        $user->update([
            'name' => $request->name,
            'locale' => $request->locale,
        ]);

        // ⚠ Through the service rather than in the update above, although it is a column on this
        // very model: the title-bar selector falls back to the session, so an account writing the
        // column alone would leave the two disagreeing. Clearing the preference here — empty means
        // "follow the browser", a real answer and the default — would then bring back a choice made
        // before signing in, as if nothing had been cleared. One writer, both places.
        \App\Services\GameLanguage::remember($request->game_language ?: null);

        // Update session locale immediately
        if ($request->locale) {
            session(['locale' => $request->locale]);
            app()->setLocale($request->locale);
        }

        return redirect()->route('profile.edit')
            ->with('success', __('profile.saved'));
    }

    /**
     * Generated avatar: reroll the seed, or clear it to return to the
     * platform avatar (OAuth accounts only).
     */
    public function avatarReroll(Request $request)
    {
        $user = $request->user();

        if ($request->input('action') === 'platform' && $user->avatar) {
            $user->forceFill(['avatar_seed' => null])->save();
        } else {
            $user->forceFill(['avatar_seed' => \Illuminate\Support\Str::random(20)])->save();
        }

        return back();
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

        return response()->streamDownload(function () use ($data) {
            echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        }, $filename, ['Content-Type' => 'application/json']);
    }

    /**
     * Erase the person, keep the work.
     *
     * 🔴 **The right to erasure is not the right to unpublish.** What is personal is the LINK
     * between an author and a translation, not the translation — which other people have forked,
     * branched and are using. So the account is anonymised, the contributions stay, and every way
     * back in is cut.
     *
     * ⚠ Anonymising IS erasing, in law as in fact — but only if it is irreversible. That is why
     * every identifying column goes in the same pass: leaving one behind turns the whole thing into
     * a pseudonym, and a pseudonym is still personal data.
     *
     * ### What is kept, and it is a rule rather than a case
     *
     * 🔴 **Anything feeding a counter, a ranking or a record that belongs to somebody ELSE is kept
     * and anonymised. Never deleted.** Votes used to be deleted here, which quietly lowered the
     * score of translations belonging to other people; reports too, erasing the trail of a
     * moderation decision that also involved the admin who made it. Attached to an anonymised
     * account they identify nobody, so keeping them costs nothing and deleting them cost others.
     *
     * ⚠ Nothing is done to the votes at all now: the row already points at this account, and the
     * account is what has just been emptied. The unique index (translation_id, user_id) also means
     * a null would be the one thing that could collide.
     */
    public function destroy(Request $request)
    {
        $user = auth()->user();

        // Verify confirmation
        if ($request->confirm_name !== $user->name) {
            return back()->withErrors(['confirm_name' => __('profile.delete_name_mismatch')]);
        }

        DB::transaction(function () use ($user) {
            // 🔴 ban() rather than writing banned_at by hand, and this is the whole bug it fixes.
            // The flag was set here directly, so the account looked banned and KEPT EVERY API
            // TOKEN: the mod went on publishing under a deleted account, since AuthenticateApi
            // never looks at banned_at. ban() cuts them, says why in its own comment, and is where
            // the next guard will be added — which a copy of its effects would miss again.
            $user->ban('Account deleted by user');

            // The other ways in. Each is an access or a message: nothing here is anybody else's.
            RecoveryCode::where('user_id', $user->id)->delete();
            DeviceCode::where('user_id', $user->id)->delete();
            MergePreviewToken::where('user_id', $user->id)->delete();
            DB::table('sessions')->where('user_id', $user->id)->delete();
            DB::table('notifications')
                ->where('notifiable_type', User::class)
                ->where('notifiable_id', $user->id)
                ->delete();

            // Every identifying column, in one pass.
            //
            // ⚠ username to null rather than to "deleted-42": null frees the name for somebody
            // else, which is right once its holder is gone, and it says nothing — where a
            // "deleted-" prefix would announce that this account was deleted, on a column that is
            // unique and therefore probeable from outside.
            //
            // ⚠ forceFill: these are not fillable, and they must not be.
            $user->forceFill([
                // Unique and random — see User::deletedAccountName for why it is neither the same
                // literal for everybody nor the account id.
                'name' => User::deletedAccountName(),
                'account_deleted_at' => now(),
                'username' => null,
                'email' => 'deleted-' . $user->id . '@deleted.local',
                'password' => null,
                'provider' => null,
                'provider_id' => null,
                'avatar' => null,
                'avatar_seed' => null,
                'locale' => null,
                'name_changed_at' => null,
                'username_prompt_seen_at' => null,
                'email_verified_at' => null,
                'remember_token' => null,
            ])->save();

            // So that a backup restored from before today can be told this id must stay erased.
            // Read AccountDeletion's migration: it only works if a restore lands BESIDE production.
            AccountDeletion::note($user->id);
        });

        // Logout
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect('/')->with('success', __('profile.account_deleted'));
    }
}
