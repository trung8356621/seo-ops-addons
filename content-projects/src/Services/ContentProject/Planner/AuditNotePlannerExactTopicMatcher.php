<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Services\ContentProject\Planner;

use Omnichannel\Addons\ContentProjects\Services\ContentProject\AuditNotes\AuditNoteClusterSuggestionQuery;

final class AuditNotePlannerExactTopicMatcher implements PlannerExactTopicMatcher
{
    public function __construct(
        private readonly AuditNoteClusterSuggestionQuery $clusters,
    ) {}

    public function findExactNormalizedNameMatches(int $siteId, string $normalizedName): array
    {
        return $this->clusters->findExactNormalizedNameMatches($siteId, $normalizedName);
    }
}
