<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Services\ContentProject;

use Omnichannel\Addons\ContentProjects\Models\SeoProject;

/**
 * Canonical project-level availability for Generate working items and Test run.
 * Eligible IDs = classifier runnable ∩ improve-manual-only filter.
 */
final class ContentProjectProjectGenerationGate
{
    public function __construct(
        private readonly ContentProjectItemGenerationClassifier $classifier,
        private readonly ContentProjectActiveGenerationRunDetector $activeRuns,
    ) {}

    public function forGenerateWorkingItems(SeoProject $project): ContentProjectProjectActionDecision
    {
        return $this->decide($project, ContentProjectProjectActionDecision::REASON_BULK_ACTIVE);
    }

    public function forTestRun(SeoProject $project): ContentProjectProjectActionDecision
    {
        return $this->decide($project, ContentProjectProjectActionDecision::REASON_TEST_ACTIVE);
    }

    /**
     * @return list<int>
     */
    public function eligibleTaskIds(SeoProject $project, bool $allowImproveGeneration = false): array
    {
        if ($project->isProjectArchived() || $project->isArchive()) {
            return [];
        }

        if ($project->isDraftPlanning()) {
            return [];
        }

        $preview = $this->classifier->preview($project);
        $ids = $preview->runnableTaskIds();
        $typesById = [];
        foreach ($preview->runDecisions() as $decision) {
            $typesById[$decision->taskId] = $decision->itemType;
        }

        $filtered = ContentProjectImproveManualOnlyGenerationGuard::filterItemIds(
            $ids,
            $typesById,
            $allowImproveGeneration,
        );

        return $filtered['eligible_ids'];
    }

    private function decide(SeoProject $project, string $conflictReason): ContentProjectProjectActionDecision
    {
        if ($project->isProjectArchived() || $project->isArchive()) {
            return self::resolve([], false, $conflictReason, archived: true);
        }

        if ($project->isDraftPlanning()) {
            return self::resolve([], false, $conflictReason, draftPlanning: true);
        }

        $eligible = $this->eligibleTaskIds($project);
        $conflictActive = $conflictReason === ContentProjectProjectActionDecision::REASON_BULK_ACTIVE
            ? $this->activeRuns->hasActiveBulkGeneration((int) $project->getKey())
            : $this->activeRuns->hasActiveTestRun((int) $project->getKey());

        return self::resolve($eligible, $conflictActive, $conflictReason);
    }

    /**
     * Pure composition for UI/server gates (unit-testable without DB).
     *
     * @param  list<int>  $eligibleTaskIds
     */
    public static function resolve(
        array $eligibleTaskIds,
        bool $conflictActive,
        string $conflictReason,
        bool $archived = false,
        bool $draftPlanning = false,
    ): ContentProjectProjectActionDecision {
        if ($archived) {
            return new ContentProjectProjectActionDecision(
                false,
                ContentProjectProjectActionDecision::REASON_ARCHIVED,
                [],
            );
        }

        if ($draftPlanning) {
            return new ContentProjectProjectActionDecision(
                false,
                ContentProjectProjectActionDecision::REASON_DRAFT_PLANNING,
                [],
            );
        }

        if ($eligibleTaskIds === []) {
            return new ContentProjectProjectActionDecision(
                false,
                ContentProjectProjectActionDecision::REASON_NO_ELIGIBLE,
                [],
            );
        }

        if ($conflictActive) {
            return new ContentProjectProjectActionDecision(false, $conflictReason, $eligibleTaskIds);
        }

        return new ContentProjectProjectActionDecision(
            true,
            ContentProjectProjectActionDecision::REASON_NONE,
            $eligibleTaskIds,
        );
    }
}
