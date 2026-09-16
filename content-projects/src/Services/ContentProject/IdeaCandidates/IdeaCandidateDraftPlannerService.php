<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Services\ContentProject\IdeaCandidates;

use Illuminate\Support\Facades\DB;
use App\Models\Site;
use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\ContentProjects\Models\SeoContentProjectItemOrigin;
use Omnichannel\Addons\ContentProjects\Models\SeoProject;
use Omnichannel\Addons\ContentProjects\Models\SeoProjectTask;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\ContentProjectItemAllocator;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\NewContent\NewContentSuggestionIdentity;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\SeoAudit\SeoAuditSuggestionPlannerService;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\SitePlanning\PlanningAttributionWriter;
use Omnichannel\Addons\ContentProjects\Support\ContentProject\ContentProjectItemIdentity;
use Omnichannel\Addons\ContentProjects\Support\ContentProject\ContentProjectMonthContext;

/**
 * Add Idea Candidates → Draft items. No AI.
 *
 * Vocabulary Suggest CREATE: permanent tombstone via IdeaCandidateConsumptionService
 * (site_id + source_type + source_ref). Candidate leaves Available Ideas.
 *
 * Vocabulary Suggest REWRITE / IMPROVE: article-scoped planning units via
 * SeoAuditSuggestionPlannerService. Does NOT claim vocabulary tombstone —
 * the suggest phrase remains a CREATE-eligible idea; REWRITE/IMPROVE attach
 * existing articles and may be repeated. Site Planning dedupes by project_task_id.
 */
final class IdeaCandidateDraftPlannerService
{
    public const ACTION_CREATE = 'create';

    public const ACTION_REWRITE = 'rewrite';

    public const ACTION_IMPROVE = 'improve';

    public function __construct(
        private readonly IdeaCandidateQueryService $candidates,
        private readonly ContentProjectItemAllocator $allocator,
        private readonly SeoAuditSuggestionPlannerService $seoAuditPlanner,
        private readonly IdeaCandidateConsumptionService $consumption,
        private readonly PlanningAttributionWriter $attributionWriter,
    ) {}

    /**
     * @param  list<int>  $keywordIds
     * @param  list<int>  $articleIds  Required for rewrite/improve
     * @return array{
     *   added: int,
     *   duplicate_skipped: int,
     *   ineligible: int,
     *   task_ids: list<int>,
     *   action: string
     * }
     */
    public function addToDraft(
        SeoProject $project,
        string $action,
        array $keywordIds,
        array $articleIds = [],
        ?int $actorId = null,
        ?int $siteId = null,
        ?string $planningMonth = null,
    ): array {
        $action = strtolower(trim($action));
        if (! in_array($action, [self::ACTION_CREATE, self::ACTION_REWRITE, self::ACTION_IMPROVE], true)) {
            return [
                'added' => 0,
                'duplicate_skipped' => 0,
                'ineligible' => count($keywordIds),
                'task_ids' => [],
                'action' => $action,
            ];
        }

        if (! $project->isDraftPlanning()) {
            return [
                'added' => 0,
                'duplicate_skipped' => 0,
                'ineligible' => count($keywordIds),
                'task_ids' => [],
                'action' => $action,
            ];
        }

        // Shared Draft is domain-neutral — prefer explicit Working Site over project.site_id.
        $workingSiteId = ($siteId !== null && $siteId > 0)
            ? $siteId
            : (int) ($project->site_id ?? 0);
        $resolved = $this->candidates->resolveVocabularyCandidates($workingSiteId, $keywordIds);
        if ($resolved === []) {
            return [
                'added' => 0,
                'duplicate_skipped' => 0,
                'ineligible' => count($keywordIds),
                'task_ids' => [],
                'action' => $action,
            ];
        }

        $month = ContentProjectMonthContext::normalize($planningMonth);

        if ($action === self::ACTION_CREATE) {
            return $this->addCreateItems($project, $resolved, $workingSiteId, $month);
        }

        return $this->addRewriteOrImprove($project, $action, $resolved, $articleIds, $actorId);
    }

