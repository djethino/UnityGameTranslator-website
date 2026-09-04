<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ApiToken;
use App\Models\AuditLog;
use App\Models\DeviceCode;
use App\Services\SsePublisher;
use App\Support\ClientAgent;
use App\Support\GameDeclaration;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DeviceFlowController extends Controller
{
    /**
     * Initiate Device Flow authentication.
     * Returns a device_code and user_code for the mod to display.
     *
     * POST /api/v1/auth/device
     */
    public function initiate(Request $request): JsonResponse
    {
        // Optional, and it has to stay optional: every mod already installed calls this with an
        // empty body, and none of them will ever be updated. What a program does not declare is
        // simply not known about its line — never a refusal.
        $declared = $request->validate([
            'game_id' => ['nullable', 'string', 'regex:/^[0-9]{1,32}$/'],
            'game_name' => ['nullable', 'string', 'max:120'],
        ]);

        $deviceCode = DeviceCode::generate();

        // Which program is asking is known here and nowhere else: this is the only call the mod or
        // the Manager makes before anybody has signed in. Parsed on arrival — the agent string
        // itself is not kept.
        $client = ClientAgent::parse($request->userAgent());

        $deviceCode->forceFill([
            'client_kind' => $client['kind'] ?? null,
            'client_version' => $client['version'] ?? null,
            'client_variant' => $client['variant'] ?? null,
            'game_id' => $declared['game_id'] ?? null,
            'game_name' => $declared['game_name'] ?? null,

            // 🔴 Which machine is asking, so the cap can act without anybody typing a name.
            //
            // ⚠ Read from the header rather than the body, and that is not a shortcut: this route
            // is unauthenticated, so the middleware that reads it everywhere else does not run —
            // but the header is on the request all the same, and both programs already put it
            // there on every call they make.
            'device_id' => GameDeclaration::parseDevice(
                $request->header(GameDeclaration::DEVICE_HEADER)),
        ])->save();

        return response()->json([
            'device_code' => $deviceCode->device_code,
            'user_code' => $deviceCode->user_code,
            'verification_uri' => url('/link'),
            'expires_in' => 900, // 15 minutes
            'interval' => 5, // Poll every 5 seconds
        ]);
    }

    /**
     * Revoke an API token.
     * Only the token owner can revoke it (verified via the token itself).
     *
     * DELETE /api/v1/auth/token
     */
    public function revoke(Request $request): JsonResponse
    {
        // Get token from Bearer header
        $plainToken = $request->bearerToken();

        if (!$plainToken) {
            return response()->json(['error' => 'Token required'], 401);
        }

        // Hash the token before lookup (tokens are stored hashed)
        $hashedToken = ApiToken::hashToken($plainToken);
        $apiToken = ApiToken::where('token', $hashedToken)->first();

        if (!$apiToken) {
            return response()->json(['error' => 'Token not found'], 404);
        }

        $userId = $apiToken->user_id;
        $apiToken->delete();

        // Log token revocation
        AuditLog::logTokenRevoked($userId, $request);

        return response()->json(['message' => 'Token revoked']);
    }

    /**
     * Show the link page where users enter the code.
     */
    public function showLinkPage(Request $request)
    {
        // ⚠ Nothing to prepare for the form itself any more. This page used to offer the names
        // already in use, for a field that asked somebody to name their machine before linking it
        // — see the view for why that went away, and where naming lives now.

        // The code entered a moment ago, waiting to be confirmed — see validateCode for the two
        // steps. Cancelled, or expired while the person was reading, and the form comes back.
        if ($request->boolean('cancel')) {
            session()->forget('link.pending');
        }

        $pending = null;
        $expired = false;

        if (($userCode = session('link.pending')) !== null) {
            $pending = DeviceCode::findByUserCode($userCode);
            if ($pending === null) {
                session()->forget('link.pending');
                $expired = true;
            }
        }

        return view('auth.link', ['pending' => $pending, 'expired' => $expired]);
    }

    /**
     * Validate the user code entered on the website — in two steps.
     *
     * 🔴 **A valid code used to link on the spot, and the page never said what it was linking.**
     * That is the shape of the phishing every device flow has met since 2025: "enter ABCD-1234 on
     * the site to unlock X", and the person types it, and the access — a year-long token under
     * their name — goes to whoever's program displayed that code. The warning under the field
     * helps; seeing what one is about to sign is what stops it.
     *
     * So the first POST only looks the code up and shows what it stands for — which program, which
     * version, which game, how long ago — and the second POST, with `confirm`, is the one that
     * links. The code itself is what is confirmed, carried in the session between the two, never
     * anything the page could be made to pre-fill from a link.
     */
    public function validateCode(Request $request)
    {
        // ⚠ The code, and whether this is the confirmation. A `device_label` used to arrive with
        // it; the field that sent it is gone, and it is NOT accepted quietly here either — a name
        // that no screen asks for must not be settable by hand-crafting the request.
        $request->validate([
            'code' => 'required|string|min:6|max:9', // ABCD-1234 is 9 chars with dash
            'confirm' => 'nullable|boolean',
        ]);

        $deviceCode = DeviceCode::findByUserCode($request->code);

        if (!$deviceCode) {
            session()->forget('link.pending');

            return back()->withErrors(['code' => __('link.invalid_code')]);
        }

        if (!$request->boolean('confirm')) {
            session(['link.pending' => $deviceCode->user_code]);

            return redirect()->route('link');
        }

        session()->forget('link.pending');

        $user = auth()->user();

        // Authorize the device code with the current user
        $deviceCode->authorize($user);

        // Nobody types a name at this point any more; it is inherited below when the machine is
        // already filed, and given by hand on the Linked devices screen otherwise.
        $label = null;

        // One game holds one access per program ON ONE DEVICE: linking it again from the same
        // device replaces what was there, rather than leaving a line nobody can identify behind.
        //
        // ⚠ Two conditions, and each one is a refusal to cut on a guess:
        //
        //  - no Steam id, no cap. A game recognised through `Application.productName` cannot be
        //    told from another carrying the same one, and two different games silently cutting
        //    each other off is worse than no cap at all.
        //  - no way to tell the machine, no cap. The cap is for the accesses an install abandons,
        //    and those are all on one machine. Without that, it also cut ACROSS machines — linking
        //    a game on a Steam Deck signed the same game out on the desktop, and back on the next
        //    switch.
        $slot = $deviceCode->game_id !== null && $deviceCode->client_kind !== null
            ? ApiToken::gameSlotFor($user, $deviceCode->game_id)
            : null;

        // 🔴 **The machine says which it is, so nobody has to type a name — and that is what
        // finally makes the cap fire.** It needed a name somebody typed, nobody types one, so
        // re-linking a game created a line and left the previous one: a reinstall, a wiped config,
        // "revoke everything" then signing in again. Thirty-six accesses on one account, measured
        // in production on 2026-08-27, almost all of them from that.
        $device = $deviceCode->device_id !== null
            ? ApiToken::deviceSlotFor($user, $deviceCode->device_id)
            : null;

        $replaced = 0;

        // ⚠ The machine, and only the machine. There was a second branch keyed on a name typed on
        // this screen — the fallback for a client that does not identify itself. It went with the
        // field: nothing sets a label at creation any more, so it could never fire again, and a
        // condition that cannot be true is worse than none (it reads as a covered case).
        //
        // 🔴 What that costs is exactly what it was already costing: a client too old to send a
        // machine accumulates an access per link. That was ALREADY true — the branch needed a name
        // somebody typed, and nobody typed one, which is how thirty-six accesses piled up on one
        // account. The cure was never the name, it was the machine.
        if ($slot !== null && $device !== null) {
            $replaced = $user->apiTokens()
                ->where('game_slot', $slot)
                ->where('client_kind', $deviceCode->client_kind)
                ->where('device_slot', $device)
                ->delete();
        }

        // ⚠ A new game linked on a machine already filed somewhere joins it, instead of arriving
        // alone and having to be moved by hand. Same rule as an ordinary call fills in: only while
        // that machine agrees with itself — split across names, there is no "its group" to inherit.
        $recognised = null;
        if ($label === null && $device !== null) {
            $label = ApiToken::inheritedLabelFor($user, $device);

            // Worth saying rather than doing quietly: somebody who left the name blank gets an
            // access filed under a name they did not type on this screen, and a group appearing
            // by itself reads as a mistake unless it is announced.
            $recognised = $label;
        }

        $apiToken = ApiToken::createForUser($user, null, [
            'device_label' => $label,
            'client_kind' => $deviceCode->client_kind,
            'client_version' => $deviceCode->client_version,
            'client_variant' => $deviceCode->client_variant,
            'game_slot' => $slot,
            'game_ref' => $deviceCode->game_name,
            'device_slot' => $device,
        ]);
        // The label used to read 'Unity Mod (Device Flow)' whatever had asked — a name that
        // matched nothing stored, and that said "mod" about a Manager link.
        AuditLog::logTokenCreated($user->id, $deviceCode->client_kind ?? 'unknown', $request);

        // Signal SSE via Redis pub/sub — Node.js relays to the mod
        SsePublisher::deviceAuthorized($deviceCode->device_code, [
            'access_token' => $apiToken->plain_token,
            'token_type' => 'Bearer',
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
            ],
        ]);

        // Delete device code — no longer needed
        $deviceCode->delete();

        // Log device linking
        AuditLog::logDeviceLinked($user->id, $request->code, $request);

        // ⚠ Reported after the fact, not asked before: the code and the label arrive in one POST,
        // so there is no moment in between to ask. The rule itself is stated on the page above the
        // field, which is where somebody can still decide not to.
        // ⚠ Three outcomes, three sentences. "Recognised" comes first because it is the one that
        // would otherwise look like a mistake: an access filed under a name nobody typed here.
        $message = match (true) {
            $recognised !== null => __('link.success_recognised', ['device' => $recognised]),
            $replaced > 0 => __('link.success_replaced'),
            default => __('link.success'),
        };

        return redirect()->route('link')->with('success', $message);
    }
}
