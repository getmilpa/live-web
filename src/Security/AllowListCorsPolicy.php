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

use Milpa\Live\Contracts\Security\CorsPolicyInterface;
use Milpa\Live\ValueObjects\CorsDecision;

/**
 * Static allow-list {@see CorsPolicyInterface}: a request is allowed only if
 * its method, `Origin`, and every requested header are each on their
 * respective configured allow-list. A missing/empty `Origin` (same-origin or
 * non-browser request) is always allowed — CORS only governs cross-origin
 * browser requests.
 */
final readonly class AllowListCorsPolicy implements CorsPolicyInterface
{
    /**
     * @param array<int, string> $allowedOrigins
     * @param array<int, string> $allowedMethods
     * @param array<int, string> $allowedHeaders
     */
    public function __construct(
        private array $allowedOrigins,
        private array $allowedMethods = ['GET', 'POST', 'OPTIONS'],
        private array $allowedHeaders = ['content-type', 'authorization', 'x-csrf-token'],
        private bool $allowCredentials = false,
        private int $maxAge = 600,
    ) {
    }

    /**
     * Evaluates one CORS preflight/request against the configured allow-lists.
     * A denial carries a machine-readable `reason` (`method_not_allowed`,
     * `origin_not_allowed`, `header_not_allowed`); an allowed cross-origin
     * request carries the response headers the caller should emit.
     */
    public function check(?string $origin, string $method, array $requestHeaders = []): CorsDecision
    {
        $method = strtoupper($method);
        if (!in_array($method, $this->allowedMethods, true)) {
            return new CorsDecision(false, reason: 'method_not_allowed');
        }

        if ($origin === null || $origin === '') {
            return new CorsDecision(true);
        }

        if (!in_array($origin, $this->allowedOrigins, true)) {
            return new CorsDecision(false, reason: 'origin_not_allowed');
        }

        $allowedHeaders = array_map('strtolower', $this->allowedHeaders);
        foreach ($requestHeaders as $header) {
            if (!in_array(strtolower($header), $allowedHeaders, true)) {
                return new CorsDecision(false, reason: 'header_not_allowed');
            }
        }

        $headers = [
            'Access-Control-Allow-Origin' => $origin,
            'Access-Control-Allow-Methods' => implode(', ', $this->allowedMethods),
            'Access-Control-Allow-Headers' => implode(', ', $this->allowedHeaders),
            'Access-Control-Max-Age' => (string) $this->maxAge,
            'Vary' => 'Origin',
        ];

        if ($this->allowCredentials) {
            $headers['Access-Control-Allow-Credentials'] = 'true';
        }

        return new CorsDecision(true, $headers);
    }
}
