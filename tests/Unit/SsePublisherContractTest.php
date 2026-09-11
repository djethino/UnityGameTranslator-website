<?php

namespace Tests\Unit;

use App\Services\SsePublisher;
use Illuminate\Support\Facades\Redis;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The site's half of the streams: resources/spec/sse-events/cases.json, the `publish` cases.
 *
 * 🔴 The site never writes a stream. It publishes a message on a Redis channel and, for an answer
 * a client may miss, stores the same message under a key with a TTL; the relay does the rest. So
 * what this side can be held to is exactly that — for each SsePublisher method, which channel,
 * which message, what is stored and for how long — and the mod's checks hold the other half
 * (the wire, and what a reader derives) on the same file.
 *
 * Redis is mocked: the point is what is SENT, and a test that needed a Redis to prove a message
 * would be green only on machines that have one.
 */
class SsePublisherContractTest extends TestCase
{
    /** @return array<string, array{array}> */
    public static function publishCases(): array
    {
        $path = dirname(__DIR__, 2) . '/resources/spec/sse-events/cases.json';
        self::assertFileExists($path, 'the spec copy is missing — run ./sync-common.ps1');
        $doc = json_decode(file_get_contents($path), true, 64, JSON_THROW_ON_ERROR);

        $out = [];
        foreach ($doc['cases'] as $case) {
            if (($case['kind'] ?? null) === 'publish') {
                $out[$case['id']] = [$case];
            }
        }

        return $out;
    }

    #[DataProvider('publishCases')]
    public function test_the_site_publishes_what_the_case_says(array $case): void
    {
        $published = [];
        $stored = [];
        $set = [];

        $connection = \Mockery::mock();
        $connection->shouldReceive('publish')->andReturnUsing(function ($channel, $message) use (&$published) {
            $published[] = [$channel, $message];

            return 1;
        });
        $connection->shouldReceive('setex')->andReturnUsing(function ($key, $ttl, $value) use (&$stored) {
            $stored[] = [$key, $ttl, $value];

            return true;
        });
        $connection->shouldReceive('set')->andReturnUsing(function ($key, $value) use (&$set) {
            $set[] = [$key, $value];

            return true;
        });
        Redis::shouldReceive('connection')->with('sse')->andReturn($connection);

        $id = $case['id'];
        SsePublisher::{$case['publisher']}(...$case['args']);

        if ($case['channel'] === null) {
            self::assertSame([], $published, "$id — nothing must be published — {$case['why']}");
        } else {
            self::assertCount(1, $published, "$id — exactly one message must be published — {$case['why']}");
            [$channel, $message] = $published[0];
            self::assertSame($case['channel'], $channel, "$id — the channel — {$case['why']}");
            self::assertSame($case['message'], json_decode($message, true), "$id — the message — {$case['why']}");
        }

        if ($case['stored'] === null) {
            self::assertSame([], $stored, "$id — nothing must be stored — {$case['why']}");
        } else {
            self::assertCount(1, $stored, "$id — exactly one key must be stored — {$case['why']}");
            [$key, $ttl, $value] = $stored[0];
            self::assertSame($case['stored']['key'], $key, "$id — the stored key — {$case['why']}");
            self::assertSame($case['stored']['ttl_s'], $ttl, "$id — the TTL — {$case['why']}");
            if (!empty($case['stored']['same_message'])) {
                self::assertSame($published[0][1], $value, "$id — what is stored is the message published — {$case['why']}");
            } else {
                self::assertSame($case['stored']['value'], $value, "$id — the stored value — {$case['why']}");
            }
        }

        $expectedSets = $case['sets'] ?? [];
        self::assertSame($expectedSets, array_column($set, 1, 0), "$id — the plain keys written — {$case['why']}");
    }
}
