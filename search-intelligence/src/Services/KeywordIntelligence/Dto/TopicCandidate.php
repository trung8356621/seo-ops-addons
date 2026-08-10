<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\Dto;

use Omnichannel\Addons\SearchIntelligence\Enums\KeywordIntelligence\KeywordTopicType;

/**
 * Node ứng viên trong cây topical map trước khi persist thành SeoKiTopic. Cho phép
 * TopicalMapHierarchyValidator kiểm tra toàn bộ cây (cycle/depth/duplicate/orphan/type
 * relationship...) trong bộ nhớ trước khi ghi DB. candidateRef là id tạm thời trong phạm
 * vi 1 lần build — KHÔNG phải public_ref đã persist.
 */
final class TopicCandidate
{
    /**
     * @param  list<string>  $clusterRefs  public_ref của cluster gán trực tiếp (leaf) vào node này
     * @param  list<string>  $intents
     * @param  list<string>  $funnelStages
     * @param  list<string>  $reasonCodes
     */
    public function __construct(
        public readonly string $candidateRef,
        public readonly string $name,
        public readonly string $slug,
        public readonly KeywordTopicType $topicType,
        public readonly ?string $parentCandidateRef,
        public readonly array $clusterRefs = [],
        public readonly string $primaryEntity = '',
        public readonly array $intents = [],
        public readonly array $funnelStages = [],
        public readonly float $confidence = 0.7,
        public readonly array $reasonCodes = [],
    ) {}

    /**
     * @return array{
     *   candidate_ref: string,
     *   name: string,
     *   slug: string,
     *   topic_type: string,
     *   parent_candidate_ref: string|null,
     *   cluster_refs: list<string>,
     *   primary_entity: string,
     *   intents: list<string>,
     *   funnel_stages: list<string>,
     *   confidence: float,
     *   reason_codes: list<string>
     * }
     */
    public function toArray(): array
    {
        return [
            'candidate_ref' => $this->candidateRef,
            'name' => $this->name,
            'slug' => $this->slug,
            'topic_type' => $this->topicType->value,
            'parent_candidate_ref' => $this->parentCandidateRef,
            'cluster_refs' => $this->clusterRefs,
            'primary_entity' => $this->primaryEntity,
            'intents' => $this->intents,
            'funnel_stages' => $this->funnelStages,
            'confidence' => $this->confidence,
            'reason_codes' => $this->reasonCodes,
        ];
    }
}
