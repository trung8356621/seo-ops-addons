<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Services\ContentProject;

/**
 * UX + server gate for project-level Generate working items / Test run.
 */
final class ContentProjectProjectActionDecision
{
    public const REASON_NONE = '';

    public const REASON_ARCHIVED = 'archived';

    public const REASON_NO_ELIGIBLE = 'no_eligible';

    public const REASON_BULK_ACTIVE = 'bulk_active';

    public const REASON_TEST_ACTIVE = 'test_active';

    /**
     * @param  list<int>  $eligibleTaskIds
     */
    public function __construct(
        public readonly bool $enabled,
        public readonly string $reasonCode,
        public readonly array $eligibleTaskIds,
    ) {}

    public function eligibleCount(): int
    {
        return count($this->eligibleTaskIds);
    }
}
