<?php

namespace App\Http\Middleware;

use App\Support\TranslationFlows;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Traces every publication the upload API turns down (the admin Flows screen).
 *
 * ⚠ **Read from the answer, not written at each refusal.** The upload refuses in some ten places —
 * ownership, a frozen branch, a game it cannot identify or that moved, a client that did not pick —
 * and each answers with a `refused_code` (`common/spec/api-v1/openapi.json`, `RefusedCode`). Reading
 * that code on the way out traces all of them, and the next refusal added to the controller too,
 * where a log line at each would be one more thing for that next refusal to forget.
 *
 * Validation errors (a malformed request) carry no code and are not refusals: they are not traced.
 */
class TraceRefusedUpload
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if ($response instanceof JsonResponse && $response->getStatusCode() >= 400) {
            $code = $response->getData(true)['refused_code'] ?? null;
            if (is_string($code) && $code !== '') {
                TranslationFlows::refused($request, $code);
            }
        }

        return $response;
    }
}
