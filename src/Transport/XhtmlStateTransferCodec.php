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

namespace Milpa\Live\Transport;

use DOMDocument;
use DOMElement;
use Milpa\Live\Contracts\Transport\StateTransferCodecInterface;
use Milpa\Live\ValueObjects\InteractionRequest;
use Milpa\Live\ValueObjects\StateSnapshot;

/**
 * The plain (unsigned) {@see StateTransferCodecInterface}: encodes a
 * {@see StateSnapshot} / {@see InteractionRequest} as a single
 * `<milpa-state>` / `<milpa-interaction>` XHTML element carrying a
 * base64-encoded JSON payload, and decodes it back. Tamper-evidence and
 * replay protection are NOT provided here — this codec is the `$inner`
 * layer {@see \Milpa\Live\Security\SignedXhtmlStateTransferCodec} wraps to
 * add HMAC signing on top; use the signed codec directly whenever the
 * envelope will round-trip through an untrusted client.
 */
final class XhtmlStateTransferCodec implements StateTransferCodecInterface
{
    /**
     * Encodes `$snapshot` as a `<milpa-state>` element whose text content is
     * the base64-encoded JSON of its id/name/version/data/meta.
     */
    public function encodeState(StateSnapshot $snapshot): string
    {
        $payload = $this->encodePayload($this->snapshotToArray($snapshot));

        return sprintf(
            '<milpa-state component-id="%s" component="%s" version="%s" encoding="json.base64">%s</milpa-state>',
            $this->escape($snapshot->componentId),
            $this->escape($snapshot->componentName),
            $this->escape($snapshot->version),
            $payload,
        );
    }

    /**
     * Parses `$encoded` as a single `<milpa-state>` XML element and decodes
     * its payload back into a {@see StateSnapshot}. Throws
     * `RuntimeException` for malformed XML, a wrong root tag, or invalid
     * base64/JSON in the payload.
     */
    public function decodeState(string $encoded): StateSnapshot
    {
        $node = $this->loadSingleElement($encoded, 'milpa-state');
        $data = $this->decodePayload($node->textContent);

        return $this->snapshotFromArray($data);
    }

    /**
     * Encodes `$request` as a `<milpa-interaction>` element whose text
     * content is the base64-encoded JSON of its id/name/action/state/payload/meta.
     */
    public function encodeInteraction(InteractionRequest $request): string
    {
        $payload = $this->encodePayload([
            'componentId' => $request->componentId,
            'componentName' => $request->componentName,
            'action' => $request->action,
            'state' => $this->snapshotToArray($request->state),
            'payload' => $request->payload,
            'meta' => $request->meta,
        ]);

        return sprintf(
            '<milpa-interaction component-id="%s" component="%s" action="%s" encoding="json.base64">%s</milpa-interaction>',
            $this->escape($request->componentId),
            $this->escape($request->componentName),
            $this->escape($request->action),
            $payload,
        );
    }

    /**
     * Parses `$encoded` as a single `<milpa-interaction>` XML element and
     * decodes its payload back into an {@see InteractionRequest}. Throws
     * `RuntimeException` for malformed XML, a wrong root tag, or invalid
     * base64/JSON in the payload.
     */
    public function decodeInteraction(string $encoded): InteractionRequest
    {
        $node = $this->loadSingleElement($encoded, 'milpa-interaction');
        $data = $this->decodePayload($node->textContent);

        return new InteractionRequest(
            componentId: (string) $data['componentId'],
            componentName: (string) $data['componentName'],
            action: (string) $data['action'],
            state: $this->snapshotFromArray($data['state']),
            payload: is_array($data['payload'] ?? null) ? $data['payload'] : [],
            meta: is_array($data['meta'] ?? null) ? $data['meta'] : [],
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function snapshotToArray(StateSnapshot $snapshot): array
    {
        return [
            'componentId' => $snapshot->componentId,
            'componentName' => $snapshot->componentName,
            'version' => $snapshot->version,
            'data' => $snapshot->data,
            'meta' => $snapshot->meta,
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    private function snapshotFromArray(array $data): StateSnapshot
    {
        return new StateSnapshot(
            componentId: (string) $data['componentId'],
            componentName: (string) $data['componentName'],
            version: (string) $data['version'],
            data: is_array($data['data'] ?? null) ? $data['data'] : [],
            meta: is_array($data['meta'] ?? null) ? $data['meta'] : [],
        );
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function encodePayload(array $payload): string
    {
        return base64_encode(json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    /**
     * @return array<string, mixed>
     */
    private function decodePayload(string $payload): array
    {
        $decoded = base64_decode(trim($payload), true);
        if ($decoded === false) {
            throw new \RuntimeException('Invalid base64 payload in Milpa transfer envelope.');
        }

        $data = json_decode($decoded, true, flags: JSON_THROW_ON_ERROR);
        if (!is_array($data)) {
            throw new \RuntimeException('Invalid JSON payload in Milpa transfer envelope.');
        }

        return $data;
    }

    private function loadSingleElement(string $encoded, string $tag): DOMElement
    {
        $document = new DOMDocument('1.0', 'UTF-8');
        $previous = libxml_use_internal_errors(true);

        try {
            $ok = $document->loadXML('<?xml version="1.0" encoding="UTF-8"?>' . trim($encoded));
            if (!$ok || $document->documentElement === null) {
                throw new \RuntimeException("Invalid XHTML transfer envelope: {$tag}");
            }

            // $document->documentElement is declared ?DOMElement; the null
            // branch already threw above, so $root is provably a DOMElement
            // here -- only the tag name still needs checking.
            $root = $document->documentElement;
            if ($root->tagName !== $tag) {
                throw new \RuntimeException("Expected <{$tag}> transfer envelope.");
            }

            return $root;
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
