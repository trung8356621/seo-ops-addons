<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Support\ContentProject;

/**
 * Execution project size limits (container packing).
 *
 * Writer monthly capacity is NOT defined here — see
 * {@see \Omnichannel\Addons\ContentProjects\Services\ContentProjectWriterCapacitySettingsService}.
 */
final class ContentProjectExecutionLimits
{
    /**
     * Max planning items per Execution Project.
     * Larger per-writer allocations are chunked into multiple projects.
     */
    public const MAX_EXECUTION_PROJECT_ITEMS = 30;
}
