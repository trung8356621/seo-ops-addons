<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Services\Topic;

/**
 * Result DTO for site Topic recluster.
 *
 * @phpstan-type Metrics array<string, int>
 */
final class TopicReclusterResult
{
    /**
     * @param  Metrics  $metrics
     */
    private function __construct(
        public readonly bool $ok,
        public readonly string $status,
        public readonly array $metrics,
        public readonly ?string $error = null,
    ) {}

    /**
     * @param  Metrics  $metrics
     */
    public static function ok(array $metrics): self
    {
        return new self(true, 'ok', $metrics);
    }

    public static function failed(string $error, array $metrics = []): self
    {
        return new self(false, 'failed', $metrics, $error);
    }
}
