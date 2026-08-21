<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\Dto;

final readonly class ApplyTopicClusterProposalResult
{
    /**
     * @param  list<int>  $keywordIds
     */
    private function __construct(
        public string $status,
        public string $clusterKey,
        public string $representativeLabel,
        public int $affectedKeywordCount,
        public array $keywordIds = [],
        public string $proposalFingerprint = '',
    ) {}

    /**
     * @param  list<int>  $keywordIds
     */
    public static function applied(
        string $clusterKey,
        string $representativeLabel,
        int $affectedKeywordCount,
        array $keywordIds,
        string $proposalFingerprint,
    ): self {
        return new self(
            status: ApplyTopicClusterProposalStatus::APPLIED,
            clusterKey: $clusterKey,
            representativeLabel: $representativeLabel,
            affectedKeywordCount: $affectedKeywordCount,
            keywordIds: $keywordIds,
            proposalFingerprint: $proposalFingerprint,
        );
    }

    /**
     * @param  list<int>  $keywordIds
     */
    public static function alreadyApplied(
        string $clusterKey,
        string $representativeLabel,
        int $affectedKeywordCount,
        array $keywordIds,
        string $proposalFingerprint,
    ): self {
        return new self(
            status: ApplyTopicClusterProposalStatus::ALREADY_APPLIED,
            clusterKey: $clusterKey,
            representativeLabel: $representativeLabel,
            affectedKeywordCount: $affectedKeywordCount,
            keywordIds: $keywordIds,
            proposalFingerprint: $proposalFingerprint,
        );
    }

    public static function stale(): self
    {
        return new self(
            status: ApplyTopicClusterProposalStatus::STALE,
            clusterKey: '',
            representativeLabel: '',
            affectedKeywordCount: 0,
        );
    }

    public static function conflict(): self
    {
        return new self(
            status: ApplyTopicClusterProposalStatus::CONFLICT,
            clusterKey: '',
            representativeLabel: '',
            affectedKeywordCount: 0,
        );
    }

    public static function unauthorized(): self
    {
        return new self(
            status: ApplyTopicClusterProposalStatus::UNAUTHORIZED,
            clusterKey: '',
            representativeLabel: '',
            affectedKeywordCount: 0,
        );
    }

    public static function failed(): self
    {
        return new self(
            status: ApplyTopicClusterProposalStatus::FAILED,
            clusterKey: '',
            representativeLabel: '',
            affectedKeywordCount: 0,
        );
    }

    public function isSuccess(): bool
    {
        return in_array($this->status, [
            ApplyTopicClusterProposalStatus::APPLIED,
            ApplyTopicClusterProposalStatus::ALREADY_APPLIED,
        ], true);
    }
}
