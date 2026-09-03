<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Services;

use Omnichannel\Addons\Content\Enums\ArticleReviewStatus;
use Omnichannel\Addons\ContentProjects\Enums\ContentProjectPublishQueueStatus;
use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\ContentProjects\Models\SeoProject;
use Omnichannel\Addons\ContentProjects\Models\SeoProjectArchive;
use Omnichannel\Addons\ContentProjects\Models\SeoProjectArchiveItem;
use Omnichannel\Addons\ContentProjects\Models\SeoProjectRun;
use Omnichannel\Addons\ContentProjects\Models\SeoProjectTask;
use Omnichannel\Addons\Content\Services\ArticleEditor\ArticleEditorSessionService;
use Omnichannel\Addons\Content\Services\ArticleLastSavedTimestampService;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Workspace\ContentProjectAiWorkspaceDestroyer;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Workspace\ContentProjectWorkspaceCleanupContext;
use Omnichannel\Addons\ContentProjects\Support\ContentProject\ContentProjectExportReviewedAtResolver;
use Omnichannel\Addons\WordPress\Support\WordPressPermalinkBuilder;
use App\Models\User;
use App\Support\RuntimeLogger;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Omnichannel\Addons\Media\Services\ArticlePostImagesService;

/**
 * Archive = Destroy AI Workspace (giữ business article + planning metadata).
 * Restore = chỉ khôi phục business flag — không phục hồi Runtime/Execution cũ.
 */
final class ArchiveContentProjectService
{
    public function __construct(
        private readonly ArticlePostImagesService $postImages,
        private readonly ArticleLastSavedTimestampService $lastSavedTimestamps,
        private readonly WordPressPermalinkBuilder $permalinkBuilder,
        private readonly ContentProjectAiWorkspaceDestroyer $workspaceDestroyer,
        private readonly ArticleEditorSessionService $editorSessions,
        private readonly ContentProjectExportReviewedAtResolver $reviewedAtResolver = new ContentProjectExportReviewedAtResolver(),
    ) {}

    /**
     * @return array{
     *     project_id: int,
     *     project_name: string,
     *     domain_id: int|null,
     *     domain_name: string,
     *     owner_id: int|null,
     *     owner_name: string,
     *     month: int|null,
     *     year: int|null,
     *     total_articles: int,
     *     completed_articles: int,
     *     approved_articles: int,
     *     synced_articles: int,
     *     failed_articles: int,
     *     incomplete_articles: int,
     *     unapproved_articles: int,
     *     unsynced_articles: int,
     *     average_seo_score: float|null,
     *     created_at: string|null,
     * }
     */
    public function buildSummary(SeoProject $project): array
    {
        $project->loadMissing(['site', 'user']);

        $tasks = $this->articleTasksForProject($project);
        $stats = $this->aggregateTaskStats($tasks);

        $monthCarbon = $this->resolveProjectMonth($project);

        return [
            'project_id' => (int) $project->getKey(),
            'project_name' => (string) ($project->name ?? ''),
            'domain_id' => $this->nullablePositiveInt($project->site_id),
            'domain_name' => $this->resolveDomainName($project),
            'owner_id' => $this->nullablePositiveInt($project->user_id),
            'owner_name' => $this->resolveOwnerName($project->user),
            'month' => $monthCarbon?->month,
            'year' => $monthCarbon?->year,
            'total_articles' => $stats['total_articles'],
            'completed_articles' => $stats['completed_articles'],
            'approved_articles' => $stats['approved_articles'],
            'synced_articles' => $stats['synced_articles'],
            'failed_articles' => $stats['failed_articles'],
            'incomplete_articles' => max(0, $stats['total_articles'] - $stats['completed_articles']),
            'unapproved_articles' => max(0, $stats['total_articles'] - $stats['approved_articles']),
            'unsynced_articles' => max(0, $stats['total_articles'] - $stats['synced_articles']),
            'average_seo_score' => $stats['average_seo_score'],
            'created_at' => $this->toIso8601($project->created_at),
        ];
    }

    public function previewStats(SeoProject $project): array
    {
        return $this->buildSummary($project);
    }

