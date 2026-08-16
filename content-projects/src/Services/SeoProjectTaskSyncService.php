<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Services;

use Omnichannel\Addons\ContentProjects\Enums\ContentProjectErrorCode;
use Omnichannel\Addons\ContentProjects\Enums\SeoProjectTaskEventType;
use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\ContentProjects\Models\SeoProject;
use Omnichannel\Addons\ContentProjects\Models\SeoProjectTask;
use Omnichannel\Addons\ContentProjects\Support\ProjectTaskSourceKeyGenerator;
use Omnichannel\Addons\ContentProjects\Support\SeoProjectTaskCanonicalCandidateResolver;
use Omnichannel\Addons\ContentProjects\Support\SeoProjectTaskSyncData;
use Omnichannel\Addons\ContentProjects\Support\SeoProjectTaskSyncDataNormalizer;
use Omnichannel\Addons\ContentProjects\Support\SeoProjectTaskSyncResult;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * Diff/upsert sync — giữ stable task ID (Phase 3C2).
 * Không delete-all/recreate.
 */
final class SeoProjectTaskSyncService
{
    /** @var list<string> */
    private const EDITABLE_FIELDS = [
        'site_id',
        'type',
        'post_type',
        'source_content',
        'source_key',
        'keyword',
        'title',
        'secondary_description',
        'rewrite_mode',
        'rewrite_notes',
        'description',
        'loai_san_pham',
        'target_date',
    ];

    public function __construct(
        private readonly SeoProjectTaskSyncDataNormalizer $normalizer,
        private readonly SeoProjectTaskCanonicalCandidateResolver $canonicalResolver,
        private readonly ProjectTaskSourceKeyGenerator $sourceKeys,
        private readonly SeoProjectTaskEventRecorder $eventRecorder,
        private readonly SeoProjectTaskUniqueWriter $uniqueWriter,
    ) {}

    public function maxTasksForMonth(Carbon|string $month): int
    {
        return $this->normalizeMonth($month)->daysInMonth;
    }

    public function normalizeMonth(Carbon|string $month): Carbon
    {
        return Carbon::parse($month)->startOfMonth();
    }

    /**
     * @param  list<array{type?: string, site_id?: int|string|null, source_content?: string, loai_san_pham?: string|null, gallery_description?: string|null, description?: string|null, post_type?: string|null}>  $tasksData
     */
    public function assertWithinMonthlyLimit(Carbon|string $month, array $tasksData): void
    {
        $carbonMonth = $this->normalizeMonth($month);

        $count = $this->countEffectiveTasks($tasksData);
        $max = $carbonMonth->daysInMonth;

        if ($count > $max) {
            throw ValidationException::withMessages([
                'tasks_data' => "Tháng {$carbonMonth->format('m/Y')} chỉ có tối đa {$max} ngày. "
                    ."Bạn không thể đăng ký {$count} bài viết/từ khóa.",
            ]);
        }
    }

    /**
     * @param  list<mixed>  $tasksData
     */
    public function countEffectiveTasks(array $tasksData): int
    {
        return count(array_filter($tasksData, static function (mixed $row): bool {
            if (! is_array($row)) {
                return false;
            }

            $type = SeoProjectTask::normalizeType($row['type'] ?? SeoProjectTask::TYPE_CREATE);
            if (in_array($type, SeoProjectTask::articlePickerTypes(), true)) {
                return trim((string) ($row['source_content'] ?? '')) !== '';
            }

            return trim((string) ($row['keyword'] ?? '')) !== ''
                || trim((string) ($row['title'] ?? '')) !== ''
                || trim((string) ($row['source_content'] ?? '')) !== '';
        }));
    }

    /**
     * Đồng bộ danh sách task: match task_id/source_key → update/create → cancel removals.
     *
     * @param  list<array<string, mixed>>  $tasksData
     */
    public function sync(SeoProject $project, array $tasksData): void
    {
        $this->syncWithResult($project, $tasksData);
    }

