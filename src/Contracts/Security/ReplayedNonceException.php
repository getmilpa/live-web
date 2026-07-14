<?php

/**
 * This file is part of Milpa Live Web — the HTTP/HTML transport layer (security, transport, rendering) of the Milpa PHP framework live component system.
 *
 * (c) Rodrigo Vicente - TeamX Agency — https://teamx.agency <hola@teamx.agency>
 *
 * @license Apache-2.0
 *
 * @link    https://github.com/getmilpa/live-web
 */

declare(strict_types=1);

namespace Milpa\Live\Contracts\Security;

/**
 * Thrown by a signed {@see \Milpa\Live\Contracts\Transport\StateTransferCodecInterface}
 * decorator when an otherwise cryptographically valid envelope's nonce has
 * already been consumed by a prior call — i.e. the envelope was not
 * tampered with, but this exact signed request has already been acted on
 * once and is being replayed (captured and resubmitted, deliberately or
 * not). A `\RuntimeException` subtype specifically so callers such as
 * {@see \Milpa\Live\Http\LiveEndpoint} can distinguish "this request was
 * already processed" from an ordinary signature/tamper failure and report
 * a different status for it, while still satisfying the interface's
 * `@throws \RuntimeException` contract for callers that only care that
 * decoding failed.
 */
final class ReplayedNonceException extends \RuntimeException
{
}