    /**
     * @return array{
     *     can_archive: bool,
     *     blocked_reason: string|null,
     *     ai_running: bool,
     *     queue_processing: bool,
     *     waiting_publish: int,
     *     requires_waiting_publish_confirm: bool,
     *     hidden_stale_runs: int,
     *     requires_hidden_stale_runs_confirm: bool,
     * }
     */
    public function archiveGate(SeoProject $project): array
    {
        $projectId = (int) $project->getKey();

        $aiRunning = SeoProjectRun::query()
            ->where('project_id', $projectId)
            ->notConsolidated()
            ->whereIn('status', [SeoProjectRun::STATUS_RUNNING, SeoProjectRun::STATUS_STOPPING])
            ->exists();

        $hiddenStaleRuns = $this->hiddenStaleRunsQuery($projectId)->count();

        $queueProcessing = false;
        $waitingPublish = 0;

        if (Schema::connection('omi_seo_ai')->hasColumn('seo_project_tasks', 'publish_queue_status')) {
            $queueProcessing = SeoProjectTask::query()
                ->where('project_id', $projectId)
                ->active()
                ->where('publish_queue_status', ContentProjectPublishQueueStatus::Processing->value)
                ->exists();

            $waitingPublish = (int) SeoProjectTask::query()
                ->where('project_id', $projectId)
                ->active()
                ->where(function ($q): void {
                    $q->whereNotNull('scheduled_publish_at')
                        ->orWhereIn('publish_queue_status', ContentProjectPublishQueueStatus::activeValues());
                })
                ->count();
        } else {
            $waitingPublish = (int) SeoProjectTask::query()
                ->where('project_id', $projectId)
                ->active()
                ->whereNotNull('scheduled_publish_at')
                ->count();
        }

        $blockedReason = null;
        if ($aiRunning) {
            $blockedReason = __('seo-content-ai::filament.projects.archive_blocked_ai_running');
        } elseif ($queueProcessing) {
            $blockedReason = __('seo-content-ai::filament.projects.archive_blocked_queue_processing');
        }

        return [
            'can_archive' => $blockedReason === null,
            'blocked_reason' => $blockedReason,
            'ai_running' => $aiRunning,
            'queue_processing' => $queueProcessing,
            'waiting_publish' => $waitingPublish,
            'requires_waiting_publish_confirm' => $waitingPublish > 0,
            'hidden_stale_runs' => $hiddenStaleRuns,
            'requires_hidden_stale_runs_confirm' => $hiddenStaleRuns > 0,
        ];
    }

    public function assertCanArchive(
        SeoProject $project,
        bool $confirmWaitingPublish = false,
        bool $confirmHiddenStaleRuns = false,
    ): void {
        $gate = $this->archiveGate($project);
        if (! $gate['can_archive']) {
            throw new RuntimeException((string) $gate['blocked_reason']);
        }

        if ($gate['requires_waiting_publish_confirm'] && ! $confirmWaitingPublish) {
            throw new RuntimeException(__('seo-content-ai::filament.projects.archive_waiting_publish_confirm_required', [
                'count' => $gate['waiting_publish'],
            ]));
        }

        if ($gate['requires_hidden_stale_runs_confirm'] && ! $confirmHiddenStaleRuns) {
            throw new RuntimeException(__('seo-content-ai::filament.projects.archive_hidden_stale_runs_confirm_required', [
                'count' => $gate['hidden_stale_runs'],
            ]));
        }
    }

    public function getCurrentArchive(SeoProject $project): ?SeoProjectArchive
    {
        $projectId = (int) $project->getKey();
        if ($projectId <= 0) {
            return null;
        }

        $archive = SeoProjectArchive::query()
            ->where('project_id', $projectId)
            ->whereNull('restored_at')
            ->orderByDesc('id')
            ->first();

        return $archive instanceof SeoProjectArchive ? $archive : null;
    }

