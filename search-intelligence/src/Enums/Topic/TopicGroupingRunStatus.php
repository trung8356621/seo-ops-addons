<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Enums\Topic;

/**
 * Proposal / analysis job states for Topic grouping runs.
 * applying / applied reserved for Prompt 5 — not used in TASK 4.
 */
final class TopicGroupingRunStatus
{
    public const QUEUED = 'queued';

    public const ANALYZING = 'analyzing';

    public const PROPOSAL_READY = 'proposal_ready';

    public const FAILED = 'failed';

    public const STALE = 'stale';

    public const DISCARDED = 'discarded';

    /** @reserved Prompt 5 */
    public const APPLYING = 'applying';

    /** @reserved Prompt 5 */
    public const APPLIED = 'applied';

    /**
     * @return list<string>
     */
    public static function activeAnalysis(): array
    {
        return [self::QUEUED, self::ANALYZING];
    }
}
