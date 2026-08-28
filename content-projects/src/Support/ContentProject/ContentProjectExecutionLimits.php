<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Support\ContentProject;

/**
 * Hard limits for Draft → Execution Project allocation.
 */
final class ContentProjectExecutionLimits
{
    /**
     * Max assigned planning items per real writer in the current calendar month
     * (sum across that user's active, non-draft execution projects).
     */
    public const MAX_WRITER_MONTHLY_ITEMS = 30;

    /**
     * Backward-compatible alias — same value as {@see MAX_WRITER_MONTHLY_ITEMS}.
     */
    public const MAX_EXECUTION_PROJECT_ITEMS = self::MAX_WRITER_MONTHLY_ITEMS;

    public static function insufficientCapacityMessage(int $shortfall): string
    {
        $shortfall = max(0, $shortfall);

        return (string) __('seo-content-ai::filament.projects.draft_split_insufficient', [
            'count' => $shortfall,
        ]);
    }
}
