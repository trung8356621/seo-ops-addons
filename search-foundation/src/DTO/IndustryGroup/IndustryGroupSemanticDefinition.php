<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchFoundation\DTO\IndustryGroup;

/**
 * Transport/read DTO for future seo-ops-semantic Concept Matching.
 * Laravel prepares examples only — no similarity scoring here.
 */
final class IndustryGroupSemanticDefinition
{
    /**
     * @param  list<string>  $positiveExamples
     * @param  list<string>  $negativeExamples
     */
    public function __construct(
        public readonly string $key,
        public readonly string $name,
        public readonly array $positiveExamples,
        public readonly array $negativeExamples = [],
        public readonly ?string $groupType = null,
        public readonly ?string $locale = null,
        public readonly ?string $matchMode = null,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'name' => $this->name,
            'positive_examples' => $this->positiveExamples,
            'negative_examples' => $this->negativeExamples,
            'group_type' => $this->groupType,
            'locale' => $this->locale,
            'match_mode' => $this->matchMode,
        ];
    }
}