    /**
     * @param  list<array<string, mixed>>  $tasksData
     */
    public function syncWithResult(SeoProject $project, array $tasksData): SeoProjectTaskSyncResult
    {
        $rows = $this->normalizer->normalize(
            $project,
            $tasksData,
            $project->site_id !== null ? (int) $project->site_id : null,
        );

        $carbonMonth = $project->monthCarbon();
        if (! $project->isArchive()) {
            $this->assertWithinMonthlyLimit(
                $carbonMonth,
                array_map(static fn (SeoProjectTaskSyncData $row): array => $row->toSanitizedArray(), $rows),
            );
        }

        $this->assertNoDuplicateInput($rows);

        $created = [];
        $updated = [];
        $reactivated = [];
        $cancelled = [];
        $unchanged = [];
        $warnings = [];
        $duplicateCandidates = [];
        $newTaskCount = 0;

        $result = DB::connection($project->getConnectionName())->transaction(
            function () use (
                $project,
                $rows,
                $carbonMonth,
                &$created,
                &$updated,
                &$reactivated,
                &$cancelled,
                &$unchanged,
                &$warnings,
                &$duplicateCandidates,
                &$newTaskCount,
            ): SeoProjectTaskSyncResult {
                /** @var SeoProject|null $locked */
                $locked = SeoProject::query()
                    ->whereKey((int) $project->id)
                    ->lockForUpdate()
                    ->first();

                if (! $locked instanceof SeoProject) {
                    throw ValidationException::withMessages([
                        'tasks_data' => ContentProjectErrorCode::SyncTaskNotFound->value,
                    ]);
                }

                /** @var array<int, true> $keptIds */
                $keptIds = [];

                foreach ($rows as $index => $row) {
                    $match = $this->resolveMatch($locked, $row);
                    if ($match['error'] !== null) {
                        throw ValidationException::withMessages([
                            'tasks_data' => $match['error'],
                            'tasks_data.'.$row->inputIndex => $match['error'],
                        ]);
                    }

                    if ($match['warning'] !== null) {
                        $warnings[] = $match['warning'];
                    }
                    if ($match['duplicate_candidates'] !== []) {
                        $duplicateCandidates[] = $match['duplicate_candidates'];
                    }

                    $targetDate = $locked->isArchive()
                        ? Carbon::parse(SeoProject::archiveSentinelMonth())->addDays(min($index, 36000))->format('Y-m-d')
                        : $carbonMonth->copy()->addDays($index)->format('Y-m-d');

                    if ($match['task'] instanceof SeoProjectTask) {
                        $task = $match['task'];
                        if ($match['reactivated']) {
                            $reactivated[] = (int) $task->id;
                        }

                        $changed = $this->applyEditableUpdate($task, $row, $targetDate);
                        if ($changed) {
                            $updated[] = (int) $task->id;
                        } elseif (! $match['reactivated']) {
                            $unchanged[] = (int) $task->id;
                        }

                        $keptIds[(int) $task->id] = true;
                        continue;
                    }

                    $createdTask = $this->createTask($locked, $row, $targetDate);
                    $created[] = (int) $createdTask->id;
                    $keptIds[(int) $createdTask->id] = true;
                    $newTaskCount++;
                }

                $removal = $this->handleRemovals($locked, $keptIds);
                $cancelled = array_merge($cancelled, $removal['cancelled']);
                $warnings = array_merge($warnings, $removal['warnings']);

                $locked->syncTotalTasksCounter();

                return new SeoProjectTaskSyncResult(
                    createdTaskIds: $created,
                    updatedTaskIds: $updated,
                    reactivatedTaskIds: $reactivated,
                    cancelledTaskIds: $cancelled,
                    unchangedTaskIds: $unchanged,
                    warnings: $warnings,
                    duplicateCandidates: $duplicateCandidates,
                );
            },
        );

        $fresh = $project->fresh();
        if ($fresh instanceof SeoProject) {
            app(SeoProjectArticleOwnerSyncService::class)->syncProjectArticles($fresh);
            // Notification: Automation Engine only (no automatic SeoNotificationService).
        }

        return $result;
    }

