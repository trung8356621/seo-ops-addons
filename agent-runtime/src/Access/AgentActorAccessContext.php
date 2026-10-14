<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AgentRuntime\Access;

/**
 * Effective actor scope for one Agent turn.
 * Project ids here come from the assignment system, never from the client payload.
 */
final readonly class AgentActorAccessContext
{
    /**
     * @param  list<int>  $projectIds
     * @param  list<int>  $siteIds
     */
    public function __construct(
        public int $userId,
        public string $role,
        public array $projectIds,
        public array $siteIds,
        public bool $teamVisible,
        public bool $includeArchivedProjects,
    ) {}

    /**
     * @param  list<int>  $requestedProjectIds
     * @return list<int>
     */
    public function authorizedProjectIds(array $requestedProjectIds = []): array
    {
        unset($requestedProjectIds);

        return $this->projectIds;
    }
}
