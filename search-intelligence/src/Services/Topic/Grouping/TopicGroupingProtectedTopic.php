<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Services\Topic\Grouping;

/**
 * Existing Topic the business layer wants analysis to respect.
 *
 * topicRef is a correlation id supplied by Laravel, not a write instruction.
 * locked Topics still accept net-new matches in the legacy engine.
 * acceptAttach=false is the manual-freeze signal (no auto-attach, no discovery of those members).
 */
final class TopicGroupingProtectedTopic
{
    /**
     * @param  list<TopicGroupingProtectedMember>  $members
     */
    public function __construct(
        public readonly int $topicRef,
        public readonly string $label,
        public readonly bool $locked,
        public readonly bool $acceptAttach,
        public readonly array $members,
    ) {}
}
