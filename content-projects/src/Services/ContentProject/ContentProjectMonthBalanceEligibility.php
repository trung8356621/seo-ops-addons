<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Services\ContentProject;

use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\ContentProjects\Enums\ContentProjectLifecyclePhase;
use Omnichannel\Addons\ContentProjects\Models\SeoProject;
use Omnichannel\Addons\ContentProjects\Models\SeoProjectTask;
use Omnichannel\Addons\ContentProjects\Support\ContentProject\ContentProjectItemState;

/**
 * Balance Months eligibility — all non-archived items are movable.
 *
 * Ownership contract:
 * - Balance Months redistributes planning_month across selected months.
 * - Generate / Review / Publishing / Published status does not block balance.
 * - Only canonical archive status is excluded from the Balance domain.
 * - Concurrent worker safety uses apply-time locks, not permanent ineligibility.
 */
final class ContentProjectMonthBalanceEligibility
{
    public const REASON_ARCHIVED = 'archived';

    public const REASON_CANCELLED = 'cancelled';

    public const REASON_PROJECT_ARCHIVED = 'project_archived';

    /**
     * @return array{
     *     in_domain: bool,
     *     movable: bool,
     *     fixed: bool,
     *     reason: string|null
     * }
     */
    public function classify(
        SeoProjectTask $task,
        ?SeoProject $project,
        ?ContentProjectItemState $state = null,
        ?SeoArticle $article = null,
    ): array {
        unset($article); // article content/status does not affect Balance eligibility

        if ($task->archived_at !== null) {
            return $this->excluded(self::REASON_ARCHIVED);
        }

        $status = strtolower(trim((string) ($task->status ?? '')));
        if ($status === SeoProjectTask::STATUS_CANCELLED || $status === 'cancelled') {
            return $this->excluded(self::REASON_CANCELLED);
        }

        if ($project instanceof SeoProject) {
            if ($project->isArchive() || $project->isProjectArchived()) {
                return $this->excluded(self::REASON_PROJECT_ARCHIVED);
            }
        }

        if ($state instanceof ContentProjectItemState
            && $state->lifecycleState === ContentProjectLifecyclePhase::Archived
        ) {
            return $this->excluded(self::REASON_ARCHIVED);
        }

        return ['in_domain' => true, 'movable' => true, 'fixed' => false, 'reason' => null];
    }

    /**
     * @param  array{
     *     archived?: bool,
     *     cancelled?: bool,
     *     project_archived?: bool,
     *     lifecycle?: string|null,
     *     generator_done?: bool,
     *     published_at?: bool,
     *     queue?: string|null,
     *     publish_state?: string|null,
     *     execution_state?: string|null,
     *     has_published_revision?: bool,
     *     raw_status?: string|null,
     *     actively_publishing?: bool,
     *     safety_movable?: bool,
     *     safety_reason?: string|null,
     *     project_ok?: bool,
     *     project_reason?: string|null,
     *     project_is_draft?: bool
     * }  $facts
     * @return array{in_domain: bool, movable: bool, fixed: bool, reason: string|null}
     */
    public function classifyFromFacts(array $facts): array
    {
        if (! empty($facts['archived'])) {
            return $this->excluded(self::REASON_ARCHIVED);
        }
        if (! empty($facts['cancelled'])) {
            return $this->excluded(self::REASON_CANCELLED);
        }
        if (! empty($facts['project_archived'])) {
            return $this->excluded(self::REASON_PROJECT_ARCHIVED);
        }

        $lifecycle = ContentProjectLifecyclePhase::tryFrom((string) ($facts['lifecycle'] ?? ''));
        if ($lifecycle === ContentProjectLifecyclePhase::Archived) {
            return $this->excluded(self::REASON_ARCHIVED);
        }

        $raw = strtolower(trim((string) ($facts['raw_status'] ?? '')));
        if ($raw === SeoProjectTask::STATUS_CANCELLED || $raw === 'cancelled') {
            return $this->excluded(self::REASON_CANCELLED);
        }

        return ['in_domain' => true, 'movable' => true, 'fixed' => false, 'reason' => null];
    }

    /**
     * @return array{in_domain: bool, movable: bool, fixed: bool, reason: string|null}
     */
    private function excluded(string $reason): array
    {
        return ['in_domain' => false, 'movable' => false, 'fixed' => false, 'reason' => $reason];
    }
}