    public function archive(
        SeoProject $project,
        int $userId,
        ?string $note = null,
        bool $confirmWaitingPublish = false,
        bool $confirmHiddenStaleRuns = false,
    ): SeoProjectArchive {
        $this->assertValidUserId($userId);

        if ($project->isArchive()) {
            throw new RuntimeException(__('seo-content-ai::filament.projects.archive_source_is_archive'));
        }

        $this->assertCanArchive($project, $confirmWaitingPublish, $confirmHiddenStaleRuns);

        $note = $this->normalizeNote($note);
        $now = now();
        $cleanupContext = null;

        $archive = DB::connection('omi_seo_ai')->transaction(function () use ($project, $userId, $note, $now, &$cleanupContext): SeoProjectArchive {
            $lockedProject = $this->lockProject($project);

            if ($lockedProject->archived_at !== null) {
                throw new RuntimeException('Project đã được lưu trữ.');
            }

            $liveGate = $this->archiveGate($lockedProject);
            if (! $liveGate['can_archive']) {
                throw new RuntimeException((string) $liveGate['blocked_reason']);
            }

            $cancelledHiddenRuns = $this->cancelHiddenStaleRuns((int) $lockedProject->getKey());

            $summary = $this->buildSummary($lockedProject);
            $archive = $this->resolveArchiveHeader($lockedProject);
            $archivedAt = $now->copy();

            $summaryWithArchivedAt = array_merge($summary, [
                'archived_at' => $this->toIso8601($archivedAt),
            ]);

            $monthCarbon = $this->resolveProjectMonth($lockedProject);

            $archive->fill([
                'site_id' => $summary['domain_id'],
                'owner_id' => $summary['owner_id'],
                'project_name' => $summary['project_name'],
                'project_month' => $monthCarbon?->month,
                'project_year' => $monthCarbon?->year,
                'articles_count' => $summary['total_articles'],
                'total_articles' => $summary['total_articles'],
                'completed_articles' => $summary['completed_articles'],
                'approved_articles' => $summary['approved_articles'],
                'synced_articles' => $summary['synced_articles'],
                'average_seo_score' => $summary['average_seo_score'],
                'note' => $note,
                'archived_by' => $userId,
                'archived_at' => $archivedAt,
                'restored_at' => null,
                'restored_by' => null,
                'summary_snapshot' => $summaryWithArchivedAt,
            ]);
            $archive->save();

            $this->syncArchiveItems($archive, $lockedProject, $now);

            $articleIds = SeoProjectTask::query()
                ->where('project_id', (int) $lockedProject->getKey())
                ->whereNotNull('article_id')
                ->where('article_id', '>', 0)
                ->pluck('article_id')
                ->map(static fn ($id): int => (int) $id)
                ->unique()
                ->values()
                ->all();
            $this->editorSessions->revokeActiveSessionsForArticles($articleIds, 'content_project_archived');

            // Snapshot xong mới destroy AI Workspace — lỗi = rollback cả archive.
            $cleanupContext = $this->workspaceDestroyer->destroyInTransaction($lockedProject);
            $resetTasks = $this->resetProjectTasksForFreshFlow($lockedProject);
            $cleanupContext->bumpStat('project_tasks_reset_for_fresh_flow', $resetTasks);

            $lockedProject->forceFill([
                'archived_at' => $archivedAt,
                'archived_by' => $userId,
            ])->saveQuietly();

            RuntimeLogger::info('content_project_archived', [
                'project_id' => (int) $lockedProject->getKey(),
                'archive_id' => (int) $archive->getKey(),
                'user_id' => $userId,
                'total_articles' => $summary['total_articles'],
                'site_id' => $summary['domain_id'],
                'archived_at' => $this->toIso8601($archivedAt),
                'workspace_destroyed' => true,
                'workspace_stats' => $cleanupContext->stats(),
                'tasks_reset_for_fresh_flow' => $resetTasks,
                'hidden_stale_runs_cancelled' => $cancelledHiddenRuns,
            ]);

            return $archive->fresh(['items']) ?? $archive;
        });

        if ($cleanupContext instanceof ContentProjectWorkspaceCleanupContext) {
            $this->workspaceDestroyer->releaseDeferredSideEffects($cleanupContext);
        }

        return $archive;
    }

