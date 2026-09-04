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
     * Only allows relative URLs or URLs on the same origin.
     *
     * 🔴 **The origin is COMPARED, never prefix-matched.** This used to accept any URL that started
     * with `app.url` as a string — and a URL is not a string. `https://our.host@evil.example/` starts
     * with our address and lands on evil.example (everything before the `@` is a user name);
     * `https://our.host.evil.example/` starts with it too and is somebody else's domain. Either one,
     * handed to /login?redirect=, turned our sign-in page into a door that opens where an attacker
     * chose. So the URL is parsed, and scheme, host and port are compared one by one.
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
        // And "/\evil.example" is read the same way by browsers, which accept a backslash where the
        // standard says slash — hence the third.
        if (Str::startsWith($url, '/') && !Str::startsWith($url, '//') && !Str::startsWith($url, '/\\')) {
            return $url;
        }

        return $this->isSameOrigin($url) ? $url : null;
    }

    /**
     * Whether an absolute URL points at this site and nowhere else.
     *
     * ⚠ Everything is measured against `app.url`, so that value has to be exact in production —
     * scheme included. It is, and a mismatch would not open anything: a URL that fails here simply
     * lands on the default page.
     */
    private function isSameOrigin(string $url): bool
    {
        $ours = parse_url((string) config('app.url'));
        $theirs = parse_url($url);

        if ($ours === false || $theirs === false || empty($ours['host']) || empty($theirs['host'])) {
            return false;
        }

        // A user name in the URL is the classic disguise: the host that follows the `@` is the
        // real one. Nothing on this site is ever addressed that way.
        if (isset($theirs['user']) || isset($theirs['pass'])) {
            return false;
        }

        $scheme = fn (array $parts) => strtolower($parts['scheme'] ?? '');
        $port = fn (array $parts) => $parts['port'] ?? match ($scheme($parts)) {
            'https' => 443,
            'http' => 80,
            default => null,
        };

        return $scheme($theirs) !== ''
            && $scheme($theirs) === $scheme($ours)
            && strtolower($theirs['host']) === strtolower($ours['host'])
            && $port($theirs) === $port($ours);
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
