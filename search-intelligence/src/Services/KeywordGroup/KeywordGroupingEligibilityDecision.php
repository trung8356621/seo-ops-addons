<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Services\KeywordGroup;

/**
 * Structural eligibility decision. Not persisted.
 */
final class KeywordGroupingEligibilityDecision
{
    /**
     * @param  list<string>  $flags
     * @param  list<string>  $excludeReasons
     */
    public function __construct(
        public readonly string $ref,
        public readonly string $text,
        public readonly bool $eligible,
        public readonly array $flags,
        public readonly array $excludeReasons,
    ) {}

    /** @return array{ref:string,text:string,eligible:bool,flags:list<string>,exclude_reasons:list<string>} */
    public function toArray(): array
    {
        return [
            'ref' => $this->ref,
            'text' => $this->text,
            'eligible' => $this->eligible,
            'flags' => $this->flags,
            'exclude_reasons' => $this->excludeReasons,
        ];
    }
}