    public function restore(SeoProject $project, int $userId): SeoProjectArchive
    {
        $this->assertValidUserId($userId);
        $now = now();

        return DB::connection('omi_seo_ai')->transaction(function () use ($project, $userId, $now): SeoProjectArchive {
            $lockedProject = $this->lockProject($project);

            if ($lockedProject->archived_at === null) {
                throw new RuntimeException('Project chưa được lưu trữ.');
            }

            $archive = $this->getCurrentArchive($lockedProject)
                ?? SeoProjectArchive::query()
                    ->where('project_id', (int) $lockedProject->getKey())
                    ->orderByDesc('id')
                    ->first();

            if (! $archive instanceof SeoProjectArchive) {
                throw new RuntimeException('Project chưa được lưu trữ.');
            }

            // Restore chỉ business flag — không phục hồi Runtime/Execution/Prompt cũ.
            // Generate/Review sau này tạo Workspace mới hoàn toàn.
            $lockedProject->forceFill([
                'archived_at' => null,
                'archived_by' => null,
            ])->saveQuietly();

            $archive->forceFill([
                'restored_at' => $now,
                'restored_by' => $userId,
            ])->save();

            RuntimeLogger::info('content_project_restored', [
                'project_id' => (int) $lockedProject->getKey(),
                'archive_id' => (int) $archive->getKey(),
                'user_id' => $userId,
                'restored_at' => $this->toIso8601($now),
                'site_id' => $this->nullablePositiveInt($lockedProject->site_id),
                'workspace_reused' => false,
            ]);

            return $archive->fresh() ?? $archive;
        });
    }

    /**
     * Re-run archive cleanup for projects that were archived before workspace-reset cleanup existed.
     *
     * @return array<string, int>
     */
    public function cleanupArchivedWorkspace(SeoProjectArchive $archive, int $userId): array
    {
        $this->assertValidUserId($userId);

        $project = $archive->project instanceof SeoProject
            ? $archive->project
            : SeoProject::query()->find((int) ($archive->project_id ?? 0));

        if (! $project instanceof SeoProject) {
            throw new RuntimeException('Project not found.');
        }

        if ($project->archived_at === null && $archive->archived_at === null) {
            throw new RuntimeException('Project is not archived.');
        }

        $cleanupContext = null;
        $stats = DB::connection('omi_seo_ai')->transaction(function () use ($project, $archive, $userId, &$cleanupContext): array {
            $lockedProject = $this->lockProject($project);
            $articleIds = $this->archiveArticleIds($archive);

            if ($articleIds !== []) {
                $this->editorSessions->revokeActiveSessionsForArticles($articleIds, 'content_project_archive_cleanup');
            }

            $cleanupContext = $this->workspaceDestroyer->destroyInTransaction($lockedProject, $articleIds);
            $resetTasks = $this->resetProjectTasksForFreshFlow($lockedProject);
            $cleanupContext->bumpStat('project_tasks_reset_for_fresh_flow', $resetTasks);

            $stats = $cleanupContext->stats();

            RuntimeLogger::info('content_project_archive_workspace_cleaned', [
                'project_id' => (int) $lockedProject->getKey(),
                'archive_id' => (int) $archive->getKey(),
                'user_id' => $userId,
                'article_count' => count($articleIds),
                'stats' => $stats,
            ]);

            return $stats;
        });

        if ($cleanupContext instanceof ContentProjectWorkspaceCleanupContext) {
            $this->workspaceDestroyer->releaseDeferredSideEffects($cleanupContext);
        }

        return $stats;
    }

    /**
     * @return Collection<int, SeoProjectTask>
     */
    private function articleTasksForProject(SeoProject $project): Collection
    {
        // Chỉ task còn gắn project (chưa detach / archive lẻ).
        return $project->tasks()
            ->active()
            ->where('article_id', '>', 0)
            ->with(['article.articleMetas', 'article.site'])
            ->orderBy('id')
            ->get();
    }

