<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;

/**
 * Middleware to decode gzip-compressed request bodies.
 *
 * This allows API clients (like the Unity mod) to send gzip-compressed
 * JSON payloads to reduce upload bandwidth by ~70%.
 *
 * The client must set:
 * - Content-Encoding: gzip
 * - Content-Type: application/json
 *
 * 🔴 **What a compressed body can cost, and why every guard here runs BEFORE the decode.** Deflate
 * compresses at best around 1:1030, so a hundred kilobytes on the wire can claim a hundred
 * megabytes of memory, and one megabyte a gigabyte. This used to inflate the whole body and only
 * then compare its length to the cap — a size check that ran once the damage was done — and it
 * sits at the head of the `api` group while every `throttle` is a route middleware, so a request
 * that died here was never counted by anything. Hence, in order: a method check, a per-address
 * counter, a bound on the compressed size, and a decode that stops AT the cap instead of after it.
 */
class DecodeGzipRequest
{
    /**
     * Maximum decompressed size (100 MB).
     *
     * Even Baldur's Gate 3 (largest RPG ever) = ~40 MB JSON; 100 MB gives 2.5x margin for any
     * realistic translation file. `store()` caps `content` at the same figure.
     *
     * ⚠ Sized against the server's memory, not only against the files: a decode that stops at the
     * cap still allocates about TWICE the cap while it runs (measured: a 200 MB bomb refused at a
     * 100 MB cap peaks at +214 MB). Production runs with `memory_limit = 512M`, which leaves room;
     * on a 128 MB host this figure would have to come down to ~48 MB.
     */
    private const MAX_DECOMPRESSED_SIZE = 100 * 1024 * 1024;

    /**
     * Maximum size of the body as it arrives (16 MB).
     *
     * A 40 MB translation file compresses to 5–8 MB, so nothing real comes near this. It is not
     * what stops a bomb — a hundred kilobytes are enough for one — but it is what keeps a client
     * from making the server read tens of megabytes before any other guard can speak.
     */
    private const MAX_COMPRESSED_SIZE = 16 * 1024 * 1024;

    /**
     * How many compressed bodies one address may send per minute.
     *
     * ⚠ Counted HERE, before decoding, because the route throttles run after this middleware and
     * never see a request that dies in it. Generous on purpose: the mod sends three kinds of
     * compressed body (an upload, a merge preview, an edit session), the Manager one more, and
     * the busiest route among them allows 30 a minute on its own — a player and a Manager on the
     * same machine stay far under this. What it bounds is the cost of refusing bombs: sixty
     * decodes stopped at the cap is a few seconds of CPU a minute, where before it was unlimited.
     */
    private const BODIES_PER_MINUTE = 60;

    /**
     * The most deflate can ever expand: about 1032 to 1. Used to tell "too large" from "corrupt"
     * when the decoder gives up — a body that could not possibly have reached the cap was simply
     * not valid gzip.
     */
    private const MAX_DEFLATE_RATIO = 1040;

    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $contentEncoding = $request->header('Content-Encoding');

        // Only process if Content-Encoding is gzip
        if ($contentEncoding !== 'gzip') {
            return $next($request);
        }

        // A GET carries no body worth decompressing. Refusing the header outright, rather than
        // ignoring it, is what keeps this middleware from being reachable from every route on the
        // API: every client we ship compresses POST bodies and nothing else.
        if (!in_array($request->method(), ['POST', 'PUT', 'PATCH'], true)) {
            return response()->json([
                'error' => 'Unsupported Media Type',
                'message' => 'A compressed body is only accepted on POST, PUT and PATCH.',
            ], 415);
        }

        // Counted before anything is read or inflated — see BODIES_PER_MINUTE.
        $key = 'gzip-bodies:' . $request->ip();
        if (RateLimiter::tooManyAttempts($key, self::BODIES_PER_MINUTE)) {
            return response()->json([
                'error' => 'Too Many Requests',
                'message' => 'Too many compressed requests. Try again shortly.',
            ], 429, ['Retry-After' => RateLimiter::availableIn($key)]);
        }
        RateLimiter::hit($key, 60);

