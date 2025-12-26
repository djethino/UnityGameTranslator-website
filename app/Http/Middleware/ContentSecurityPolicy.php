<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ContentSecurityPolicy
{
    /**
     * Add Content-Security-Policy header to responses.
     * Helps prevent XSS attacks by restricting resource loading.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        // Only add CSP to HTML responses
        $contentType = $response->headers->get('Content-Type', '');
        if (!str_contains($contentType, 'text/html') && !empty($contentType)) {
            return $response;
        }

        // Build CSP directives
        // Assets are now bundled locally via Vite - no external CDNs needed
        $csp = implode('; ', [
            // Default: only allow same-origin
            "default-src 'self'",

            // Scripts: self + unsafe-eval (required for Alpine.js inline expressions)
            "script-src 'self' 'unsafe-eval'",

            // Styles: self + inline (for dynamic styles in Blade templates)
            "style-src 'self' 'unsafe-inline'",

            // Images: self + data URIs + external (game covers, avatars)
            "img-src 'self' data: https: blob:",

            // Fonts: self only (FontAwesome bundled locally)
            "font-src 'self'",

            // Connect: self + local Ollama (for future mod integration)
            "connect-src 'self'",

            // Forms: only submit to self
            "form-action 'self'",

            // Frames: none (no iframes allowed)
            "frame-ancestors 'none'",

            // Base URI: self only
            "base-uri 'self'",

            // Object/embed: none
            "object-src 'none'",
        ]);

        $response->headers->set('Content-Security-Policy', $csp);

        // Additional security headers
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('X-Frame-Options', 'DENY');
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');

        return $response;
    }
}
