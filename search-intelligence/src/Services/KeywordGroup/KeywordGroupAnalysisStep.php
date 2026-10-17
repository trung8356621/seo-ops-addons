<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Services\KeywordGroup;

final class KeywordGroupAnalysisStep
{
    public function __construct(
        public readonly string $phase,
        public readonly ?string $runId,
        public readonly bool $pollAgain,
        public readonly int $groupCount = 0,
        public readonly int $memberCount = 0,
        public readonly ?string $error = null,
    ) {}
}
