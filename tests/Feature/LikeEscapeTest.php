<?php

namespace Tests\Feature;

use App\Support\Like;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * What a person types cannot become a wildcard.
 *
 * 🔴 **The case that shipped: escaping the wildcards and not the backslash.** An input of `\%`
 * became `\\%` — a literal backslash followed by a LIVE wildcard — so both special characters
 * stayed reachable behind one. It was true of the five copies this rule had before they were
 * merged, and it survived the merge.
 *
 * ⚠ Never an SQL injection: every caller binds the value as a parameter. The cost was a search
 * wider than the one asked for, bounded by the limits above it — which is why it is checked here
 * against the engine rather than argued about.
 */
class LikeEscapeTest extends TestCase
{
    public function test_the_wildcards_are_escaped(): void
    {
        $this->assertSame('100\%', Like::escape('100%'));
        $this->assertSame('a\_b', Like::escape('a_b'));
        $this->assertSame('plain', Like::escape('plain'));
    }

    public function test_the_backslash_is_escaped_first(): void
    {
        // `\%` must not leave a live wildcard behind a literal backslash.
        $this->assertSame('\\\\\%', Like::escape('\%'));
        $this->assertSame('\\\\\_', Like::escape('\_'));

        // And a lone backslash — a Windows path pasted into a search box — is doubled, not left
        // to escape whatever follows it.
        $this->assertSame('C:\\\\path', Like::escape('C:\path'));
    }

    public function test_the_engine_agrees(): void
    {
        // 🔴 The check that matters: the string rules above are only right if the engine reads
        // them that way. MariaDB treats `\` as the LIKE escape unless NO_BACKSLASH_ESCAPES is set.
        $needle = '%' . Like::escape('\%') . '%';

        $literal = DB::selectOne('SELECT ? LIKE ? AS m', ['a\%b', $needle]);
        $other = DB::selectOne('SELECT ? LIKE ? AS m', ['a\ANYTHINGb', $needle]);

        $this->assertEquals(1, $literal->m, 'the literal string is found');
        $this->assertEquals(0, $other->m, 'and nothing else is — the wildcard stays inert');
    }
}
