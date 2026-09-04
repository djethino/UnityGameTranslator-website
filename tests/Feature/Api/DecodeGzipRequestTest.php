<?php

namespace Tests\Feature\Api;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

/**
 * What a compressed request body may cost the server.
 *
 * 🔴 **The first case is a REAL bomb, and that is deliberate.** Deflate compresses runs of one byte
 * at about 1:1030, so two hundred kilobytes here claim two hundred megabytes. The middleware used
 * to inflate that in full before comparing it to its cap; a cap that is checked after the damage
 * protects nothing, and the only proof that it now acts BEFORE is a body that would have hurt.
 *
 * ⚠ The bomb is built incrementally so the test itself never holds the plain bytes: what matters
 * is what the SERVER holds, and a test that needed a gigabyte to prove the server never does would
 * be its own counter-example.
 */
class DecodeGzipRequestTest extends TestCase
{
    use RefreshDatabase;

    private const MB = 1024 * 1024;

    protected function setUp(): void
    {
        parent::setUp();

        // The per-address counter lives in the cache, which outlives one test in a run.
        RateLimiter::clear('gzip-bodies:127.0.0.1');
    }

    /** A gzip stream claiming $plainBytes of zeros, built a megabyte at a time. */
    private function bomb(int $plainBytes): string
    {
        $ctx = deflate_init(ZLIB_ENCODING_GZIP, ['level' => 9]);
        $out = '';
        $chunk = str_repeat("\0", self::MB);

        for ($sent = 0; $sent < $plainBytes; $sent += self::MB) {
            $out .= deflate_add($ctx, $chunk, ZLIB_NO_FLUSH);
        }

        return $out . deflate_add($ctx, '', ZLIB_FINISH);
    }

    private function postGzip(string $uri, string $body, array $server = []): \Illuminate\Testing\TestResponse
    {
        return $this->call('POST', $uri, [], [], [], array_merge([
            'HTTP_CONTENT_ENCODING' => 'gzip',
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
        ], $server), $body);
    }

    public function test_a_bomb_is_refused_at_the_cap_and_never_inflated(): void
    {
        $bomb = $this->bomb(200 * self::MB);
        $this->assertLessThan(self::MB, strlen($bomb), 'the bomb must be small on the wire, or it proves nothing');

        $before = memory_get_peak_usage(true);

        $this->postGzip('/api/v1/translations/for-games', $bomb)
            ->assertStatus(413)
            ->assertJsonPath('error', 'Payload too large');

        // A decode that stops at a 100 MB cap costs about twice the cap; one that ran to the end
        // would have cost the 200 MB claimed plus the buffer, and on a bigger claim, everything.
        $this->assertLessThan(
            300 * self::MB,
            memory_get_peak_usage(true) - $before,
            'the decode ran past the cap'
        );
    }

    public function test_a_real_compressed_body_is_decoded_and_routed(): void
    {
        $body = gzencode(json_encode(['games' => [['name' => 'Nothing Called This']]]));

        $this->postGzip('/api/v1/translations/for-games', $body)
            ->assertOk()
            ->assertJsonPath('results.0.games_total', 0);
    }

    public function test_a_compressed_body_is_only_accepted_where_a_body_belongs(): void
    {
        $this->call('GET', '/api/v1/translations?q=anything', [], [], [], [
            'HTTP_CONTENT_ENCODING' => 'gzip',
            'HTTP_ACCEPT' => 'application/json',
        ])->assertStatus(415);
    }

    public function test_a_body_declared_too_large_is_refused_before_it_is_read(): void
    {
        $this->postGzip('/api/v1/translations/for-games', gzencode('{}'), [
            'CONTENT_LENGTH' => 17 * self::MB,
        ])->assertStatus(413);
    }

    public function test_a_corrupt_body_is_an_invalid_request_not_a_large_one(): void
    {
        $this->postGzip('/api/v1/translations/for-games', 'this is not gzip')
            ->assertStatus(400)
            ->assertJsonPath('error', 'Invalid gzip content');
    }

    public function test_compressed_bodies_are_counted_before_they_are_decoded(): void
    {
        // ⚠ Sixty REFUSED bodies, on purpose: a corrupt one is answered by this middleware and never
        // reaches the route or its throttle. That is exactly the traffic nothing used to count.
        for ($i = 0; $i < 60; $i++) {
            $this->postGzip('/api/v1/translations/for-games', 'not gzip')->assertStatus(400);
        }

        // The sixty-first is refused whatever it holds — a bomb included, which would otherwise
        // have answered 413 after a decode — and says when to retry.
        $this->postGzip('/api/v1/translations/for-games', $this->bomb(self::MB))
            ->assertStatus(429)
            ->assertHeader('Retry-After');
    }
}
