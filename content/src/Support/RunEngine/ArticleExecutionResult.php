<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Content\Support\RunEngine;

use Omnichannel\Addons\ContentProjects\Enums\ContentProjectArticleSemanticStatus;

/**
 * Normalized article execution outcome for ContentProjectRunEngine
 * (Phase 1 result object — doc name ContentProjectArticleRunResult).
 * Engine must not parse exception text / DOM for decisions.
 */
final class ArticleExecutionResult
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        public readonly int $runId,
        public readonly int $taskId,
        public readonly ?int $runItemId,
        public readonly ContentProjectArticleSemanticStatus $status,
        public readonly ?int $articleId = null,
        public readonly string $message = '',
        public readonly ?string $errorCode = null,
        public readonly array $payload = [],
        public readonly ?bool $mayDispatchNextOverride = null,
    ) {}

    public function isSuccess(): bool
    {
        return $this->status === ContentProjectArticleSemanticStatus::Completed
            || $this->status === ContentProjectArticleSemanticStatus::Skipped;
    }

    public function isFailed(): bool
    {
        return $this->status === ContentProjectArticleSemanticStatus::Failed;
    }

    public function isCancelled(): bool
    {
        return $this->status === ContentProjectArticleSemanticStatus::Cancelled;
    }

    /**
     * Domain failed/completed/skipped → continue run.
     * Cancelled → no next article (run stopping/cancelled path).
     */
    public function mayDispatchNext(): bool
    {
        if ($this->mayDispatchNextOverride !== null) {
            return $this->mayDispatchNextOverride;
        }

        return $this->status !== ContentProjectArticleSemanticStatus::Cancelled;
    }

    /**
     * @param  array<string, mixed>  $itemRow
     */
    public static function fromLegacyItemRow(
        int $runId,
        int $taskId,
        ?int $runItemId,
        array $itemRow,
        ContentProjectRunStatusMapper $mapper,
    ): self {
        $legacyStatus = (string) ($itemRow['status'] ?? 'failed');
        $errorDetail = (string) ($itemRow['error_detail'] ?? $itemRow['message'] ?? '');

        if ($legacyStatus === 'success') {
            $semantic = ContentProjectArticleSemanticStatus::Completed;
        } elseif ($legacyStatus === 'pending') {
            $semantic = ContentProjectArticleSemanticStatus::Pending;
        } elseif ($legacyStatus === 'skipped' || $legacyStatus === 'manual') {
            $semantic = ContentProjectArticleSemanticStatus::Skipped;
        } elseif ($legacyStatus === 'failed' && $mapper->errorLooksCancelled($errorDetail)) {
            $semantic = ContentProjectArticleSemanticStatus::Cancelled;
        } elseif ($legacyStatus === 'failed') {
            $semantic = ContentProjectArticleSemanticStatus::Failed;
        } else {
            $semantic = $mapper->articleFromDb($legacyStatus, $errorDetail);
        }

        return new self(
            runId: $runId,
            taskId: $taskId,
            runItemId: $runItemId,
            status: $semantic,
            articleId: isset($itemRow['article_id']) ? (int) $itemRow['article_id'] : null,
            message: (string) ($itemRow['message'] ?? ''),
            errorCode: isset($itemRow['error_code']) ? (string) $itemRow['error_code'] : null,
            payload: $itemRow,
        );
    }
}
