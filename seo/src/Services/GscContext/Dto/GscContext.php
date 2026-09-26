<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Services\GscContext\Dto;

use Omnichannel\Addons\Seo\Services\Context\ContextEnvelopeBuilder;
use Omnichannel\Addons\Seo\Services\Context\Contracts\ContextEnvelope;
use Omnichannel\Addons\Seo\Services\MonthlyMcp\MonthlyMcpFreshness;

/**
 * Typed GSC intelligence context (persisted facts — never live GSC API).
 *
 * Persisted monthly schema remains gsc.mcp.v1 for compatibility.
 */
final class GscContext implements ContextEnvelope
{
    public const SCHEMA = 'gsc.mcp.v1';

    public const VERSION = 1;

    /**
     * @param  array<string, mixed>  $metrics
     * @param  array<string, mixed>  $summary
     * @param  array<string, mixed>  $context
     */
    public function __construct(
        public readonly int $siteId,
        public readonly string $periodKey,
        public readonly array $metrics,
        public readonly array $summary,
        public readonly array $context,
        public readonly ?string $sourceUpdatedAt,
        public readonly string $generatedAt,
        public readonly bool $available,
        public readonly bool $stale,
    ) {}

    /**
     * @param  array{
     *   metrics: array<string, mixed>,
     *   summary: array<string, mixed>,
     *   context: array<string, mixed>,
     *   source_updated_at: ?string
     * }  $built
     */
    public static function fromBuilderPayload(int $siteId, string $periodKey, array $built): self
    {
        $metrics = is_array($built['metrics'] ?? null) ? $built['metrics'] : [];
        $sourceUpdatedAt = isset($built['source_updated_at']) && is_string($built['source_updated_at'])
            ? $built['source_updated_at']
            : null;
        $absent = ($metrics['absent'] ?? false) === true;

        return new self(
            siteId: $siteId,
            periodKey: $periodKey,
            metrics: $metrics,
            summary: is_array($built['summary'] ?? null) ? $built['summary'] : [],
            context: is_array($built['context'] ?? null) ? $built['context'] : [],
            sourceUpdatedAt: $sourceUpdatedAt,
            generatedAt: now()->toIso8601String(),
            available: ! $absent,
            stale: MonthlyMcpFreshness::isSourceStale($sourceUpdatedAt),
        );
    }

    public function schema(): string
    {
        return self::SCHEMA;
    }

    public function version(): int
    {
        return self::VERSION;
    }

    public function scope(): array
    {
        return ['site_ref' => ContextEnvelopeBuilder::siteRef($this->siteId)];
    }

    public function generatedAt(): string
    {
        return $this->generatedAt;
    }

    public function sourceUpdatedAt(): ?string
    {
        return $this->sourceUpdatedAt;
    }

    public function stale(): bool
    {
        return $this->stale;
    }

    public function available(): bool
    {
        return $this->available;
    }

    /**
     * @return array{
     *   metrics: array<string, mixed>,
     *   summary: array<string, mixed>,
     *   context: array<string, mixed>,
     *   period: string
     * }
     */
    public function data(): array
    {
        return [
            'metrics' => $this->metrics,
            'summary' => $this->summary,
            'context' => $this->context,
            'period' => $this->periodKey,
        ];
    }

    public function toEnvelopeArray(): array
    {
        return ContextEnvelopeBuilder::make(
            self::SCHEMA,
            self::VERSION,
            $this->siteId,
            $this->sourceUpdatedAt,
            $this->available,
            $this->data(),
            $this->generatedAt,
            $this->stale,
        );
    }

    /**
     * Fragments for monthly MCP snapshot adapter (schema-compatible).
     *
     * @return array{
     *   metrics: array<string, mixed>,
     *   summary: array<string, mixed>,
     *   context: array<string, mixed>,
     *   source_updated_at: ?string
     * }
     */
    public function toMonthlyParts(): array
    {
        return [
            'metrics' => $this->metrics,
            'summary' => $this->summary,
            'context' => $this->context,
            'source_updated_at' => $this->sourceUpdatedAt,
        ];
    }
}