    /**
     * @param  Collection<int, SeoProjectTask>  $tasks
     * @return array{
     *     total_articles: int,
     *     completed_articles: int,
     *     approved_articles: int,
     *     synced_articles: int,
     *     failed_articles: int,
     *     average_seo_score: float|null,
     * }
     */
    private function aggregateTaskStats(Collection $tasks): array
    {
        $totalArticles = 0;
        $completedArticles = 0;
        $approvedArticles = 0;
        $syncedArticles = 0;
        $failedArticles = 0;
        $seoScores = [];

        foreach ($tasks as $task) {
            if (! $task instanceof SeoProjectTask) {
                continue;
            }

            $totalArticles++;

            if ((string) $task->status === SeoProjectTask::STATUS_FAILED) {
                $failedArticles++;
            }

            $article = $task->article;
            if ($article instanceof SeoArticle) {
                if ($this->isTaskOrArticleCompleted($task, $article)) {
                    $completedArticles++;
                }

                if ($this->isArticleApproved($article)) {
                    $approvedArticles++;
                }

                if ((int) ($article->wordpressLink?->wp_post_id ?? 0) > 0) {
                    $syncedArticles++;
                }

                if ($article->seoProfile?->seo_score !== null) {
                    $seoScores[] = (float) $article->seoProfile->seo_score;
                }
            } elseif ((string) $task->status === SeoProjectTask::STATUS_COMPLETED) {
                $completedArticles++;
            }
        }

        return [
            'total_articles' => $totalArticles,
            'completed_articles' => $completedArticles,
            'approved_articles' => $approvedArticles,
            'synced_articles' => $syncedArticles,
            'failed_articles' => $failedArticles,
            'average_seo_score' => $seoScores === []
                ? null
                : round(array_sum($seoScores) / count($seoScores), 2),
        ];
    }

    private function syncArchiveItems(SeoProjectArchive $archive, SeoProject $project, Carbon $now): void
    {
        $tasks = $this->articleTasksForProject($project);
        $archiveId = (int) $archive->getKey();
        $currentArticleIds = [];
        $position = 0;

        foreach ($tasks as $task) {
            if (! $task instanceof SeoProjectTask) {
                continue;
            }

            $article = $task->article;
            if (! $article instanceof SeoArticle) {
                continue;
            }

            $position++;
            $articleId = (int) $article->getKey();
            $currentArticleIds[] = $articleId;

            SeoProjectArchiveItem::query()->updateOrCreate(
                [
                    'seo_project_archive_id' => $archiveId,
                    'article_id' => $articleId,
                ],
                [
                    'task_id' => (int) $task->getKey(),
                    'position' => $position,
                    'article_snapshot' => $this->buildArticleSnapshot($task, $article),
                    'updated_at' => $now,
                ],
            );
        }

        if ($currentArticleIds === []) {
            SeoProjectArchiveItem::query()
                ->where('seo_project_archive_id', $archiveId)
                ->delete();

            return;
        }

        SeoProjectArchiveItem::query()
            ->where('seo_project_archive_id', $archiveId)
            ->whereNotIn('article_id', $currentArticleIds)
            ->delete();
    }

