<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Support;

use Omnichannel\Addons\ContentProjects\Enums\SeoProjectRunAction;
use Omnichannel\Addons\ContentProjects\Enums\SeoProjectRunItemStatus;

/**
 * Map legacy seo_project_runs.items[] shape → payload chuẩn cho seo_project_run_items.
 * Phase 2: pure mapper — không đọc/ghi DB.
 *
 * @phpstan-type MappedRunItem array{
 *     task_id: int|null,
 *     article_id: int|null,
 *     action: string,
 *     status: string,
 *     attempt: int,
 *     message: string|null,
 *     error_code: string|null,
 *     error_message: string|null,
 *     input_snapshot: array<string, mixed>,
 *     output_snapshot: array<string, mixed>,
 *     started_at: string|null,
 *     finished_at: string|null,
 * }
 */
final class LegacyProjectRunItemMapper
{
    /**
     * @param  array<string, mixed>  $item
     * @return MappedRunItem|null
     */
    public function map(array $item): ?array
    {
        if ($item === []) {
            return null;
        }

        $taskId = (int) ($item['task_id'] ?? 0);
        $articleId = (int) ($item['article_id'] ?? 0);
        $legacyStatus = (string) ($item['status'] ?? 'pending');
        $status = SeoProjectRunItemStatus::fromLegacy($legacyStatus);
        $action = SeoProjectRunAction::fromLegacyTaskType(
            isset($item['type']) ? (string) $item['type'] : null,
        );

        $retryCount = max(0, (int) ($item['retry_count'] ?? 0));
        $attempt = $retryCount > 0 ? $retryCount + 1 : 1;

        $message = trim((string) ($item['message'] ?? ''));
        $errorDetail = trim((string) ($item['error_detail'] ?? ''));

        $lastRunAt = $this->normalizeTimestamp($item['last_run_at'] ?? null);

        return [
            'task_id' => $taskId > 0 ? $taskId : null,
            'article_id' => $articleId > 0 ? $articleId : null,
            'action' => $action->value,
            'status' => $status->value,
            'attempt' => $attempt,
            'message' => $message !== '' ? $message : null,
            'error_code' => $status === SeoProjectRunItemStatus::Failed ? 'legacy_failed' : null,
            'error_message' => $errorDetail !== '' ? $errorDetail : null,
            'input_snapshot' => [
                'type' => $item['type'] ?? null,
                'source_content' => $item['source_content'] ?? null,
                'post_type' => $item['post_type'] ?? null,
                'loai_san_pham' => $item['loai_san_pham'] ?? null,
                'gallery_description' => $item['gallery_description'] ?? null,
                'rewrite_mode' => $item['rewrite_mode'] ?? null,
                'rewrite_notes' => $item['rewrite_notes'] ?? null,
                'target_date' => $item['target_date'] ?? null,
                'retry_task_id' => $item['retry_task_id'] ?? null,
            ],
            'output_snapshot' => [
                'steps' => is_array($item['steps'] ?? null) ? $item['steps'] : [],
                'step_stats' => is_array($item['step_stats'] ?? null) ? $item['step_stats'] : null,
                'article_edit_url' => $item['article_edit_url'] ?? null,
                'debug' => is_array($item['debug'] ?? null) ? $item['debug'] : null,
            ],
            'started_at' => null,
            'finished_at' => $lastRunAt,
        ];
    }

    private function normalizeTimestamp(mixed $value): ?string
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d H:i:s');
        }

        $raw = trim((string) $value);

        return $raw !== '' ? $raw : null;
    }
}
