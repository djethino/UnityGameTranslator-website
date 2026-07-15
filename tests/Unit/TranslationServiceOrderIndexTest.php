<?php

namespace Tests\Unit;

use App\Services\TranslationService;
use PHPUnit\Framework\TestCase;

/**
 * The optional per-entry ordering index "i" is presentation metadata:
 * it must never affect the content hash (mods compare hashes to detect
 * real content changes, and "i" differs across devices for identical
 * content), and it must validate as a bounded positive integer.
 */
class TranslationServiceOrderIndexTest extends TestCase
{
    private TranslationService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new TranslationService();
    }

    public function test_hash_ignores_order_index(): void
    {
        $without = [
            '_uuid' => 'abc-123',
            'Hello' => ['v' => 'Bonjour', 't' => 'H'],
            'Play' => ['v' => 'Jouer', 't' => 'A'],
        ];
        $with = [
            '_uuid' => 'abc-123',
            'Hello' => ['v' => 'Bonjour', 't' => 'H', 'i' => 1],
            'Play' => ['v' => 'Jouer', 't' => 'A', 'i' => 2],
        ];

        $this->assertSame(
            $this->service->computeHash($without),
            $this->service->computeHash($with)
        );
    }

    public function test_hash_ignores_differing_indices_for_identical_content(): void
    {
        $deviceA = [
            '_uuid' => 'abc-123',
            'Hello' => ['v' => 'Bonjour', 't' => 'H', 'i' => 5],
        ];
        $deviceB = [
            '_uuid' => 'abc-123',
            'Hello' => ['v' => 'Bonjour', 't' => 'H', 'i' => 42],
        ];

        $this->assertSame(
            $this->service->computeHash($deviceA),
            $this->service->computeHash($deviceB)
        );
    }

    public function test_hash_unchanged_for_legacy_files_without_index(): void
    {
        // Guards backward compatibility: the v/t filtering must be strictly
        // neutral for existing files (no server-side hash migration needed).
        // This hash was produced by the pre-index implementation.
        $legacy = [
            '_uuid' => 'abc-123',
            'Hello' => ['v' => 'Bonjour', 't' => 'H'],
        ];
        $expected = hash('sha256', json_encode(
            ['Hello' => ['v' => 'Bonjour', 't' => 'H'], '_uuid' => 'abc-123'],
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        ));

        $this->assertSame($expected, $this->service->computeHash($legacy));
    }

    public function test_hash_still_changes_when_content_changes(): void
    {
        $before = ['_uuid' => 'abc-123', 'Hello' => ['v' => 'Bonjour', 't' => 'A', 'i' => 1]];
        $afterValue = ['_uuid' => 'abc-123', 'Hello' => ['v' => 'Salut', 't' => 'A', 'i' => 1]];
        $afterTag = ['_uuid' => 'abc-123', 'Hello' => ['v' => 'Bonjour', 't' => 'H', 'i' => 1]];

        $this->assertNotSame($this->service->computeHash($before), $this->service->computeHash($afterValue));
        $this->assertNotSame($this->service->computeHash($before), $this->service->computeHash($afterTag));
    }

    public function test_valid_index_passes_validation(): void
    {
        $json = [
            '_uuid' => 'abc-123',
            'Hello' => ['v' => 'Bonjour', 't' => 'H', 'i' => 1],
            'Play' => ['v' => 'Jouer', 't' => 'A', 'i' => TranslationService::MAX_ORDER_INDEX],
            'NoIndex' => ['v' => 'Sans index', 't' => 'A'],
        ];

        $this->assertSame([], $this->service->validateEntries($json));
    }

    public function test_rebuild_entry_preserves_order_index(): void
    {
        // Server-side rewrites (merge apply, tag change, edit-session save)
        // must carry "i" over from the previous entry
        $this->assertSame(
            ['v' => 'New', 't' => 'H', 'i' => 42],
            TranslationService::rebuildEntry(['v' => 'Old', 't' => 'A', 'i' => 42], 'New', 'H')
        );
        $this->assertSame(
            ['v' => 'New', 't' => 'H'],
            TranslationService::rebuildEntry(['v' => 'Old', 't' => 'A'], 'New', 'H')
        );
        $this->assertSame(
            ['v' => 'New', 't' => 'H'],
            TranslationService::rebuildEntry(null, 'New', 'H')
        );
    }

    public function test_invalid_index_fails_validation(): void
    {
        $cases = [
            'zero' => 0,
            'negative' => -5,
            'string' => '3',
            'float' => 1.5,
            'above safe range' => TranslationService::MAX_ORDER_INDEX + 1,
        ];

        foreach ($cases as $label => $bad) {
            $json = ['Hello' => ['v' => 'Bonjour', 't' => 'H', 'i' => $bad]];
            $this->assertNotEmpty(
                $this->service->validateEntries($json),
                "Index case '$label' should be rejected"
            );
        }
    }
}
