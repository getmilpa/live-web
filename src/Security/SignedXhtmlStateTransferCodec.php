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

namespace Milpa\Live\Security;

use DOMDocument;
use DOMElement;
use Milpa\Live\Contracts\Security\NonceStoreInterface;
use Milpa\Live\Contracts\Security\ReplayedNonceException;
use Milpa\Live\Contracts\Security\StateSignerInterface;
use Milpa\Live\Contracts\Transport\StateTransferCodecInterface;
use Milpa\Live\ValueObjects\InteractionRequest;
use Milpa\Live\ValueObjects\StateSignature;
use Milpa\Live\ValueObjects\StateSnapshot;

/**
 * Tamper-evident, optionally replay-protected {@see StateTransferCodecInterface}:
 * wraps an inner unsigned codec (typically {@see \Milpa\Live\Transport\XhtmlStateTransferCodec}),
 * signing its output with a {@see StateSignerInterface} and, on decode,
 * verifying the signature before delegating back to the inner codec. This is
 * the codec {@see \Milpa\Live\Http\LiveEndpoint} trusts to prove a
 * client-echoed `<milpa-state>` envelope hasn't been altered since this
 * server last signed it — see the endpoint's trust-model docblock.
 */
final readonly class SignedXhtmlStateTransferCodec implements StateTransferCodecInterface
{
    /**
     * @param NonceStoreInterface|null $nonces When given, every successfully signature-verified
     *                                         envelope has its `sig-nonce` consumed against this
     *                                         store before decoding proceeds — a second decode of
     *                                         the exact same signed envelope (a captured, replayed
     *                                         request) throws {@see ReplayedNonceException} instead
     *                                         of succeeding a second time. `null` preserves the
     *                                         original tamper-evidence-only behavior (no replay
     *                                         protection), e.g. for isolated codec unit tests.
     */
    public function __construct(
        private StateTransferCodecInterface $inner,
        private StateSignerInterface $signer,
        private ?NonceStoreInterface $nonces = null,
    ) {
    }

    /**
     * Encodes `$snapshot` via the inner codec, then signs the resulting
     * envelope with `kind`/`componentId`/`componentName` claims bound in.
     */
    public function encodeState(StateSnapshot $snapshot): string
    {
        return $this->signEnvelope($this->inner->encodeState($snapshot), [
            'kind' => 'state',
            'componentId' => $snapshot->componentId,
            'componentName' => $snapshot->componentName,
        ]);
    }

    /**
     * Verifies the envelope's signature (and, when a {@see NonceStoreInterface}
     * is configured, consumes its nonce — a second decode of the exact same
     * envelope throws {@see ReplayedNonceException}), then delegates to the
     * inner codec. Throws `RuntimeException` on an invalid signature or a
     * component id/name claim mismatch against the decoded snapshot.
     */
    public function decodeState(string $encoded): StateSnapshot
    {
        [$unsigned, $signature] = $this->verifyEnvelope($encoded, ['kind' => 'state']);
        $snapshot = $this->inner->decodeState($unsigned);

        if (($signature->claims['componentId'] ?? null) !== $snapshot->componentId) {
            throw new \RuntimeException('Signed Milpa state component id claim mismatch.');
        }

        if (($signature->claims['componentName'] ?? null) !== $snapshot->componentName) {
            throw new \RuntimeException('Signed Milpa state component name claim mismatch.');
        }

        return $snapshot;
    }

    /**
     * Encodes `$request` via the inner codec, then signs the resulting
     * envelope with `kind`/`componentId`/`componentName`/`action` claims
     * bound in.
     */
    public function encodeInteraction(InteractionRequest $request): string
    {
        return $this->signEnvelope($this->inner->encodeInteraction($request), [
            'kind' => 'interaction',
            'componentId' => $request->componentId,
            'componentName' => $request->componentName,
            'action' => $request->action,
        ]);
    }

    /**
     * Verifies the envelope's signature (and consumes its nonce when replay
     * protection is configured, as in {@see decodeState()}), then delegates
     * to the inner codec. Throws `RuntimeException` on an invalid signature
     * or a component id/name/action claim mismatch against the decoded
     * request.
     */
    public function decodeInteraction(string $encoded): InteractionRequest
    {
        [$unsigned, $signature] = $this->verifyEnvelope($encoded, ['kind' => 'interaction']);
        $request = $this->inner->decodeInteraction($unsigned);

        if (($signature->claims['componentId'] ?? null) !== $request->componentId) {
            throw new \RuntimeException('Signed Milpa interaction component id claim mismatch.');
        }

        if (($signature->claims['componentName'] ?? null) !== $request->componentName) {
            throw new \RuntimeException('Signed Milpa interaction component name claim mismatch.');
        }

        if (($signature->claims['action'] ?? null) !== $request->action) {
            throw new \RuntimeException('Signed Milpa interaction action claim mismatch.');
        }

        return $request;
    }

    /**
     * @param array<string, mixed> $claims
     */
    private function signEnvelope(string $encoded, array $claims): string
    {
        $node = $this->loadSingleElement($encoded);
        $signature = $this->signer->sign($this->canonicalEnvelope($node), $claims);

        $node->setAttribute('security', 'signed');
        foreach ($signature->toAttributes() as $name => $value) {
            $node->setAttribute($name, (string) $value);
        }

        return $this->saveElement($node);
    }

    /**
     * @param array<string, mixed> $requiredClaims
     *
     * @return array{0: string, 1: StateSignature}
     */
    private function verifyEnvelope(string $encoded, array $requiredClaims): array
    {
        $node = $this->loadSingleElement($encoded);
        $signature = StateSignature::fromAttributes($this->signatureAttributes($node));

        if (!$this->signer->verify($this->canonicalEnvelope($node), $signature, $requiredClaims)) {
            throw new \RuntimeException('Invalid Milpa XHTML state signature.');
        }

        if ($this->nonces !== null && !$this->nonces->consume($signature->nonce, $signature->expiresAt)) {
            throw new ReplayedNonceException('Milpa XHTML state signature nonce has already been consumed.');
        }

        $this->removeSignatureAttributes($node);

        return [$this->saveElement($node), $signature];
    }

    private function canonicalEnvelope(DOMElement $node): string
    {
        $attributes = [];
        foreach ($node->attributes as $attribute) {
            $name = (string) $attribute->nodeName;
            if ($this->isSignatureAttribute($name)) {
                continue;
            }

            $attributes[$name] = (string) $attribute->nodeValue;
        }
        ksort($attributes);

        return json_encode([
            'tag' => $node->tagName,
            'attributes' => $attributes,
            'payload' => trim($node->textContent),
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /**
     * @return array<string, string>
     */
    private function signatureAttributes(DOMElement $node): array
    {
        $attributes = [];
        foreach ($node->attributes as $attribute) {
            $name = (string) $attribute->nodeName;
            if ($this->isSignatureAttribute($name)) {
                $attributes[$name] = (string) $attribute->nodeValue;
            }
        }

        return $attributes;
    }

    private function removeSignatureAttributes(DOMElement $node): void
    {
        foreach (array_keys($this->signatureAttributes($node)) as $attribute) {
            $node->removeAttribute($attribute);
        }
        $node->removeAttribute('security');
    }

    private function isSignatureAttribute(string $name): bool
    {
        return str_starts_with($name, 'sig-') || $name === 'security';
    }

    private function loadSingleElement(string $encoded): DOMElement
    {
        $document = new DOMDocument('1.0', 'UTF-8');
        $previous = libxml_use_internal_errors(true);

        try {
            $ok = $document->loadXML('<?xml version="1.0" encoding="UTF-8"?>' . trim($encoded));
            if (!$ok || $document->documentElement === null) {
                throw new \RuntimeException('Invalid signed Milpa XHTML envelope.');
            }

            return $document->documentElement;
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }

    private function saveElement(DOMElement $node): string
    {
        return $node->ownerDocument->saveXML($node) ?: '';
    }
}
