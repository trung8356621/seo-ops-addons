<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Services\IndustryGroup;

final class IndustryGroupEntityMatchResult
{
    /**
     * @param  list<IndustryGroupMatchEvidence>  $evidence
     */
    public function __construct(
        public readonly string $ref,
        public readonly string $text,
        public readonly array $evidence,
    ) {}
}
