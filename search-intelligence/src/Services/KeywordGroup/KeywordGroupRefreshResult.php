<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Services\KeywordGroup;

final class KeywordGroupRefreshResult
{
    public function __construct(
        public readonly int $groupCount,
        public readonly int $memberCount,
        public readonly int $preservedGroupCount,
        public readonly bool $skipped,
    ) {}
}
