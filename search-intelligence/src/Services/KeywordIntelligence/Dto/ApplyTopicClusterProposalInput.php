<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\Dto;

final readonly class ApplyTopicClusterProposalInput
{
    /**
     * @param  list<int>  $memberKeywordIds
     */
    public function __construct(
        public int $siteId,
        public string $strategy,
        public string $previewFingerprint,
        public string $proposalFingerprint,
        public array $memberKeywordIds = [],
        public int $representativeKeywordId = 0,
        public string $representativeLabel = '',
        public string $finalStatus = KeywordClusterProposalCluster::FINAL_READY,
        public string $qualityState = KeywordClusterQualityMetrics::STATE_ACCEPTABLE,
    ) {}
}
