<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Services\ContentProject\Draft;

use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\ContentProjects\Enums\ContentProjectItemExecutionState;
use Omnichannel\Addons\ContentProjects\Enums\ContentProjectItemGenerationState;
use Omnichannel\Addons\ContentProjects\Enums\ContentProjectItemPublishState;
use Omnichannel\Addons\ContentProjects\Enums\ContentProjectItemReviewState;
use Omnichannel\Addons\ContentProjects\Enums\ContentProjectPublishQueueStatus;
use Omnichannel\Addons\ContentProjects\Models\SeoProject;
use Omnichannel\Addons\ContentProjects\Models\SeoProjectTask;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\ActorContext;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Commands\ArchiveProjectItemsCommand;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\ContentProjectCommandBus;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\IdeaManager\IdeaManagerKeywordIntakeResult;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\IdeaManager\IdeaManagerKeywordIntakeService;
use Omnichannel\Addons\ContentProjects\Support\ContentProject\ContentProjectItemStateResolver;
use Omnichannel\Addons\Seo\Support\SeoAccessControl;
use Throwable;

/**
 * Orchestrates MOVE from Shared Planning Draft → Idea Manager (Row + Bulk).
 *
 * Contract:
 * - Transfers ONLY canonical Keyword/Phrase + source site_id (no title/description/AI/DNA/review metadata).
 * - Persists/confirms Idea via IdeaManagerKeywordIntakeService BEFORE deleting Draft.
 * - Deletes Draft only via canonical ArchiveProjectItemsCommand on ContentProjectCommandBus.
 * - Skips tasks with linked Article/Review/Publish/Running AI states (`skipped_unsafe`).
 */
final class MoveSharedDraftToIdeaManagerService
{
    public function __construct(
        private readonly IdeaManagerKeywordIntakeService $keywordIntake,
        private readonly ContentProjectCommandBus $commandBus,
        private readonly ContentProjectItemStateResolver $stateResolver = new ContentProjectItemStateResolver,
    ) {}

    /**
     * @param  list<int>  $taskIds
     * @return array{
     *   ok: bool,
     *   moved_ids: list<int>,
     *   added_count: int,
     *   already_exists_count: int,
     *   deleted_count: int,
     *   skipped_unsafe: list<array{task_id: int, keyword: string, reason: string}>,
     *   failed: list<array{task_id: int, keyword: string, reason: string}>
     * }
     */
    public function move(SeoProject $project, array $taskIds, ?int $actorId = null): array
    {
        abort_unless(SeoAccessControl::canManageContentProjectWorkflow(), 403);

        $ids = array_values(array_unique(array_filter(
            array_map(static fn (mixed $id): int => (int) $id, $taskIds),
            static fn (int $id): bool => $id > 0,
        )));

        $result = [
            'ok' => false,
            'moved_ids' => [],
            'added_count' => 0,
            'already_exists_count' => 0,
            'deleted_count' => 0,
            'skipped_unsafe' => [],
            'failed' => [],
        ];

        if ($ids === []) {
            return $result;
        }

        if (! $project->isDraftPlanning() || $project->archived_at !== null || $project->isArchive()) {
            foreach ($ids as $id) {
                $result['failed'][] = [
                    'task_id' => $id,
                    'keyword' => '',
                    'reason' => 'Chỉ hỗ trợ Đưa về Kho Idea cho Shared Planning Draft đang hoạt động.',
                ];
            }

            return $result;
        }

        $actorId = $actorId !== null && $actorId > 0
            ? $actorId
            : (auth()->id() !== null ? (int) auth()->id() : null);

        if ($actorId === null || $actorId <= 0) {
            foreach ($ids as $id) {
                $result['failed'][] = [
                    'task_id' => $id,
                    'keyword' => '',
                    'reason' => 'Thiếu thông tin người thực hiện.',
                ];
            }

            return $result;
        }

        $tasksById = SeoProjectTask::query()
            ->where('project_id', (int) $project->getKey())
            ->whereIn('id', $ids)
            ->with(['article', 'project'])
            ->get()
            ->keyBy(static fn (SeoProjectTask $task): int => (int) $task->getKey());

        foreach ($ids as $taskId) {
            $task = $tasksById->get($taskId);
            if (! $task instanceof SeoProjectTask) {
                $result['failed'][] = [
                    'task_id' => $taskId,
                    'keyword' => '',
                    'reason' => 'Không tìm thấy Draft trong Shared Planning Draft.',
                ];
                continue;
            }

            $keyword = trim((string) ($task->keyword ?? ''));
            $unsafeReason = $this->detectUnsafeLinkedReason($task);
            if ($unsafeReason !== null) {
                $result['skipped_unsafe'][] = [
                    'task_id' => $taskId,
                    'keyword' => $keyword,
                    'reason' => $unsafeReason,
                ];
                continue;
            }

            if ($keyword === '') {
                $result['failed'][] = [
                    'task_id' => $taskId,
                    'keyword' => '',
                    'reason' => 'Draft chưa có Keyword/Phrase để đưa về Kho Idea.',
                ];
                continue;
            }

            $siteId = (int) ($task->site_id ?? 0);
            if ($siteId <= 0) {
                $result['failed'][] = [
                    'task_id' => $taskId,
                    'keyword' => $keyword,
                    'reason' => 'Draft chưa gán Domain nguồn.',
                ];
                continue;
            }

            if (! SeoAccessControl::canAccessSite($siteId)) {
                $result['failed'][] = [
                    'task_id' => $taskId,
                    'keyword' => $keyword,
                    'reason' => 'Không có quyền ghi vào Kho Idea của Domain này.',
                ];
                continue;
            }

            // Step 1: Persist or confirm Idea in Idea Manager BEFORE deleting Draft.
            try {
                $intakeResult = $this->keywordIntake->addPhrases([$keyword], [$siteId], $actorId);
            } catch (Throwable $e) {
                $result['failed'][] = [
                    'task_id' => $taskId,
                    'keyword' => $keyword,
                    'reason' => $e->getMessage() !== '' ? $e->getMessage() : 'Lỗi khi lưu Idea vào Kho.',
                ];
                continue;
            }

            if (! $this->isIntakeConfirmed($intakeResult)) {
                $result['failed'][] = [
                    'task_id' => $taskId,
                    'keyword' => $keyword,
                    'reason' => $intakeResult->message !== '' ? $intakeResult->message : 'Không thể xác nhận lưu Idea vào Kho.',
                ];
                continue;
            }

            // Step 2: Delete original Draft only via canonical ArchiveProjectItemsCommand.
            try {
                $archiveResult = $this->commandBus->dispatch(
                    new ArchiveProjectItemsCommand(
                        (int) $project->getKey(),
                        [$taskId],
                        note: 'moved_to_idea_manager',
                        removeReason: ArchiveProjectItemsCommand::REASON_GLOBAL_SKIP,
                    ),
                    ActorContext::user(
                        $actorId,
                        (int) ($project->site_id ?? 0) ?: null,
                    ),
                );
            } catch (Throwable $e) {
                $result['failed'][] = [
                    'task_id' => $taskId,
                    'keyword' => $keyword,
                    'reason' => 'Đã lưu Idea nhưng xóa Draft thất bại: '.$e->getMessage(),
                ];
                continue;
            }

            if (! $archiveResult->success) {
                $result['failed'][] = [
                    'task_id' => $taskId,
                    'keyword' => $keyword,
                    'reason' => $archiveResult->message !== '' ? $archiveResult->message : 'Đã lưu Idea nhưng không thể xóa Draft.',
                ];
                continue;
            }

            $result['moved_ids'][] = $taskId;
            $result['deleted_count']++;
            if ($intakeResult->isAlreadyPresent()) {
                $result['already_exists_count']++;
            } else {
                $result['added_count']++;
            }
        }

        $result['ok'] = $result['moved_ids'] !== [] && $result['failed'] === [];

        return $result;
    }

