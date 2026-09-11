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

namespace Milpa\Live\Tests\Components;

use Milpa\Live\Components\WebLibrary;
use Milpa\Live\Contracts\Component\ComponentDefinitionInterface;
use Milpa\Live\Contracts\Component\DeclaresComponents;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * THE DECLARATION IS THE DIRECTORY — the guard that keeps a component from going unlisted again.
 *
 * `brand-mark` sat on disk with no catalogue row because nothing in this package implemented
 * `DeclaresComponents`. A hand-kept list would go stale the same way, so the list is asserted
 * against `ls src/Components` (greenhouse decisions/0214).
 */
#[CoversClass(WebLibrary::class)]
final class WebLibraryIsTheDirectoryTest extends TestCase
{
    /** Added and not declared, or declared and deleted — either turns this red. */
    public function testTheDeclarationIsExactlyTheComponentsOnDisk(): void
    {
        $declared = (new WebLibrary())->declaredComponents();
        $onDisk = self::concreteComponentClasses();

        sort($declared);
        sort($onDisk);

        self::assertSame($onDisk, $declared, 'declare every component under src/Components, and only those');
    }

    /** A declaration is a promise the catalogue can mount the class. */
    public function testEveryDeclaredClassIsAComponentDefinition(): void
    {
        foreach ((new WebLibrary())->declaredComponents() as $class) {
            self::assertTrue(
                is_subclass_of($class, ComponentDefinitionInterface::class),
                $class . ' is declared but is not a component definition',
            );
        }
    }

    /** The same interface a plugin uses — this package has no privileged path into the catalogue. */
    public function testItEntersTheCatalogueThroughThePublicInterface(): void
    {
        self::assertInstanceOf(DeclaresComponents::class, new WebLibrary());
    }

    /**
     * Every concrete component class under src/Components, by walking the directory.
     *
     * @return list<class-string<ComponentDefinitionInterface>>
     */
    private static function concreteComponentClasses(): array
    {
        $root = \dirname(__DIR__, 2) . '/src/Components';
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root));

        $found = [];
        foreach ($files as $file) {
            if (!$file instanceof \SplFileInfo || $file->getExtension() !== 'php') {
                continue;
            }

            $relative = substr($file->getPathname(), \strlen($root) + 1, -4);
            $class = 'Milpa\\Live\\Components\\' . str_replace('/', '\\', $relative);
            if (!class_exists($class)) {
                continue;
            }

            $reflection = new \ReflectionClass($class);
            if ($reflection->isAbstract() || !$reflection->implementsInterface(ComponentDefinitionInterface::class)) {
                continue;
            }

            $found[] = $class;
        }

        return $found;
    }
}
