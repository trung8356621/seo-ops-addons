<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Services\ContentProject;

use Omnichannel\Addons\ContentProjects\Enums\ContentProjectItemAction;
use Omnichannel\Addons\Content\Filament\Resources\ArticleResource;
use Omnichannel\Addons\Content\Support\ArticleKeywordDistinctCounter;
use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\ContentProjects\Models\SeoProject;
use Omnichannel\Addons\ContentProjects\Models\SeoProjectRun;
use Omnichannel\Addons\ContentProjects\Models\SeoProjectRunItem;
use Omnichannel\Addons\ContentProjects\Models\SeoProjectTask;
use Omnichannel\Addons\ContentProjects\Support\ContentProject\ContentProjectGenerationKeyword;
use Omnichannel\Addons\ContentProjects\Support\ContentProject\ContentProjectFailedOpsDefinition;
use Omnichannel\Addons\ContentProjects\Support\ContentProject\ContentProjectFailureTypeMapper;
use Omnichannel\Addons\ContentProjects\Support\ContentProject\ContentProjectInReviewReportingDefinition;
use Omnichannel\Addons\ContentProjects\Support\ContentProject\ContentProjectLifecycle;
use Omnichannel\Addons\ContentProjects\Support\ContentProject\ContentProjectOpsStateClassifier;
use Omnichannel\Addons\ContentProjects\Support\ContentProject\ContentProjectPublishedEvidence;
use Omnichannel\Addons\ContentProjects\Support\ContentProject\ContentProjectRecentlyCompletedDefinition;
use Omnichannel\Addons\ContentProjects\Support\ContentProject\ContentProjectScheduledDefinition;
use Omnichannel\Addons\ContentProjects\Support\ContentProject\ContentProjectStatusBadgePresenter;
use Omnichannel\Addons\Publishing\Support\PublishingQueue\PublishingQueueHandoffEligibility;
use Omnichannel\Addons\WordPress\Services\ArticleWordPressSyncFlagService;
use Carbon\Carbon;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;

/**
 * Canonical Project Items operations read-model (generation + review + publishing).
 */
final class ContentProjectItemOperationsReadModel
{
    public function __construct(
        private readonly ContentProjectLifecycle $lifecycle,
        private readonly ArticleWordPressSyncFlagService $syncFlags,
        private readonly ContentProjectExecutionStalenessPolicy $staleness,
        private readonly ContentProjectGenerationRecoveryService $generationRecovery,
        private readonly ContentProjectGenerationReadStateStore $generationReadStates,
        private readonly ContentProjectItemGenerationClassifier $generationClassifier,
        private readonly ContentProjectExistingArticleReconciler $existingArticleReconciler,
        private readonly ContentProjectGenerationCapabilityResolver $generationCapability,
    ) {}

