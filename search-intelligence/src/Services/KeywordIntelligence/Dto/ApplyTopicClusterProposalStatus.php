<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\Dto;

final class ApplyTopicClusterProposalStatus
{
    public const APPLIED = 'applied';

    public const ALREADY_APPLIED = 'already_applied';

    public const STALE = 'stale';

    public const CONFLICT = 'conflict';

    public const UNAUTHORIZED = 'unauthorized';

    public const FAILED = 'failed';
}
