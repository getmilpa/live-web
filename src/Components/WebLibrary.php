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

use Milpa\Live\Contracts\Component\DeclaresComponents;

/**
 * THE PRIMITIVES THIS PACKAGE BRINGS — declared the way any plugin declares its own.
 *
 * 🚨 THIS CLASS EXISTS BECAUSE THIS PACKAGE'S COMPONENTS WERE INVISIBLE TO THE CATALOGUE.
 *
 * `components:catalogue` answers from `DeclaresComponents`, and only `milpa/live`'s {@see Library}
 * and the booted plugins implement it — so the two components that live HERE were never listed.
 * `brand-mark` had been on disk since it was written and no catalogue row ever said so. That is the
 * exact debt the catalogue was built to kill: the agent stops inventing components only if it can
 * SEE the ones that exist, and a component nothing enumerates is a component that gets written a
 * second time (greenhouse decisions/0214, and the rule from decisions/0213 — a piece built and not
 * wired is debt that looks like a capability).
 *
 * It is a SECOND library rather than rows added to the first because the two packages ship
 * separately: `milpa/live` is render-target-agnostic and its primitives have a TUI renderer as well
 * as an HTML one, while everything here is HTML-flavoured and would be a lie in a TUI catalogue.
 * Two declarers, one interface, no privileged path for either.
 */
final class WebLibrary implements DeclaresComponents
{
    /**
     * The HTML-flavoured primitives under `src/Components` — asserted against the directory.
     *
     * {@see \Milpa\Live\Tests\Components\WebLibraryIsTheDirectoryTest} turns red when a component is
     * added here and not declared, or declared and deleted. Without that test this is a hand-kept
     * copy of `ls src/Components`, which is how the two below went years without a catalogue row.
     *
     * @return list<class-string>
     */
    public function declaredComponents(): array
    {
        return [
            BrandMarkComponent::class,
            CodeBlockComponent::class,
        ];
    }
}
