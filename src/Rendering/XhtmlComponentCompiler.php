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

namespace Milpa\Live\Rendering;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMText;
use Milpa\Live\Contracts\Component\ComponentRegistryInterface;
use Milpa\Live\Contracts\Rendering\ComponentRendererInterface;
use Milpa\Live\Contracts\Rendering\DeclaresClientAssets;
use Milpa\Live\Contracts\Rendering\MarkupCompilerInterface;
use Milpa\Live\Support\Html;
use Milpa\Live\ValueObjects\ClientAssets;
use Milpa\Live\ValueObjects\ComponentContext;
use Milpa\Live\ValueObjects\RenderRequest;
use Milpa\Live\ValueObjects\RenderResult;
use Milpa\Live\ValueObjects\RenderTarget;

/**
 * Compiles a fragment of `<milpa:*>` / `<milpa-*>` XHTML markup into rendered
 * HTML by parsing it as XML, resolving each Milpa element to its registered
 * component + renderer, and recursively rendering non-Milpa children as
 * plain escaped/passthrough markup. This is the bridge between authoring
 * component trees as markup and the {@see ComponentRendererInterface}
 * pipeline every renderer in this package implements.
 *
 * Declared views (greenhouse decisions/0211): every renderer met while
 * compiling — root or nested — that implements {@see DeclaresClientAssets}
 * contributes its {@see ClientAssets}; they are merged (deduplicated by URL,
 * first-seen order) into the result's {@see RenderResult::clientAssets()},
 * so the host emits each file once through `LiveBoot::html()`. The legacy
 * string-keyed {@see RenderResult::$assets} bag keeps its `array_merge`
 * semantics untouched.
 */
