<?php

namespace App\Http\Middleware;

use App\Models\ApiToken;
use App\Support\GameDeclaration;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Resolves the API user when a valid bearer token is present, and lets the request
 * through untouched otherwise.
 *
 * For PUBLIC endpoints that can say something extra to a signed-in caller — the mod
 * sends its token on every call, so a translation list can carry back the caller's own
 * vote. Unlike AuthenticateApi this never rejects: no token, an expired one or a revoked
 * one simply means an anonymous response, never a 401.
 */
class OptionalAuthenticateApi
{
    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->bearerToken();

        if ($token) {
            // ⚠ The declaration is read here too, and that is the point rather than an oversight:
            // the mod spends most of its calls on public routes — looking a game up, checking for
            // an update — so waiting for an authenticated one would leave the line nameless for
            // whoever never publishes.
            $apiToken = ApiToken::findAndMarkUsed(
                $token,
                $request->userAgent(),
                GameDeclaration::parse($request->header(GameDeclaration::HEADER)),
                GameDeclaration::parseDevice($request->header(GameDeclaration::DEVICE_HEADER))
            );

            if ($apiToken) {
                $request->setUserResolver(fn () => $apiToken->user);
                $request->attributes->set('api_token', $apiToken);
            }
        }

        return $next($request);
    }
}
