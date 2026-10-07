<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Services\Semantic\ConceptMatching;

final class ConceptMatchEntityResult
{
    /**
     * @param  list<ConceptMatchEvidence>  $concepts
     */
    public function __construct(
        public readonly string $ref,
        public readonly string $text,
        public readonly array $concepts,
    ) {}
}
