<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\Dto;

final class ApplyTopicClusterProposalBatchStatus
{
    public const APPLIED = 'applied';

    public const ALREADY_APPLIED = 'already_applied';

    public const STALE = 'stale';

    public const CONFLICT = 'conflict';

    public const INVALID_SELECTION = 'invalid_selection';

    public const UNAUTHORIZED = 'unauthorized';

    public const ERROR = 'error';
}
