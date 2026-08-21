<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\Dto;

final readonly class ApplyTopicClusterProposalBatchInput
{
    /**
     * @param  list<string>  $selectedProposalFingerprints
     */
    public function __construct(
        public int $siteId,
        public string $strategy,
        public string $previewFingerprint,
        public string $mode,
        public array $selectedProposalFingerprints = [],
    ) {}
}
