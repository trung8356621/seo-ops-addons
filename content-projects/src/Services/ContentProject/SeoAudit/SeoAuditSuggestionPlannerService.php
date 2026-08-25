<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Services\ContentProject\SeoAudit;

use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\ContentProjects\Models\SeoContentProjectItemOrigin;
use Omnichannel\Addons\ContentProjects\Models\SeoContentProjectPlannerRun;
use Omnichannel\Addons\ContentProjects\Models\SeoProject;
use Omnichannel\Addons\ContentProjects\Models\SeoProjectTask;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Planner\ContentProjectPlannerRunService;
use Omnichannel\Addons\Seo\Services\SeoIssueProjectTaskAssignmentService;
use Omnichannel\Addons\WordPress\Services\SitePrimaryLanguageService;
use App\Models\Site;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Write path: Existing Content suggestions → Draft Content Project items.
 * Uses SeoIssueProjectTaskAssignmentService (shared with Agent action).
 */
final class SeoAuditSuggestionPlannerService
{
    public function __construct(
        private readonly SeoAuditExistingContentSuggestionService $suggestions,
        private readonly SeoAuditSuggestionDecisionService $decisions,
        private readonly SeoIssueProjectTaskAssignmentService $assignment,
        private readonly ContentProjectPlannerRunService $plannerRuns,
    ) {}

