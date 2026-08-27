<?php

namespace App\Http\Controllers;

use App\Models\ApiToken;
use App\Models\AuditLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * What holds an access to this account, and how to cut it.
 *
 * The gap this fills: `DELETE /api/v1/auth/token` only ever revoked the token presented in the
 * Bearer header, so the one credential somebody would actually want to cut — a stolen one — was
 * the one they could not reach.
 *
 * 🔴 Every lookup goes through `auth()->user()->apiTokens()`, never route-model binding on the id.
 * Anything else is an IDOR: the ids are sequential. And a miss is a 404, never a 403 — a 403 would
 * confirm that the row exists and belongs to somebody.
 */
class ConnectionsController extends Controller
{
    /**
     * Lines this account holds, grouped by the name their owner gave the machine.
     */
    public function index(Request $request)
    {
        $user = $request->user();

        $tokens = $user->apiTokens()
            ->orderByDesc('last_used_at')
            ->orderByDesc('created_at')
            ->get();

        // Grouped by the machine, which two things can say — see ApiToken::machineKey.
        //
        // 🔴 It used to be the typed name alone, and in production that meant no grouping at all:
        // thirty-six accesses on one account, thirty-five of them in a single "device not named"
        // heap, because nobody types a name when linking. The page listed everything and helped
        // with nothing. A machine that says who it is groups its own games without anybody typing.
        //
        // ⚠ Sorted by what is DISPLAYED, not by the key: the keys are opaque now, and ordering by
        // them would shuffle the page each time an access is added.
        $groups = $tokens
            ->groupBy(fn (ApiToken $token) => $token->groupKey())
            ->sortBy(fn ($group) => [
                // Named machines first, unnamed ones last — the named ones are the ones somebody
                // can act on with confidence.
                $group->first()->device_label === null ? 1 : 0,
                $group->first()->device_label ?? '',
            ]);

        return view('profile.connections', [
            'groups' => $groups,
            'total' => $tokens->count(),
            'otherBrowsers' => $this->otherBrowserCount($request),

            // The groups that already exist, offered to move a line into. Sorted, because a list
            // somebody scans should not change order every time an access speaks.
            'groupNames' => $tokens->pluck('device_label')->filter()->unique()->sort()->values(),
        ]);
    }

    /**
     * Rename a whole group.
     *
     * 🔴 It used to rename ONE line, which was right while a typed name was the only thing grouping
     * anything, and became wrong the moment a machine could say so itself: naming a PC would have
     * meant typing the same words into fifteen games one after the other.
     *
     * ⚠ **It acts on the group as displayed, not on the machine behind it** — see `sameGroupAs`. A
     * line filed somewhere else has left this group, so nothing here can reach it: "a destination
     * somebody chose is not touched" needs no special case, it is what following the group means.
     */
    public function update(Request $request, string $token)
    {
        $validated = $request->validate([
            // An opaque note for its owner: the server stores it and never reads anything into it.
            'device_label' => ['nullable', 'string', 'max:60'],
        ]);

        $apiToken = $request->user()->apiTokens()->findOrFail($token);

        $label = trim((string) ($validated['device_label'] ?? ''));

        $renamed = $request->user()->apiTokens()
            ->sameGroupAs($apiToken)
            ->update(['device_label' => $label === '' ? null : $label]);

        return redirect()->route('profile.connections')
            ->with('success', trans_choice('connections.renamed_many', $renamed, ['count' => $renamed]));
    }

    /**
     * Move ONE line into another group, existing or new.
     *
     * 🔴 A group is not a machine. We arrange by machine because that is what we can know without
     * asking, and it is a starting point: somebody may just as well file their accesses by language,
     * by kind of game, or by anything else that means something to them. So a line can be picked up
     * and put elsewhere, and what we knew about the machine stays underneath, untouched.
     *
     * ⚠ Deliberately ONE line, where rename takes the group. They are different acts: naming says
     * "this pile is called that", moving says "this one belongs over there". One control each, and
     * neither can be mistaken for the other.
     *
     * ⚠ Emptying the field puts a line back under the default arrangement — its machine — rather
     * than into a group called "". `device_label = null` is exactly "not filed by hand", which is
     * what the grouping already reads.
     */
    public function move(Request $request, string $token)
    {
        $validated = $request->validate([
            'device_label' => ['nullable', 'string', 'max:60'],
            'new_group' => ['nullable', 'string', 'max:60'],
        ]);

        $apiToken = $request->user()->apiTokens()->findOrFail($token);

        // ⚠ Typing wins over picking. Somebody who has written a name has said what they want more
        // recently than the list they left alone, and a form that ignored it would look broken.
        $typed = trim((string) ($validated['new_group'] ?? ''));
        $label = $typed !== '' ? $typed : trim((string) ($validated['device_label'] ?? ''));

        $apiToken->update(['device_label' => $label === '' ? null : $label]);

        return redirect()->route('profile.connections')
            ->with('success', __('connections.moved'));
    }