    /**
     * @param  array{
     *     search?: string,
     *     type?: string,
     *     generation?: string,
     *     lifecycle?: string,
     *     queue?: string,
     *     scheduled?: string,
     *     failed_only?: bool,
     *     page?: int,
     *     per_page?: int,
     *     reconcile_stale?: bool,
     *     viewer_user_id?: int,
     * }  $filters
     * @return array{
     *     project_id: int,
     *     stats: array<string, int>,
     *     last_execution_at: string|null,
     *     last_execution_status: string|null,
     *     rows: list<array<string, mixed>>,
     *     paginator: LengthAwarePaginator,
     * }
     */
    public function forProject(SeoProject $project, array $filters = []): array
    {
        $projectId = (int) $project->getKey();
        if (($filters['reconcile_stale'] ?? true) === true) {
            // Once per HTTP request — page visit reconciles without auto-starting generation.
            $guardKey = 'seo.cp.stale_gen_reconciled.'.$projectId;
            if (! app()->bound($guardKey)) {
                $this->generationRecovery->reconcileProject($project);
                app()->instance($guardKey, true);
            }
        }

        $articleRepairKey = 'seo.cp.existing_article_reconciled.'.$projectId;
        if (! app()->bound($articleRepairKey)) {
            $this->existingArticleReconciler->reconcileProjectMissingLinks($project);
            app()->instance($articleRepairKey, true);
        }

        $viewerUserId = max(0, (int) ($filters['viewer_user_id'] ?? 0));

        $tasks = SeoProjectTask::query()
            ->where('project_id', $projectId)
            ->planned()
            ->inContentProjectWorkingSet()
            ->with([
                'article.articleMetas' => static fn ($q) => $q->whereIn('meta_key', [
                    'wp_featured_image_url',
                    ArticleKeywordDistinctCounter::META_KEY,
                    ArticleWordPressSyncFlagService::META_LOCAL_EDIT_PENDING,
                    ArticleWordPressSyncFlagService::META_LOCAL_CONTENT_HASH,
                    ArticleWordPressSyncFlagService::META_PUBLISHED_CONTENT_HASH,
                ]),
                'article.wordpressLink',
            ])
            ->orderBy('id')
            ->get();

        $taskIds = $tasks->map(static fn (SeoProjectTask $t): int => (int) $t->id)->all();
        $latestByTask = $this->latestRunItemsByTaskIds($taskIds);
        $viewedByTask = $this->generationReadStates->viewedCompletedAtByItemIds(
            $viewerUserId,
            $projectId,
            $taskIds,
        );
        $preview = $this->generationClassifier->preview($project);
        $generatePendingRunnable = array_fill_keys($preview->runnableTaskIds(), true);
        $keywordDirtyByTask = [];
        foreach ($preview->decisions as $decision) {
            if ($decision->reason === ContentProjectGenerationKeyword::REASON_DIRTY) {
                $keywordDirtyByTask[$decision->taskId] = true;
            }
        }

        $latestRun = SeoProjectRun::query()
            ->where('project_id', $projectId)
            ->orderByDesc('id')
            ->first();

        $rows = [];
        $index = 0;
        foreach ($tasks as $task) {
            if (! $task instanceof SeoProjectTask) {
                continue;
            }
            $index++;
            $tid = (int) $task->id;
            $viewed = $viewedByTask[$tid] ?? null;
            $rows[] = $this->mapRow(
                $project,
                $task,
                $index,
                $latestByTask[$tid] ?? null,
                $viewed?->toIso8601String(),
                isset($generatePendingRunnable[$tid]),
                isset($keywordDirtyByTask[$tid]),
            );
        }

        $stats = ContentProjectOpsStateClassifier::countSummary($rows);

        // Badge SoT (B): project total INCLUDES items handed to Publishing Queue.
        // Working set (Normal) stays scoped to $rows (publishing_queued_at IS NULL).
        $workingSetCount = count($rows);
        $publishingQueueCount = SeoProjectTask::query()
            ->where('project_id', $projectId)
            ->planned()
            ->inPublishingQueue()
            ->count();
        $stats['working_set'] = $workingSetCount;
        $stats['publishing_queue'] = $publishingQueueCount;
        $stats['normal'] = $workingSetCount;
        $stats['total_items'] = $workingSetCount + $publishingQueueCount;

        $filtered = $this->applyFilters(collect($rows), $filters);
        $generationFilter = trim((string) ($filters['generation'] ?? ''));
        if ($generationFilter === ContentProjectRecentlyCompletedDefinition::FILTER) {
            $filtered = collect(
                ContentProjectRecentlyCompletedDefinition::sortNewestFirst($filtered->values()->all()),
            );
        }

        $perPage = max(10, min(100, (int) ($filters['per_page'] ?? 30)));
        $page = max(1, (int) ($filters['page'] ?? LengthAwarePaginator::resolveCurrentPage()));
        $total = $filtered->count();
        $slice = $filtered->forPage($page, $perPage)->values()->all();

        $paginator = new LengthAwarePaginator(
            $slice,
            $total,
            $perPage,
            $page,
            [
                'path' => LengthAwarePaginator::resolveCurrentPath(),
                'query' => request()->query(),
            ],
        );

        return [
            'project_id' => $projectId,
            'stats' => $stats,
            'last_execution_at' => $latestRun?->finished_at?->format('d/m/Y H:i')
                ?? $latestRun?->started_at?->format('d/m/Y H:i'),
            'last_execution_status' => $latestRun !== null ? (string) $latestRun->status : null,
            'rows' => $slice,
            'paginator' => $paginator,
        ];
    }

    /**
     * Lightweight ops summary for lazy refresh (no table rows).
     *
     * @return array{
     *     pending: int,
     *     needs_review: int,
     *     failed: int,
     *     review: int,
     *     approved: int,
     *     scheduled: int,
     *     published: int,
     *     running: int,
     * }
     */
    public function summaryForProject(SeoProject $project, int $viewerUserId): array
    {
        $payload = $this->forProject($project, [
            'viewer_user_id' => $viewerUserId,
            'reconcile_stale' => false,
            'per_page' => 1,
            'page' => 1,
        ]);

        return self::normalizeSummaryStats($payload['stats'] ?? []);
    }

