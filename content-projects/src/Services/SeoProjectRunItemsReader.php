<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Services;

use Omnichannel\Addons\Content\Enums\ArticleReviewStatus;
use Omnichannel\Addons\ContentProjects\Enums\ContentProjectErrorCode;
use Omnichannel\Addons\ContentProjects\Enums\SeoProjectRunItemStatus;
use Omnichannel\Addons\Content\Filament\Resources\ArticleResource;
use Omnichannel\Addons\Content\Services\ArticleEditorReadinessService;
use Omnichannel\Addons\ContentProjects\Models\SeoProjectRun;
use Omnichannel\Addons\ContentProjects\Models\SeoProjectRunItem;
use Omnichannel\Addons\ContentProjects\Models\SeoProjectTask;
use Omnichannel\Addons\ContentProjects\Support\LegacyProjectRunItemMapper;
use Omnichannel\Addons\ContentProjects\Support\SeoProjectRunItemViewData;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * Read source cho run detail: DB run items XOR legacy JSON — không bao giờ merge.
 */
final class SeoProjectRunItemsReader
{
    public const SOURCE_DATABASE = 'database';

    public const SOURCE_LEGACY_JSON = 'legacy_json';

    public const SOURCE_EMPTY = 'empty';

    /** @var array<int, Collection<int, SeoProjectRunItemViewData>> */
    private array $requestCache = [];

    public function __construct(
        private readonly LegacyProjectRunItemMapper $legacyMapper,
        private readonly ?ArticleEditorReadinessService $editorReadiness = null,
    ) {}

    public function usesLegacyFallback(SeoProjectRun $run): bool
    {
        return $this->sourceForRun($run) === self::SOURCE_LEGACY_JSON;
    }

    public function sourceForRun(SeoProjectRun $run): string
    {
        if ($this->hasDatabaseItems($run)) {
            return self::SOURCE_DATABASE;
        }

        $items = is_array($run->items) ? $run->items : [];
        foreach ($items as $item) {
            if (is_array($item) && $item !== []) {
                return self::SOURCE_LEGACY_JSON;
            }
        }

        return self::SOURCE_EMPTY;
    }

    public function hasDatabaseItems(SeoProjectRun $run): bool
    {
        return SeoProjectRunItem::query()
            ->where('run_id', (int) $run->id)
            ->exists();
    }

    /**
     * @return Collection<int, SeoProjectRunItemViewData>
     */
    public function forRun(SeoProjectRun $run): Collection
    {
        $runId = (int) $run->id;
        if (isset($this->requestCache[$runId])) {
            return $this->requestCache[$runId];
        }

        $source = $this->sourceForRun($run);

        $collection = match ($source) {
            self::SOURCE_DATABASE => $this->fromDatabase($run),
            self::SOURCE_LEGACY_JSON => $this->fromLegacyJson($run),
            default => collect(),
        };

        $collection = $this->flagDuplicateIdentities($collection);

        return $this->requestCache[$runId] = $collection->values();
    }

