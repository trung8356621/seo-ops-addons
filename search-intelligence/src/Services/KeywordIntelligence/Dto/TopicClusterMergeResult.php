<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\Dto;

final class TopicClusterMergeResult
{
    /**
     * @param  list<string>  $mergedKeys
     */
    public function __construct(
        public readonly string $survivorKey,
        public readonly string $canonicalPhrase,
        public readonly int $keywordsMoved,
        public readonly array $mergedKeys,
    ) {}
}
