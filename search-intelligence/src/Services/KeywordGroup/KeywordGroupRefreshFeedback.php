<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Services\KeywordGroup;

/**
 * Decides the operator-facing outcome of one semantic refresh.
 * A completed Python response with zero persisted groups is not a save.
 */
final class KeywordGroupRefreshFeedback
{
    public const SAVED = 'saved';

    public const PYTHON_ZERO = 'python_zero';

    public const NO_ELIGIBLE = 'no_eligible';

    public const NOT_RUN = 'not_run';

    public static function kind(?KeywordGroupRefreshResult $result): string
    {
        if (! $result instanceof KeywordGroupRefreshResult) {
            return self::NOT_RUN;
        }
        if ($result->groupCount > 0) {
            return self::SAVED;
        }
        if ($result->skipped || $result->eligibleCount === 0) {
            return self::NO_ELIGIBLE;
        }

        return self::PYTHON_ZERO;
    }
}
