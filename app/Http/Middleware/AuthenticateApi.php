<?php

namespace App\Http\Middleware;

use App\Models\ApiToken;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateApi
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->bearerToken();

        if (!$token) {
            return response()->json([
                'error' => 'Unauthorized',
                'message' => 'API token required',
            ], 401);
        }

        $apiToken = ApiToken::findAndMarkUsed($token);

        if (!$apiToken) {
            return response()->json([
                'error' => 'Unauthorized',
                'message' => 'Invalid API token',
            ], 401);
        }

        // Set the authenticated user on the request
        $request->setUserResolver(function () use ($apiToken) {
            return $apiToken->user;
        });

        // Store the token for potential use in controllers
        $request->attributes->set('api_token', $apiToken);

        return $next($request);
    }
}