    /**
     * @param  list<SeoProjectTaskSyncData>  $rows
     */
    private function assertNoDuplicateInput(array $rows): void
    {
        /** @var array<string, SeoProjectTaskSyncData> $seen */
        $seen = [];

        foreach ($rows as $row) {
            $key = $row->sourceKey;
            if (! isset($seen[$key])) {
                $seen[$key] = $row;
                continue;
            }

            $prev = $seen[$key];
            $sameTask = $prev->taskId !== null
                && $row->taskId !== null
                && $prev->taskId === $row->taskId;

            if ($sameTask) {
                // Collapse identical task_id duplicates — keep first.
                continue;
            }

            throw ValidationException::withMessages([
                'tasks_data' => __('seo-content-ai::filament.projects.sync_duplicate_input'),
                'tasks_data.'.$prev->inputIndex => __('seo-content-ai::filament.projects.sync_duplicate_input_row'),
                'tasks_data.'.$row->inputIndex => __('seo-content-ai::filament.projects.sync_duplicate_input_row'),
            ]);
        }
    }

    /**
     * Preflight cho Edit/Create form — trùng identity (type+post_type+source) với task_id khác.
     *
     * @param  list<array<string, mixed>>  $tasksData
     */
    public function assertNoDuplicateTasksData(SeoProject $project, array $tasksData): void
    {
        $rows = $this->normalizer->normalize(
            $project,
            $tasksData,
            $project->site_id !== null ? (int) $project->site_id : null,
        );
        $this->assertNoDuplicateInput($rows);
    }

