<?php

namespace Tests\Feature;

use App\Models\DeviceCode;
use App\Support\ClientAgent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * What a caller declares about itself must never be able to break the call.
 *
 * 🔴 **`POST /api/v1/auth/device` answered 500 on a long version.** It is public, unauthenticated,
 * and it is the endpoint a mod uses to sign in — so the failure was reachable by anyone and hit the
 * one flow a player cannot work around.
 *
 * ⚠ **Nothing guarded it; an accident did.** The path used to truncate every version to major.minor,
 * so nothing long ever reached a column that holds 16 characters while the parser accepts 32.
 * Removing that truncation — correct in itself, it hid the difference between 0.12.0 and 0.12.1 —
 * uncovered the mismatch. These tests exist so the pair cannot drift apart again in silence.
 */
class DeclaredClientVersionTest extends TestCase
{
    use RefreshDatabase;

    /** The longest thing the parser will hand over, per its own pattern. */
    private const LONGEST = '9999.9999.9999.9999-aaaaaaaaaaaa';

    public function test_the_parser_and_the_column_agree_on_the_longest_version(): void
    {
        $client = ClientAgent::parse('UnityGameTranslator/' . self::LONGEST . ' (BepInEx5)');

        $this->assertSame(self::LONGEST, $client['version'], 'the pattern no longer accepts this');
        $this->assertLessThanOrEqual(
            32,
            strlen($client['version']),
            'the parser accepts a longer version than the column was widened to'
        );
    }

    public function test_signing_in_survives_a_version_longer_than_the_column_once_was(): void
    {
        $response = $this->withHeaders(['User-Agent' => 'UnityGameTranslator/' . self::LONGEST . ' (BepInEx5)'])
            ->postJson('/api/v1/auth/device', []);

        $response->assertOk();

        $this->assertSame(self::LONGEST, DeviceCode::first()?->client_version);
    }

    /**
     * ⚠ The counting path was never exposed, and this says why rather than leaving it to be
     * rediscovered: it only ever stores a version we have published, or one of the two buckets.
     */
    public function test_counting_files_an_over_long_version_as_unrecognised(): void
    {
        $this->withHeaders(['User-Agent' => 'UnityGameTranslator/' . self::LONGEST . ' (BepInEx5)'])
            ->get('/api/v1/games')
            ->assertOk();

        $row = \App\Models\ClientUsageDaily::first();

        $this->assertNotNull($row);
        $this->assertSame(\App\Models\ClientUsageDaily::UNRECOGNISED, $row->version);
    }

    /** Rubbish stays rubbish: too long for the pattern is not a version at all. */
    public function test_something_longer_than_the_pattern_is_not_read_as_a_version(): void
    {
        $client = ClientAgent::parse('UnityGameTranslator/' . str_repeat('9', 60) . ' (BepInEx5)');

        $this->assertNull($client['version']);
    }
}
