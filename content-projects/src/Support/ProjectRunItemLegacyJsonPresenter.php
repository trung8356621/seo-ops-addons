<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Support;

use Omnichannel\Addons\ContentProjects\Enums\SeoProjectRunAction;
use Omnichannel\Addons\ContentProjects\Enums\SeoProjectRunItemStatus;
use Omnichannel\Addons\Content\Filament\Resources\ArticleResource;
use Omnichannel\Addons\ContentProjects\Models\SeoProjectRunItem;
use Omnichannel\Addons\ContentProjects\Models\SeoProjectTask;
use Omnichannel\Addons\Content\Services\ArticleEditorReadinessService;

/**
 * Build legacy seo_project_runs.items[] row từ run item + task (UI compatibility).
 *
 * retry_task_id = task_id gốc (không còn task copy).
 */
final class ProjectRunItemLegacyJsonPresenter
{
    public function __construct(
        private readonly ?ArticleEditorReadinessService $editorReadiness = null,
    ) {}

    /**
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    public function present(SeoProjectRunItem $runItem, SeoProjectTask $task, array $extra = []): array
    {
        $status = SeoProjectRunItemStatus::tryFrom((string) $runItem->status)
            ?? SeoProjectRunItemStatus::Pending;
        $articleId = (int) ($runItem->article_id ?? $task->article_id ?? 0);
        $legacyStatus = $status->toLegacyJsonStatus();

        if ($task->type === SeoProjectTask::TYPE_IMPROVE && $status === SeoProjectRunItemStatus::Pending) {
            $legacyStatus = SeoProjectRunItemStatus::Manual->value;
        }

        $row = [
            'task_id' => (int) $task->id,
            'retry_task_id' => (int) $task->id,
            'action' => (string) $runItem->action,
            'type' => (string) $task->type,
            'source_content' => (string) $task->source_content,
            'post_type' => SeoProjectTask::isNewArticleType($task->type)
                ? SeoProjectTask::normalizePostType($task->post_type)
                : null,
            'loai_san_pham' => SeoProjectTask::isNewArticleType($task->type)
                && SeoProjectTask::normalizePostType($task->post_type) === SeoProjectTask::POST_TYPE_PRODUCT
                    ? (string) ($task->loai_san_pham ?? '')
                    : null,
            'gallery_description' => SeoProjectTask::isNewArticleType($task->type)
                && SeoProjectTask::normalizePostType($task->post_type) === SeoProjectTask::POST_TYPE_PRODUCT
                    ? (string) ($task->description ?? '')
                    : null,
            'target_date' => $task->target_date?->format('Y-m-d'),
            'rewrite_mode' => $task->type === SeoProjectTask::TYPE_REWRITE
                ? SeoProjectTask::normalizeRewriteMode($task->rewrite_mode)
                : null,
            'rewrite_notes' => $task->type === SeoProjectTask::TYPE_REWRITE
                ? $task->rewrite_notes
                : null,
            'status' => $legacyStatus,
            'article_id' => $articleId > 0 ? $articleId : null,
            'article_edit_url' => null,
            'message' => (string) ($runItem->message ?? ''),
            'steps' => is_array($runItem->output_snapshot['steps'] ?? null)
                ? $runItem->output_snapshot['steps']
                : [],
            'attempt' => (int) $runItem->attempt,
            'retry_count' => max(0, (int) $runItem->attempt - 1),
            'error_code' => $runItem->error_code,
        ];

        if ($articleId > 0) {
            $ready = $this->editorReadiness?->isReady($articleId) ?? true;
            $row['article_edit_url'] = $ready
                ? ArticleResource::getUrl('edit', ['record' => $articleId], isAbsolute: false)
                : null;
            $row['article_editor_ready'] = $ready;
        }

        if (filled($runItem->error_message)) {
            $row['error_detail'] = (string) $runItem->error_message;
        }

        if ($runItem->finished_at !== null) {
            $row['last_run_at'] = $runItem->finished_at->format('Y-m-d H:i:s');
        }

        $action = SeoProjectRunAction::tryFrom((string) $runItem->action);
        if ($action === SeoProjectRunAction::ArticleUpdate && $task->type === SeoProjectTask::TYPE_IMPROVE) {
            $row['status'] = SeoProjectRunItemStatus::Manual->value;
        }

        return array_merge($row, $extra);
    }
}