    /**
     * @param  array<string, mixed>  $stats
     * @return array{
     *     total_items: int,
     *     working_set: int,
     *     publishing_queue: int,
     *     normal: int,
     *     draft: int,
     *     pending: int,
     *     needs_review: int,
     *     failed: int,
     *     review: int,
     *     approved: int,
     *     scheduled: int,
     *     published: int,
     *     running: int,
     * }
     */
    public static function normalizeSummaryStats(array $stats): array
    {
        $needsReview = (int) ($stats['recently_completed'] ?? $stats['needs_review'] ?? $stats['ai_inbox'] ?? 0);
        $workingSet = (int) ($stats['working_set'] ?? $stats['normal'] ?? $stats['total_items'] ?? 0);

        return [
            // Project-wide badge SoT — includes items handed to Publishing Queue.
            'total_items' => (int) ($stats['total_items'] ?? 0),
            // Content Project working set (publishing_queued_at IS NULL) — Normal card.
            'working_set' => $workingSet,
            'publishing_queue' => (int) ($stats['publishing_queue'] ?? 0),
            'normal' => (int) ($stats['normal'] ?? $workingSet),
            'draft' => (int) ($stats['draft'] ?? 0),
            'pending' => (int) ($stats['pending'] ?? 0),
            'needs_review' => $needsReview,
            'failed' => (int) ($stats['failed'] ?? 0),
            'review' => (int) ($stats['waiting_review'] ?? $stats['review'] ?? 0),
            'approved' => (int) ($stats['approved'] ?? 0),
            'scheduled' => (int) ($stats['waiting_publish'] ?? $stats['scheduled'] ?? 0),
            'published' => (int) ($stats['published'] ?? 0),
            'running' => (int) ($stats['running'] ?? $stats['pending'] ?? 0),
        ];
    }

    /**
     * Unread successful completions for mark-all — same definition as summary card.
     *
     * @return array<int, \Carbon\CarbonInterface> project_item_id => generation_completed_at
     */
    public function unreadSuccessfulCompletions(SeoProject $project, int $viewerUserId): array
    {
        $payload = $this->forProject($project, [
            'viewer_user_id' => $viewerUserId,
            'generation' => ContentProjectRecentlyCompletedDefinition::FILTER,
            'reconcile_stale' => false,
            'per_page' => 10000,
            'page' => 1,
        ]);

        $map = [];
        foreach ($payload['rows'] as $row) {
            $tid = (int) ($row['task_id'] ?? 0);
            $completed = ContentProjectRecentlyCompletedDefinition::parseTimestamp(
                $row['generation_completed_at'] ?? null,
            );
            if ($tid > 0 && $completed !== null && ! empty($row['is_recently_completed'])) {
                $map[$tid] = $completed;
            }
        }

        return $map;
    }

