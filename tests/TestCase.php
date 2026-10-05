<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * Say that the stores answer nothing about a game's classification.
     *
     * ⚠ **Every test that stubs GameSearchService needs this.** Creating a game card also asks
     * whether the game is for adults only (App\Services\AdultRating), through the same service —
     * so a stub that only expects `findGame` or `getGame` makes Mockery refuse the call, and the
     * upload under test fails for a reason that has nothing to do with what it is testing.
     *
     * Answering nothing is the honest default here: these tests have no network and no
     * credentials, and "the store said nothing" is exactly what that means. A test about the
     * classification itself states its own expectations instead.
     *
     * ⚠ Same for the card's pictures (App\Services\GameArt, 2026-10-06): a card created asks the
     * stores for the art of its ids, through the same service. Nothing found keeps the picture of
     * the store the game was picked from — what these tests were written against.
     */
    protected function storesSayNothingAboutAdultContent($mock): void
    {
        $mock->shouldReceive('steamApp')->andReturnNull();
        $mock->shouldReceive('igdb')->andReturn([]);
        $mock->shouldReceive('steamAssets')->andReturnNull();
        $mock->shouldReceive('igdbCover')->andReturnNull();
    }
}