final readonly class XhtmlComponentCompiler implements MarkupCompilerInterface
{
    /**
     * @param array<string, ComponentRendererInterface>|ComponentRendererRegistry $renderers component name => renderer, or a registry answering {@see ComponentRendererRegistry::resolveFor()} per name (its `registerFor()`ed renderers only — like the array, no fallback)
     * @param array<string, array<string, mixed>>                                 $defaults
     */
    public function __construct(
        private ComponentRegistryInterface $components,
        private array|ComponentRendererRegistry $renderers,
        private array $defaults = [],
    ) {
    }

    /**
     * Compiles markup that MUST contain exactly one root Milpa component
     * element; throws `RuntimeException` for zero or more than one root.
     */
    public function compile(string $markup, ComponentContext $context): RenderResult
    {
        $nodes = $this->parseComponents($markup);
        if (count($nodes) !== 1) {
            throw new \RuntimeException('Expected exactly one root Milpa component element.');
        }

        $declared = ClientAssets::empty();
        $rendered = $this->renderNode($nodes[0], $context, $declared);

        return new RenderResult(
            output: $rendered->output,
            state: $rendered->state,
            assets: $rendered->assets,
            effects: $rendered->effects,
            format: $rendered->format,
            clientAssets: $declared,
        );
    }

    /**
     * Compiles markup that may contain multiple sibling root Milpa component
     * elements, concatenating their rendered output. The returned
     * {@see RenderResult}'s `state`/`format` are taken from the first node
     * rendered; `assets` are merged across every node (`array_merge`, legacy
     * string keys), and the declared {@see ClientAssets} of every renderer
     * met are merged by URL.
     */
    public function compileFragment(string $markup, ComponentContext $context): RenderResult
    {
        $nodes = $this->parseComponents($markup);
        $output = [];
        $assets = [];
        $declared = ClientAssets::empty();
        $state = null;
        $format = null;

        foreach ($nodes as $node) {
            $rendered = $this->renderNode($node, $context, $declared);
            $output[] = $rendered->output;
            $assets = array_merge($assets, $rendered->assets);
            $state ??= $rendered->state;
            $format ??= $rendered->format;
        }

        return new RenderResult(
            output: implode("\n", $output),
            state: $state,
            assets: $assets,
            format: $format ?? RenderTarget::HTML,
            clientAssets: $declared,
        );
    }

    private function renderNode(DOMElement $node, ComponentContext $context, ClientAssets &$declared): RenderResult
    {
        $componentName = $this->componentName($node);

        if (!$this->components->has($componentName)) {
            throw new \RuntimeException("No component registered for <{$node->tagName}>.");
        }

        $renderer = $this->rendererFor($componentName);
        if ($renderer === null) {
            throw new \RuntimeException("No renderer registered for component: {$componentName}");
        }

        // Document order: the outer component's files before its children's, siblings left to right.
        if ($renderer instanceof DeclaresClientAssets) {
            $declared = $declared->merge($renderer->clientAssets());
        }

        $component = $this->components->get($componentName);
        $props = array_merge($this->defaults[$componentName] ?? [], $this->props($node));
        $children = $this->renderChildren($node, $context, $declared);
        if (trim($children) !== '') {
            $props['childrenHtml'] = $children;
            $props['childrenOutput'] = $children;
        }
        $componentId = (string) ($props['id'] ?? $props['name'] ?? $context->componentId . '-' . $componentName . '-' . substr(sha1($node->getNodePath() ?: $node->tagName), 0, 8));

        $rendered = $renderer->render($component, new RenderRequest(
            context: new ComponentContext(
                componentId: $componentId,
                principal: $context->principal,
                locale: $context->locale,
                route: $context->route,
                meta: $context->meta,
            ),
            props: $props,
        ));
        $declared = $declared->merge($rendered->clientAssets());

        return $rendered;
    }

    private function rendererFor(string $componentName): ?ComponentRendererInterface
    {
        if ($this->renderers instanceof ComponentRendererRegistry) {
            return $this->renderers->resolveFor($componentName, RenderTarget::HTML);
        }

        return $this->renderers[$componentName] ?? null;
    }

    /**
     * @return array<int, DOMElement>
     */
    private function parseComponents(string $markup): array
    {
        $document = new DOMDocument('1.0', 'UTF-8');
        $previous = libxml_use_internal_errors(true);

        try {
            $wrapped = '<?xml version="1.0" encoding="UTF-8"?><root xmlns:milpa="urn:milpa:components">' . trim($markup) . '</root>';
            $ok = $document->loadXML($wrapped);

            if (!$ok || $document->documentElement === null) {
                throw new \RuntimeException('Invalid component XHTML markup.');
            }

            $elements = [];
            foreach ($document->documentElement->childNodes as $child) {
                if ($child instanceof DOMElement) {
                    $elements[] = $child;
                }
            }

            if ($elements === []) {
                throw new \RuntimeException('Expected at least one root Milpa component element.');
            }

            return $elements;
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }

    private function componentName(DOMElement $node): string
    {
        if ($node->prefix === 'milpa') {
            return $node->localName;
        }

        if (str_starts_with($node->tagName, 'milpa-')) {
            return substr($node->tagName, strlen('milpa-'));
        }

        throw new \RuntimeException("Expected a Milpa component tag, got <{$node->tagName}>.");
    }

    private function isMilpaElement(DOMElement $node): bool
    {
        return $node->prefix === 'milpa' || str_starts_with($node->tagName, 'milpa-');
    }

    private function renderChildren(DOMElement $node, ComponentContext $context, ClientAssets &$declared): string
    {
        $html = [];
        foreach ($node->childNodes as $child) {
            $html[] = $this->renderChild($child, $context, $declared);
        }

        return implode('', $html);
    }

    private function renderChild(DOMNode $node, ComponentContext $context, ClientAssets &$declared): string
    {
        if ($node instanceof DOMText) {
            return Html::escape($node->nodeValue ?? '');
        }

        if (!$node instanceof DOMElement) {
            return '';
        }

        if ($this->isMilpaElement($node)) {
            return $this->renderNode($node, $context, $declared)->output;
        }

        $attributes = [];
        foreach ($node->attributes as $attribute) {
            $attributes[(string) $attribute->nodeName] = (string) $attribute->nodeValue;
        }

        $attrs = Html::attrs($attributes);
        $open = '<' . $node->tagName . ($attrs !== '' ? ' ' . $attrs : '') . '>';
        $close = '</' . $node->tagName . '>';

        return $open . $this->renderChildren($node, $context, $declared) . $close;
    }

    /**
     * @return array<string, mixed>
     */
    private function props(DOMElement $node): array
    {
        $props = [];
        foreach ($node->attributes as $attribute) {
            $props[$this->camel((string) $attribute->nodeName)] = $attribute->nodeValue;
        }

        return $props;
    }

    private function camel(string $name): string
    {
        return preg_replace_callback(
            '/-([a-z])/',
            static fn (array $match): string => strtoupper($match[1]),
            $name,
        ) ?? $name;
    }
}
