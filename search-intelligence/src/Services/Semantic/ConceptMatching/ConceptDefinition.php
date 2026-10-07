<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Services\Semantic\ConceptMatching;

/** Explicit Python concept payload — no Laravel DTO dump. */
final class ConceptDefinition
{
    /**
     * @param  list<string>  $positiveExamples
     * @param  list<string>  $negativeExamples
     */
    public function __construct(
        public readonly string $key,
        public readonly array $positiveExamples,
        public readonly array $negativeExamples = [],
        public readonly string $matchMode = 'phrase',
        public readonly string $matchingStrategy = 'semantic',
    ) {}

    /** @return array<string, mixed> */
    public function toApiArray(): array
    {
        return [
            'key' => $this->key,
            'positive_examples' => array_values($this->positiveExamples),
            'negative_examples' => array_values($this->negativeExamples),
            'match_mode' => $this->matchMode,
            'matching_strategy' => $this->matchingStrategy,
        ];
    }
}
