<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\Dto;

final class CanonicalClusterMatch
{
    public function __construct(
        public readonly string $clusterKey,
        public readonly string $canonicalPhrase,
        public readonly string $confidence,
        public readonly bool $needsReview,
        public readonly string $matchType,
    ) {}
}
