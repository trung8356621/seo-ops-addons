<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Support;

/**
 * Normalized view model cho Content Project run detail UI.
 *
 * @phpstan-type ViewArray array{
 *     id: string,
 *     run_item_id: int|null,
 *     task_id: int|null,
 *     article_id: int|null,
 *     action: string|null,
 *     type: string,
 *     post_type: string|null,
 *     source_content: string,
 *     status: string,
 *     attempt: int,
 *     retry_count: int,
 *     retry_task_id: int|null,
 *     message: string,
 *     error_code: string|null,
 *     error_message: string|null,
 *     error_detail: string|null,
 *     article_edit_url: string|null,
 *     target_date: string|null,
 *     description: string|null,
 *     is_legacy: bool,
 *     source: string,
 *     task_exists: bool,
 *     can_retry: bool,
 *     can_archive: bool,
 *     task_archived: bool,
 *     duplicate_identity_detected: bool,
 *     steps: list<array<string, mixed>>,
 *     last_run_at: string|null,
 * }
 */
final class SeoProjectRunItemViewData
{
    /**
     * @param  list<array<string, mixed>>  $steps
     */
    public function __construct(
        public readonly ?int $runItemId,
        public readonly ?int $taskId,
        public readonly ?int $articleId,
        public readonly ?string $action,
        public readonly string $type,
        public readonly ?string $postType,
        public readonly string $sourceContent,
        public readonly string $status,
        public readonly int $attempt,
        public readonly string $message,
        public readonly ?string $errorCode,
        public readonly ?string $errorMessage,
        public readonly ?string $articleEditUrl,
        public readonly ?string $targetDate,
        public readonly ?string $description,
        public readonly bool $isLegacy,
        public readonly string $source,
        public readonly bool $taskExists,
        public readonly bool $canRetry,
        public readonly bool $canArchive,
        public readonly bool $taskArchived = false,
        public readonly bool $duplicateIdentityDetected = false,
        public readonly array $steps = [],
        public readonly ?string $lastRunAt = null,
        public readonly ?string $loaiSanPham = null,
        public readonly ?string $galleryDescription = null,
        public readonly ?string $rewriteMode = null,
        public readonly ?string $rewriteNotes = null,
        public readonly ?string $articleReviewStatus = null,
        public readonly bool $articleIsApproved = false,
        public readonly array $extra = [],
    ) {}

    public function uiKey(): string
    {
        if ($this->runItemId !== null && $this->runItemId > 0) {
            return 'run-item-'.$this->runItemId;
        }

        $runId = (int) ($this->extra['run_id'] ?? 0);
        $legacy = implode('|', [
            $this->taskId ?? 0,
            $this->action ?? '',
            $this->type,
            mb_strtolower(trim($this->sourceContent)),
            (string) ($this->extra['legacy_index'] ?? ''),
        ]);

        return 'legacy-'.$runId.'-'.hash('sha256', $legacy);
    }

    /**
     * @return ViewArray
     */
    public function toArray(): array
    {
        $row = [
            'id' => $this->uiKey(),
            'run_item_id' => $this->runItemId,
            'task_id' => $this->taskId,
            'article_id' => $this->articleId,
            'action' => $this->action,
            'type' => $this->type,
            'post_type' => $this->postType,
            'source_content' => $this->sourceContent,
            'status' => $this->status,
            'attempt' => $this->attempt,
            'retry_count' => max(0, $this->attempt - 1),
            'retry_task_id' => $this->taskExists ? $this->taskId : null,
            'message' => $this->message,
            'error_code' => $this->errorCode,
            'error_message' => $this->errorMessage,
            'error_detail' => $this->errorMessage,
            'article_edit_url' => $this->articleEditUrl,
            'target_date' => $this->targetDate,
            'description' => $this->description,
            'loai_san_pham' => $this->loaiSanPham,
            'gallery_description' => $this->galleryDescription,
            'rewrite_mode' => $this->rewriteMode,
            'rewrite_notes' => $this->rewriteNotes,
            'is_legacy' => $this->isLegacy,
            'source' => $this->source,
            'task_exists' => $this->taskExists,
            'can_retry' => $this->canRetry,
            'can_archive' => $this->canArchive,
            'task_archived' => $this->taskArchived,
            'duplicate_identity_detected' => $this->duplicateIdentityDetected,
            'steps' => $this->steps,
            'last_run_at' => $this->lastRunAt,
            'article_review_status' => $this->articleReviewStatus,
            'article_is_approved' => $this->articleIsApproved,
        ];

        return array_merge($row, $this->extra);
    }
}
