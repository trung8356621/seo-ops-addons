<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\Dto;

final readonly class KeywordClusterProposalCluster
{
    public const FINAL_READY = 'READY';

    public const FINAL_NEEDS_REVIEW = 'NEEDS_REVIEW';

    /**
     * @param  list<array{keyword_id: int, phrase: string, seo_intent: string}>  $members
     */
    public function __construct(
        public string $representativeLabel,
        public int $representativeKeywordId,
        public float $cohesion,
        public float $minSimilarity,
        public int $memberCount,
        public array $members,
        public ?KeywordClusterQualityMetrics $quality = null,
        public ?string $splitFromLabel = null,
        public ?string $splitReason = null,
        public ?string $rehomeNote = null,
        public string $finalStatus = self::FINAL_READY,
        public string $proposalFingerprint = '',
        public string $proposalRef = '',
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $payload = [
            'representative_label' => $this->representativeLabel,
            'representative_keyword_id' => $this->representativeKeywordId,
            'cohesion' => round($this->cohesion, 3),
            'cohesion_label' => self::cohesionLabel($this->cohesion),
            'min_similarity' => round($this->minSimilarity, 3),
            'member_count' => $this->memberCount,
            'members' => $this->members,
            'final_status' => $this->finalStatus,
            'final_status_label' => self::finalStatusLabel($this->finalStatus),
            'proposal_fingerprint' => $this->proposalFingerprint,
            'proposal_ref' => $this->proposalRef !== '' ? $this->proposalRef : $this->proposalFingerprint,
        ];

        if ($this->quality !== null) {
            $payload['quality'] = $this->quality->toArray();
            $payload['quality_state'] = $this->quality->qualityState;
            $payload['quality_label'] = self::qualityLabel($this->quality->qualityState);
            $payload['p25_similarity'] = round($this->quality->p25Similarity, 3);
            $payload['core_member_count'] = $this->quality->coreMemberCount;
            $payload['borderline_member_count'] = $this->quality->borderlineMemberCount;
        }

        if ($this->splitFromLabel !== null && $this->splitFromLabel !== '') {
            $payload['split_from_label'] = $this->splitFromLabel;
        }

        if ($this->splitReason !== null && $this->splitReason !== '') {
            $payload['split_reason'] = $this->splitReason;
        }

        if ($this->rehomeNote !== null && $this->rehomeNote !== '') {
            $payload['rehome_note'] = $this->rehomeNote;
        }

        return $payload;
    }

    public static function cohesionLabel(float $cohesion): string
    {
        if ($cohesion >= 0.75) {
            return __('seo-content-ai::filament.keyword.topic_proposal_cohesion_high');
        }
        if ($cohesion >= 0.55) {
            return __('seo-content-ai::filament.keyword.topic_proposal_cohesion_good');
        }

        return __('seo-content-ai::filament.keyword.topic_proposal_cohesion_fair');
    }

    public static function qualityLabel(string $qualityState): string
    {
        return match ($qualityState) {
            KeywordClusterQualityMetrics::STATE_COMPACT,
            KeywordClusterQualityMetrics::STATE_ACCEPTABLE => __('seo-content-ai::filament.keyword.topic_proposal_quality_stable'),
            KeywordClusterQualityMetrics::STATE_LOOSE,
            KeywordClusterQualityMetrics::STATE_MEGA => __('seo-content-ai::filament.keyword.topic_proposal_quality_review'),
            default => __('seo-content-ai::filament.keyword.topic_proposal_quality_stable'),
        };
    }

    public static function finalStatusLabel(string $finalStatus): string
    {
        return $finalStatus === self::FINAL_READY
            ? __('seo-content-ai::filament.keyword.topic_proposal_final_ready')
            : __('seo-content-ai::filament.keyword.topic_proposal_final_needs_review');
    }
}
