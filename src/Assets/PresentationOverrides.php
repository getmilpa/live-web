<?php

/**
 * This file is part of Milpa Live Web — the HTTP/HTML transport layer of the Milpa PHP framework.
 *
 * (c) Rodrigo Vicente - TeamX Agency — https://teamx.agency <hola@teamx.agency>
 *
 * @license Apache-2.0
 *
 * @link    https://github.com/getmilpa/live-web
 */

declare(strict_types=1);

namespace Milpa\Live\Assets;

use Milpa\Live\ValueObjects\ComponentPresentation;

/**
 * What somebody was AUTHORIZED to add on top of a component they did not write.
 *
 * Contributing a component and overriding one are two different acts, and only the second one spends
 * authority. Shipping your own is construction, and construction concedes nothing: install the
 * package and it is there. Changing what SOMEBODY ELSE'S component looks like is an effect on a
 * resource that is not yours, so it does not happen because a package was installed — it happens
 * because a human said so, once, and the record of that saying is what an implementation of this
 * reads back (greenhouse `decisions/0240` invariant 1, `decisions/0246` §2).
 *
 * This transport deliberately knows NOTHING about what an authorization is, where it is kept, or who
 * may give one. It asks a question and honours the answer. That is what lets the governing half live
 * in the package that has the operation machinery while the effect lives here — and it is why the
 * default is no implementation at all: with nobody to ask, there are no overrides, and a house that
 * never wired one cannot be surprised by one.
 */
interface PresentationOverrides
{
    /**
     * What was authorized on top of this component, or `null` when nothing was.
     *
     * The returned presentation is ADDITIVE, not a replacement: its stylesheet is emitted after the
     * component's own, so the cascade does the overriding and an override can be three lines instead
     * of a reproduction of three hundred. Its messages lay over the component's by key.
     */
    public function forComponent(string $component): ?ComponentPresentation;
}
