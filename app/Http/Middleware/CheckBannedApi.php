<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CheckBannedApi
{
    /**
     * Check if the authenticated API user is banned.
     * If banned, return 403 Forbidden with ban reason.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user && $user->isBanned()) {
            return response()->json([
                'error' => 'Account banned',
                'message' => 'Your account has been banned.',
                'reason' => $user->ban_reason ?? 'No reason provided.',
            ], 403);
        }

        return $next($request);
    }
}
