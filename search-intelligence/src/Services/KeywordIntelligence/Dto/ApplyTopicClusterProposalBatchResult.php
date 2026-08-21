<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\Dto;

final readonly class ApplyTopicClusterProposalBatchResult
{
    /**
     * @param  list<string>  $clusterKeys
     * @param  list<string>  $appliedProposalFingerprints
     */
    private function __construct(
        public string $status,
        public string $mode,
        public int $proposalCount,
        public int $keywordCount,
        public array $clusterKeys = [],
        public array $appliedProposalFingerprints = [],
    ) {}

    /**
     * @param  list<string>  $clusterKeys
     * @param  list<string>  $appliedProposalFingerprints
     */
    public static function applied(
        string $mode,
        int $proposalCount,
        int $keywordCount,
        array $clusterKeys,
        array $appliedProposalFingerprints,
    ): self {
        return new self(
            status: ApplyTopicClusterProposalBatchStatus::APPLIED,
            mode: $mode,
            proposalCount: $proposalCount,
            keywordCount: $keywordCount,
            clusterKeys: $clusterKeys,
            appliedProposalFingerprints: $appliedProposalFingerprints,
        );
    }

    /**
     * @param  list<string>  $clusterKeys
     * @param  list<string>  $appliedProposalFingerprints
     */
    public static function alreadyApplied(
        string $mode,
        int $proposalCount,
        int $keywordCount,
        array $clusterKeys,
        array $appliedProposalFingerprints,
    ): self {
        return new self(
            status: ApplyTopicClusterProposalBatchStatus::ALREADY_APPLIED,
            mode: $mode,
            proposalCount: $proposalCount,
            keywordCount: $keywordCount,
            clusterKeys: $clusterKeys,
            appliedProposalFingerprints: $appliedProposalFingerprints,
        );
    }

    public static function stale(string $mode): self
    {
        return new self(
            status: ApplyTopicClusterProposalBatchStatus::STALE,
            mode: $mode,
            proposalCount: 0,
            keywordCount: 0,
        );
    }

    public static function conflict(string $mode): self
    {
        return new self(
            status: ApplyTopicClusterProposalBatchStatus::CONFLICT,
            mode: $mode,
            proposalCount: 0,
            keywordCount: 0,
        );
    }

    public static function invalidSelection(string $mode): self
    {
        return new self(
            status: ApplyTopicClusterProposalBatchStatus::INVALID_SELECTION,
            mode: $mode,
            proposalCount: 0,
            keywordCount: 0,
        );
    }

    public static function unauthorized(string $mode): self
    {
        return new self(
            status: ApplyTopicClusterProposalBatchStatus::UNAUTHORIZED,
            mode: $mode,
            proposalCount: 0,
            keywordCount: 0,
        );
    }

    public static function error(string $mode): self
    {
        return new self(
            status: ApplyTopicClusterProposalBatchStatus::ERROR,
            mode: $mode,
            proposalCount: 0,
            keywordCount: 0,
        );
    }

    public function isSuccess(): bool
    {
        return in_array($this->status, [
            ApplyTopicClusterProposalBatchStatus::APPLIED,
            ApplyTopicClusterProposalBatchStatus::ALREADY_APPLIED,
        ], true);
    }
}
