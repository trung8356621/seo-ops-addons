<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Services\Semantic\ConceptMatching;

final class ConceptMatchEvidence
{
    public function __construct(
        public readonly string $key,
        public readonly LexicalEvidence $lexical,
        public readonly string $matchingStrategy,
        public readonly ?float $positiveMax,
        public readonly ?float $positiveTopKMean,
        public readonly ?float $negativeMax,
        public readonly ?float $margin,
        public readonly ?string $bestPositiveExample,
        public readonly ?float $bestPositiveSimilarity,
        public readonly ?string $bestNegativeExample,
        public readonly ?float $bestNegativeSimilarity,
        public readonly ?bool $suggestedMatch,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'lexical' => $this->lexical->toArray(),
            'matching_strategy' => $this->matchingStrategy,
            'positive_max' => $this->positiveMax,
            'positive_top_k_mean' => $this->positiveTopKMean,
            'negative_max' => $this->negativeMax,
            'margin' => $this->margin,
            'best_positive_example' => $this->bestPositiveExample,
            'best_positive_similarity' => $this->bestPositiveSimilarity,
            'best_negative_example' => $this->bestNegativeExample,
            'best_negative_similarity' => $this->bestNegativeSimilarity,
            'suggested_match' => $this->suggestedMatch,
        ];
    }
}
