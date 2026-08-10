<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Services\SerpIntelligence\Data;

/**
 * Normalized snapshot shape — provider-agnostic, ready for overlap/intent analysis.
 */
final class SerpNormalizedSnapshot
{
    /**
     * @param  list<array<string, mixed>>  $results
     * @param  list<array<string, mixed>>  $features
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public readonly string $snapshotRef,
        public readonly SerpQueryRequest $query,
        public readonly array $results,
        public readonly array $features = [],
        public readonly array $metadata = [],
        public readonly ?string $collectedAt = null,
        public readonly string $normalizationVersion = '1.0.0',
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'snapshot_ref' => $this->snapshotRef,
            'query' => $this->query->scopeKey(),
            'results' => $this->results,
            'features' => $this->features,
            'metadata' => $this->metadata,
            'collected_at' => $this->collectedAt,
            'normalization_version' => $this->normalizationVersion,
        ];
    }
}