    /**
     * Cut one line.
     */
    public function destroy(Request $request, string $token)
    {
        $apiToken = $request->user()->apiTokens()->findOrFail($token);

        $apiToken->delete();
        AuditLog::logTokenRevoked($request->user()->id, $request);

        return redirect()->route('profile.connections')
            ->with('success', __('connections.revoked_one'));
    }

    /**
     * Cut a whole device, or everything.
     *
     * ⚠ One entry point for both, because they are the same act with a different scope, and a
     * second door would be a second place to forget a condition. Which one is decided by what the
     * form carries, and "everything" is the absence of a scope rather than a special value — so a
     * request that loses its field cuts more than asked rather than less, and the confirmation
     * says which is about to happen.
     */
    public function destroyMany(Request $request)
    {
        $validated = $request->validate([
            'scope' => ['required', 'in:device,all'],
            // ⚠ One of the group's own lines, not the typed name. Since a machine can group without
            // anybody typing, two groups can share the same (absent) name — cutting by name would
            // then cut a machine somebody was not looking at. The id is already scoped to the
            // account below, so it says exactly one group and cannot say another account's.
            'token' => ['required_if:scope,device', 'integer'],
        ]);

        $query = $request->user()->apiTokens();

        if ($validated['scope'] === 'device') {
            $one = $request->user()->apiTokens()->findOrFail($validated['token'] ?? '');

            $query->sameGroupAs($one);
        }

        $cut = $query->delete();

        if ($cut > 0) {
            AuditLog::logTokenRevoked($request->user()->id, $request);
        }

        return redirect()->route('profile.connections')
            ->with('success', trans_choice('connections.revoked_many', $cut, ['count' => $cut]));
    }

    /**
     * Sign every other browser out of this account.
     *
     * The case it answers: a session left open on a shared machine. Whoever sits down next can
     * link any game of theirs to this account, and the token they get outlives both the session and
     * the browser — so closing the session is half the remedy, and the page says the other half.
     *
     * ⚠ `Auth::logoutOtherDevices()` cannot be used: it works by replaying the account's password
     * hash into the session, and accounts that signed in through a platform have no password at
     * all. Deleting the rows works for every kind of account.
     */
    public function signOutOtherBrowsers(Request $request)
    {
        $user = $request->user();

        $deleted = DB::table('sessions')
            ->where('user_id', $user->id)
            ->where('id', '!=', $request->session()->getId())
            ->delete();

        // 🔴 Deleting the session rows is NOT signing a browser out. Both sign-in paths call
        // `Auth::login($user, remember: true)` unconditionally, so every browser also holds a
        // recaller cookie — and Laravel rebuilds the session from it on the very next request,
        // silently and with no sign-in screen. Reported from a real machine: the other browser was
        // back before anything had been typed, and this page counted it again.
        //
        // Rotating the token invalidates every recaller ever issued for this account, which is the
        // point: there is no way to invalidate one cookie and not the others.
        $hadRecaller = $request->cookies->has(Auth::guard()->getRecallerName());

        $user->setRememberToken(Str::random(60));
        $user->save();

        // ⚠ Including this browser's own, so it has to be handed a new one — otherwise closing the
        // window here signs this browser out too, which is not what the button says it does.
        if ($hadRecaller) {
            Auth::login($user, true);
        }

        return redirect()->route('profile.connections')
            ->with('success', trans_choice('connections.browsers_signed_out', $deleted, ['count' => $deleted]));
    }

    /**
     * How many other browsers hold a session — a count, and nothing else.
     *
     * 🔴 No address, no place, no agent string, no date. This page is readable by whoever is
     * already inside the account, so anything locating its owner turns it into a surveillance
     * tool aimed at them. A count is enough to decide, and tells a watcher nothing.
     */
    private function otherBrowserCount(Request $request): int
    {
        return DB::table('sessions')
            ->where('user_id', $request->user()->id)
            ->where('id', '!=', $request->session()->getId())
            ->count();
    }
}