    /**
     * @param  list<IdeaCandidate>  $candidates
     * @return array{added: int, duplicate_skipped: int, ineligible: int, task_ids: list<int>, action: string}
     */
    private function addCreateItems(
        SeoProject $project,
        array $candidates,
        int $workingSiteId,
        string $planningMonth,
    ): array {
        $added = 0;
        $dup = 0;
        $ineligible = 0;
        $taskIds = [];
        /** @var list<array{keyword_id: int, site_id: int}> $cleanupQueue */
        $cleanupQueue = [];

        DB::connection('omi_seo_ai')->transaction(function () use (
            $project,
            $candidates,
            $workingSiteId,
            $planningMonth,
            &$added,
            &$dup,
            &$ineligible,
            &$taskIds,
            &$cleanupQueue,
        ): void {
            $session = $this->allocator->begin($project);
            $monthDate = ContentProjectMonthContext::toDateString($planningMonth);

            foreach ($candidates as $candidate) {
                if (! $candidate instanceof IdeaCandidate) {
                    $ineligible++;

                    continue;
                }

                $phrase = ContentProjectItemIdentity::normalize($candidate->phrase);
                if (! ContentProjectItemIdentity::isValid($phrase, null)) {
                    $ineligible++;

                    continue;
                }

                $itemSiteId = $workingSiteId > 0
                    ? $workingSiteId
                    : (int) ($project->site_id ?? 0);
                if ($itemSiteId <= 0) {
                    $ineligible++;

                    continue;
                }

                // Capacity before claim: failed Draft create must not leave a tombstone.
                $target = $session->projectWithRemainingCapacity();
                if ($target === null || (int) $target->getKey() <= 0) {
                    $ineligible++;

                    continue;
                }

                $claim = $this->consumption->claim(
                    $itemSiteId,
                    SeoContentProjectItemOrigin::SOURCE_VOCABULARY_SUGGEST,
                    $candidate->keywordId,
                    $phrase,
                    $candidate->sourceArticleId,
                    $candidate->vocabularyGroup,
                );
                if (! $claim['claimed']) {
                    $dup++;

                    continue;
                }

                $occupied = $session->occupiedCount($target);
                $taskPayload = [
                    'project_id' => (int) $target->getKey(),
                    'site_id' => $itemSiteId,
                    'type' => SeoProjectTask::TYPE_CREATE,
                    'post_type' => SeoProjectTask::POST_TYPE_ARTICLE,
                    'source_content' => $phrase,
                    'keyword' => $phrase,
                    'title' => null,
                    'status' => SeoProjectTask::STATUS_PENDING,
                    'article_id' => null,
                    'target_date' => $target->monthCarbon()->copy()->addDays($occupied)->format('Y-m-d'),
                ];
                if (\Illuminate\Support\Facades\Schema::connection('omi_seo_ai')->hasColumn('seo_project_tasks', 'planning_month')) {
                    $taskPayload['planning_month'] = $monthDate;
                }

                $task = SeoProjectTask::query()->create($taskPayload);
                $session->recordAdded($target);
                $taskId = (int) $task->getKey();
                $taskIds[] = $taskId;
                $added++;

                $consumedId = (int) ($claim['consumed_idea_id'] ?? 0);
                if ($consumedId > 0) {
                    $this->consumption->attachTask($consumedId, $taskId);
                }

                $origin = $this->recordOrigin(
                    $target,
                    $taskId,
                    $candidate,
                    provenanceArticleId: $candidate->sourceArticleId,
                );

                $this->attributionWriter->writeForTask($task, $origin, [
                    'planning_month' => $planningMonth,
                    'source_type' => SeoContentProjectItemOrigin::SOURCE_VOCABULARY_SUGGEST,
                    'source_keyword_id' => $candidate->keywordId,
                ]);

                $cleanupQueue[] = [
                    'keyword_id' => $candidate->keywordId,
                    'site_id' => $itemSiteId,
                ];
            }

            $session->syncTouchedCounters();
        });

        foreach ($cleanupQueue as $row) {
            $this->consumption->cleanupVocabularySuggestSource(
                (int) $row['keyword_id'],
                (int) $row['site_id'],
            );
        }

        return [
            'added' => $added,
            'duplicate_skipped' => $dup,
            'ineligible' => $ineligible,
            'task_ids' => $taskIds,
            'action' => self::ACTION_CREATE,
        ];
    }

