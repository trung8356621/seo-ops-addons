<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Services\IndustryGroup;

/**
 * Runtime Industry Group concept-match result.
 * No keyword membership persistence.
 */
final class IndustryGroupMatchResult
{
    /**
     * @param  list<IndustryGroupEntityMatchResult>  $entities
     * @param  list<string>  $staleGroupKeysSkipped
     * @param  list<string>  $disabledGroupKeysSkipped
     */
    public function __construct(
        public readonly string $scopeRef,
        public readonly array $entities,
        public readonly int $conceptsUsed,
        public readonly int $staleGroupsSkipped,
        public readonly int $disabledGroupsSkipped,
        public readonly bool $calledPython,
        public readonly ?string $analysisId = null,
        public readonly array $staleGroupKeysSkipped = [],
        public readonly array $disabledGroupKeysSkipped = [],
        public readonly string $reason = '',
    ) {}

    public static function emptyNoGroups(string $scopeRef, string $reason): self
    {
        return new self(
            scopeRef: $scopeRef,
            entities: [],
            conceptsUsed: 0,
            staleGroupsSkipped: 0,
            disabledGroupsSkipped: 0,
            calledPython: false,
            reason: $reason,
        );
    }
}
