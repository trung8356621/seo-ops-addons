<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Services;

use Omnichannel\Addons\ContentProjects\Enums\ContentProjectErrorCode;
use Omnichannel\Addons\ContentProjects\Enums\SeoProjectTaskEventType;
use Omnichannel\Addons\Content\Filament\Resources\ArticleResource;
use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\ContentProjects\Models\SeoContentArchiveItem;
use Omnichannel\Addons\ContentProjects\Models\SeoProject;
use Omnichannel\Addons\ContentProjects\Models\SeoProjectArchiveItem;
use Omnichannel\Addons\ContentProjects\Models\SeoProjectTask;
use Omnichannel\Addons\WordPress\Support\WordPressPermalinkBuilder;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * @deprecated Per-article / move-to-warehouse archive. Đơn vị archive chính =
 * {@see ArchiveContentProjectService}. Giữ class cho diagnose/repair/legacy mirror.
 * Không gọi từ UI Content Project list.
 */
final class SeoProjectArchiveService
{
    public function __construct(
        private readonly SeoProjectTaskLifecycleService $lifecycle,
        private readonly SeoProjectTaskEventRecorder $eventRecorder,
        private readonly SeoProjectRunItemsReader $runItemsReader,
    ) {}

    /**
     * Archive toàn bộ active task của project tháng.
     * Prefer recoverable/idempotent per-task hơn all-or-nothing toàn project.
     *
     * @return array{archived: int, tasks_removed: int}
     */
    public function archiveProject(SeoProject $project, int $archivedByUserId, ?string $note = null): array
    {
        if ($archivedByUserId <= 0) {
            throw new RuntimeException(__('seo-content-ai::filament.projects.archive_failed'));
        }

        if ($project->isArchive()) {
            throw new RuntimeException(__('seo-content-ai::filament.projects.archive_source_is_archive'));
        }

        $note = $this->normalizeNote($note);
        $lockedProject = $this->lockMonthlyProject($project);
        $siteId = (int) ($lockedProject->site_id ?? 0);
        if ($siteId <= 0) {
            throw new RuntimeException(__('seo-content-ai::filament.projects.domain_required'));
        }

        $activeTasks = $lockedProject->tasks()
            ->active()
            ->orderBy('id')
            ->get();

        $resolvedFromRuns = $this->resolveArticleIdsFromProjectRuns($lockedProject);
        $tasksWithArticles = $this->hydrateTasksWithResolvedArticles(
            $activeTasks,
            $resolvedFromRuns['by_task_id'],
            $resolvedFromRuns['by_identity'],
        );

        $now = now();
        $archivedArticles = 0;
        $tasksArchived = 0;
        /** @var array<int, true> $archivedArticleIds */
        $archivedArticleIds = [];

        foreach ($tasksWithArticles as $task) {
            if (! $task instanceof SeoProjectTask) {
                continue;
            }

            $this->lifecycle->archive($task, $archivedByUserId, [
                'from_project_id' => (int) $lockedProject->getKey(),
            ]);
            $tasksArchived++;

            $articleId = (int) ($task->article_id ?? 0);
            if ($articleId <= 0 || isset($archivedArticleIds[$articleId])) {
                continue;
            }

            $mirrorOk = $this->tryPersistArchiveMirrorFromTask(
                $task->fresh() ?? $task,
                $lockedProject,
                $siteId,
                $archivedByUserId,
                $note,
                $now,
            );
            if ($mirrorOk) {
                $archivedArticleIds[$articleId] = true;
                $archivedArticles++;
            }
        }

        foreach ($resolvedFromRuns['article_ids'] as $orphanArticleId) {
            if (isset($archivedArticleIds[$orphanArticleId])) {
                continue;
            }

            $linkedElsewhere = SeoProjectTask::query()
                ->active()
                ->where('article_id', $orphanArticleId)
                ->where('project_id', '!=', (int) $lockedProject->getKey())
                ->exists();

            if ($linkedElsewhere) {
                continue;
            }

            // Mirror article-only — không chọn/xóa task khác.
            $this->tryPersistArchiveMirrorFromArticle(
                $siteId,
                $orphanArticleId,
                (int) $lockedProject->getKey(),
                $archivedByUserId,
                $note,
                $now,
                null,
            );
            $archivedArticleIds[$orphanArticleId] = true;
            $archivedArticles++;
        }

        if ($tasksArchived === 0 && $archivedArticles === 0) {
            throw new RuntimeException(__('seo-content-ai::filament.projects.archive_no_active_articles'));
        }

        $lockedProject->syncTotalTasksCounter();
        $lockedProject->update([
            'status' => SeoProject::STATUS_MANUAL,
        ]);

        return [
            'archived' => max($archivedArticles, $tasksArchived),
            'tasks_removed' => $tasksArchived,
        ];
    }

