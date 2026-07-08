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

namespace Milpa\Live\Tests\Fixtures;

use Milpa\Interfaces\Event\MilpaEventDispatcherInterface;

/**
 * Exact-match-only in-memory {@see MilpaEventDispatcherInterface} test double
 * (no wildcard subscription support — this suite never needs it). Records
 * every dispatch verbatim so assertions can inspect exactly what fired, in
 * order. Ported from the lab's `tests/smoke.php` `SmokeEventRecorder`.
 */
final class RecordingEventDispatcher implements MilpaEventDispatcherInterface
{
    /** @var array<string, array<int, array{priority: int, handler: callable}>> */
    private array $subscribers = [];

    /** @var array<int, array{name: string, payload: array<string, mixed>}> */
    public array $dispatched = [];

    public function dispatch(string $eventName, array $payload = [], bool $async = false): void
    {
        $this->dispatched[] = ['name' => $eventName, 'payload' => $payload];
        foreach ($this->getSubscribers($eventName) as $handler) {
            $handler($eventName, $payload);
        }
    }

    public function subscribe(string $eventName, callable $handler, int $priority = 0): void
    {
        $this->subscribers[$eventName][] = ['priority' => $priority, 'handler' => $handler];
    }

    public function getSubscribers(string $eventName): array
    {
        $entries = $this->subscribers[$eventName] ?? [];
        usort($entries, static fn (array $a, array $b): int => $b['priority'] <=> $a['priority']);

        return array_map(static fn (array $entry): callable => $entry['handler'], $entries);
    }

    public function hasSubscribers(string $eventName): bool
    {
        return $this->getSubscribers($eventName) !== [];
    }

    /**
     * @return array<int, array{name: string, payload: array<string, mixed>}>
     */
    public function named(string $name): array
    {
        return array_values(array_filter($this->dispatched, static fn (array $entry): bool => $entry['name'] === $name));
    }
}
