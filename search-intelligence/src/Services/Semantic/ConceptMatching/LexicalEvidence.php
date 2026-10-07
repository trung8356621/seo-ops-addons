<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Services\Semantic\ConceptMatching;

final class LexicalEvidence
{
    /**
     * @param  list<string>  $matchedExamples
     * @param  list<string>  $negativeMatchedExamples
     */
    public function __construct(
        public readonly bool $matched,
        public readonly string $matchMode,
        public readonly array $matchedExamples = [],
        public readonly ?string $bestMatch = null,
        public readonly bool $negativeMatched = false,
        public readonly array $negativeMatchedExamples = [],
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'matched' => $this->matched,
            'match_mode' => $this->matchMode,
            'matched_examples' => $this->matchedExamples,
            'best_match' => $this->bestMatch,
            'negative_matched' => $this->negativeMatched,
            'negative_matched_examples' => $this->negativeMatchedExamples,
        ];
    }
}