    /**
     * @param  list<array{article_id:int, action?:string, reason_codes?:list<string>, recommendation_summary?:string}>  $rows
     * @return array{
     *   added:int,
     *   already_planned:int,
     *   dismissed_skipped:int,
     *   ineligible:int,
     *   domain_mismatch:int,
     *   overflow:int,
     *   duplicate:int,
     *   task_ids: list<int>
     * }
     */
    public function addToDraftProject(
        SeoProject $project,
        array $rows,
        ?int $actorId = null,
        ?int $plannerRunId = null,
    ): array {
        $totals = [
            'added' => 0,
            'already_planned' => 0,
            'dismissed_skipped' => 0,
            'ineligible' => 0,
            'domain_mismatch' => 0,
            'overflow' => 0,
            'duplicate' => 0,
            'task_ids' => [],
        ];

        if (! $project->isDraftPlanning()) {
            $totals['ineligible'] = count($rows);

            return $totals;
        }

        $siteId = (int) ($project->site_id ?? 0);
        if ($siteId <= 0) {
            $totals['ineligible'] = count($rows);

            return $totals;
        }

        $dismissed = $this->decisions->dismissedArticleIds($project);
        $taskIds = [];

        foreach ($rows as $row) {
            if (! is_array($row)) {
                $totals['ineligible']++;

                continue;
            }

            $articleId = (int) ($row['article_id'] ?? 0);
            if ($articleId <= 0) {
                $totals['ineligible']++;

                continue;
            }

            if (isset($dismissed[$articleId])) {
                $totals['dismissed_skipped']++;

                continue;
            }

            $article = SeoArticle::query()
                ->whereKey($articleId)
                ->where('site_id', $siteId)
                ->notContentArchived()
                ->first();

            if (! $article instanceof SeoArticle) {
                $totals['ineligible']++;

                continue;
            }

            $taskType = $this->normalizeAction($row['action'] ?? $row['suggested_action'] ?? null);
            $reasonCodes = array_values(array_map('strval', $row['reason_codes'] ?? []));
            $note = trim((string) ($row['recommendation_summary'] ?? ''));
            if ($note === '') {
                $note = $this->suggestions->recommendationSummary(
                    $taskType === SeoProjectTask::TYPE_REWRITE
                        ? SeoAuditExistingContentSuggestionService::ACTION_REWRITE
                        : SeoAuditExistingContentSuggestionService::ACTION_IMPROVE,
                    $reasonCodes,
                );
            }

            $summary = $this->assignment->assignArticles(
                Collection::make([$article]),
                (int) $project->getKey(),
                $taskType,
                $taskType === SeoProjectTask::TYPE_REWRITE ? SeoProjectTask::REWRITE_MODE_CONTENT : null,
                $note,
            );

            $added = (int) ($summary['added'] ?? 0);
            $dup = (int) ($summary['duplicate'] ?? 0);
            $already = (int) ($summary['already_in_project'] ?? 0);

            $totals['added'] += $added;
            $totals['duplicate'] += $dup;
            $totals['already_planned'] += $already + $dup;
            $totals['domain_mismatch'] += (int) ($summary['domain_mismatch'] ?? 0);
            $totals['overflow'] += (int) ($summary['overflow'] ?? 0);

            if ($added <= 0) {
                continue;
            }

            $task = SeoProjectTask::query()
                ->where('project_id', (int) $project->getKey())
                ->where('article_id', $articleId)
                ->whereNull('archived_at')
                ->orderByDesc('id')
                ->first();

            if (! $task instanceof SeoProjectTask) {
                continue;
            }

            $taskIds[] = (int) $task->getKey();
            $this->recordOriginAndAccepted($project, $task, $articleId, $reasonCodes, $actorId, $plannerRunId);
        }

        $totals['task_ids'] = array_values(array_unique($taskIds));

        return $totals;
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function fillSuggestions(SeoProject $project, array $filters, int|string $limit, ?int $actorId = null): array
    {
        if (! $project->isDraftPlanning()) {
            return [
                'requested' => 0,
                'matched' => 0,
                'added' => 0,
                'already_planned' => 0,
                'dismissed_skipped' => 0,
                'unavailable' => 0,
                'ineligible' => 0,
                'domain_mismatch' => 0,
                'overflow' => 0,
                'duplicate' => 0,
                'task_ids' => [],
                'planner_run_id' => null,
                'blocked' => 'not_draft',
            ];
        }

        $filters = SeoAuditSuggestionFilterSet::normalize($filters);
        $cap = is_string($limit) && strtolower($limit) === 'all'
            ? 500
            : max(1, (int) $limit);

        $matched = $this->suggestions->countMatched($project, $filters);
        $eligible = $this->suggestions->eligibleForFill($project, $filters, $cap);
        $rows = array_map(static fn (array $c): array => [
            'article_id' => (int) $c['article_id'],
            'action' => (string) ($c['suggested_action'] ?? SeoAuditExistingContentSuggestionService::ACTION_IMPROVE),
            'reason_codes' => $c['reason_codes'] ?? [],
            'recommendation_summary' => (string) ($c['recommendation_summary'] ?? ''),
        ], $eligible);

        $run = $this->plannerRuns->recordExecuted(
            $project,
            SeoContentProjectPlannerRun::SOURCE_SEO_AUDIT,
            $cap,
            SeoAuditSuggestionFilterSet::snapshot($filters, $this->resolvedPrimaryLanguage($project)),
            [
                'matched_count' => $matched,
                'added_count' => 0,
                'skipped_count' => 0,
            ],
            $actorId,
        );

        $totals = $this->addToDraftProject($project, $rows, $actorId, (int) $run->getKey());
        $added = (int) ($totals['added'] ?? 0);
        $unavailable = max(0, $cap - $added);
        $skipped = (int) ($totals['already_planned'] ?? 0)
            + (int) ($totals['dismissed_skipped'] ?? 0)
            + (int) ($totals['ineligible'] ?? 0);

        $run->result_summary = array_merge(
            is_array($run->result_summary) ? $run->result_summary : [],
            [
                'kind' => SeoContentProjectPlannerRun::KIND_EXECUTED,
                'matched_count' => $matched,
                'added_count' => $added,
                'skipped_count' => $skipped,
                'unavailable_count' => $unavailable,
                'already_planned' => (int) ($totals['already_planned'] ?? 0),
                'dismissed_skipped' => (int) ($totals['dismissed_skipped'] ?? 0),
            ],
        );
        $run->save();

        return array_merge($totals, [
            'requested' => $cap,
            'matched' => $matched,
            'unavailable' => $unavailable,
            'planner_run_id' => (int) $run->getKey(),
            'filter_snapshot' => $run->configuration_snapshot,
        ]);
    }

    /**
     * @param  list<string>  $reasonCodes
     */
    private function recordOriginAndAccepted(
        SeoProject $project,
        SeoProjectTask $task,
        int $articleId,
        array $reasonCodes,
        ?int $actorId,
        ?int $plannerRunId = null,
    ): void {
        DB::connection('omi_seo_ai')->transaction(function () use ($project, $task, $articleId, $reasonCodes, $actorId, $plannerRunId): void {
            SeoContentProjectItemOrigin::query()->updateOrCreate(
                ['project_task_id' => (int) $task->getKey()],
                [
                    'project_id' => (int) $project->getKey(),
                    'planner_run_id' => $plannerRunId !== null && $plannerRunId > 0 ? $plannerRunId : null,
                    'source_type' => SeoContentProjectItemOrigin::SOURCE_SEO_AUDIT,
                    'source_article_id' => $articleId,
                    'source_finding_ids' => [],
                    'reason_codes' => array_values($reasonCodes),
                    'source_fingerprint' => SeoContentProjectItemOrigin::fingerprint(
                        SeoContentProjectItemOrigin::SOURCE_SEO_AUDIT,
                        $articleId,
                        $reasonCodes,
                    ),
                    'created_at' => now(),
                ],
            );

            $this->decisions->markAccepted($project, $articleId, $actorId, meta: [
                'task_id' => (int) $task->getKey(),
                'task_type' => (string) $task->type,
                'planner_run_id' => $plannerRunId,
            ]);
        });
    }

    private function resolvedPrimaryLanguage(SeoProject $project): ?string
    {
        $site = $project->site;
        if (! $site instanceof Site) {
            $siteId = (int) ($project->site_id ?? 0);
            $site = $siteId > 0 ? Site::query()->find($siteId) : null;
        }
        if (! $site instanceof Site) {
            return null;
        }

        $svc = app(SitePrimaryLanguageService::class);

        return $svc->resolvePrimaryLanguage($site) ?? $svc->seedCandidate($site);
    }

    private function normalizeAction(mixed $value): string
    {
        $action = strtolower(trim((string) $value));

        return $action === SeoAuditExistingContentSuggestionService::ACTION_REWRITE
            || $action === SeoProjectTask::TYPE_REWRITE
            ? SeoProjectTask::TYPE_REWRITE
            : SeoProjectTask::TYPE_IMPROVE;
    }
}