    /**
     * Archive một/nhiều task theo exact task ID — không hard-delete.
     *
     * @param  list<int>  $taskIds
     * @param  array<int, int>  $articleIdByTaskId  Fallback khi task.article_id trống (vd. lấy từ run item).
     * @return array{archived: int}
     */
    public function archiveTasks(
        SeoProject $project,
        array $taskIds,
        int $archivedByUserId,
        ?string $note = null,
        array $articleIdByTaskId = [],
    ): array {
        if ($archivedByUserId <= 0) {
            throw new RuntimeException(__('seo-content-ai::filament.projects.archive_failed'));
        }

        if ($project->isArchive()) {
            throw new RuntimeException(__('seo-content-ai::filament.projects.archive_source_is_archive'));
        }

        $taskIds = array_values(array_unique(array_filter(
            array_map(static fn (mixed $id): int => (int) $id, $taskIds),
            static fn (int $id): bool => $id > 0,
        )));

        if ($taskIds === []) {
            throw new RuntimeException(__('seo-content-ai::filament.projects.archive_no_active_articles'));
        }

        $articleIdByTaskId = array_filter(
            array_map(static fn (mixed $id): int => (int) $id, $articleIdByTaskId),
            static fn (int $id): bool => $id > 0,
        );

        $note = $this->normalizeNote($note);
        $lockedProject = $this->lockMonthlyProject($project);
        $siteId = (int) ($lockedProject->site_id ?? 0);
        if ($siteId <= 0) {
            throw new RuntimeException(__('seo-content-ai::filament.projects.domain_required'));
        }

        $tasks = $lockedProject->tasks()
            ->active()
            ->whereIn('id', $taskIds)
            ->orderBy('id')
            ->get()
            ->keyBy(static fn (SeoProjectTask $task): int => (int) $task->id);

        $now = now();
        $archived = 0;

        foreach ($taskIds as $taskId) {
            $task = $tasks->get($taskId);
            if (! $task instanceof SeoProjectTask) {
                Log::warning('seo.project_archive.task_not_found', [
                    'task_id' => $taskId,
                    'project_id' => (int) $lockedProject->getKey(),
                    'error_code' => ContentProjectErrorCode::TaskNotFound->value,
                ]);

                continue;
            }

            $articleId = (int) ($task->article_id ?? 0);
            if ($articleId <= 0) {
                $articleId = (int) ($articleIdByTaskId[$taskId] ?? 0);
            }
            if ($articleId > 0 && (int) ($task->article_id ?? 0) !== $articleId) {
                $task->article_id = $articleId;
                $task->save();
            }

            $this->lifecycle->archive($task, $archivedByUserId, [
                'from_project_id' => (int) $lockedProject->getKey(),
            ]);
            $archived++;

            if ($articleId > 0) {
                $this->tryPersistArchiveMirrorFromTask(
                    $task->fresh() ?? $task,
                    $lockedProject,
                    $siteId,
                    $archivedByUserId,
                    $note,
                    $now,
                );
            }
        }

        if ($archived === 0) {
            throw new RuntimeException(__('seo-content-ai::filament.projects.archive_no_active_articles'));
        }

        $lockedProject->syncTotalTasksCounter();

        return [
            'archived' => $archived,
        ];
    }