    /**
     * @return array{
     *     task: SeoProjectTask|null,
     *     reactivated: bool,
     *     error: string|null,
     *     warning: string|null,
     *     duplicate_candidates: array<string, mixed>
     * }
     */
    private function resolveMatch(SeoProject $project, SeoProjectTaskSyncData $row): array
    {
        $empty = [
            'task' => null,
            'reactivated' => false,
            'error' => null,
            'warning' => null,
            'duplicate_candidates' => [],
        ];

        if ($row->taskId !== null) {
            /** @var SeoProjectTask|null $task */
            $task = SeoProjectTask::withTrashed()
                ->whereKey($row->taskId)
                ->lockForUpdate()
                ->first();

            if (! $task instanceof SeoProjectTask) {
                return [...$empty, 'error' => ContentProjectErrorCode::SyncTaskNotFound->value];
            }

            if ((int) $task->project_id !== (int) $project->id) {
                return [...$empty, 'error' => ContentProjectErrorCode::SyncTaskProjectMismatch->value];
            }

            if ($task->trashed() || $task->deleted_at !== null) {
                return [...$empty, 'error' => ContentProjectErrorCode::SyncTaskDeleted->value];
            }

            if ($task->archived_at !== null
                || (string) $task->status === SeoProjectTask::STATUS_ARCHIVED
            ) {
                return [...$empty, 'error' => ContentProjectErrorCode::SyncTaskArchived->value];
            }

            return [...$empty, 'task' => $task];
        }

        $byKey = SeoProjectTask::query()
            ->where('project_id', (int) $project->id)
            ->where('source_key', $row->sourceKey)
            ->whereNull('archived_at')
            ->lockForUpdate()
            ->get();

        // Reactivate cancelled with same source_key (no article).
        $cancelled = $byKey->filter(
            static fn (SeoProjectTask $task): bool => (string) $task->status === SeoProjectTask::STATUS_CANCELLED,
        );
        $activePeers = $byKey->filter(
            static fn (SeoProjectTask $task): bool => (string) $task->status !== SeoProjectTask::STATUS_CANCELLED,
        );

        if ($activePeers->isEmpty() && $cancelled->count() === 1) {
            /** @var SeoProjectTask $only */
            $only = $cancelled->first();
            if ((int) ($only->article_id ?? 0) <= 0) {
                $only->forceFill(['status' => SeoProjectTask::STATUS_PENDING])->save();
                $this->eventRecorder->record(
                    $only,
                    SeoProjectTaskEventType::TaskReactivated,
                    SeoProjectTask::STATUS_CANCELLED,
                    SeoProjectTask::STATUS_PENDING,
                    [
                        'task_id' => (int) $only->id,
                        'sync_source' => 'project_editor',
                        'source_key' => $row->sourceKey,
                    ],
                );

                return [...$empty, 'task' => $only->fresh() ?? $only, 'reactivated' => true];
            }
        }

        $candidates = $activePeers->isNotEmpty() ? $activePeers : $byKey;
        if ($candidates->isEmpty()) {
            // Legacy: unique null source_key match by raw identity.
            $legacy = $this->findUniqueLegacyNullSourceKey($project, $row);
            if ($legacy instanceof SeoProjectTask) {
                return [...$empty, 'task' => $legacy];
            }

            return $empty;
        }

        $resolved = $this->canonicalResolver->resolve($candidates);
        if ($resolved['status'] === 'ambiguous') {
            return [
                ...$empty,
                'error' => ContentProjectErrorCode::SyncDuplicateIdentity->value,
                'duplicate_candidates' => [
                    'source_key' => $row->sourceKey,
                    'candidate_task_ids' => $resolved['candidate_task_ids'],
                    'input_index' => $row->inputIndex,
                    'reason' => $resolved['reason'],
                ],
            ];
        }

        if ($resolved['status'] === 'resolved' && $resolved['task'] instanceof SeoProjectTask) {
            $warning = null;
            $dupPayload = [];
            if (count($resolved['candidate_task_ids']) > 1) {
                $warning = 'SYNC_DUPLICATE_AUTO_RESOLVED:'.$resolved['reason'];
                $dupPayload = [
                    'source_key' => $row->sourceKey,
                    'candidate_task_ids' => $resolved['candidate_task_ids'],
                    'chosen_task_id' => (int) $resolved['task']->id,
                    'reason' => $resolved['reason'],
                    'input_index' => $row->inputIndex,
                ];
                Log::warning('seo.project_task.sync_duplicate_auto_resolved', $dupPayload);
            }

            return [
                ...$empty,
                'task' => $resolved['task'],
                'warning' => $warning,
                'duplicate_candidates' => $dupPayload,
            ];
        }

        return $empty;
    }

    private function findUniqueLegacyNullSourceKey(
        SeoProject $project,
        SeoProjectTaskSyncData $row,
    ): ?SeoProjectTask {
        $normalizedSource = $this->sourceKeys->normalizeSourceContent($row->sourceContent);

        $candidates = SeoProjectTask::query()
            ->where('project_id', (int) $project->id)
            ->whereNull('source_key')
            ->whereNull('archived_at')
            ->where('type', $row->type)
            ->where('status', '!=', SeoProjectTask::STATUS_CANCELLED)
            ->lockForUpdate()
            ->get()
            ->filter(function (SeoProjectTask $task) use ($row, $normalizedSource): bool {
                $taskPost = SeoProjectTask::isNewArticleType((string) $task->type)
                    ? SeoProjectTask::normalizePostType($task->post_type)
                    : null;
                $rowPost = $row->postType;
                if ((string) ($taskPost ?? '') !== (string) ($rowPost ?? '')) {
                    return false;
                }

                return $this->sourceKeys->normalizeSourceContent((string) $task->source_content) === $normalizedSource;
            })
            ->values();

        if ($candidates->count() !== 1) {
            return null;
        }

        /** @var SeoProjectTask $task */
        $task = $candidates->first();
        $task->forceFill(['source_key' => $row->sourceKey])->save();

        return $task->fresh() ?? $task;
    }

