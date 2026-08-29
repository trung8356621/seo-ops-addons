<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Support\ContentProject;

/**
 * Execution project size limits (not a per-writer monthly hard cap).
 */
final class ContentProjectExecutionLimits
{
    /**
     * Max planning items per Execution Project.
     * Larger per-writer allocations are chunked into multiple projects.
     */
    public const MAX_EXECUTION_PROJECT_ITEMS = 30;

    /**
     * @deprecated Retired monthly hard-cap semantics — alias of {@see MAX_EXECUTION_PROJECT_ITEMS} only.
     */
    public const MAX_WRITER_MONTHLY_ITEMS = self::MAX_EXECUTION_PROJECT_ITEMS;
}
