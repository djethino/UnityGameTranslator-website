<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ApiToken;
use App\Models\AuditLog;
use App\Models\DeviceCode;
use App\Services\SsePublisher;
use App\Support\ClientAgent;
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
    public function showLinkPage()
    {
        // The names this account has already used, offered as chips to click.
        //
        // ⚠ Chips, never a pre-filled field: a field already reading "Living room PC" gets accepted
        // without a thought on the one day it matters — the day a game is linked at a friend's
        // place. A chip costs the same gesture and stays a choice.
        $devices = auth()->check()
            ? auth()->user()->apiTokens()
                ->whereNotNull('device_label')
                ->distinct()
                ->orderBy('device_label')
                ->pluck('device_label')
            : collect();

        return view('auth.link', ['devices' => $devices]);
    }

    /**
     * Validate the user code entered on the website.
     */
    public function validateCode(Request $request)
    {
        $validated = $request->validate([
            'code' => 'required|string|min:6|max:9', // ABCD-1234 is 9 chars with dash
            // An opaque note for its owner. The server never reads anything into it.
            'device_label' => ['nullable', 'string', 'max:60'],
        ]);

        $deviceCode = DeviceCode::findByUserCode($request->code);

        if (!$deviceCode) {
            return back()->withErrors(['code' => 'Invalid or expired code. Please check the code displayed in your game.']);
        }

        $user = auth()->user();

        // Authorize the device code with the current user
        $deviceCode->authorize($user);

        $label = trim((string) ($validated['device_label'] ?? ''));
        $label = $label === '' ? null : $label;

        // One game holds one access per program ON ONE DEVICE: linking it again from the same
        // device replaces what was there, rather than leaving a line nobody can identify behind.
        //
        // ⚠ Two conditions, and each one is a refusal to cut on a guess:
        //
        //  - no Steam id, no cap. A game recognised through `Application.productName` cannot be
        //    told from another carrying the same one, and two different games silently cutting
        //    each other off is worse than no cap at all.
        //  - no device name, no cap. The cap is for the accesses an install abandons, and those
        //    are all on one machine. Without the name it also cut across machines — linking a game
        //    on a Steam Deck signed the same game out on the desktop, and back on the next switch.
        $slot = $deviceCode->game_id !== null && $deviceCode->client_kind !== null
            ? ApiToken::gameSlotFor($user, $deviceCode->game_id)
            : null;

        $replaced = 0;
        if ($slot !== null && $label !== null) {
            $replaced = $user->apiTokens()->sameSlot($slot, $deviceCode->client_kind, $label)->delete();
        }

        $apiToken = ApiToken::createForUser($user, null, [
            'device_label' => $label,
            'client_kind' => $deviceCode->client_kind,
            'client_version' => $deviceCode->client_version,
            'client_variant' => $deviceCode->client_variant,
            'game_slot' => $slot,
            'game_ref' => $deviceCode->game_name,
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
        return redirect()->route('link')->with(
            'success',
            $replaced > 0 ? __('link.success_replaced') : __('link.success')
        );
    }
}