    /**
     * @param  array<string, mixed>|null  $exec
     * @return array<string, mixed>
     */
    private function mapRow(
        SeoProject $project,
        SeoProjectTask $task,
        int $index,
        ?array $exec,
        ?string $viewedGenerationCompletedAt = null,
        bool $isGeneratePendingRunnable = false,
        bool $isGenerationKeywordDirty = false,
    ): array {
        $tid = (int) $task->id;
        $article = $task->article;
        $staleEval = $this->staleness->evaluateTask($task);
        $isStaleGeneration = (bool) ($staleEval['stale'] ?? false);
        $runError = $exec !== null
            ? trim((string) ($exec['error_message'] ?? $exec['message'] ?? ''))
            : '';
        $state = $this->lifecycle->resolveState(
            $task,
            $article instanceof SeoArticle ? $article : null,
            [
                'run_item_status' => $exec['status'] ?? null,
                'run_item_error' => $runError !== '' ? $runError : null,
                'stale_generation' => $isStaleGeneration,
                'execution_running' => (bool) ($staleEval['has_fresh_active_execution'] ?? false),
            ],
        );
        $phase = $state->lifecycleState;
        $type = SeoProjectTask::normalizeType($task->type);

        $articleId = (int) ($task->article_id ?? 0);
        $keywordOriginal = ContentProjectGenerationKeyword::originalKeyword($task);
        $keywordEffective = ContentProjectGenerationKeyword::effective($task);
        $hasKeywordOverride = ContentProjectGenerationKeyword::hasOverride($task);
        $keyword = $keywordOriginal;
        $title = trim((string) ($task->title ?? ''));
        if ($articleId <= 0 || ! ($article instanceof SeoArticle)) {
            $title = '';
        } elseif ($title === '') {
            $title = trim((string) ($article->title ?? ''));
        }
        $source = trim((string) ($task->source_content ?? ''));
        if ($keyword === '' && $source !== '' && $type !== SeoProjectTask::TYPE_IMPROVE) {
            $keyword = $source;
            if ($keywordOriginal === '') {
                $keywordOriginal = $source;
            }
            if ($keywordEffective === '') {
                $keywordEffective = $source;
            }
        }

        $primary = $title !== '' ? $title : ($keywordEffective !== '' ? $keywordEffective : ($keyword !== '' ? $keyword : '#'.$tid));
        $articleEmptyLabel = $articleId <= 0 || ! ($article instanceof SeoArticle)
            ? 'Chưa có bài viết'
            : null;

        $thumbnailUrl = null;
        if ($article instanceof SeoArticle && $article->relationLoaded('articleMetas')) {
            $thumbnailUrl = trim((string) (
                $article->articleMetas->firstWhere('meta_key', 'wp_featured_image_url')?->meta_value ?? ''
            ));
            $thumbnailUrl = $thumbnailUrl !== '' ? $thumbnailUrl : null;
        }

        $execStatusEarly = strtolower((string) ($exec['status'] ?? ''));
        $latestAttemptQueued = in_array($execStatusEarly, ['pending', 'processing'], true);

        $message = $state->currentError ?? '';
        if ($latestAttemptQueued) {
            // New attempt accepted — hide stale failed message on the row.
            $message = '';
        }
        if ($message === '' && $state->currentErrorSource->value === 'publish' && $task->last_publish_error !== null) {
            $message = (string) $task->last_publish_error;
        }

        // Latest run-item attempt is SoT for Generation — independent from article lifecycle.
        $genStatus = (string) ($task->status ?? 'pending');
        if (in_array($execStatusEarly, ['failed', 'error', 'cancelled', 'stopped', 'timeout'], true)) {
            $genStatus = SeoProjectTask::STATUS_FAILED;
        } elseif ($latestAttemptQueued && $genStatus === SeoProjectTask::STATUS_FAILED) {
            // Prefer latest run-item attempt over sticky task.failed until worker claims.
            $genStatus = SeoProjectTask::STATUS_PENDING;
        } elseif (in_array($execStatusEarly, ['success', 'completed'], true)
            && in_array($genStatus, [SeoProjectTask::STATUS_COMPLETED, SeoProjectTask::STATUS_REVIEWING, 'completed', 'reviewing'], true)
        ) {
            // keep completed only when latest attempt also succeeded
            $genStatus = SeoProjectTask::STATUS_COMPLETED;
        }
        $queueStatus = (string) ($task->publish_queue_status ?? 'none');
        if ($queueStatus === '') {
            $queueStatus = 'none';
        }

        $observedPostStatus = ContentProjectPublishedEvidence::resolveObservedPostStatus(
            $article instanceof SeoArticle ? $article : null,
        );
        $hasUnpublishedChanges = $article instanceof SeoArticle
            && $this->syncFlags->hasUnpublishedChanges($article);

        $lastActivityCarbon = $this->resolveLastActivity($task, $article, $exec);
        $isGenuineRunning = (string) ($task->status ?? '') === SeoProjectTask::STATUS_WRITING
            && ! $isStaleGeneration
            && (bool) ($staleEval['has_fresh_active_execution'] ?? false);
        if ($execStatusEarly === 'processing' && ! $isStaleGeneration) {
            $isGenuineRunning = true;
            $genStatus = SeoProjectTask::STATUS_WRITING;
        }
        $hasResumableCheckpoint = false;
        $generationRecoveryAction = ContentProjectGenerationRecoveryDecision::ACTION_NONE;
        $generationRecoveryReason = '';
        $resumableFromStep = null;
        try {
            $capability = $this->generationCapability->decide($project, $task, [
                'recover_stale' => false,
                'persist_article_repair' => false,
            ]);
            $generationRecoveryAction = $capability->action;
            $generationRecoveryReason = $capability->reason;
            $resumableFromStep = $capability->resumableFromStep;
            $hasResumableCheckpoint = $capability->showResume();
            $taskArticleId = (int) ($task->article_id ?? 0);
            if (
                $taskArticleId > 0
                && $capability->existingArticleId !== null
                && $capability->existingArticleId > 0
                && (int) $capability->existingArticleId === $taskArticleId
            ) {
                $articleId = $taskArticleId;
                if (! $article instanceof SeoArticle || (int) $article->id !== $articleId) {
                    $article = SeoArticle::query()->find($articleId);
                    if ($article instanceof SeoArticle) {
                        $task->setRelation('article', $article);
                    }
                }
            }
            if ($capability->isActive()) {
                $isGenuineRunning = true;
            }
        } catch (\Throwable) {
            // Fail open to legacy failed-exec heuristic only for resume hint.
            $hasResumableCheckpoint = ! $isGenuineRunning
                && ! $isStaleGeneration
                && $exec !== null
                && in_array(strtolower((string) ($exec['status'] ?? '')), ['failed', 'cancelled', 'stopped', 'timeout'], true)
                && trim((string) ($exec['action'] ?? '')) !== '';
            $generationRecoveryAction = $hasResumableCheckpoint
                ? ContentProjectGenerationRecoveryDecision::ACTION_RESUME
                : ContentProjectGenerationRecoveryDecision::ACTION_NONE;
        }

        $displayGenStatus = $isStaleGeneration ? SeoProjectTask::STATUS_FAILED : $genStatus;
        $displayPhase = $phase;

        $queueBadge = ContentProjectStatusBadgePresenter::queue($queueStatus);

        if ($isStaleGeneration && ($message === '' || $message === null)) {
            $message = ContentProjectGenerationRecoveryService::RECOVERY_MESSAGE;
        }

        $generationCompletedAt = null;
        $execStatusForInbox = strtolower((string) ($exec['status'] ?? ''));
        if (in_array($execStatusForInbox, ['success', 'completed'], true)
            && ! empty($exec['finished_at_iso'])
        ) {
            $generationCompletedAt = (string) $exec['finished_at_iso'];
        }

        $rowBase = [
            'generation_status' => $displayGenStatus,
            'execution_status' => $exec['status'] ?? null,
            'is_genuinely_running' => $isGenuineRunning,
            'generation_completed_at' => $generationCompletedAt,
            'viewed_generation_completed_at' => $viewedGenerationCompletedAt,
            'review_status' => $article instanceof SeoArticle
                ? strtolower(trim((string) ($article->review_status ?? '')))
                : '',
            'content_manager_reviewed_at' => $task->content_manager_reviewed_at?->toIso8601String(),
            'is_content_manager_reviewed' => $task->content_manager_reviewed_at !== null,
            'lifecycle' => $displayPhase->value,
            'is_scheduled' => $task->scheduled_publish_at !== null,
            'scheduled_raw' => $task->scheduled_publish_at?->toIso8601String(),
            'queue_status' => $queueStatus,
            'publish_published_at' => $task->publish_published_at?->toIso8601String(),
            'observed_post_status' => $observedPostStatus,
            'has_unpublished_changes' => $hasUnpublishedChanges,
            'has_published_revision' => $state->hasPublishedRevision,
            'is_generation_stale' => $isStaleGeneration,
            'can_generate' => in_array(ContentProjectItemAction::Generate, $state->availableActions, true)
                && ! $task->isGenerationBlocked(),
            'message' => is_string($message) ? $message : '',
            'current_error_source' => $state->currentErrorSource->value,
            // Rows here always come from scopeInContentProjectWorkingSet() — kept explicit
            // for eligibility computation and to stay a stable contract for consumers.
            'article_id' => $articleId > 0 ? $articleId : 0,
            'type' => $type,
            'is_improve' => $type === SeoProjectTask::TYPE_IMPROVE,
            'publishing_queued_at' => $task->publishing_queued_at?->toIso8601String(),
            'in_publishing_queue' => $task->publishing_queued_at !== null,
            'generation_blocked' => $task->isGenerationBlocked(),
            'generation_blocked_at' => $task->generation_blocked_at?->toIso8601String(),
            'generation_block_reason' => $task->generation_block_reason !== null
                ? (string) $task->generation_block_reason
                : null,
        ];
        $classified = ContentProjectOpsStateClassifier::classify($rowBase);
        $genBadge = match ($classified['generation_key']) {
            'running' => ContentProjectStatusBadgePresenter::generation('writing', 'running'),
            'failed' => ContentProjectStatusBadgePresenter::generation('failed', 'failed'),
            'generated' => ContentProjectStatusBadgePresenter::generation('completed', 'success'),
            default => ContentProjectStatusBadgePresenter::generation('pending', null),
        };
        $workflowBadge = ContentProjectStatusBadgePresenter::workflow($classified['workflow_key']);
        $reportingBadge = ContentProjectStatusBadgePresenter::reporting($classified['reporting_key']);

        return [
            'index' => $index,
            'task_id' => $tid,
            'type' => $type,
            'type_label' => match ($type) {
                SeoProjectTask::TYPE_REWRITE => 'rewrite',
                SeoProjectTask::TYPE_IMPROVE => 'improve',
                default => 'new',
            },
            'primary_label' => $primary,
            'article_empty_label' => $articleEmptyLabel,
            'thumbnail_url' => $thumbnailUrl,
            'has_featured_image' => $thumbnailUrl !== null,
            'keyword' => $keywordEffective !== '' ? $keywordEffective : ($keyword !== '' ? $keyword : '—'),
            'keyword_original' => $keywordOriginal !== '' ? $keywordOriginal : ($keyword !== '' ? $keyword : '—'),
            'keyword_effective' => $keywordEffective !== '' ? $keywordEffective : '—',
            'has_keyword_override' => $hasKeywordOverride,
            'generation_keyword_dirty' => $isGenerationKeywordDirty,
            'can_edit_keyword_override' => $type !== SeoProjectTask::TYPE_IMPROVE && ! $task->isGenerationBlocked(),
            'title' => $title !== '' ? $title : '—',
            'article_id' => $articleId > 0 ? $articleId : null,
            'article_missing' => $articleId > 0 && ! ($article instanceof SeoArticle),
            'stale_missing_article' => SeoProjectTask::isNewArticleType($type)
                && $articleId > 0
                && ! ($article instanceof SeoArticle),
            'article_edit_url' => ($articleId > 0 && $article instanceof SeoArticle)
                ? ArticleResource::getUrl('edit', ['record' => $articleId])
                : null,
            'article_slug' => $article instanceof SeoArticle ? (string) ($article->slug ?? '') : '',
            'generation_status' => $displayGenStatus,
            'execution_status' => $exec['status'] ?? null,
            'current_step' => $exec['action'] ?? null,
            'lifecycle' => $displayPhase->value,
            'workflow_key' => $classified['workflow_key'],
            'generation_key' => $classified['generation_key'],
            'summary_bucket' => $classified['summary_bucket'],
            'reporting_key' => $classified['reporting_key'],
            'failure_type' => $classified['failure_type'],
            'review_status' => $rowBase['review_status'],
            'queue_status' => $queueStatus,
            'item_state' => $state->toArray(),
            'current_error_source' => $state->currentErrorSource->value,
            'available_actions' => array_map(
                static fn ($a): string => $a->value,
                $state->availableActions,
            ),
            'scheduled_at' => $task->scheduled_publish_at?->format('d/m/Y H:i'),
            'scheduled_raw' => $task->scheduled_publish_at?->toIso8601String(),
            'is_scheduled' => $task->scheduled_publish_at !== null,
            'publish_published_at' => $rowBase['publish_published_at'],
            'message' => $message !== '' ? $message : null,
            'last_activity' => $lastActivityCarbon?->diffForHumans() ?? '—',
            'last_activity_full' => $lastActivityCarbon?->format('d/m/Y H:i:s'),
            'last_run_at' => $exec['finished_at'] ?? $exec['started_at'] ?? null,
            'generation_completed_at' => $generationCompletedAt,
            'viewed_generation_completed_at' => $viewedGenerationCompletedAt,
            'content_manager_reviewed_at' => $rowBase['content_manager_reviewed_at'],
            'content_manager_reviewed_by' => $task->content_manager_reviewed_by !== null
                ? (int) $task->content_manager_reviewed_by
                : null,
            'is_content_manager_reviewed' => (bool) ($rowBase['is_content_manager_reviewed'] ?? false),
            'is_recently_completed' => $classified['is_needs_review'],
            'is_in_review_reporting' => $classified['is_in_review_reporting'],
            'show_reporting_chip' => $classified['show_reporting_chip'],
            'can_generate' => (bool) $rowBase['can_generate'],
            'is_generate_pending_runnable' => $isGeneratePendingRunnable,
            'can_regen' => $articleId > 0
                && $type !== SeoProjectTask::TYPE_IMPROVE
                && ! $task->isGenerationBlocked()
                && in_array(ContentProjectItemAction::Rerun, $state->availableActions, true),
            'can_run_again' => $generationRecoveryAction === ContentProjectGenerationRecoveryDecision::ACTION_RERUN
                || $generationRecoveryAction === ContentProjectGenerationRecoveryDecision::ACTION_GENERATE,
            'is_generation_stale' => $isStaleGeneration,
            'is_genuinely_running' => $isGenuineRunning,
            'is_activity_processing' => $isGenuineRunning,
            'has_resumable_checkpoint' => $hasResumableCheckpoint,
            'generation_recovery_action' => $generationRecoveryAction,
            'generation_recovery_reason' => $generationRecoveryReason,
            'existing_article_link' => match ($generationRecoveryAction) {
                ContentProjectGenerationRecoveryDecision::ACTION_SELECT_EXISTING_ARTICLE => match ($generationRecoveryReason) {
                    'existing_article_ambiguous' => 'ambiguous',
                    'article_owned_by_active_task' => 'conflict',
                    default => 'unlinked',
                },
                default => ($articleId > 0 ? 'linked' : null),
            },
            'resumable_from_step' => $resumableFromStep,
            'is_improve' => $type === SeoProjectTask::TYPE_IMPROVE,
            'generation_blocked' => (bool) ($rowBase['generation_blocked'] ?? false),
            'generation_blocked_at' => $rowBase['generation_blocked_at'] ?? null,
            'generation_block_reason' => $rowBase['generation_block_reason'] ?? null,
            'generation_badge' => $genBadge,
            'lifecycle_badge' => $workflowBadge,
            'workflow_badge' => $workflowBadge,
            'reporting_badge' => $reportingBadge,
            'queue_badge' => $queueBadge,
            'has_unpublished_changes' => $hasUnpublishedChanges,
            'observed_post_status' => $observedPostStatus,
            'in_publishing_queue' => $rowBase['in_publishing_queue'],
            'can_send_to_publishing_queue' => PublishingQueueHandoffEligibility::canSend($rowBase),
            'keywords_count' => $this->distinctKeywordCount($article),
        ];
    }

