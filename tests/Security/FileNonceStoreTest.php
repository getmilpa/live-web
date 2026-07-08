<?php

/**
 * This file is part of Milpa Live Web — the HTTP/HTML transport layer (security, transport, rendering) of the Milpa PHP framework live component system.
 *
 * (c) TeamX Agency — https://teamx.agency <hola@teamx.agency>
 *
 * @license Apache-2.0
 *
 * @link    https://github.com/getmilpa/live-web
 */

declare(strict_types=1);

namespace Milpa\Live\Tests\Security;

use Milpa\Live\Security\FileNonceStore;
use PHPUnit\Framework\TestCase;

/**
 * Converted from tests/smoke.php lines ~377-414 — prune behavior tested
 * directly (not through the full LiveEndpoint) with an injected clock so it
 * doesn't depend on real 300s TTLs.
 */
final class FileNonceStoreTest extends TestCase
{
    private string $path;

    protected function setUp(): void
    {
        $this->path = sys_get_temp_dir() . '/milpa-live-web-nonce-' . getmypid() . '-' . bin2hex(random_bytes(4)) . '.json';
        if (is_file($this->path)) {
            unlink($this->path);
        }
    }

    protected function tearDown(): void
    {
        if (is_file($this->path)) {
            unlink($this->path);
        }
    }

    /** PINNED: replay -> reject at the nonce-store layer. */
    public function testConsumeRejectsASecondConsumptionOfTheSameNonce(): void
    {
        $clock = 1_000_000;
        $store = new FileNonceStore($this->path, static fn (): int => $clock);

        self::assertTrue($store->consume('nonce-a', $clock + 300), 'first consume must succeed');
        self::assertFalse($store->consume('nonce-a', $clock + 300), 'second consume of the same nonce must be rejected as a replay');
    }

    public function testConsumePrunesExpiredEntriesBeforeCheckingMembership(): void
    {
        $clock = 1_000_000;
        // A by-reference closure, deliberately not `static fn () => $clock`: arrow
        // functions snapshot captured variables by value at creation time, so
        // mutating $clock afterward would never be seen by the store.
        $store = new FileNonceStore($this->path, function () use (&$clock): int {
            return $clock;
        });

        self::assertTrue($store->consume('nonce-a', $clock + 300));
        for ($i = 0; $i < 5; $i++) {
            self::assertTrue($store->consume("nonce-short-{$i}", $clock + 10));
        }

        $beforePrune = json_decode((string) file_get_contents($this->path), true);
        self::assertCount(6, $beforePrune, 'expected 6 entries (nonce-a + 5 short-lived) before pruning');

        $clock += 1000; // past every entry's expiresAt, including nonce-a's
        self::assertTrue($store->consume('nonce-b', $clock + 300));

        $afterPrune = json_decode((string) file_get_contents($this->path), true);
        self::assertCount(1, $afterPrune, 'expected pruning to drop all expired entries');
        self::assertArrayHasKey('nonce-b', $afterPrune);
    }

    public function testAnExpiredAndPrunedNonceCanBeConsumedAgain(): void
    {
        $clock = 1_000_000;
        $store = new FileNonceStore($this->path, function () use (&$clock): int {
            return $clock;
        });

        self::assertTrue($store->consume('nonce-a', $clock + 10));
        $clock += 1000; // past nonce-a's expiry
        // Consuming a different nonce triggers the prune sweep that drops nonce-a.
        self::assertTrue($store->consume('nonce-c', $clock + 300));

        self::assertTrue(
            $store->consume('nonce-a', $clock + 300),
            'a since-expired nonce must be consumable again after pruning — this store forgets on purpose',
        );
    }
}
