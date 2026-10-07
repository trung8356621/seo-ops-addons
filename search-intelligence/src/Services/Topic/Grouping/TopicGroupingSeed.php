<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Services\Topic\Grouping;

/** Seed evidence supplied by Laravel. Not a Topic row. */
final class TopicGroupingSeed
{
    public function __construct(
        public readonly int $keywordRef,
        public readonly string $text,
        public readonly string $source,
        public readonly ?float $confidence,
    ) {}
}
