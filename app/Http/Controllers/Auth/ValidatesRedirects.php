<?php

namespace App\Http\Controllers\Auth;

use Illuminate\Support\Str;

/**
 * Where to send somebody once they are signed in, when they asked to come back somewhere.
 *
 * 🔴 **Shared because there are two ways to sign in, and only one of them had this.** The rule that
 * stops an open redirect lived inside SocialController, so signing in with a username — the local
 * account — simply ignored where the person had come from and dropped them on the home page. The
 * page it matters most for is /link: they are holding a code shown in their game, and sending them
 * anywhere else means finding that page again on their own.
 *
 * ⚠ A second copy of this check would be a second chance to get it wrong, and getting it wrong here
 * means an attacker chooses where our sign-in page sends people.
 */
trait ValidatesRedirects
{
    /**
     * Validate redirect URL to prevent open redirect attacks.
     * Only allows relative URLs or URLs on the same domain.
     */
    protected function validateRedirectUrl(?string $url): ?string
    {
        if (empty($url)) {
            return null;
        }

        // Decode if URL-encoded
        $url = urldecode($url);

        // Allow relative URLs starting with /
        // ⚠ "//evil.example" is a protocol-relative URL, i.e. another host — hence the second test.
        if (Str::startsWith($url, '/') && !Str::startsWith($url, '//')) {
            return $url;
        }

        // Allow same-origin absolute URLs
        $appUrl = config('app.url');
        if ($appUrl && Str::startsWith($url, $appUrl)) {
            return $url;
        }

        // Reject all other URLs (potential open redirect)
        return null;
    }

    /**
     * Remember it as the place to land, the way Laravel's own intended() reads it.
     *
     * Silent when the address is refused: somebody signing in still gets signed in, they simply
     * arrive at the default page. A refusal here is not their problem to solve.
     */
    protected function rememberIntendedUrl(?string $url): void
    {
        $safe = $this->validateRedirectUrl($url);

        if ($safe) {
            session(['url.intended' => $safe]);
        }
    }
}