    private function isIntakeConfirmed(IdeaManagerKeywordIntakeResult $intakeResult): bool
    {
        if (! $intakeResult->isSuccess()) {
            return false;
        }

        if ((int) ($intakeResult->summary['failed'] ?? 0) > 0) {
            return false;
        }

        return ((int) ($intakeResult->summary['added'] ?? 0) + (int) ($intakeResult->summary['duplicate'] ?? 0)) > 0;
    }

    private function detectUnsafeLinkedReason(SeoProjectTask $task): ?string
    {
        $status = (string) ($task->status ?? '');
        if ($task->archived_at !== null || in_array($status, [SeoProjectTask::STATUS_ARCHIVED, SeoProjectTask::STATUS_CANCELLED], true)) {
            return 'Draft đã bị lưu trữ hoặc hủy trước đó.';
        }

        if (in_array($status, [SeoProjectTask::STATUS_WRITING, SeoProjectTask::STATUS_PROCESSING], true) || $task->isWaitingOutlineReview()) {
            return 'Task đang có tiến trình AI chạy.';
        }

        $hasActiveRunItem = $task->relationLoaded('runItems')
            ? $task->runItems->whereIn('status', ['pending', 'queued', 'running', 'processing', 'writing'])->isNotEmpty()
            : $task->runItems()->whereIn('status', ['pending', 'queued', 'running', 'processing', 'writing'])->exists();
        if ($hasActiveRunItem) {
            return 'Task đang có AI run item hoạt động.';
        }

        $article = $task->relationLoaded('article') ? $task->article : null;
        $state = $this->stateResolver->resolve($task, $article instanceof SeoArticle ? $article : null);

        if ($state->executionState === ContentProjectItemExecutionState::Running) {
            return 'Task đang có tiến trình thực thi hoạt động.';
        }

        if ($state->publishState !== ContentProjectItemPublishState::None) {
            return 'Task đã gắn bài viết đang hoặc đã Publish.';
        }

        $queueStatus = ContentProjectPublishQueueStatus::tryFrom((string) ($task->publish_queue_status ?? 'none'))
            ?? ContentProjectPublishQueueStatus::None;
        if ($queueStatus !== ContentProjectPublishQueueStatus::None || $task->publishing_queued_at !== null || $task->publishing_started_at !== null || $task->publish_published_at !== null) {
            return 'Task đang nằm trong Publishing Queue hoặc đã có dữ liệu Publish.';
        }

        if ($task->content_manager_reviewed_at !== null || $state->reviewState !== ContentProjectItemReviewState::None || $status === SeoProjectTask::STATUS_REVIEWING) {
            return 'Task đang ở trạng thái Review/Approval.';
        }

        $isCreateType = SeoProjectTask::isNewArticleType($task->type);
        if ($isCreateType && (int) ($task->article_id ?? 0) > 0) {
            return 'Task đã liên kết với Article đã Generate.';
        }

        if ($task->completed_at !== null
            || in_array($status, [SeoProjectTask::STATUS_COMPLETED, SeoProjectTask::STATUS_DRAFT], true)
            || in_array($state->generationState, [
                ContentProjectItemGenerationState::Completed,
                ContentProjectItemGenerationState::Writing,
                ContentProjectItemGenerationState::Processing,
            ], true)
        ) {
            return 'Task đã có kết quả Generate/Draft bài viết.';
        }

        return null;
    }
}
