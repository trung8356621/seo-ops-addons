<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Support\RunEngine;

use Omnichannel\Addons\ContentProjects\Enums\ContentProjectArticleSemanticStatus;
use Omnichannel\Addons\ContentProjects\Enums\ContentProjectErrorCode;
use Throwable;

/**
 * Normalized task/article execution outcome (Phase 1.7).
 * Not mixed array — callers map via toLegacyItemRow() for BC.
 */
final class ContentProjectTaskExecutionResult
{
    /**
     * @param  array<string, mixed>  $outputs
     * @param  array<string, mixed>  $providerUsage
     * @param  list<int|string>  $promptIds
     */
    public function __construct(
        public readonly bool $success,
        public readonly bool $cancelled,
        public readonly bool $failed,
        public readonly bool $retryable,
        public readonly string $status,
        public readonly string $message = '',
        public readonly ?string $errorCode = null,
        public readonly ?int $taskId = null,
        public readonly ?int $runItemId = null,
        public readonly ?int $articleId = null,
        public readonly array $outputs = [],
        public readonly array $promptIds = [],
        public readonly array $providerUsage = [],
        public readonly ?float $durationSeconds = null,
        public readonly ?Throwable $exception = null,
        public readonly array $legacyItemRow = [],
    ) {}

    /**
     * @param  array<string, mixed>  $itemRow
     */
    public static function fromLegacyItemRow(
        array $itemRow,
        ?float $durationSeconds = null,
        ?Throwable $exception = null,
    ): self {
        $status = (string) ($itemRow['status'] ?? 'failed');
        $message = (string) ($itemRow['message'] ?? '');
        $errorDetail = (string) ($itemRow['error_detail'] ?? $message);
        $cancelled = $status === 'failed' && self::looksCancelled($errorDetail);
        $success = in_array($status, ['success', 'skipped', 'manual'], true);
        $failed = ! $success && ! $cancelled && $status !== 'pending';

        $promptIds = [];
        $steps = is_array($itemRow['steps'] ?? null) ? $itemRow['steps'] : [];
        foreach ($steps as $step) {
            if (! is_array($step)) {
                continue;
            }
            if (($step['type'] ?? '') === 'prompt' && isset($step['prompt_id'])) {
                $promptIds[] = $step['prompt_id'];
            }
        }

        return new self(
            success: $success,
            cancelled: $cancelled,
            failed: $failed,
            retryable: $failed && ! $cancelled,
            status: $cancelled ? 'cancelled' : $status,
            message: $message,
            errorCode: isset($itemRow['error_code']) ? (string) $itemRow['error_code'] : null,
            taskId: isset($itemRow['task_id']) ? (int) $itemRow['task_id'] : null,
            runItemId: isset($itemRow['run_item_id']) ? (int) $itemRow['run_item_id'] : null,
            articleId: isset($itemRow['article_id']) ? (int) $itemRow['article_id'] : null,
            outputs: [
                'steps' => $steps,
                'step_stats' => $itemRow['step_stats'] ?? null,
            ],
            promptIds: $promptIds,
            providerUsage: is_array($itemRow['provider_usage'] ?? null) ? $itemRow['provider_usage'] : [],
            durationSeconds: $durationSeconds,
            exception: $exception,
            legacyItemRow: $itemRow,
        );
    }

    public static function fromException(int $taskId, Throwable $exception, bool $cancelled = false): self
    {
        return new self(
            success: false,
            cancelled: $cancelled,
            failed: ! $cancelled,
            retryable: ! $cancelled,
            status: $cancelled ? 'cancelled' : 'failed',
            message: $exception->getMessage(),
            errorCode: $cancelled
                ? ContentProjectErrorCode::TaskCancelled->value
                : ContentProjectErrorCode::ExternalWorkflowFailed->value,
            taskId: $taskId,
            exception: $exception,
            legacyItemRow: [
                'task_id' => $taskId,
                'retry_task_id' => $taskId,
                'status' => 'failed',
                'message' => $exception->getMessage(),
                'error_code' => ContentProjectErrorCode::ExternalWorkflowFailed->value,
                'error_detail' => $exception->getMessage(),
                'steps' => [],
            ],
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toLegacyItemRow(): array
    {
        if ($this->legacyItemRow !== []) {
            return $this->legacyItemRow;
        }

        return [
            'task_id' => $this->taskId,
            'retry_task_id' => $this->taskId,
            'run_item_id' => $this->runItemId,
            'status' => $this->cancelled ? 'failed' : $this->status,
            'message' => $this->message,
            'error_code' => $this->errorCode,
            'article_id' => $this->articleId,
            'steps' => $this->outputs['steps'] ?? [],
        ];
    }

    public function toArticleSemanticStatus(): ContentProjectArticleSemanticStatus
    {
        if ($this->cancelled) {
            return ContentProjectArticleSemanticStatus::Cancelled;
        }
        if ($this->success) {
            return match ($this->status) {
                'skipped', 'manual' => ContentProjectArticleSemanticStatus::Skipped,
                default => ContentProjectArticleSemanticStatus::Completed,
            };
        }
        if ($this->status === 'pending') {
            return ContentProjectArticleSemanticStatus::Pending;
        }

        return ContentProjectArticleSemanticStatus::Failed;
    }

    private static function looksCancelled(string $message): bool
    {
        $lower = mb_strtolower($message);

        return str_contains($lower, 'cancel')
            || str_contains($lower, 'dừng')
            || str_contains($lower, 'stopped');
    }
}
