<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Services\Semantic\ConceptMatching;

/** Caller-owned entity for Concept Matching (not a Keyword model). */
final class ConceptMatchEntity
{
    public function __construct(
        public readonly string $ref,
        public readonly string $text,
    ) {}

    /** @return array{ref: string, text: string} */
    public function toApiArray(): array
    {
        return [
            'ref' => $this->ref,
            'text' => $this->text,
        ];
    }
}