        // The declared size first, so an oversized body is refused before it is read at all;
        // the measured size second, because a client need not declare one.
        $declared = (int) $request->header('Content-Length', 0);
        if ($declared > self::MAX_COMPRESSED_SIZE) {
            return $this->tooLarge('Compressed body exceeds the maximum allowed size');
        }

        $compressedContent = $request->getContent();

        if (empty($compressedContent)) {
            return $next($request);
        }

        if (strlen($compressedContent) > self::MAX_COMPRESSED_SIZE) {
            return $this->tooLarge('Compressed body exceeds the maximum allowed size');
        }

        // 🔴 `max_length` makes the decoder give up AT the cap: the cost of a bomb is the cap, never
        // the bomb. Without it, a body claiming a gigabyte was inflated in full and the check below
        // ran on the corpse. ⚠ The bound is rounded up to zlib's block, so a body can come back a
        // little longer than asked — the explicit length check afterwards stays.
        $decompressed = @gzdecode($compressedContent, self::MAX_DECOMPRESSED_SIZE + 1);

        if ($decompressed === false) {
            // Could this body have reached the cap at all? If not, it was simply not gzip.
            $couldBeLarge = strlen($compressedContent) * self::MAX_DEFLATE_RATIO > self::MAX_DECOMPRESSED_SIZE;

            return $couldBeLarge
                ? $this->tooLarge('Decompressed content exceeds maximum allowed size')
                : response()->json([
                    'error' => 'Invalid gzip content',
                    'message' => 'Failed to decompress request body',
                ], 400);
        }

        // Prevent zip bomb attacks
        if (strlen($decompressed) > self::MAX_DECOMPRESSED_SIZE) {
            return $this->tooLarge('Decompressed content exceeds maximum allowed size');
        }

        // Replace the request content with decompressed data
        // We need to create a new request with the decompressed content
        $server = $request->server->all();

        // Remove Content-Encoding since we've decoded it
        $headers = $request->headers->all();
        unset($headers['content-encoding']);

        // Update Content-Length to match decompressed size
        $headers['content-length'] = [strlen($decompressed)];

        $newRequest = $request->duplicate(
            $request->query->all(),
            $request->request->all(),
            $request->attributes->all(),
            $request->cookies->all(),
            $request->files->all(),
            $server
        );

        // Replace content
        $newRequest->headers->replace($headers);

        // Use reflection to set the content (it's protected)
        $reflection = new \ReflectionClass($newRequest);
        $contentProperty = $reflection->getProperty('content');
        $contentProperty->setAccessible(true);
        $contentProperty->setValue($newRequest, $decompressed);

        // Parse JSON if content type is JSON
        if (str_contains($request->header('Content-Type', ''), 'application/json')) {
            $decoded = json_decode($decompressed, true);

            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                // For JSON requests, Laravel uses json() which creates an InputBag
                // We need to set this directly for validation to work
                $jsonBag = new \Symfony\Component\HttpFoundation\InputBag($decoded);

                // Use reflection to set the json property on the NEW request
                $requestReflection = new \ReflectionClass($newRequest);
                if ($requestReflection->hasProperty('json')) {
                    $jsonProperty = $requestReflection->getProperty('json');
                    $jsonProperty->setAccessible(true);
                    $jsonProperty->setValue($newRequest, $jsonBag);
                }

                // Set request bag - this is what Laravel's input() uses for POST data
                $newRequest->request->replace($decoded);

                // Merge into the request - this populates getInputSource()
                $newRequest->merge($decoded);
            }
        }

        // CRITICAL: Replace the request in Laravel's service container
        // Without this, dependency injection in controllers gets the original request
        app()->instance('request', $newRequest);
        \Illuminate\Support\Facades\Facade::clearResolvedInstance('request');

        return $next($newRequest);
    }

    private function tooLarge(string $message): Response
    {
        return response()->json([
            'error' => 'Payload too large',
            'message' => $message,
        ], 413);
    }
}
