<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Services\Context;

use Omnichannel\Addons\Seo\Services\MonthlyMcp\MonthlyMcpFreshness;

/**
 * Neutral helpers for context envelope metadata (not MCP-owned).
 */
final class ContextEnvelopeBuilder
{
    public static function siteRef(int $siteId): string
    {
        return 'site:'.max(0, $siteId);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{
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
    public static function make(
        string $schema,
        int $version,
        int $siteId,
        ?string $sourceUpdatedAt,
        bool $available,
        array $data,
        ?string $generatedAt = null,
        ?bool $stale = null,
    ): array {
        return [
            'schema' => $schema,
            'version' => $version,
            'scope' => [
                'site_ref' => self::siteRef($siteId),
            ],
            'generated_at' => $generatedAt ?? now()->toIso8601String(),
            'source_updated_at' => $sourceUpdatedAt,
            'stale' => $stale ?? MonthlyMcpFreshness::isSourceStale($sourceUpdatedAt),
            'available' => $available,
            'data' => $data,
        ];
    }
}
