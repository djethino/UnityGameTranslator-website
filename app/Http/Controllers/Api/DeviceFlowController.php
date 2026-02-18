<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ApiToken;
use App\Models\AuditLog;
use App\Models\DeviceCode;
use App\Services\SsePublisher;
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
    public function initiate(): JsonResponse
    {
        $deviceCode = DeviceCode::generate();

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
        return view('auth.link');
    }

    /**
     * Validate the user code entered on the website.
     */
    public function validateCode(Request $request)
    {
        $request->validate([
            'code' => 'required|string|min:6|max:9', // ABCD-1234 is 9 chars with dash
        ]);

        $deviceCode = DeviceCode::findByUserCode($request->code);

        if (!$deviceCode) {
            return back()->withErrors(['code' => 'Invalid or expired code. Please check the code displayed in your game.']);
        }

        $user = auth()->user();

        // Authorize the device code with the current user
        $deviceCode->authorize($user);

        // Create API token for the mod (previously done inside SseController::emitAuthorized)
        $apiToken = ApiToken::createForUser($user);
        AuditLog::logTokenCreated($user->id, 'Unity Mod (Device Flow)', $request);

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

        return redirect()->route('link')->with('success', 'Device linked successfully! You can now return to your game.');
    }
}
