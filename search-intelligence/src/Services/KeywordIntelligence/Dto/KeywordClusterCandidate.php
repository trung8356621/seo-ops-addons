<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\Dto;

use Omnichannel\Addons\SearchIntelligence\Enums\KeywordIntelligence\KeywordFunnelStage;
use Omnichannel\Addons\SearchIntelligence\Enums\KeywordIntelligence\KeywordSearchIntent;

/**
 * Ứng viên cluster trước khi được ghi thành SeoKeywordCluster — dùng để validate/preview
 * (KeywordClusterValidator) trước khi persist, tránh tạo cluster rác trực tiếp trong DB.
 */
final class KeywordClusterCandidate
{
    /**
     * @param  list<int>  $keywordIds
     * @param  list<string>  $modifiers
     * @param  list<string>  $reasonCodes
     */
    public function __construct(
        public readonly string $candidateRef,
        public readonly array $keywordIds,
        public readonly ?int $primaryKeywordId,
        public readonly ?KeywordSearchIntent $intent,
        public readonly ?KeywordFunnelStage $funnelStage,
        public readonly string $entity,
        public readonly array $modifiers,
        public readonly string $suggestedName,
        public readonly string $suggestedContentType,
        public readonly float $confidence,
        public readonly array $reasonCodes = [],
        public readonly ?int $existingArticleId = null,
    ) {}

    /**
     * @return array{
     *   candidate_ref: string,
     *   keyword_ids: list<int>,
     *   primary_keyword_id: int|null,
     *   intent: string|null,
     *   funnel_stage: string|null,
     *   entity: string,
     *   modifiers: list<string>,
     *   suggested_name: string,
     *   suggested_content_type: string,
     *   confidence: float,
     *   reason_codes: list<string>,
     *   existing_article_id: int|null
     * }
     */
    public function toArray(): array
    {
        return [
            'candidate_ref' => $this->candidateRef,
            'keyword_ids' => $this->keywordIds,
            'primary_keyword_id' => $this->primaryKeywordId,
            'intent' => $this->intent?->value,
            'funnel_stage' => $this->funnelStage?->value,
            'entity' => $this->entity,
            'modifiers' => $this->modifiers,
            'suggested_name' => $this->suggestedName,
            'suggested_content_type' => $this->suggestedContentType,
            'confidence' => $this->confidence,
            'reason_codes' => $this->reasonCodes,
            'existing_article_id' => $this->existingArticleId,
        ];
    }
}