    private function applyEditableUpdate(
        SeoProjectTask $task,
        SeoProjectTaskSyncData $row,
        string $targetDate,
    ): bool {
        $payload = [
            'site_id' => $row->siteId,
            'type' => $row->type,
            'post_type' => $row->postType,
            'source_content' => $row->sourceContent,
            'source_key' => $row->sourceKey,
            'keyword' => $row->keyword,
            'title' => $row->title,
            'secondary_description' => $row->secondaryDescription,
            'rewrite_mode' => $row->type === SeoProjectTask::TYPE_REWRITE
                ? SeoProjectTask::REWRITE_MODE_CONTENT
                : SeoProjectTask::REWRITE_MODE_KEYWORD,
            'rewrite_notes' => in_array($row->type, [SeoProjectTask::TYPE_REWRITE, SeoProjectTask::TYPE_IMPROVE], true)
                ? $row->rewriteNotes
                : null,
            'description' => $row->description,
            'loai_san_pham' => $row->loaiSanPham,
            'target_date' => $targetDate,
        ];

        $changedFields = [];
        foreach (self::EDITABLE_FIELDS as $field) {
            $new = $payload[$field] ?? null;
            $old = $task->{$field};
            if ($field === 'target_date') {
                $old = $task->target_date?->format('Y-m-d');
            }
            if ($this->scalarEquals($old, $new)) {
                continue;
            }
            $changedFields[] = $field;
            $task->{$field} = $new;
        }

        if ($changedFields === []) {
            return false;
        }

        // Conflict: identity change onto another active task's source_key.
        if (in_array('source_key', $changedFields, true)) {
            $conflict = SeoProjectTask::query()
                ->where('project_id', (int) $task->project_id)
                ->where('source_key', $row->sourceKey)
                ->whereKeyNot((int) $task->id)
                ->whereNull('archived_at')
                ->where('status', '!=', SeoProjectTask::STATUS_CANCELLED)
                ->exists();

            if ($conflict) {
                throw ValidationException::withMessages([
                    'tasks_data' => ContentProjectErrorCode::SyncDuplicateIdentity->value,
                ]);
            }

            $articleConflict = SeoProjectTask::query()
                ->where('project_id', (int) $task->project_id)
                ->where('source_key', $row->sourceKey)
                ->whereKeyNot((int) $task->id)
                ->whereNotNull('article_id')
                ->whereNull('archived_at')
                ->exists();

            if ($articleConflict && (int) ($task->article_id ?? 0) > 0) {
                throw ValidationException::withMessages([
                    'tasks_data' => ContentProjectErrorCode::SyncArticleIdentityConflict->value,
                ]);
            }
        }

        $fromStatus = (string) $task->status;
        $task->save();

        $this->eventRecorder->record(
            $task,
            SeoProjectTaskEventType::TaskUpdated,
            $fromStatus,
            (string) $task->status,
            [
                'changed_fields' => $changedFields,
                'sync_source' => 'project_editor',
                'task_id' => (int) $task->id,
            ],
        );

        return true;
    }

    private function createTask(
        SeoProject $project,
        SeoProjectTaskSyncData $row,
        string $targetDate,
    ): SeoProjectTask {
        $articleId = null;
        if (in_array($row->type, SeoProjectTask::articlePickerTypes(), true)) {
            // Rewrite/Improve: gắn bài có sẵn theo tiêu đề picker — không tạo bài mới.
            $articleId = $this->resolveArticleIdByTitle($row->sourceContent, $row->siteId);
        }

        $task = $this->uniqueWriter->createStrict([
            'project_id' => (int) $project->id,
            'site_id' => $row->siteId,
            'article_id' => $articleId,
            'type' => $row->type,
            'post_type' => $row->postType,
            'loai_san_pham' => $row->loaiSanPham,
            'source_content' => $row->sourceContent,
            'source_key' => $row->sourceKey,
            'keyword' => $row->keyword,
            'title' => $row->title,
            'secondary_description' => $row->secondaryDescription,
            'description' => $row->description,
            'rewrite_mode' => $row->type === SeoProjectTask::TYPE_REWRITE
                ? SeoProjectTask::REWRITE_MODE_CONTENT
                : SeoProjectTask::REWRITE_MODE_KEYWORD,
            'rewrite_notes' => in_array($row->type, [SeoProjectTask::TYPE_REWRITE, SeoProjectTask::TYPE_IMPROVE], true)
                ? $row->rewriteNotes
                : null,
            'target_date' => $targetDate,
            'status' => SeoProjectTask::STATUS_PENDING,
            'connected_at' => $articleId !== null ? now() : null,
            'completed_at' => null,
        ]);

        $this->eventRecorder->record(
            $task,
            SeoProjectTaskEventType::TaskCreated,
            null,
            SeoProjectTask::STATUS_PENDING,
            [
                'task_id' => (int) $task->id,
                'sync_source' => 'project_editor',
                'source_key' => $row->sourceKey,
            ],
        );

        return $task;
    }