    public function countForSite(int $siteId): int
    {
        if ($siteId <= 0) {
            return 0;
        }

        return (int) SeoContentArchiveItem::query()
            ->where('site_id', $siteId)
            ->count();
    }

    /**
     * Hủy archive: restore task lifecycle + clear article flag + xóa warehouse mirror.
     * Không xóa / tạo task mới.
     *
     * @return array{article_id: int, task_id: int|null}
     */
    public function unarchiveItem(int $archiveItemId, int $siteId, int $requestedByUserId): array
    {
        if ($archiveItemId <= 0 || $siteId <= 0) {
            throw new RuntimeException(__('seo-content-ai::filament.projects.unarchive_item_not_found'));
        }

        if ($requestedByUserId <= 0) {
            throw new RuntimeException(__('seo-content-ai::filament.projects.unarchive_failed'));
        }

        return DB::connection((new SeoContentArchiveItem)->getConnectionName())->transaction(
            function () use ($archiveItemId, $siteId, $requestedByUserId): array {
                /** @var SeoContentArchiveItem|null $item */
                $item = SeoContentArchiveItem::query()
                    ->whereKey($archiveItemId)
                    ->where('site_id', $siteId)
                    ->lockForUpdate()
                    ->first();

                if (! $item instanceof SeoContentArchiveItem) {
                    throw new RuntimeException(__('seo-content-ai::filament.projects.unarchive_item_not_found'));
                }

                $articleId = (int) ($item->article_id ?? 0);
                if ($articleId <= 0) {
                    throw new RuntimeException(__('seo-content-ai::filament.projects.unarchive_item_not_found'));
                }

                $restoredTaskId = $this->resolveAndRestoreTaskForUnarchive(
                    $item,
                    $articleId,
                    $requestedByUserId,
                );

                $item->delete();

                SeoArticle::query()
                    ->whereKey($articleId)
                    ->update([
                        'content_archived_at' => null,
                        'content_archived_by' => null,
                    ]);

                $restoredArticle = SeoArticle::query()->find($articleId);
                if ($restoredArticle instanceof SeoArticle) {
                    app(\Omnichannel\Addons\Agent\Automation\BusinessHook\Support\BusinessHookEmitter::class)
                        ->articleRestored($restoredArticle);
                }

                if ($restoredTaskId !== null) {
                    $this->eventRecorder->record(
                        SeoProjectTask::query()->find($restoredTaskId),
                        SeoProjectTaskEventType::ArticleRestore,
                        null,
                        null,
                        [
                            'task_id' => $restoredTaskId,
                            'article_id' => $articleId,
                            'archive_mirror_id' => $archiveItemId,
                            'actor_id' => $requestedByUserId,
                        ],
                        null,
                        $requestedByUserId,
                    );
                }

                SeoProjectArchiveItem::query()
                    ->where('article_id', $articleId)
                    ->delete();

                return [
                    'article_id' => $articleId,
                    'task_id' => $restoredTaskId,
                ];
            },
        );
    }

    /**
     * Resolve exact archived task — không chọn đại khi ambiguous.
     */
    private function resolveAndRestoreTaskForUnarchive(
        SeoContentArchiveItem $item,
        int $articleId,
        int $requestedByUserId,
    ): ?int {
        $taskId = (int) ($item->task_id ?? 0);
        if ($taskId > 0) {
            $task = SeoProjectTask::query()->whereKey($taskId)->first();
            if ($task instanceof SeoProjectTask) {
                $this->lifecycle->restore($task, $requestedByUserId, [
                    'archive_mirror_id' => (int) $item->id,
                ]);

                return (int) $task->id;
            }

            return null;
        }

        $candidates = SeoProjectTask::query()
            ->archived()
            ->where('article_id', $articleId)
            ->orderBy('id')
            ->get();

        if ($candidates->count() > 1) {
            throw new RuntimeException(ContentProjectErrorCode::ArchiveTaskAmbiguous->value);
        }

        $task = $candidates->first();
        if (! $task instanceof SeoProjectTask) {
            return null;
        }

        $this->lifecycle->restore($task, $requestedByUserId, [
            'archive_mirror_id' => (int) $item->id,
        ]);

        return (int) $task->id;
    }

