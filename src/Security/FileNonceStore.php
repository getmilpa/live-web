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

namespace Milpa\Live\Security;

use Milpa\Live\Contracts\Security\NonceStoreInterface;

/**
 * File-based {@see NonceStoreInterface}: a single JSON file mapping
 * nonce => expiry, guarded by `flock()` across the whole
 * read-prune-check-write cycle.
 *
 * Why `flock()` and not an atomic rename: consuming a nonce is a
 * read-modify-write (read the current set, prune expired entries, check
 * membership, write the updated set back). An atomic rename only makes
 * the final file swap atomic — it does nothing to stop two concurrent
 * callers from both reading the pre-write set and independently deciding
 * the same nonce is still fresh, which is exactly the race this store
 * exists to close. Holding `LOCK_EX` for the full read-modify-write cycle
 * serializes it correctly. For a single small JSON file with the lab's
 * request volume this is the smallest correct primitive; it would need
 * revisiting (e.g. sharding, a real datastore) under real concurrency.
 *
 * Pruning happens inline on every {@see consume()} call rather than via a
 * separate sweep process: entries whose `$expiresAt` is at or before the
 * current time are dropped before the incoming nonce is checked and
 * added, so the store cannot grow unbounded and never needs a cron job.
 */
final readonly class FileNonceStore implements NonceStoreInterface
{
    /**
     * @param string                 $path  Path to the JSON file backing this store; its directory is created
     *                                      on first use if missing.
     * @param (\Closure(): int)|null $clock Injectable clock for pruning tests; defaults to `time()`.
     */
    public function __construct(
        private string $path,
        private ?\Closure $clock = null,
    ) {
    }

    /**
     * Atomically checks whether `$nonce` has already been consumed and, if
     * not, records it (to expire at `$expiresAt`) in the same locked
     * critical section — returns `true` the first time a nonce is seen,
     * `false` on every subsequent replay. Expired entries are pruned inline
     * on every call, replayed or not.
     */
    public function consume(string $nonce, int $expiresAt): bool
    {
        $dir = dirname($this->path);
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new \RuntimeException("Unable to create nonce store directory: {$dir}");
        }

        $handle = fopen($this->path, 'c+');
        if ($handle === false) {
            throw new \RuntimeException("Unable to open nonce store file: {$this->path}");
        }

        try {
            if (!flock($handle, LOCK_EX)) {
                throw new \RuntimeException("Unable to lock nonce store file: {$this->path}");
            }

            $now = $this->clock !== null ? ($this->clock)() : time();
            $entries = $this->prune($this->read($handle), $now);

            if (array_key_exists($nonce, $entries)) {
                // Still write back the pruned set even on a rejected replay,
                // so a long-idle store doesn't wait for a fresh nonce to
                // shrink again.
                $this->write($handle, $entries);

                return false;
            }

            $entries[$nonce] = $expiresAt;
            $this->write($handle, $entries);

            return true;
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    /**
     * @return array<string, int>
     */
    private function read(mixed $handle): array
    {
        rewind($handle);
        $contents = stream_get_contents($handle);
        if (!is_string($contents) || trim($contents) === '') {
            return [];
        }

        $decoded = json_decode($contents, true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @param array<string, int> $entries
     *
     * @return array<string, int>
     */
    private function prune(array $entries, int $now): array
    {
        return array_filter($entries, static fn (mixed $entryExpiresAt): bool => (int) $entryExpiresAt > $now);
    }

    /**
     * @param array<string, int> $entries
     */
    private function write(mixed $handle, array $entries): void
    {
        $json = json_encode($entries, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        rewind($handle);
        ftruncate($handle, 0);
        fwrite($handle, $json);
        fflush($handle);
    }
}