    /**
     * @param  array<int, true>  $keptIds
     * @return array{cancelled: list<int>, warnings: list<string>}
     */
    private function handleRemovals(SeoProject $project, array $keptIds): array
    {
        $cancelled = [];
        $warnings = [];

        $existing = SeoProjectTask::query()
            ->where('project_id', (int) $project->id)
            ->whereNull('archived_at')
            ->where('status', '!=', SeoProjectTask::STATUS_CANCELLED)
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        foreach ($existing as $task) {
            if (! $task instanceof SeoProjectTask) {
                continue;
            }

            $id = (int) $task->id;
            if (isset($keptIds[$id])) {
                continue;
            }

            $status = (string) $task->status;
            $hasArticle = (int) ($task->article_id ?? 0) > 0;

            if (in_array($status, [
                SeoProjectTask::STATUS_WRITING,
                'processing',
                SeoProjectTask::STATUS_REVIEWING,
            ], true)) {
                $warnings[] = 'SYNC_REMOVAL_BLOCKED_PROCESSING:'.$id;
                continue;
            }

            if (in_array($status, [
                SeoProjectTask::STATUS_PENDING,
                SeoProjectTask::STATUS_FAILED,
                SeoProjectTask::STATUS_COMPLETED,
                'draft',
            ], true)) {
                $from = $status;
                $task->forceFill(['status' => SeoProjectTask::STATUS_CANCELLED])->save();
                $this->eventRecorder->record(
                    $task,
                    SeoProjectTaskEventType::TaskCancelled,
                    $from,
                    SeoProjectTask::STATUS_CANCELLED,
                    [
                        'task_id' => $id,
                        'sync_source' => 'project_editor',
                        'article_id' => $hasArticle ? (int) $task->article_id : null,
                    ],
                );
                $cancelled[] = $id;
            }
        }

        return [
            'cancelled' => $cancelled,
            'warnings' => $warnings,
        ];
    }

    private function scalarEquals(mixed $old, mixed $new): bool
    {
        if ($old === null && $new === null) {
            return true;
        }

        return (string) ($old ?? '') === (string) ($new ?? '');
    }

    private function resolveArticleIdByTitle(string $title, int $siteId): ?int
    {
        $normalized = mb_strtolower(trim($title));
        if ($normalized === '' || $siteId <= 0) {
            return null;
        }

        $article = SeoArticle::query()
            ->where('site_id', $siteId)
            ->whereRaw('LOWER(TRIM(title)) = ?', [$normalized])
            ->orderBy('id')
            ->first();

        if (! $article instanceof SeoArticle) {
            return null;
        }

        $articleId = (int) $article->id;
        $taken = SeoProjectTask::query()
            ->where('article_id', $articleId)
            ->whereNull('archived_at')
            ->exists();

        return $taken ? null : $articleId;
    }

