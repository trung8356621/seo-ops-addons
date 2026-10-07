<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Services\Semantic\ConceptMatching;

final class ConceptMatchDiagnostics
{
    public function __construct(
        public readonly int $entityCount,
        public readonly int $conceptCount,
        public readonly int $uniqueTextCount,
        public readonly int $embedMs,
        public readonly int $scoreMs,
        public readonly int $totalMs,
        public readonly string $model,
        public readonly string $provider,
        public readonly int $dimensions,
        public readonly string $cache,
        public readonly int $semanticConcepts = 0,
        public readonly int $lexicalOnlyConcepts = 0,
    ) {}
}