    /**
     * Dashboard kho lưu trữ — group theo ngày hoàn tất (giống Articles Reviewed).
     *
     * @return array{
     *     groups: list<array{
     *         date: string,
     *         date_label: string,
     *         month_key: string,
     *         month_label: string,
     *         count: int,
     *         articles: list<array{
     *             id: int,
     *             task_id: int,
     *             title: string,
     *             author: string,
     *             connected_at: string,
     *             connected_label: string,
     *             completed_time: string,
     *             edit_url: string,
     *             view_url: string|null
     *         }>
     *     }>,
     *     month_options: list<array{value: string, label: string}>
     * }
     */
    /**
     * @param  list<int>|int  $siteIds  Single site id (legacy) or list of accessible site ids.
     */
    public function buildGroupedDashboard(array|int $siteIds): array
    {
        $normalizedSiteIds = array_values(array_unique(array_filter(
            array_map(
                static fn (mixed $id): int => (int) $id,
                is_array($siteIds) ? $siteIds : [$siteIds],
            ),
            static fn (int $id): bool => $id > 0,
        )));

        if ($normalizedSiteIds === []) {
            return [
                'groups' => [],
                'month_options' => [],
            ];
        }

        $items = SeoContentArchiveItem::query()
            ->whereIn('site_id', $normalizedSiteIds)
            ->with([
                'article:id,title,slug,user_id,site_id',
                'article.user:id,name',
                'article.site',
                'article.articleMetas',
            ])
            ->orderByDesc('completed_at')
            ->orderByDesc('archived_at')
            ->orderByDesc('id')
            ->get();

        /** @var array<string, array{date: string, date_label: string, month_key: string, month_label: string, count: int, articles: list<array<string, mixed>>}> $grouped */
        $grouped = [];
        /** @var array<string, string> $monthLabels */
        $monthLabels = [];

        foreach ($items as $item) {
            if (! $item instanceof SeoContentArchiveItem) {
                continue;
            }

            $completedRaw = $item->completed_at ?? $item->archived_at ?? $item->connected_at ?? $item->created_at;
            if ($completedRaw === null) {
                continue;
            }

            $completedAt = $completedRaw instanceof Carbon
                ? $completedRaw
                : Carbon::parse((string) $completedRaw);

            $dateKey = $completedAt->toDateString();
            $monthKey = $completedAt->format('Y-m');
            $monthLabels[$monthKey] = $completedAt->format('m/Y');

            if (! isset($grouped[$dateKey])) {
                $grouped[$dateKey] = [
                    'date' => $dateKey,
                    'date_label' => $completedAt->translatedFormat('d/m/Y'),
                    'month_key' => $monthKey,
                    'month_label' => $monthLabels[$monthKey],
                    'count' => 0,
                    'articles' => [],
                ];
            }

            $article = $item->article;
            $title = trim((string) ($article?->title ?? $item->source_content ?? ''));
            if ($title === '') {
                $title = 'Article #'.(int) ($item->article_id ?? 0);
            }

            $author = trim((string) ($article?->user?->name ?? ''));
            $connectedAt = $item->connected_at instanceof Carbon
                ? $item->connected_at
                : ($item->connected_at !== null ? Carbon::parse((string) $item->connected_at) : null);

            $grouped[$dateKey]['articles'][] = [
                'id' => (int) ($item->article_id ?? 0),
                'archive_item_id' => (int) $item->id,
                'task_id' => (int) ($item->task_id ?? 0),
                'title' => $title,
                'author' => $author !== '' ? $author : '—',
                'connected_at' => $connectedAt?->toDateString() ?? '',
                'connected_label' => $connectedAt?->format('d/m/Y H:i') ?? '—',
                'completed_time' => $completedAt->format('H:i'),
                'edit_url' => $article !== null
                    ? ArticleResource::getUrl('edit', ['record' => $article])
                    : '#',
                'view_url' => $article !== null
                    ? $this->resolveArticleViewUrl($article)
                    : null,
            ];
            $grouped[$dateKey]['count']++;
        }

        krsort($monthLabels);
        $monthOptions = [];
        foreach ($monthLabels as $value => $label) {
            $monthOptions[] = [
                'value' => $value,
                'label' => $label,
            ];
        }

        return [
            'groups' => array_values($grouped),
            'month_options' => $monthOptions,
        ];
    }

