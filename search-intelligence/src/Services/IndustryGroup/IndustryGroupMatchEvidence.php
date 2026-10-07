<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Services\IndustryGroup;

/**
 * Typed Industry Group × entity match evidence from seo-ops-semantic.
 * Cosine fields are similarities, not confidence/probability.
 */
final class IndustryGroupMatchEvidence
{
    /**
     * @param  list<string>  $lexicalMatchedExamples
     */
    public function __construct(
        public readonly string $entityRef,
        public readonly string $industryGroupKey,
        public readonly ?string $groupType,
        public readonly bool $lexicalMatched,
        public readonly bool $lexicalNegativeMatched,
        public readonly array $lexicalMatchedExamples,
        public readonly ?float $positiveMax,
        public readonly ?float $positiveTopKMean,
        public readonly ?float $negativeMax,
        public readonly ?float $margin,
        public readonly ?string $bestPositiveExample,
        public readonly ?string $bestNegativeExample,
        public readonly ?bool $suggestedMatch,
        public readonly string $matchingStrategy,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'entity_ref' => $this->entityRef,
            'industry_group_key' => $this->industryGroupKey,
            'group_type' => $this->groupType,
            'lexical_matched' => $this->lexicalMatched,
            'lexical_negative_matched' => $this->lexicalNegativeMatched,
            'lexical_matched_examples' => $this->lexicalMatchedExamples,
            'positive_max' => $this->positiveMax,
            'positive_top_k_mean' => $this->positiveTopKMean,
            'negative_max' => $this->negativeMax,
            'margin' => $this->margin,
            'best_positive_example' => $this->bestPositiveExample,
            'best_negative_example' => $this->bestNegativeExample,
            'suggested_match' => $this->suggestedMatch,
            'matching_strategy' => $this->matchingStrategy,
        ];
    }
}
