<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ApiToken;
use App\Models\AuditLog;
use App\Models\DeviceCode;
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
     * Poll for authorization status.
     * The mod calls this repeatedly until authorized or expired.
     *
     * POST /api/v1/auth/device/poll
     */
    public function poll(Request $request): JsonResponse
    {
        $request->validate([
            'device_code' => 'required|string',
        ]);

        $deviceCode = DeviceCode::findByDeviceCode($request->device_code);

        if (!$deviceCode) {
            return response()->json([
                'error' => 'expired_token',
                'error_description' => 'The device code has expired. Please restart the login process.',
            ], 400);
        }

        if (!$deviceCode->isAuthorized()) {
            return response()->json([
                'error' => 'authorization_pending',
                'error_description' => 'The user has not yet authorized. Continue polling.',
            ], 400);
        }

        // Create API token for the user
        $apiToken = ApiToken::createForUser($deviceCode->user);

        // Log token creation
        AuditLog::logTokenCreated($deviceCode->user->id, 'Unity Mod (Device Flow)', $request);

        // Clean up the device code
        $deviceCode->delete();

        return response()->json([
            'access_token' => $apiToken->plain_token, // Return plain token (shown only once)
            'token_type' => 'Bearer',
            'user' => [
                'id' => $deviceCode->user->id,
                'name' => $deviceCode->user->name,
            ],
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

        // Authorize the device code with the current user
        $deviceCode->authorize(auth()->user());

        // Log device linking
        AuditLog::logDeviceLinked(auth()->id(), $request->code, $request);

        return redirect()->route('link')->with('success', 'Device linked successfully! You can now return to your game.');
    }
}