    /**
     * Distinct vocabulary keywords from eager-loaded `seo_article_keywords` meta.
     * Missing meta or missing article → 0. Does not read article body.
     */
    private function distinctKeywordCount(mixed $article): int
    {
        if (! $article instanceof SeoArticle || ! $article->relationLoaded('articleMetas')) {
            return 0;
        }

        $raw = $article->articleMetas->firstWhere('meta_key', ArticleKeywordDistinctCounter::META_KEY)?->meta_value;

        return ArticleKeywordDistinctCounter::count($raw);
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $rows
     * @param  array<string, mixed>  $filters
     * @return Collection<int, array<string, mixed>>
     */
    private function applyFilters(Collection $rows, array $filters): Collection
    {
        $search = strtolower(trim((string) ($filters['search'] ?? '')));
        $type = trim((string) ($filters['type'] ?? ''));
        $generation = trim((string) ($filters['generation'] ?? ''));
        $lifecycle = trim((string) ($filters['lifecycle'] ?? ''));
        $reporting = trim((string) ($filters['reporting'] ?? ''));
        $workflow = trim((string) ($filters['workflow'] ?? ''));
        $failureType = strtolower(trim((string) ($filters['failure_type'] ?? '')));
        $queue = trim((string) ($filters['queue'] ?? ''));
        $scheduled = trim((string) ($filters['scheduled'] ?? ''));
        $failedOnly = (bool) ($filters['failed_only'] ?? false);

        return $rows->filter(static function (array $row) use (
            $search,
            $type,
            $generation,
            $lifecycle,
            $reporting,
            $workflow,
            $failureType,
            $queue,
            $scheduled,
            $failedOnly
        ): bool {
            if ($search !== '') {
                $hay = strtolower(implode(' ', [
                    (string) $row['primary_label'],
                    (string) $row['keyword'],
                    (string) ($row['keyword_original'] ?? ''),
                    (string) ($row['keyword_effective'] ?? ''),
                    (string) $row['title'],
                    (string) ($row['article_slug'] ?? ''),
                    (string) $row['task_id'],
                    (string) ($row['article_id'] ?? ''),
                ]));
                if (! str_contains($hay, $search)) {
                    return false;
                }
            }

            if ($type !== '' && (string) $row['type'] !== $type) {
                return false;
            }

            if ($workflow !== '' && $workflow !== 'all'
                && ! ContentProjectOpsStateClassifier::matchesSummaryFilter($row, $workflow)
            ) {
                return false;
            }

            if ($generation !== '') {
                if (in_array($generation, [
                    ContentProjectRecentlyCompletedDefinition::FILTER,
                    ContentProjectInReviewReportingDefinition::FILTER,
                    'needs_review',
                    'in_review',
                    'draft',
                ], true)) {
                    if (! ContentProjectOpsStateClassifier::matchesSummaryFilter($row, $generation)) {
                        return false;
                    }
                } else {
                    $classifiedGen = (string) ($row['generation_key'] ?? '');
                    $ok = match ($generation) {
                        'pending' => $classifiedGen === 'pending',
                        'running' => $classifiedGen === 'running',
                        'success', 'generated' => $classifiedGen === 'generated',
                        'failed' => $classifiedGen === 'failed',
                        default => (string) ($row['generation_status'] ?? '') === $generation,
                    };
                    if (! $ok) {
                        return false;
                    }
                }
            }

            if ($lifecycle !== '') {
                $wanted = array_filter(array_map('trim', explode(',', $lifecycle)));
                if ($wanted === []) {
                    return true;
                }
                $matched = false;
                foreach ($wanted as $w) {
                    if (in_array($w, [
                        'draft',
                        'pending',
                        'approved',
                        'waiting_publish',
                        'scheduled',
                        'published',
                        'failed',
                    ], true)) {
                        $filterKey = $w === 'scheduled' ? 'waiting_publish' : $w;
                        if (ContentProjectOpsStateClassifier::matchesSummaryFilter($row, $filterKey)) {
                            $matched = true;
                            break;
                        }
                        continue;
                    }
                    $workflowKey = (string) ($row['workflow_key'] ?? '');
                    $life = (string) ($row['lifecycle'] ?? '');
                    if ($w === $workflowKey || $w === $life || ($w === 'draft' && $workflowKey === 'draft')) {
                        $matched = true;
                        break;
                    }
                }
                if (! $matched) {
                    return false;
                }
            }

            if ($reporting !== ''
                && ! ContentProjectOpsStateClassifier::matchesSummaryFilter($row, $reporting)
            ) {
                return false;
            }

            if ($queue !== '' && (string) $row['queue_status'] !== $queue) {
                return false;
            }

            if ($scheduled === 'yes' && ! ContentProjectScheduledDefinition::matches($row)) {
                return false;
            }
            if ($scheduled === 'no' && ContentProjectScheduledDefinition::matches($row)) {
                return false;
            }

            if ($failedOnly && ! ContentProjectFailedOpsDefinition::matches($row)) {
                return false;
            }

            if ($failureType !== ''
                && ContentProjectFailedOpsDefinition::matches($row)
                && ContentProjectFailureTypeMapper::resolve($row) !== $failureType
            ) {
                return false;
            }

            if ($failureType !== '' && ! ContentProjectFailedOpsDefinition::matches($row)) {
                return false;
            }

            return true;
        })->values();
    }

    /**
     * @param  array<string, mixed>|null  $exec
     */
    private function resolveLastActivity(SeoProjectTask $task, mixed $article, ?array $exec): ?Carbon
    {
        $candidates = [];
        if ($article instanceof SeoArticle) {
            foreach ([$article->last_manual_saved_at, $article->wordpressLink?->last_synced_at, $article->updated_at] as $dt) {
                if ($dt !== null) {
                    $candidates[] = Carbon::parse($dt);
                }
            }
        }
        if ($task->updated_at !== null) {
            $candidates[] = Carbon::parse($task->updated_at);
        }
        foreach (['finished_at', 'started_at'] as $key) {
            if (! empty($exec[$key])) {
                try {
                    $candidates[] = Carbon::createFromFormat('d/m/Y H:i', (string) $exec[$key]);
                } catch (\Throwable) {
                    try {
                        $candidates[] = Carbon::parse((string) $exec[$key]);
                    } catch (\Throwable) {
                    }
                }
            }
        }

        if ($candidates === []) {
            return null;
        }

        usort($candidates, static fn (Carbon $a, Carbon $b): int => $b <=> $a);

        return $candidates[0];
    }

    /**
     * @param  list<int>  $taskIds
     * @return array<int, array<string, mixed>>
     */
    private function latestRunItemsByTaskIds(array $taskIds): array
    {
        if ($taskIds === [] || ! Schema::connection('omi_seo_ai')->hasTable('seo_project_run_items')) {
            return [];
        }

        $items = SeoProjectRunItem::query()
            ->whereIn('task_id', $taskIds)
            ->orderByDesc('id')
            ->get(['id', 'task_id', 'run_id', 'status', 'action', 'error_message', 'started_at', 'finished_at']);

        $map = [];
        foreach ($items as $item) {
            $tid = (int) $item->task_id;
            if ($tid <= 0 || isset($map[$tid])) {
                continue;
            }
            $map[$tid] = [
                'id' => (int) $item->id,
                'run_id' => (int) $item->run_id,
                'status' => (string) ($item->status ?? ''),
                'action' => $item->action !== null ? (string) $item->action : null,
                'error_message' => $item->error_message !== null ? (string) $item->error_message : null,
                'message' => null,
                'started_at' => $item->started_at?->format('d/m/Y H:i'),
                'finished_at' => $item->finished_at?->format('d/m/Y H:i'),
                'finished_at_iso' => $item->finished_at?->toIso8601String(),
            ];
        }

        return $map;
    }
}
