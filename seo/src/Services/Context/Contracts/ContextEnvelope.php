<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Services\Context\Contracts;

/**
 * Shared outer contract for domain context payloads.
 *
 * Domain Context ≠ MCP transport. HTTP API and MCP adapters wrap this shape later.
 * Each context keeps strongly typed / domain-specific `data()`; this interface only
 * standardizes metadata semantics for future unified API readiness.
 *
 * @phpstan-type ContextEnvelopeArray array{
 *   schema: string,
 *   version: int,
 *   scope: array{site_ref: string},
 *   generated_at: string,
 *   source_updated_at: string|null,
 *   stale: bool,
 *   available: bool,
 *   data: array<string, mixed>
 * }
 */
interface ContextEnvelope
{
    public function schema(): string;

    public function version(): int;

    /**
     * @return array{site_ref: string}
     */
    public function scope(): array;

    public function generatedAt(): string;

    public function sourceUpdatedAt(): ?string;

    public function stale(): bool;

    public function available(): bool;

    /**
     * Domain-specific payload (not a universal untyped schema).
     *
     * @return array<string, mixed>
     */
    public function data(): array;

    /**
     * @return ContextEnvelopeArray
     */
    public function toEnvelopeArray(): array;
}
