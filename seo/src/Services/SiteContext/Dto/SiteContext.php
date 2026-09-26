<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Services\SiteContext\Dto;

use Omnichannel\Addons\Seo\Services\Context\ContextEnvelopeBuilder;
use Omnichannel\Addons\Seo\Services\Context\ContextFreshness;
use Omnichannel\Addons\Seo\Services\Context\Contracts\ContextEnvelope;

/**
 * Site Intelligence preset composition (health, content, links, publishing, sync).
 *
 * Distinct from Site Knowledge Profile. Schema id `site.mcp.v1` is a stable
 * compatibility contract id — not MCP transport ownership.
 */
final class SiteContext implements ContextEnvelope
{
    public const SCHEMA = 'site.mcp.v1';

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

    public static function computeStale(?string $sourceUpdatedAt): bool
    {
        return ContextFreshness::isSourceStale($sourceUpdatedAt);
    }
}
