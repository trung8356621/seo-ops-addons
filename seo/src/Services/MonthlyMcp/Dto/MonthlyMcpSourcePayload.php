<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Services\MonthlyMcp\Dto;

use Omnichannel\Addons\Seo\Enums\McpSourceKey;

final class MonthlyMcpSourcePayload
{
    /**
     * @param  array<string, mixed>  $metrics
     * @param  array<string, mixed>  $summary
     * @param  array<string, mixed>  $context
     */
    public function __construct(
        public readonly McpSourceKey $source,
        public readonly string $schema,
        public readonly array $metrics,
        public readonly array $summary,
        public readonly array $context,
        public readonly ?string $sourceUpdatedAt,
        public readonly string $contentHash,
    ) {}

    /**
     * @param  array<string, mixed>  $metrics
     * @param  array<string, mixed>  $summary
     * @param  array<string, mixed>  $context
     */
    public static function make(
        McpSourceKey $source,
        array $metrics,
        array $summary,
        array $context,
        ?string $sourceUpdatedAt,
    ): self {
        $canonical = [
            'metrics' => $metrics,
            'summary' => $summary,
            'context' => $context,
        ];
        $encoded = json_encode($canonical, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        return new self(
            source: $source,
            schema: $source->schema(),
            metrics: $metrics,
            summary: $summary,
            context: $context,
            sourceUpdatedAt: $sourceUpdatedAt,
            contentHash: hash('sha256', $encoded),
        );
    }
}