    /**
     * @param  list<array{type?: string, site_id?: int|string|null, source_content?: string|null, loai_san_pham?: string|null, gallery_description?: string|null, description?: string|null, post_type?: string|null}>  $tasksData
     * @return list<array<string, mixed>>
     */
    public function sanitizeTasksData(array $tasksData, ?int $defaultSiteId = null): array
    {
        // Compatibility wrapper — dùng project stub chỉ để normalize khi chưa có project.
        // Callers create/edit luôn có site; sync dùng normalizer trực tiếp.
        $project = new SeoProject;
        $project->id = 0;
        $project->site_id = $defaultSiteId;

        // Khi project_id=0, source_key vẫn deterministic theo input; id được giữ.
        $rows = $this->normalizer->normalize($project, $tasksData, $defaultSiteId);

        /** @var array<string, true> $seen */
        $seen = [];
        $out = [];
        foreach ($rows as $row) {
            // Dedupe by source_key for signature/limit helpers (giữ first).
            if (isset($seen[$row->sourceKey])) {
                continue;
            }
            $seen[$row->sourceKey] = true;
            $out[] = $row->toSanitizedArray();
        }

        return $out;
    }

    /**
     * @param  list<array<string, mixed>>  $tasksData
     */
    public function tasksSignature(array $tasksData, ?int $defaultSiteId = null): string
    {
        return json_encode(
            $this->sanitizeTasksData($tasksData, $defaultSiteId),
            JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        );
    }

    /**
     * @deprecated Phase 3C2 — dùng SeoProjectTaskCanonicalCandidateResolver.
     */
    private static function preferTaskForSyncPreserve(SeoProjectTask $current, SeoProjectTask $candidate): SeoProjectTask
    {
        return app(SeoProjectTaskCanonicalCandidateResolver::class)->preferLegacy($current, $candidate);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function tasksDataFromProject(SeoProject $project): array
    {
        return $project->tasks()
            ->planned()
            ->orderBy('target_date')
            ->orderBy('id')
            ->get()
            ->map(fn (SeoProjectTask $task): array => [
                'id' => (int) $task->id,
                'type' => SeoProjectTask::normalizeType($task->type),
                'source_content' => $task->source_content,
                'keyword' => in_array(SeoProjectTask::normalizeType($task->type), [
                    SeoProjectTask::TYPE_CREATE,
                    SeoProjectTask::TYPE_REWRITE,
                ], true) ? ($task->keyword ?? null) : null,
                'title' => in_array(SeoProjectTask::normalizeType($task->type), [
                    SeoProjectTask::TYPE_CREATE,
                    SeoProjectTask::TYPE_REWRITE,
                ], true) ? ($task->title ?? null) : null,
                'secondary_description' => in_array(SeoProjectTask::normalizeType($task->type), [
                    SeoProjectTask::TYPE_CREATE,
                    SeoProjectTask::TYPE_REWRITE,
                ], true) ? ($task->secondary_description ?? null) : null,
                'loai_san_pham' => SeoProjectTask::isNewArticleType($task->type)
                    && SeoProjectTask::normalizePostType($task->post_type) === SeoProjectTask::POST_TYPE_PRODUCT
                        ? $task->loai_san_pham
                        : null,
                'description' => SeoProjectTask::isNewArticleType($task->type)
                    && SeoProjectTask::normalizePostType($task->post_type) === SeoProjectTask::POST_TYPE_PRODUCT
                        ? $task->description
                        : null,
                'post_type' => SeoProjectTask::isNewArticleType($task->type)
                    ? SeoProjectTask::normalizePostType($task->post_type)
                    : null,
                'rewrite_mode' => $task->type === SeoProjectTask::TYPE_REWRITE
                    ? SeoProjectTask::REWRITE_MODE_CONTENT
                    : null,
                'rewrite_notes' => in_array(SeoProjectTask::normalizeType($task->type), [
                    SeoProjectTask::TYPE_REWRITE,
                    SeoProjectTask::TYPE_IMPROVE,
                ], true) ? $task->rewrite_notes : null,
                'connected_at' => $task->connected_at?->format('Y-m-d H:i:s'),
                'completed_at' => $task->completed_at?->format('Y-m-d H:i:s'),
            ])
            ->all();
    }
}
