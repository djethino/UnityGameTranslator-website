<?php

namespace Tests\Feature;

use App\Http\Controllers\Auth\ValidatesRedirects;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Where the sign-in page may send somebody afterwards — and where it must not.
 *
 * 🔴 **A URL is not a string, and this check treated it as one.** It accepted any address that
 * STARTED with the site's, so `https://our.host@evil.example/` (our host is a user name there, the
 * real host follows the `@`) and `https://our.host.evil.example/` (somebody else's domain) both
 * passed — and /login?redirect= became a door our own sign-in page opened onto an attacker's site.
 * Origin is now compared part by part.
 *
 * ⚠ The legitimate half matters as much: /link with its code, a relative path, the same site
 * written in full. A validator that refused those would send every player home instead of back to
 * the page holding their code.
 */
class RedirectValidationTest extends TestCase
{
    private object $validator;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.url' => 'https://unitygametranslator.test']);

        $this->validator = new class {
            use ValidatesRedirects;

            public function check(?string $url): ?string
            {
                return $this->validateRedirectUrl($url);
            }
        };
    }

    public static function accepted(): array
    {
        return [
            'a relative path' => ['/link'],
            'a relative path with a query' => ['/games/hollow-knight?lang=French'],
            'the site written in full' => ['https://unitygametranslator.test/link'],
            'the site written in full, host in capitals' => ['https://UnityGameTranslator.test/link'],
            'the site with its default port spelt out' => ['https://unitygametranslator.test:443/link'],
            'a relative path, URL-encoded' => ['%2Flink%3Fcode%3DABCD'],
        ];
    }

    public static function refused(): array
    {
        return [
            'another host' => ['https://evil.example/'],
            'our host as a user name' => ['https://unitygametranslator.test@evil.example/'],
            'our host as a subdomain of theirs' => ['https://unitygametranslator.test.evil.example/'],
            'our host as a prefix of theirs' => ['https://unitygametranslator.testevil.example/'],
            'a protocol-relative address' => ['//evil.example/'],
            'a protocol-relative address with a backslash' => ['/\\evil.example/'],
            'a protocol-relative address, URL-encoded' => ['%2F%2Fevil.example'],
            'our host over plain http' => ['http://unitygametranslator.test/link'],
            'our host on another port' => ['https://unitygametranslator.test:8443/link'],
            'a javascript URL' => ['javascript:alert(1)'],
            'nothing' => [''],
            'null' => [null],
        ];
    }

    #[DataProvider('accepted')]
    public function test_an_address_on_this_site_is_kept(string $url): void
    {
        $this->assertNotNull($this->validator->check($url), "'{$url}' must be kept");
    }

    #[DataProvider('refused')]
    public function test_an_address_elsewhere_is_dropped(?string $url): void
    {
        $this->assertNull($this->validator->check($url), "'{$url}' must be dropped");
    }
}
