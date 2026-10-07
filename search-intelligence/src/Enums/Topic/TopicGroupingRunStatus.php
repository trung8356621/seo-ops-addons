<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Enums\Topic;

/**
 * Proposal / analysis / apply job states for Topic grouping runs.
 */
final class TopicGroupingRunStatus
{
    public const QUEUED = 'queued';

    public const ANALYZING = 'analyzing';

    public const PROPOSAL_READY = 'proposal_ready';

    public const FAILED = 'failed';

    public const STALE = 'stale';

    public const DISCARDED = 'discarded';

    public const APPLYING = 'applying';

    public const APPLIED = 'applied';

    public const APPLY_FAILED = 'apply_failed';

    /**
     * @return list<string>
     */
    public static function activeAnalysis(): array
    {
        return [self::QUEUED, self::ANALYZING];
    }

    /**
     * @return list<string>
     */
    public static function applyTerminal(): array
    {
        return [self::APPLIED, self::APPLY_FAILED, self::DISCARDED];
    }
}