    /**
     * @deprecated Legacy batch loader.
     *
     * @return Collection<int, \Omnichannel\Addons\ContentProjects\Models\SeoProjectArchive>
     */
    public function batchesForProject(SeoProject $project): Collection
    {
        return $project->archives()
            ->with([
                'archivedByUser:id,name',
                'items.article:id,title,status,user_id,created_at',
                'items.article.user:id,name',
            ])
            ->orderByDesc('created_at')
            ->get();
    }

    private function lockMonthlyProject(SeoProject $project): SeoProject
    {
        /** @var SeoProject|null $lockedProject */
        $lockedProject = SeoProject::query()
            ->whereKey($project->getKey())
            ->lockForUpdate()
            ->first();

        if (! $lockedProject instanceof SeoProject || $lockedProject->isArchive()) {
            throw new RuntimeException(__('seo-content-ai::filament.projects.archive_failed'));
        }

        return $lockedProject;
    }

    /**
     * @return array{
     *     by_task_id: array<int, int>,
     *     by_identity: array<string, int>,
     *     article_ids: list<int>
     * }
     */
    private function resolveArticleIdsFromProjectRuns(SeoProject $project): array
    {
        /** @var array<int, int> $byTaskId */
        $byTaskId = [];
        /** @var array<string, int> $byIdentity */
        $byIdentity = [];
        /** @var array<int, true> $articleIds */
        $articleIds = [];

        $runs = $project->runs()
            ->orderByDesc('id')
            ->get();

        foreach ($runs as $run) {
            $items = $this->runItemsReader->forRunAsArrays($run);
            foreach ($items as $item) {
                if (! is_array($item)) {
                    continue;
                }

                if ((string) ($item['status'] ?? '') !== 'success') {
                    continue;
                }

                $articleId = (int) ($item['article_id'] ?? 0);
                if ($articleId <= 0) {
                    continue;
                }

                $articleIds[$articleId] = true;

                $taskId = (int) ($item['task_id'] ?? 0);
                if ($taskId > 0 && ! isset($byTaskId[$taskId])) {
                    $byTaskId[$taskId] = $articleId;
                }

                $retryTaskId = (int) ($item['retry_task_id'] ?? 0);
                if ($retryTaskId > 0 && ! isset($byTaskId[$retryTaskId])) {
                    $byTaskId[$retryTaskId] = $articleId;
                }

                $identity = $this->taskIdentityKeyFromItem($item);
                if ($identity !== '' && ! isset($byIdentity[$identity])) {
                    $byIdentity[$identity] = $articleId;
                }
            }
        }

        return [
            'by_task_id' => $byTaskId,
            'by_identity' => $byIdentity,
            'article_ids' => array_keys($articleIds),
        ];
    }