    public function forgetRun(int $runId): void
    {
        unset($this->requestCache[$runId]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function forRunAsArrays(SeoProjectRun $run): array
    {
        return $this->forRun($run)
            ->map(static fn (SeoProjectRunItemViewData $row): array => $row->toArray())
            ->all();
    }

    /**
     * @return array{total: int, succeeded: int, failed: int, pending: int, processing: int, skipped: int, manual: int}
     */
    public function aggregateCounters(SeoProjectRun $run): array
    {
        if ($this->sourceForRun($run) === self::SOURCE_DATABASE) {
            $rows = SeoProjectRunItem::query()
                ->where('run_id', (int) $run->id)
                ->articleExecution()
                ->selectRaw('status, COUNT(*) as aggregate_count')
                ->groupBy('status')
                ->pluck('aggregate_count', 'status');

            $pending = (int) ($rows[SeoProjectRunItemStatus::Pending->value] ?? 0);
            $processing = (int) ($rows[SeoProjectRunItemStatus::Processing->value] ?? 0);
            $success = (int) ($rows[SeoProjectRunItemStatus::Success->value] ?? 0);
            $failed = (int) ($rows[SeoProjectRunItemStatus::Failed->value] ?? 0);
            $skipped = (int) ($rows[SeoProjectRunItemStatus::Skipped->value] ?? 0);
            $manual = (int) ($rows[SeoProjectRunItemStatus::Manual->value] ?? 0);

            return [
                'total' => $pending + $processing + $success + $failed + $skipped + $manual,
                'succeeded' => $success + $skipped,
                'failed' => $failed,
                'pending' => $pending + $processing + $manual,
                'processing' => $processing,
                'skipped' => $skipped,
                'manual' => $manual,
            ];
        }

        $items = $this->forRun($run);
        $succeeded = $items->filter(static fn (SeoProjectRunItemViewData $r): bool => $r->status === 'success')->count();
        $failed = $items->filter(static fn (SeoProjectRunItemViewData $r): bool => $r->status === 'failed')->count();
        $pending = $items->filter(static fn (SeoProjectRunItemViewData $r): bool => in_array($r->status, ['pending', 'manual'], true))->count();

        return [
            'total' => $items->count(),
            'succeeded' => $succeeded,
            'failed' => $failed,
            'pending' => $pending,
            'processing' => 0,
            'skipped' => 0,
            'manual' => $items->filter(static fn (SeoProjectRunItemViewData $r): bool => $r->status === 'manual')->count(),
        ];
    }

    public function detectInconsistency(SeoProjectRun $run): ?string
    {
        if (! $this->hasDatabaseItems($run)) {
            return null;
        }

        $json = is_array($run->items) ? $run->items : [];
        if ($json === []) {
            return null;
        }

        $dbTaskIds = SeoProjectRunItem::query()
            ->where('run_id', (int) $run->id)
            ->whereNotNull('task_id')
            ->pluck('task_id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->all();
        $dbSet = array_fill_keys($dbTaskIds, true);

        foreach ($json as $item) {
            if (! is_array($item)) {
                continue;
            }
            $taskId = (int) ($item['task_id'] ?? 0);
            if ($taskId > 0 && ! isset($dbSet[$taskId])) {
                return 'RUN_ITEMS_PARTIAL_BACKFILL';
            }
        }

        $dbCount = SeoProjectRunItem::query()->where('run_id', (int) $run->id)->count();
        $jsonCount = count(array_filter($json, 'is_array'));
        if ($jsonCount > $dbCount) {
            return 'RUN_ITEMS_JSON_MISMATCH';
        }

        return null;
    }

    /**
     * @return Collection<int, SeoProjectRunItemViewData>
     */
    private function fromDatabase(SeoProjectRun $run): Collection
    {
        $items = SeoProjectRunItem::query()
            ->where('run_id', (int) $run->id)
            ->articleExecution()
            ->with(['taskIncludingDeleted', 'article'])
            ->orderBy('id')
            ->get();

        return $items->map(fn (SeoProjectRunItem $item): SeoProjectRunItemViewData => $this->fromRunItemModel($item));
    }

    private function fromRunItemModel(SeoProjectRunItem $item): SeoProjectRunItemViewData
    {
        $task = $item->relationLoaded('taskIncludingDeleted')
            ? $item->taskIncludingDeleted
            : ($item->relationLoaded('task') ? $item->task : null);
        $taskSoftDeleted = $task instanceof SeoProjectTask && $task->trashed();
        $taskExists = $task instanceof SeoProjectTask && ! $taskSoftDeleted;
        $snapshot = is_array($item->input_snapshot) ? $item->input_snapshot : [];

        $type = $task instanceof SeoProjectTask
            ? (string) $task->type
            : (string) ($snapshot['type'] ?? '');
        $sourceContent = $task instanceof SeoProjectTask
            ? (string) $task->source_content
            : (string) ($snapshot['source_content'] ?? '');
        $postType = $task instanceof SeoProjectTask
            ? ($task->post_type !== null ? (string) $task->post_type : null)
            : (isset($snapshot['post_type']) ? (string) $snapshot['post_type'] : null);

        $statusEnum = SeoProjectRunItemStatus::tryFrom((string) $item->status)
            ?? SeoProjectRunItemStatus::Pending;
        $legacyStatus = $statusEnum->toLegacyJsonStatus();

        $articleId = (int) ($item->article_id ?? ($task instanceof SeoProjectTask ? ($task->article_id ?? 0) : 0));
        $articleId = $articleId > 0 ? $articleId : null;

        $editUrl = null;
        if ($articleId !== null) {
            $ready = $this->editorReadiness?->isReady($articleId) ?? true;
            $editUrl = $ready
                ? ArticleResource::getUrl('edit', ['record' => $articleId], isAbsolute: false)
                : null;
        }

        $canRetry = $taskExists
            && $task->archived_at === null
            && ! in_array($legacyStatus, ['manual'], true);

        // Task soft-deleted vẫn tính archived nếu đã có archived_at (để ẩn khỏi UI sau khi detach).
        $taskArchived = ($task instanceof SeoProjectTask
            && ($task->archived_at !== null || (string) $task->status === SeoProjectTask::STATUS_ARCHIVED))
            || (bool) ($snapshot['detached_from_project'] ?? false);

        // Hiện Archive khi còn article và task chưa detach xong (kể cả task mất / soft-delete
        // trước khi có archived_at — data kẹt trước khi Complete tự detach).
        $canArchive = $articleId !== null && ! $taskArchived;

        $missingErrorCode = null;
        $missingErrorMessage = null;
        if (! $taskExists) {
            $missingErrorCode = $taskSoftDeleted
                ? ContentProjectErrorCode::TaskDeleted->value
                : ContentProjectErrorCode::TaskNotFound->value;
            $missingErrorMessage = $taskSoftDeleted
                ? 'Task đã bị xóa. Chỉ xem lịch sử chạy.'
                : 'Task gốc không còn tồn tại.';
        }

        $articleModel = $item->relationLoaded('article') ? $item->article : null;
        $articleReviewStatus = $articleModel instanceof \Omnichannel\Addons\Content\Models\SeoArticle
            ? (is_string($articleModel->review_status ?? null) ? (string) $articleModel->review_status : null)
            : null;
        $articleIsApproved = $articleReviewStatus === ArticleReviewStatus::Approved->value;

        return new SeoProjectRunItemViewData(
            runItemId: (int) $item->id,
            taskId: $item->task_id !== null ? (int) $item->task_id : null,
            articleId: $articleId,
            action: (string) $item->action,
            type: $type,
            postType: $postType,
            sourceContent: $sourceContent,
            status: $legacyStatus,
            attempt: max(1, (int) $item->attempt),
            message: (string) ($item->message ?? ''),
            errorCode: $item->error_code !== null ? (string) $item->error_code : $missingErrorCode,
            errorMessage: $item->error_message !== null
                ? (string) $item->error_message
                : $missingErrorMessage,
            articleEditUrl: $editUrl,
            targetDate: $task instanceof SeoProjectTask
                ? $task->target_date?->format('Y-m-d')
                : (isset($snapshot['target_date']) ? (string) $snapshot['target_date'] : null),
            description: $task instanceof SeoProjectTask
                ? ($task->description !== null ? (string) $task->description : null)
                : (isset($snapshot['description']) ? (string) $snapshot['description'] : null),
            isLegacy: false,
            source: self::SOURCE_DATABASE,
            taskExists: $taskExists,
            canRetry: $canRetry,
            canArchive: $canArchive,
            taskArchived: $taskArchived,
            steps: is_array($item->output_snapshot['steps'] ?? null) ? $item->output_snapshot['steps'] : [],
            lastRunAt: $item->finished_at?->format('Y-m-d H:i:s'),
            loaiSanPham: $task instanceof SeoProjectTask ? ($task->loai_san_pham !== null ? (string) $task->loai_san_pham : null) : null,
            galleryDescription: $task instanceof SeoProjectTask ? ($task->description !== null ? (string) $task->description : null) : null,
            rewriteMode: $task instanceof SeoProjectTask && $task->type === SeoProjectTask::TYPE_REWRITE
                ? SeoProjectTask::normalizeRewriteMode($task->rewrite_mode)
                : null,
            rewriteNotes: $task instanceof SeoProjectTask && $task->type === SeoProjectTask::TYPE_REWRITE
                ? ($task->rewrite_notes !== null ? (string) $task->rewrite_notes : null)
                : null,
            articleReviewStatus: $articleReviewStatus,
            articleIsApproved: $articleIsApproved,
        );
    }

    /**
     * @return Collection<int, SeoProjectRunItemViewData>
     */
    private function fromLegacyJson(SeoProjectRun $run): Collection
    {
        $raw = is_array($run->items) ? $run->items : [];
        $taskIds = [];
        foreach ($raw as $item) {
            if (is_array($item)) {
                $id = (int) ($item['task_id'] ?? 0);
                if ($id > 0) {
                    $taskIds[] = $id;
                }
            }
        }

        $tasks = SeoProjectTask::query()
            ->whereIn('id', array_values(array_unique($taskIds)))
            ->get()
            ->keyBy('id');

        $out = collect();
        foreach ($raw as $index => $item) {
            if (! is_array($item) || $item === []) {
                continue;
            }

            $mapped = $this->legacyMapper->map($item);
            if ($mapped === null) {
                Log::warning('seo.project_run.legacy_item_invalid', [
                    'run_id' => (int) $run->id,
                    'index' => $index,
                ]);

                continue;
            }

            $taskId = $mapped['task_id'];
            $task = $taskId !== null ? $tasks->get($taskId) : null;
            $taskExists = $task instanceof SeoProjectTask;
            $taskArchived = $taskExists
                && ($task->archived_at !== null || (string) $task->status === SeoProjectTask::STATUS_ARCHIVED);

            $status = (string) ($mapped['status'] ?? 'pending');
            $type = (string) ($item['type'] ?? ($mapped['input_snapshot']['type'] ?? ''));
            $articleId = $mapped['article_id'];

            $canRetry = $taskExists
                && ! $taskArchived
                && $status !== 'manual';
            $canArchive = $articleId !== null && $articleId > 0 && ! $taskArchived;

            $editUrl = null;
            if ($articleId !== null && $articleId > 0) {
                $ready = $this->editorReadiness?->isReady($articleId) ?? true;
                $editUrl = $ready
                    ? ArticleResource::getUrl('edit', ['record' => $articleId], isAbsolute: false)
                    : null;
            }

            $out->push(new SeoProjectRunItemViewData(
                runItemId: null,
                taskId: $taskId,
                articleId: $articleId,
                action: (string) $mapped['action'],
                type: $type,
                postType: isset($item['post_type']) ? (string) $item['post_type'] : null,
                sourceContent: (string) ($item['source_content'] ?? ''),
                status: $status,
                attempt: max(1, (int) ($mapped['attempt'] ?? 1)),
                message: (string) ($mapped['message'] ?? ''),
                errorCode: $taskExists ? ($mapped['error_code'] ?? null) : ContentProjectErrorCode::TaskNotFound->value,
                errorMessage: $taskExists
                    ? ($mapped['error_message'] ?? null)
                    : 'Task gốc không còn tồn tại.',
                articleEditUrl: $editUrl ?? (isset($item['article_edit_url']) ? (string) $item['article_edit_url'] : null),
                targetDate: isset($item['target_date']) ? (string) $item['target_date'] : null,
                description: isset($item['gallery_description']) ? (string) $item['gallery_description'] : null,
                isLegacy: true,
                source: self::SOURCE_LEGACY_JSON,
                taskExists: $taskExists,
                canRetry: $canRetry,
                canArchive: $canArchive,
                taskArchived: $taskArchived,
                steps: is_array($mapped['output_snapshot']['steps'] ?? null) ? $mapped['output_snapshot']['steps'] : [],
                lastRunAt: $mapped['finished_at'] ?? (isset($item['last_run_at']) ? (string) $item['last_run_at'] : null),
                loaiSanPham: isset($item['loai_san_pham']) ? (string) $item['loai_san_pham'] : null,
                galleryDescription: isset($item['gallery_description']) ? (string) $item['gallery_description'] : null,
                rewriteMode: isset($item['rewrite_mode']) ? (string) $item['rewrite_mode'] : null,
                rewriteNotes: isset($item['rewrite_notes']) ? (string) $item['rewrite_notes'] : null,
                extra: [
                    'legacy_index' => (int) $index,
                    'run_id' => (int) $run->id,
                ],
            ));
        }

        return $out;
    }

    /**
     * @param  Collection<int, SeoProjectRunItemViewData>  $rows
     * @return Collection<int, SeoProjectRunItemViewData>
     */
    private function flagDuplicateIdentities(Collection $rows): Collection
    {
        /** @var array<string, list<int>> $groups */
        $groups = [];
        foreach ($rows as $index => $row) {
            $key = mb_strtolower(trim($row->type.'|'.$row->sourceContent.'|'.(string) ($row->postType ?? '')));
            $groups[$key][] = $index;
        }

        $flagged = [];
        foreach ($groups as $indexes) {
            if (count($indexes) < 2) {
                continue;
            }
            $taskIds = [];
            foreach ($indexes as $i) {
                $id = $rows[$i]->taskId;
                if ($id !== null && $id > 0) {
                    $taskIds[$id] = true;
                }
            }
            if (count($taskIds) >= 2) {
                foreach ($indexes as $i) {
                    $flagged[$i] = true;
                }
            }
        }

        if ($flagged === []) {
            return $rows;
        }

        return $rows->map(function (SeoProjectRunItemViewData $row, int $index) use ($flagged): SeoProjectRunItemViewData {
            if (! isset($flagged[$index])) {
                return $row;
            }

            return new SeoProjectRunItemViewData(
                runItemId: $row->runItemId,
                taskId: $row->taskId,
                articleId: $row->articleId,
                action: $row->action,
                type: $row->type,
                postType: $row->postType,
                sourceContent: $row->sourceContent,
                status: $row->status,
                attempt: $row->attempt,
                message: $row->message,
                errorCode: $row->errorCode,
                errorMessage: $row->errorMessage,
                articleEditUrl: $row->articleEditUrl,
                targetDate: $row->targetDate,
                description: $row->description,
                isLegacy: $row->isLegacy,
                source: $row->source,
                taskExists: $row->taskExists,
                canRetry: $row->canRetry,
                canArchive: $row->canArchive,
                taskArchived: $row->taskArchived,
                duplicateIdentityDetected: true,
                steps: $row->steps,
                lastRunAt: $row->lastRunAt,
                loaiSanPham: $row->loaiSanPham,
                galleryDescription: $row->galleryDescription,
                rewriteMode: $row->rewriteMode,
                rewriteNotes: $row->rewriteNotes,
                articleReviewStatus: $row->articleReviewStatus,
                articleIsApproved: $row->articleIsApproved,
                extra: $row->extra,
            );
        });
    }
}
