<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Services\Topic\Grouping;

/**
 * Proposed member. Evidence describes why the analyzer grouped the keyword.
 * It is not a persistence command.
 */
final class TopicGroupingMember
{
    public const EVIDENCE_SOURCE = 'source';

    public const EVIDENCE_IS_SEED = 'is_seed';

    public const EVIDENCE_IS_LOCKED = 'is_locked';

    public const EVIDENCE_MATCH = 'match';

    /**
     * @param  array<string, mixed>  $evidence
     */
    public function __construct(
        public readonly int $keywordRef,
        public readonly string $text,
        public readonly ?float $confidence,
        public readonly array $evidence = [],
    ) {}
}
