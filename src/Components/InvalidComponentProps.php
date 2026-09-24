<?php

/**
 * This file is part of milpa/live-web.
 *
 * (c) Rodrigo Vicente - TeamX Agency — https://teamx.agency <hola@teamx.agency>
 *
 * @license Apache-2.0
 *
 * @link    https://github.com/getmilpa/live-web
 */

declare(strict_types=1);

namespace Milpa\Live\Components;

/**
 * The props a component was given cannot be painted as declared — named, so a host answers 422 with the
 * reason instead of a 500, or a page that looks empty and reads as «nothing here».
 *
 * `$path` points at the failing prop the way a screen declaration names it (`props.roles.body`), so the
 * refusal says WHERE as well as why.
 */
final class InvalidComponentProps extends \InvalidArgumentException
{
    public function __construct(public readonly string $path, string $reason)
    {
        parent::__construct($reason);
    }
}
