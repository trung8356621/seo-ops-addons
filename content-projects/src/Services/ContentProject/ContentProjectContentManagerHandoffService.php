<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Services\ContentProject;

use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\ContentProjects\Models\SeoProjectTask;
use Omnichannel\Addons\Seo\Support\SeoAccessControl;
use App\Models\User;
use App\Support\RuntimeLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Content Manager canonical Save → reporting stamp "In Review" once.
 *
 * Does NOT change lifecycle / review_status / task generation status.
 * Does NOT gate Schedule or Approve.
 */
final class ContentProjectContentManagerHandoffService
{
    public const ORIGIN_ARTICLE_EDITOR = 'article_editor';

    public function __construct(
        private readonly ContentProjectArticleMembership $membership,
        private readonly ContentProjectGenerationReadStateStore $readStates,
    ) {}

    /**
     * @return array{
     *     handed_off: bool,
     *     already_in_review: bool,
     *     skipped: bool,
     *     reason: string|null,
     *     task_id: int|null,
     *     project_id: int|null,
     *     counter_action: string|null
     * }
     */
    public function maybeHandoffAfterCanonicalSave(
        SeoArticle $article,
        ?User $actor,
        ?string $origin,
    ): array {
        $empty = [
            'handed_off' => false,
            'already_in_review' => false,
            'skipped' => true,
            'reason' => null,
            'task_id' => null,
            'project_id' => null,
            'counter_action' => null,
        ];

        if (trim((string) $origin) !== self::ORIGIN_ARTICLE_EDITOR) {
            return [...$empty, 'reason' => 'origin_not_canonical_editor'];
        }

        if (! $actor instanceof User) {
            return [...$empty, 'reason' => 'no_actor'];
        }

        if (! SeoAccessControl::canSubmitArticleReview()) {
            return [...$empty, 'reason' => 'actor_not_content_manager'];
        }

        $task = $this->membership->activeTaskForArticle($article);
        if (! $task instanceof SeoProjectTask) {
            return [...$empty, 'reason' => 'not_content_project_item'];
        }

        $taskId = (int) $task->getKey();
        $projectId = (int) ($task->project_id ?? 0);

        if (! Schema::connection('omi_seo_ai')->hasColumn('seo_project_tasks', 'content_manager_reviewed_at')) {
            return [...$empty, 'reason' => 'reporting_column_missing', 'task_id' => $taskId, 'project_id' => $projectId > 0 ? $projectId : null];
        }

        if ($task->content_manager_reviewed_at !== null) {
            return [
                'handed_off' => false,
                'already_in_review' => true,
                'skipped' => true,
                'reason' => 'already_content_manager_reviewed',
                'task_id' => $taskId,
                'project_id' => $projectId > 0 ? $projectId : null,
                'counter_action' => null,
            ];
        }

        $taskStatus = strtolower(trim((string) ($task->status ?? '')));
        if (! in_array($taskStatus, [
            SeoProjectTask::STATUS_COMPLETED,
            SeoProjectTask::STATUS_REVIEWING,
            'completed',
            'reviewing',
        ], true)) {
            return [...$empty, 'reason' => 'generation_not_ready', 'task_id' => $taskId, 'project_id' => $projectId > 0 ? $projectId : null];
        }

        $now = now();
        $actorId = (int) $actor->getKey();

        try {
            $updated = SeoProjectTask::query()
                ->whereKey($taskId)
                ->whereNull('content_manager_reviewed_at')
                ->update([
                    'content_manager_reviewed_at' => $now,
                    'content_manager_reviewed_by' => $actorId > 0 ? $actorId : null,
                ]);
        } catch (Throwable $e) {
            RuntimeLogger::warning('seo.content_project.cm_review_stamp_failed', [
                'article_id' => (int) $article->getKey(),
                'task_id' => $taskId,
                'message' => $e->getMessage(),
            ]);

            return [...$empty, 'reason' => 'stamp_failed', 'task_id' => $taskId, 'project_id' => $projectId > 0 ? $projectId : null];
        }

        if ($updated === 0) {
            return [
                'handed_off' => false,
                'already_in_review' => true,
                'skipped' => true,
                'reason' => 'already_content_manager_reviewed',
                'task_id' => $taskId,
                'project_id' => $projectId > 0 ? $projectId : null,
                'counter_action' => null,
            ];
        }

        if ($projectId > 0 && auth()->id() !== null) {
            $this->readStates->markViewed(
                (int) auth()->id(),
                $projectId,
                $taskId,
                $now,
            );
        }

        $this->recordBusinessAudit($actorId, $projectId, $taskId, (int) $article->getKey());

        RuntimeLogger::info('seo.content_project.cm_review_stamp', [
            'article_id' => (int) $article->getKey(),
            'task_id' => $taskId,
            'project_id' => $projectId,
            'reviewed_by' => $actorId,
        ]);

        return [
            'handed_off' => true,
            'already_in_review' => false,
            'skipped' => false,
            'reason' => null,
            'task_id' => $taskId,
            'project_id' => $projectId > 0 ? $projectId : null,
            'counter_action' => 'content_manager_handoff',
        ];
    }

    private function recordBusinessAudit(int $actorId, int $projectId, int $taskId, int $articleId): void
    {
        if (! Schema::connection('omi_seo_ai')->hasTable('seo_content_project_business_audits')) {
            return;
        }

        try {
            DB::connection('omi_seo_ai')->table('seo_content_project_business_audits')->insert([
                'actor_type' => 'user',
                'actor_id' => $actorId > 0 ? $actorId : null,
                'action' => 'content_manager.canonical_save_review_stamp',
                'project_ref' => $projectId > 0 ? 'project:'.$projectId : null,
                'item_ref' => 'item:'.$taskId,
                'result' => 'success',
                'result_code' => 'cm.review_stamped',
                'metadata' => json_encode([
                    'article_id' => $articleId,
                    'reporting_state' => 'in_review',
                    'lifecycle_changed' => false,
                ], JSON_UNESCAPED_UNICODE),
                'occurred_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } catch (Throwable) {
            // audit never breaks save path
        }
    }
}
