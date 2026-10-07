<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Services\Topic\Grouping;

/**
 * Membership Laravel already owns and is handing to analysis as context.
 * Echoed so the legacy engine can exclude it from free grouping.
 */
final class TopicGroupingProtectedMember
{
    public function __construct(
        public readonly int $keywordRef,
        public readonly string $text,
        public readonly string $source,
        public readonly bool $isSeed,
        public readonly ?float $confidence,
        public readonly bool $isLocked,
    ) {}
}