    /**
     * @param  Collection<int, SeoProjectTask>  $tasks
     * @param  array<int, int>  $articleIdByTaskId
     * @param  array<string, int>  $articleIdByIdentity
     * @return Collection<int, SeoProjectTask>
     */
    private function hydrateTasksWithResolvedArticles(
        Collection $tasks,
        array $articleIdByTaskId,
        array $articleIdByIdentity,
    ): Collection {
        /** @var array<int, true> $usedArticleIds */
        $usedArticleIds = [];
        $hydrated = collect();

        foreach ($tasks as $task) {
            if (! $task instanceof SeoProjectTask) {
                continue;
            }

            $articleId = (int) ($task->article_id ?? 0);
            if ($articleId <= 0) {
                $articleId = (int) ($articleIdByTaskId[(int) $task->id] ?? 0);
            }
            if ($articleId <= 0) {
                $articleId = (int) ($articleIdByIdentity[$this->taskIdentityKeyFromTask($task)] ?? 0);
            }

            if ($articleId <= 0 || isset($usedArticleIds[$articleId])) {
                continue;
            }

            if ((int) ($task->article_id ?? 0) !== $articleId) {
                SeoProjectTask::query()
                    ->where('article_id', $articleId)
                    ->whereKeyNot((int) $task->id)
                    ->update(['article_id' => null]);

                $payload = ['article_id' => $articleId];
                if ($task->connected_at === null) {
                    $payload['connected_at'] = now();
                }

                SeoProjectTask::query()->whereKey((int) $task->id)->update($payload);
                $task->article_id = $articleId;
                if ($task->connected_at === null) {
                    $task->connected_at = $payload['connected_at'] ?? now();
                }
            }

            $usedArticleIds[$articleId] = true;
            $hydrated->push($task);
        }

        return $hydrated->values();
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private function taskIdentityKeyFromItem(array $item): string
    {
        $type = trim((string) ($item['type'] ?? ''));
        $source = mb_strtolower(trim((string) ($item['source_content'] ?? '')));
        if ($type === '' || $source === '') {
            return '';
        }

        $postType = SeoProjectTask::isNewArticleType($type)
            ? SeoProjectTask::normalizePostType($item['post_type'] ?? null)
            : '';

        return implode('|', [$type, $postType, $source]);
    }

    private function taskIdentityKeyFromTask(SeoProjectTask $task): string
    {
        return $this->taskIdentityKeyFromItem([
            'type' => $task->type,
            'post_type' => $task->post_type,
            'source_content' => $task->source_content,
        ]);
    }

    private function tryPersistArchiveMirrorFromTask(
        SeoProjectTask $task,
        SeoProject $fromProject,
        int $siteId,
        int $archivedByUserId,
        ?string $note,
        Carbon $now,
    ): bool {
        try {
            $this->persistArchiveItemFromTask(
                $task,
                $fromProject,
                $siteId,
                $archivedByUserId,
                $note,
                $now,
            );

            return true;
        } catch (\Throwable $exception) {
            Log::error('seo.project_archive.mirror_failed', [
                'task_id' => (int) $task->id,
                'article_id' => (int) ($task->article_id ?? 0),
                'error_code' => ContentProjectErrorCode::ArchiveMirrorFailed->value,
                'error' => $exception->getMessage(),
            ]);

            return false;
        }
    }

    private function tryPersistArchiveMirrorFromArticle(
        int $siteId,
        int $articleId,
        int $fromProjectId,
        int $archivedByUserId,
        ?string $note,
        Carbon $now,
        ?int $taskId,
    ): bool {
        try {
            $this->persistArchiveItemFromArticle(
                $siteId,
                $articleId,
                $fromProjectId,
                $archivedByUserId,
                $note,
                $now,
                $taskId,
            );

            return true;
        } catch (\Throwable $exception) {
            Log::error('seo.project_archive.mirror_failed', [
                'task_id' => $taskId,
                'article_id' => $articleId,
                'error_code' => ContentProjectErrorCode::ArchiveMirrorFailed->value,
                'error' => $exception->getMessage(),
            ]);

            return false;
        }
    }

    private function persistArchiveItemFromTask(
        SeoProjectTask $task,
        SeoProject $fromProject,
        int $siteId,
        int $archivedByUserId,
        ?string $note,
        Carbon $now,
    ): void {
        $articleId = (int) ($task->article_id ?? 0);
        if ($articleId <= 0) {
            return;
        }

        $article = SeoArticle::query()->find($articleId);
        if (! $article instanceof SeoArticle) {
            $this->eventRecorder->record(
                $task,
                SeoProjectTaskEventType::ArticleRelationMissing,
                (string) $task->status,
                (string) $task->status,
                [
                    'task_id' => (int) $task->id,
                    'article_id' => $articleId,
                    'error_code' => ContentProjectErrorCode::ArticleRelationMissing->value,
                    'actor_id' => $archivedByUserId,
                ],
                null,
                $archivedByUserId,
            );

            return;
        }

        $completedAt = $task->completed_at;
        if ($completedAt === null && (string) $task->status === SeoProjectTask::STATUS_COMPLETED) {
            $completedAt = $task->updated_at ?? $now;
        }

        $mirror = $this->upsertArchiveItem(
            siteId: $siteId,
            articleId: $articleId,
            fromProjectId: (int) $fromProject->getKey(),
            archivedByUserId: $archivedByUserId,
            connectedAt: $task->connected_at ?? $now,
            completedAt: $completedAt ?? $now,
            archivedAt: $now,
            note: $note,
            sourceContent: (string) ($task->source_content ?? ''),
            taskType: $task->type !== null ? (string) $task->type : null,
            taskId: (int) $task->id,
        );

        $this->eventRecorder->record(
            $task,
            SeoProjectTaskEventType::ArticleArchive,
            null,
            null,
            [
                'task_id' => (int) $task->id,
                'article_id' => $articleId,
                'archive_mirror_id' => (int) $mirror->id,
                'actor_id' => $archivedByUserId,
            ],
            null,
            $archivedByUserId,
        );
    }

    private function persistArchiveItemFromArticle(
        int $siteId,
        int $articleId,
        int $fromProjectId,
        int $archivedByUserId,
        ?string $note,
        Carbon $now,
        ?int $taskId = null,
    ): void {
        if ($articleId <= 0) {
            return;
        }

        $article = SeoArticle::query()->find($articleId);
        $sourceContent = trim((string) ($article?->title ?? ''));

        $this->upsertArchiveItem(
            siteId: $siteId,
            articleId: $articleId,
            fromProjectId: $fromProjectId > 0 ? $fromProjectId : null,
            archivedByUserId: $archivedByUserId,
            connectedAt: $now,
            completedAt: $now,
            archivedAt: $now,
            note: $note,
            sourceContent: $sourceContent,
            taskType: null,
            taskId: $taskId,
        );
    }

    private function upsertArchiveItem(
        int $siteId,
        int $articleId,
        ?int $fromProjectId,
        int $archivedByUserId,
        mixed $connectedAt,
        mixed $completedAt,
        mixed $archivedAt,
        ?string $note,
        string $sourceContent,
        ?string $taskType,
        ?int $taskId = null,
    ): SeoContentArchiveItem {
        /** @var SeoContentArchiveItem $item */
        $item = SeoContentArchiveItem::query()->updateOrCreate(
            ['article_id' => $articleId],
            [
                'site_id' => $siteId,
                'task_id' => $taskId !== null && $taskId > 0 ? $taskId : null,
                'from_project_id' => $fromProjectId,
                'archived_by' => $archivedByUserId,
                'connected_at' => $connectedAt,
                'completed_at' => $completedAt,
                'archived_at' => $archivedAt,
                'note' => $note,
                'source_content' => $sourceContent !== ''
                    ? mb_substr($sourceContent, 0, 500)
                    : null,
                'task_type' => $taskType,
            ],
        );

        SeoArticle::query()
            ->whereKey($articleId)
            ->update([
                'content_archived_at' => $archivedAt,
                'content_archived_by' => $archivedByUserId,
            ]);

        $article = SeoArticle::query()->find($articleId);
        if ($article instanceof SeoArticle) {
            app(\Omnichannel\Addons\Agent\Automation\BusinessHook\Support\BusinessHookEmitter::class)
                ->articleArchived($article);
        }

        return $item;
    }

    private function normalizeNote(?string $note): ?string
    {
        $note = trim((string) $note);

        return $note !== '' ? mb_substr($note, 0, 500) : null;
    }

    private function resolveArticleViewUrl(SeoArticle $article): ?string
    {
        $article->loadMissing('site', 'articleMetas');

        $cached = trim((string) ($article->articleMetas->firstWhere('meta_key', 'wp_permalink')?->meta_value ?? ''));
        $slug = trim((string) ($article->slug ?? ''));

        $resolved = app(WordPressPermalinkBuilder::class)->resolve($article, $cached, $slug !== '' ? $slug : null);
        if ($resolved !== '') {
            return $resolved;
        }

        return null;
    }
}
