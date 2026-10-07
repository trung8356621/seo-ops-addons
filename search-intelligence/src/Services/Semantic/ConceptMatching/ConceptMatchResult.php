<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Services\Semantic\ConceptMatching;

final class ConceptMatchResult
{
    /**
     * @param  list<ConceptMatchEntityResult>  $entities
     */
    public function __construct(
        public readonly string $analysisId,
        public readonly string $scopeRef,
        public readonly ?string $language,
        public readonly array $entities,
        public readonly ConceptMatchDiagnostics $diagnostics,
    ) {}
}
