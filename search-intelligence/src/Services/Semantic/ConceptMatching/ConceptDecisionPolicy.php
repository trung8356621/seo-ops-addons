<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Services\Semantic\ConceptMatching;

/**
 * Caller-supplied decision gates for Python.
 * Do not bake universal cosine thresholds here.
 */
final class ConceptDecisionPolicy
{
    public function __construct(
        public readonly bool $semanticFallback = false,
        public readonly ?float $minPositiveScore = null,
        public readonly ?float $minMargin = null,
    ) {}

    /** @return array<string, mixed> */
    public function toApiArray(): array
    {
        $out = [
            'semantic_fallback' => $this->semanticFallback,
        ];
        if ($this->minPositiveScore !== null) {
            $out['min_positive_score'] = $this->minPositiveScore;
        }
        if ($this->minMargin !== null) {
            $out['min_margin'] = $this->minMargin;
        }

        return $out;
    }
}