    /**
     * @return list<int>
     */
    private function archiveArticleIds(SeoProjectArchive $archive): array
    {
        $ids = [];
        foreach ($archive->items()->get(['article_id']) as $item) {
            $articleId = (int) ($item->article_id ?? 0);
            if ($articleId > 0) {
                $ids[] = $articleId;
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * @return array<string, mixed>
     */
    private function buildArticleSnapshot(SeoProjectTask $task, SeoArticle $article): array
    {
        $article->loadMissing(['articleMetas', 'site', 'wordpressLink']);

        $lastSaved = $this->lastSavedTimestamps->resolve($article);
        $wpPostId = (int) ($article->wordpressLink?->wp_post_id ?? 0);
        $syncStatus = trim((string) ($article->wordpressLink?->sync_status ?? ''));
        if ($syncStatus === '') {
            $syncStatus = $wpPostId > 0 ? 'synced' : 'unsynced';
        }

        $cachedPermalink = trim((string) ($this->getMeta($article, 'wp_permalink') ?? ''));
        $slug = trim((string) ($article->slug ?? ''));
        $wordpressUrl = $this->permalinkBuilder->resolve(
            $article,
            $cachedPermalink,
            $slug !== '' ? $slug : null,
        );

        $queuePayload = $this->resolveWpSyncQueuePayload($article);
        $wpSyncError = trim((string) ($queuePayload['error'] ?? $queuePayload['error_message'] ?? ''));

        $violationsRaw = $this->getMeta($article, 'seo_rule_violations');
        $seoRuleViolations = null;
        if ($violationsRaw !== null && $violationsRaw !== '') {
            $decoded = json_decode($violationsRaw, true);
            $seoRuleViolations = is_array($decoded) ? $decoded : null;
        }

        $reviewedFields = $this->reviewedAtResolver->exportFields($article);

        return [
            'task_id' => (int) $task->getKey(),
            'article_id' => (int) $article->getKey(),
            'title' => (string) ($article->title ?? ''),
            'slug' => $slug,
            'primary_keyword' => $this->getMeta($article, 'seo_focus_keyword'),
            'status' => (string) ($task->status ?? ''),
            'approved_status' => (string) ($article->review_status ?? ''),
            'word_count' => $this->countWords((string) ($article->body ?? '')),
            'image_count' => $this->postImages->countForArticle($article),
            'internal_link_count' => (int) ($article->seoProfile?->internal_link_count ?? 0),
            'external_link_count' => (int) ($article->seoProfile?->external_link_count ?? 0),
            'seo_score' => $article->seoProfile?->seo_score !== null ? (float) $article->seoProfile->seo_score : null,
            'sync_status' => $syncStatus,
            'wordpress_post_id' => $wpPostId > 0 ? $wpPostId : null,
            'wordpress_url' => $wordpressUrl !== '' ? $wordpressUrl : null,
            'created_at' => $this->toIso8601($article->created_at),
            'updated_at' => $this->toIso8601($article->updated_at),
            'completed_at' => $this->toIso8601($task->completed_at),
            'reviewed_at' => $this->toIso8601($reviewedFields['reviewed_at'] ?? null),
            'last_update_wp' => $this->toIso8601($reviewedFields['last_update_wp'] ?? null),
            'wp_created_at' => $this->toIso8601($reviewedFields['wp_created_at'] ?? null),
            'last_saved_at' => $this->toIso8601($lastSaved['at'] ?? null),
            'meta_title' => trim((string) ($article->title ?? '')) !== ''
                ? trim((string) $article->title)
                : null,
            'meta_description' => $this->getMeta($article, 'seo_meta_description'),
            'seo_rule_violations' => $seoRuleViolations,
            'last_synced_at' => $this->toIso8601($article->wordpressLink?->last_synced_at),
            'wp_sync_error' => $wpSyncError !== '' ? $wpSyncError : null,
            'indexed_at' => $this->toIso8601($article->seoProfile?->indexed_at ?? null),
            'previous_indexed_at' => $this->toIso8601($article->seoProfile?->previous_indexed_at ?? null),
        ];
    }

    private function getMeta(SeoArticle $article, string $key): ?string
    {
        $article->loadMissing('articleMetas');

        $value = $article->articleMetas->firstWhere('meta_key', $key)?->meta_value;
        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed !== '' ? $trimmed : null;
    }

    /**
     * @return array<string, mixed>
     */
    private function resolveWpSyncQueuePayload(SeoArticle $article): array
    {
        $raw = $this->getMeta($article, 'wp_sync_queue');
        if ($raw === null) {
            return [];
        }

        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : [];
    }

    private function isTaskOrArticleCompleted(SeoProjectTask $task, SeoArticle $article): bool
    {
        if ((string) $task->status === SeoProjectTask::STATUS_COMPLETED) {
            return true;
        }

        return ArticleReviewStatus::tryFromString((string) ($article->review_status ?? ''))
            === ArticleReviewStatus::Approved;
    }

    private function isArticleApproved(SeoArticle $article): bool
    {
        return ArticleReviewStatus::tryFromString((string) ($article->review_status ?? ''))
            === ArticleReviewStatus::Approved;
    }

    private function resolveArchiveHeader(SeoProject $project): SeoProjectArchive
    {
        $projectId = (int) $project->getKey();

        $current = SeoProjectArchive::query()
            ->where('project_id', $projectId)
            ->whereNull('restored_at')
            ->orderByDesc('id')
            ->first();

        if ($current instanceof SeoProjectArchive) {
            return $current;
        }

        $latest = SeoProjectArchive::query()
            ->where('project_id', $projectId)
            ->orderByDesc('id')
            ->first();

        if ($latest instanceof SeoProjectArchive) {
            return $latest;
        }

        $archive = new SeoProjectArchive;
        $archive->project_id = $projectId;

        return $archive;
    }

    private function lockProject(SeoProject $project): SeoProject
    {
        $locked = SeoProject::query()
            ->whereKey((int) $project->getKey())
            ->lockForUpdate()
            ->first();

        if (! $locked instanceof SeoProject) {
            throw new RuntimeException('Project không tồn tại.');
        }

        return $locked;
    }

    private function resetProjectTasksForFreshFlow(SeoProject $project): int
    {
        $projectId = (int) $project->getKey();
        if ($projectId <= 0) {
            return 0;
        }

        $payload = [
            'status' => SeoProjectTask::STATUS_PENDING,
            'article_id' => null,
            'completed_at' => null,
        ];

        foreach ([
            'content_manager_reviewed_at' => null,
            'content_manager_reviewed_by' => null,
            'publishing_queued_at' => null,
            'publishing_queued_by' => null,
            'scheduled_publish_at' => null,
            'publish_queue_status' => ContentProjectPublishQueueStatus::None->value,
            'publish_published_at' => null,
            'publish_started_at' => null,
            'publish_retry_count' => 0,
            'publish_attempt_count' => 0,
            'last_publish_error' => null,
            'last_publish_failed_at' => null,
            'last_publish_http_status' => null,
            'last_publish_attempt_at' => null,
            'next_publish_retry_at' => null,
            'publishing_started_at' => null,
            'delivery_dispatched_at' => null,
            'publisher_started_at' => null,
            'publish_lease_expires_at' => null,
        ] as $column => $value) {
            if (Schema::connection('omi_seo_ai')->hasColumn('seo_project_tasks', $column)) {
                $payload[$column] = $value;
            }
        }

        return (int) SeoProjectTask::query()
            ->where('project_id', $projectId)
            ->whereNull('archived_at')
            ->update($payload);
    }

    private function assertValidUserId(int $userId): void
    {
        if ($userId <= 0) {
            throw new RuntimeException('Invalid user ID.');
        }
    }

    private function normalizeNote(?string $note): ?string
    {
        $note = trim((string) $note);

        return $note !== '' ? mb_substr($note, 0, 500) : null;
    }

    private function resolveProjectMonth(SeoProject $project): ?Carbon
    {
        if ($project->month === null) {
            return null;
        }

        try {
            return Carbon::parse($project->month)->startOfMonth();
        } catch (\Throwable) {
            return null;
        }
    }

    private function resolveDomainName(SeoProject $project): string
    {
        $site = $project->site;
        if ($site === null) {
            return '';
        }

        return trim((string) ($site->domain ?? ''));
    }

    private function resolveOwnerName(?User $user): string
    {
        if (! $user instanceof User) {
            return '';
        }

        $name = trim((string) ($user->name ?? ''));

        return $name !== '' ? $name : (string) ($user->email ?? '');
    }

    private function nullablePositiveInt(mixed $value): ?int
    {
        $int = (int) $value;

        return $int > 0 ? $int : null;
    }

    private function toIso8601(mixed $value): ?string
    {
        if ($value instanceof Carbon) {
            return $value->toIso8601String();
        }

        if ($value instanceof \DateTimeInterface) {
            return Carbon::instance($value)->toIso8601String();
        }

        if (is_string($value) && trim($value) !== '') {
            try {
                return Carbon::parse($value)->toIso8601String();
            } catch (\Throwable) {
                return null;
            }
        }

        return null;
    }

    /**
     * Run đã consolidate (ẩn khỏi UI) nhưng status vẫn running/stopping — zombie, không phải AI live.
     *
     * @return \Illuminate\Database\Eloquent\Builder<SeoProjectRun>
     */
    private function hiddenStaleRunsQuery(int $projectId): \Illuminate\Database\Eloquent\Builder
    {
        return SeoProjectRun::query()
            ->where('project_id', $projectId)
            ->whereNotNull('consolidated_into_run_id')
            ->whereIn('status', [SeoProjectRun::STATUS_RUNNING, SeoProjectRun::STATUS_STOPPING]);
    }

    private function cancelHiddenStaleRuns(int $projectId): int
    {
        return $this->hiddenStaleRunsQuery($projectId)->update([
            'status' => SeoProjectRun::STATUS_CANCELLED,
            'finished_at' => now(),
        ]);
    }

    private function countWords(string $html): int
    {
        $text = trim(strip_tags($html));
        $text = preg_replace('/\s+/u', ' ', $text) ?? '';

        if ($text === '') {
            return 0;
        }

        preg_match_all('/\pL[\pL\pN\-]*/u', $text, $matches);

        return count($matches[0] ?? []);
    }
}
