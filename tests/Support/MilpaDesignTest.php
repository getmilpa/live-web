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

namespace Milpa\Live\Tests\Support;

use Milpa\Live\Support\MilpaDesign;
use PHPUnit\Framework\TestCase;

/**
 * Converted (trimmed) from tests/smoke.php lines ~1282-1295. The lab's own
 * assertions there check ITS `node_modules/@milpa/design` npm install and
 * demo-wiring files (`public/milpa-design.css.php`, `package.json`) — those
 * are lab-specific per the partition doc §5 and stay in the lab. This suite
 * instead exercises `MilpaDesign`'s own behavior in isolation, honestly,
 * without assuming an npm install exists inside this package.
 */
final class MilpaDesignTest extends TestCase
{
    private ?string $previousOverride = null;
    private ?string $tempDesignDir = null;

    protected function setUp(): void
    {
        $current = getenv('MILPA_DESIGN_PATH');
        $this->previousOverride = $current === false ? null : $current;
        putenv('MILPA_DESIGN_PATH'); // unset for the default-source tests
    }

    protected function tearDown(): void
    {
        if ($this->previousOverride === null) {
            putenv('MILPA_DESIGN_PATH');
        } else {
            putenv('MILPA_DESIGN_PATH=' . $this->previousOverride);
        }

        if ($this->tempDesignDir !== null && is_dir($this->tempDesignDir)) {
            rmdir($this->tempDesignDir);
        }
    }

    public function testContractFormatsARelativePathAsAMilpaDesignReference(): void
    {
        self::assertSame('@milpa/design:dist/milpa-tokens.css', MilpaDesign::contract('dist/milpa-tokens.css'));
        self::assertSame('@milpa/design:dist/milpa-tokens.css', MilpaDesign::contract('/dist/milpa-tokens.css'), 'a leading slash must be stripped');
    }

    public function testSourceDefaultsToTheNpmPackageWhenNoOverrideIsSet(): void
    {
        self::assertSame('npm:@milpa/design', MilpaDesign::source());
    }

    public function testSourceReportsTheEnvOverrideWhenSet(): void
    {
        $this->tempDesignDir = sys_get_temp_dir() . '/milpa-design-test-' . bin2hex(random_bytes(6));
        mkdir($this->tempDesignDir);
        putenv('MILPA_DESIGN_PATH=' . $this->tempDesignDir);

        self::assertSame('env:MILPA_DESIGN_PATH', MilpaDesign::source());
        self::assertSame(rtrim($this->tempDesignDir, '/'), MilpaDesign::path());
    }

    public function testPathThrowsWhenNeitherAnOverrideNorAnInstalledPackageExists(): void
    {
        // No MILPA_DESIGN_PATH override, and this package ships no
        // node_modules/@milpa/design of its own -> path() must fail loudly
        // rather than silently resolving to a nonexistent directory.
        $this->expectException(\RuntimeException::class);
        MilpaDesign::path();
    }

    public function testPathThrowsWhenTheOverrideDoesNotExist(): void
    {
        putenv('MILPA_DESIGN_PATH=/definitely/does/not/exist/anywhere');

        $this->expectException(\RuntimeException::class);
        MilpaDesign::path();
    }

    public function testCssFilesEnumeratesTheExpectedRelativeKeys(): void
    {
        $this->tempDesignDir = sys_get_temp_dir() . '/milpa-design-test-' . bin2hex(random_bytes(6));
        mkdir($this->tempDesignDir);
        putenv('MILPA_DESIGN_PATH=' . $this->tempDesignDir);

        $files = MilpaDesign::cssFiles();

        self::assertArrayHasKey('dist/milpa-tokens.css', $files);
        self::assertSame($this->tempDesignDir . '/dist/milpa-tokens.css', $files['dist/milpa-tokens.css']);
    }
}