    /**
     * @param  list<IdeaCandidate>  $candidates
     * @param  list<int>  $articleIds
     * @return array{added: int, duplicate_skipped: int, ineligible: int, task_ids: list<int>, action: string}
     */
    private function addRewriteOrImprove(
        SeoProject $project,
        string $action,
        array $candidates,
        array $articleIds,
        ?int $actorId,
    ): array {
        $articleIds = array_values(array_unique(array_filter(array_map('intval', $articleIds))));
        if ($articleIds === []) {
            return [
                'added' => 0,
                'duplicate_skipped' => 0,
                'ineligible' => count($candidates),
                'task_ids' => [],
                'action' => $action,
            ];
        }

        $phrases = array_values(array_filter(array_map(
            static fn (IdeaCandidate $c): string => $c->phrase,
            $candidates,
        )));
        $note = $phrases !== []
            ? 'Idea: '.implode(' · ', array_slice($phrases, 0, 5))
            : '';
        $keywordIds = array_map(static fn (IdeaCandidate $c): int => $c->keywordId, $candidates);
        $reasonCodes = [
            'vocabulary_suggest',
            'idea_action:'.$action,
        ];
        foreach ($candidates as $candidate) {
            if ($candidate->vocabularyGroup !== null && $candidate->vocabularyGroup !== '') {
                $reasonCodes[] = 'vocab_group:'.$candidate->vocabularyGroup;
            }
        }
        $reasonCodes = array_values(array_unique($reasonCodes));

        $rows = array_map(static fn (int $articleId): array => [
            'article_id' => $articleId,
            'action' => $action,
            'reason_codes' => $reasonCodes,
            'recommendation_summary' => $note,
        ], $articleIds);

        $site = $this->resolveWorkingSite($project, $articleIds);
        if (! $site instanceof Site) {
            return [
                'added' => 0,
                'duplicate_skipped' => 0,
                'ineligible' => count($candidates),
                'task_ids' => [],
                'action' => $action,
            ];
        }

        $summary = $this->seoAuditPlanner->addToDraftProject($project, $site, $rows, $actorId);

        // Overlay vocabulary provenance on newly created tasks (seo_audit origin remains primary for article).
        $taskIds = array_values(array_map('intval', $summary['task_ids'] ?? []));
        if ($taskIds !== [] && $keywordIds !== []) {
            SeoContentProjectItemOrigin::query()
                ->whereIn('project_task_id', $taskIds)
                ->get()
                ->each(function (SeoContentProjectItemOrigin $origin) use ($keywordIds, $reasonCodes): void {
                    $findings = is_array($origin->source_finding_ids) ? $origin->source_finding_ids : [];
                    foreach ($keywordIds as $kid) {
                        $findings[] = $kid;
                    }
                    $codes = is_array($origin->reason_codes) ? $origin->reason_codes : [];
                    $origin->source_finding_ids = array_values(array_unique(array_map('intval', $findings)));
                    $origin->reason_codes = array_values(array_unique(array_merge($codes, $reasonCodes)));
                    $origin->save();
                });
        }

        return [
            'added' => (int) ($summary['added'] ?? 0),
            'duplicate_skipped' => (int) ($summary['already_planned'] ?? 0) + (int) ($summary['duplicate'] ?? 0),
            'ineligible' => (int) ($summary['ineligible'] ?? 0),
            'task_ids' => $taskIds,
            'action' => $action,
        ];
    }

    private function recordOrigin(
        SeoProject $project,
        int $taskId,
        IdeaCandidate $candidate,
        ?int $provenanceArticleId,
    ): SeoContentProjectItemOrigin {
        $reasonCodes = [
            'vocabulary_suggest',
            'source_keyword_id:'.$candidate->keywordId,
        ];
        if ($candidate->vocabularyGroup !== null && $candidate->vocabularyGroup !== '') {
            $reasonCodes[] = 'vocab_group:'.$candidate->vocabularyGroup;
        }

        /** @var SeoContentProjectItemOrigin $origin */
        $origin = SeoContentProjectItemOrigin::query()->updateOrCreate(
            ['project_task_id' => $taskId],
            [
                'project_id' => (int) $project->getKey(),
                'planner_run_id' => null,
                'source_type' => SeoContentProjectItemOrigin::SOURCE_VOCABULARY_SUGGEST,
                'source_article_id' => $provenanceArticleId !== null && $provenanceArticleId > 0
                    ? $provenanceArticleId
                    : null,
                'source_finding_ids' => [$candidate->keywordId],
                'reason_codes' => $reasonCodes,
                'source_fingerprint' => SeoContentProjectItemOrigin::planningFingerprint(
                    SeoContentProjectItemOrigin::SOURCE_VOCABULARY_SUGGEST,
                    NewContentSuggestionIdentity::normalize($candidate->phrase),
                    '',
                ),
                'created_at' => now(),
            ],
        );

        return $origin;
    }

    /**
     * @param  list<int>  $articleIds
     */
    private function resolveWorkingSite(SeoProject $project, array $articleIds): ?Site
    {
        $siteId = (int) ($project->site_id ?? 0);
        if ($siteId <= 0 && $articleIds !== []) {
            $article = SeoArticle::query()->find((int) $articleIds[0]);
            $siteId = (int) ($article?->site_id ?? 0);
        }
        if ($siteId <= 0) {
            return null;
        }

        $site = Site::query()->find($siteId);

        return $site instanceof Site ? $site : null;
    }
}
