<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
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
 */
class DecodeGzipRequest
{
    // Maximum decompressed size (10MB) to prevent zip bomb attacks
    private const MAX_DECOMPRESSED_SIZE = 10 * 1024 * 1024;

    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $contentEncoding = $request->header('Content-Encoding');

        // Only process if Content-Encoding is gzip
        if ($contentEncoding === 'gzip') {
            $compressedContent = $request->getContent();

            if (!empty($compressedContent)) {
                // Decompress the gzip content
                $decompressed = @gzdecode($compressedContent);

                if ($decompressed === false) {
                    return response()->json([
                        'error' => 'Invalid gzip content',
                        'message' => 'Failed to decompress request body',
                    ], 400);
                }

                // Prevent zip bomb attacks
                if (strlen($decompressed) > self::MAX_DECOMPRESSED_SIZE) {
                    return response()->json([
                        'error' => 'Payload too large',
                        'message' => 'Decompressed content exceeds maximum allowed size',
                    ], 413);
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
        }

        return $next($request);
    }
}
