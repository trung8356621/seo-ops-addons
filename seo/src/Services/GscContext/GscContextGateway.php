<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Services\GscContext;

use Omnichannel\Addons\SearchIntelligence\Support\GscIntelligence\GscMcpContextBuilder;
use Omnichannel\Addons\Seo\Services\GscContext\Dto\GscContext;

/**
 * Canonical application boundary for site-level GSC intelligence context.
 *
 * Implementation currently delegates to GscMcpContextBuilder (compatibility detail —
 * reads persisted GSC facts only; never live GSC API / HTTP loopback).
 */
final class GscContextGateway implements GscContextLoader
{
    public const SCHEMA = GscContext::SCHEMA;

    public function __construct(
        private readonly GscMcpContextBuilder $builder,
    ) {}

    public function forSite(int $siteId, string $periodKey): GscContext
    {
        $built = $this->builder->build($siteId, $periodKey);

        return GscContext::fromBuilderPayload($siteId, $periodKey, $built);
    }

    public function sourceUpdatedAt(int $siteId): ?string
    {
        return $this->builder->sourceUpdatedAt($siteId);
    }

    public function latestSyncedPeriodOnOrBefore(int $siteId, string $onOrBeforePeriod): ?string
    {
        return $this->builder->latestSyncedPeriodOnOrBefore($siteId, $onOrBeforePeriod);
    }

    /**
     * Future API / MCP-ready outer envelope.
     *
     * @return array<string, mixed>
     */
    public function envelope(int $siteId, string $periodKey): array
    {
        return $this->forSite($siteId, $periodKey)->toEnvelopeArray();
    }
}
