<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Support\ContentProject;

use Omnichannel\Addons\ContentProjects\Models\SeoProjectTask;
use Omnichannel\Addons\ContentProjects\Support\TaskTestContext;
use Illuminate\Support\Facades\Schema;

/**
 * Optional per-item safety gate: Outline success → intentional pause → Content on Resume.
 *
 * Not a failure. Does not consume retry counters. Preference stays until disabled.
 */
final class ContentProjectOutlineReviewCheckpoint
{
    public const PAUSE_REASON = 'review_checkpoint';

    public const RESULT_FLAG = 'awaiting_review';

    public static function isEnabledForTask(SeoProjectTask $task): bool
    {
        if (! self::hasEnabledColumn()) {
            return false;
        }

        return (bool) ($task->review_checkpoint_enabled ?? false);
    }

    /**
     * Re-read preference from DB at the Content-dispatch seam (mid-outline toggle must still pause).
     * Also honors runtime variable `review_checkpoint_enabled=1` for tests / forced runs.
     */
    public static function isEnabledForContext(TaskTestContext $context): bool
    {
        $flag = $context->variables['review_checkpoint_enabled'] ?? null;
        if ($flag === true || $flag === 1 || $flag === '1' || $flag === 'true') {
            return true;
        }

        $task = self::resolveProjectTask($context);
        if (! $task instanceof SeoProjectTask) {
            return false;
        }

        $fresh = SeoProjectTask::query()->find((int) $task->getKey());

        return $fresh instanceof SeoProjectTask && self::isEnabledForTask($fresh);
    }

    public static function resolveProjectTask(TaskTestContext $context): ?SeoProjectTask
    {
        $taskId = (int) ($context->variables['project_task_id'] ?? $context->variables['task_id'] ?? 0);
        if ($taskId <= 0) {
            return null;
        }

        $task = SeoProjectTask::query()->find($taskId);

        return $task instanceof SeoProjectTask ? $task : null;
    }

    public static function isWaitingReview(SeoProjectTask $task): bool
    {
        if (! self::hasPauseColumns()) {
            return false;
        }

        $reason = trim((string) ($task->generation_pause_reason ?? ''));

        return $reason === self::PAUSE_REASON && $task->generation_paused_at !== null;
    }

    public static function markWaitingReview(SeoProjectTask $task): void
    {
        if (! self::hasPauseColumns()) {
            return;
        }

        SeoProjectTask::query()->whereKey((int) $task->getKey())->update([
            'generation_paused_at' => now(),
            'generation_pause_reason' => self::PAUSE_REASON,
            // Neutral — not failed, not completed writing.
            'status' => SeoProjectTask::STATUS_PENDING,
        ]);
        $task->refresh();
    }

    public static function clearPause(SeoProjectTask $task): void
    {
        if (! self::hasPauseColumns()) {
            return;
        }

        SeoProjectTask::query()->whereKey((int) $task->getKey())->update([
            'generation_paused_at' => null,
            'generation_pause_reason' => null,
        ]);
        $task->refresh();
    }

    /**
     * @param  list<array<string, mixed>>  $steps
     * @return array{
     *   success: bool,
     *   awaiting_review: bool,
     *   pause_reason: string,
     *   article_id: ?int,
     *   steps: list<array<string, mixed>>,
     *   message: string,
     *   outline_checkpoint_hash?: string
     * }
     */
    public static function pausedResult(int $articleId, array $steps, ?string $outlineHash = null): array
    {
        $payload = [
            'success' => false,
            self::RESULT_FLAG => true,
            'pause_reason' => self::PAUSE_REASON,
            'article_id' => $articleId > 0 ? $articleId : null,
            'steps' => $steps,
            'message' => 'Waiting for review — outline ready. Resume to continue with Content.',
        ];

        if ($outlineHash !== null && $outlineHash !== '') {
            $payload['outline_checkpoint_hash'] = $outlineHash;
        }

        return $payload;
    }

    public static function isPausedResult(array $result): bool
    {
        return ($result[self::RESULT_FLAG] ?? false) === true
            && (string) ($result['pause_reason'] ?? '') === self::PAUSE_REASON;
    }

    private static function hasEnabledColumn(): bool
    {
        try {
            return Schema::connection('omi_seo_ai')->hasColumn('seo_project_tasks', 'review_checkpoint_enabled');
        } catch (\Throwable) {
            return false;
        }
    }

    private static function hasPauseColumns(): bool
    {
        try {
            return Schema::connection('omi_seo_ai')->hasColumn('seo_project_tasks', 'generation_pause_reason')
                && Schema::connection('omi_seo_ai')->hasColumn('seo_project_tasks', 'generation_paused_at');
        } catch (\Throwable) {
            return false;
        }
    }
}
