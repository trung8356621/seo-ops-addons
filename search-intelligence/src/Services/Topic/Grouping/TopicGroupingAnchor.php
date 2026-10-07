<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Services\Topic\Grouping;

/** Single existing Topic a membership scan should analyze against. */
final class TopicGroupingAnchor
{
    public function __construct(
        public readonly int $topicRef,
        public readonly string $label,
    ) {}
}
