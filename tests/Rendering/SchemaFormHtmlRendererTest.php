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

namespace Milpa\Live\Tests\Rendering;

use Milpa\Live\Rendering\SchemaFormHtmlRenderer;
use Milpa\Live\Schema\FieldConstraints;
use Milpa\Live\Schema\FieldError;
use Milpa\Live\Schema\FieldType;
use Milpa\Live\Schema\FormDefinition;
use Milpa\Live\Schema\FormField;
use Milpa\Live\Schema\FormView;
use Milpa\Live\Schema\ValidationResult;
use PHPUnit\Framework\TestCase;

final class SchemaFormHtmlRendererTest extends TestCase
{
    private function definition(): FormDefinition
    {
        return new FormDefinition('settings:update', [
            new FormField('siteName', FieldType::Text, 'Site name', true, null, new FieldConstraints()),
            new FormField('maintenance', FieldType::Boolean, 'Maintenance', false, false, new FieldConstraints()),
            new FormField('theme', FieldType::Text, 'Theme', false, 'light', new FieldConstraints(enumOptions: ['light', 'dark'])),
        ]);
    }

    public function test_renders_a_widget_per_field_by_type(): void
    {
        $html = (new SchemaFormHtmlRenderer())->render(new FormView(
            $this->definition(),
            ['siteName' => 'Acme', 'maintenance' => true, 'theme' => 'dark'],
            new ValidationResult(true, []),
        ));

        self::assertStringContainsString('name="siteName"', $html);          // text input
        self::assertStringContainsString('type="checkbox"', $html);          // boolean -> checkbox
        self::assertStringContainsString('<select', $html);                   // enum -> select
        self::assertStringContainsString('dark', $html);                      // option / value
        self::assertStringContainsString('Acme', $html);                      // current value injected
    }

    public function test_injects_field_error(): void
    {
        $html = (new SchemaFormHtmlRenderer())->render(new FormView(
            $this->definition(),
            ['siteName' => '', 'maintenance' => false, 'theme' => 'light'],
            new ValidationResult(false, ['siteName' => [new FieldError('required', 'Site name is required.')]]),
        ));

        self::assertStringContainsString('Site name is required.', $html);   // error surfaced on the field
    }
}
