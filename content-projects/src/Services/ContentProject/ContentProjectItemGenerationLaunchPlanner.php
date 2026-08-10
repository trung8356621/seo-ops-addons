<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Services\ContentProject;

use Omnichannel\Addons\ContentProjects\Models\SeoProject;
use Omnichannel\Addons\ContentProjects\Models\SeoProjectTask;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\ContentProjectActionCodes;

/**
 * Decide Generate vs Rerun after healing stale runtime + Existing Article repair.
 * Does not start runs — callers dispatch existing CommandBus commands.
 */
final class ContentProjectItemGenerationLaunchPlanner
{
    public const ACTION_GENERATE = 'generate';

    public const ACTION_RERUN = 'rerun';

    public const ACTION_BLOCKED_ACTIVE = 'blocked_active';

    public const ACTION_BLOCKED_NONE = 'blocked_none';

    public const ACTIVE_MESSAGE = 'Bài viết đang được tạo.';

    public function __construct(
        private readonly ContentProjectGenerationCapabilityResolver $capability,
    ) {}

    /**
     * @return array{
     *     action: self::ACTION_GENERATE|self::ACTION_RERUN|self::ACTION_BLOCKED_ACTIVE|self::ACTION_BLOCKED_NONE,
     *     message: string,
     *     code: string,
     *     recovered: bool,
     *     task_id: int,
     *     existing_article_id: int|null,
     * }
     */
    public function plan(SeoProject $project, SeoProjectTask $task): array
    {
        $decision = $this->capability->decide($project, $task, [
            'recover_stale' => true,
            'persist_article_repair' => true,
        ]);

        $taskId = (int) $task->getKey();

        if ($decision->action === ContentProjectGenerationRecoveryDecision::ACTION_ACTIVE) {
            return [
                'action' => self::ACTION_BLOCKED_ACTIVE,
                'message' => self::ACTIVE_MESSAGE,
                'code' => ContentProjectActionCodes::OPERATION_ALREADY_PROCESSING,
                'recovered' => $decision->staleRecovered,
                'task_id' => $taskId,
                'existing_article_id' => $decision->existingArticleId,
            ];
        }

        if ($decision->action === ContentProjectGenerationRecoveryDecision::ACTION_GENERATE) {
            return [
                'action' => self::ACTION_GENERATE,
                'message' => 'Generate pending.',
                'code' => ContentProjectActionCodes::ITEMS_GENERATE_REQUESTED,
                'recovered' => $decision->staleRecovered,
                'task_id' => $taskId,
                'existing_article_id' => $decision->existingArticleId,
            ];
        }

        if ($decision->action === ContentProjectGenerationRecoveryDecision::ACTION_RERUN) {
            return [
                'action' => self::ACTION_RERUN,
                'message' => 'Rerun generation.',
                'code' => ContentProjectActionCodes::ITEMS_GENERATE_REQUESTED,
                'recovered' => $decision->staleRecovered,
                'task_id' => $taskId,
                'existing_article_id' => $decision->existingArticleId,
            ];
        }

        // Resume / manual select are separate UI/command paths — smart create/rerun must not steal them.
        if ($decision->action === ContentProjectGenerationRecoveryDecision::ACTION_RESUME) {
            return [
                'action' => self::ACTION_BLOCKED_NONE,
                'message' => 'Dùng «Tiếp tục từ bước lỗi» — không chạy lại toàn bộ.',
                'code' => ContentProjectActionCodes::VALIDATION_FAILED,
                'recovered' => $decision->staleRecovered,
                'task_id' => $taskId,
                'existing_article_id' => $decision->existingArticleId,
            ];
        }

        if ($decision->action === ContentProjectGenerationRecoveryDecision::ACTION_SELECT_EXISTING_ARTICLE) {
            return [
                'action' => self::ACTION_BLOCKED_NONE,
                'message' => 'Chọn Existing Article trước khi tạo lại.',
                'code' => ContentProjectActionCodes::VALIDATION_FAILED,
                'recovered' => $decision->staleRecovered,
                'task_id' => $taskId,
                'existing_article_id' => $decision->existingArticleId,
            ];
        }

        return [
            'action' => self::ACTION_BLOCKED_NONE,
            'message' => $decision->reason !== '' ? $decision->reason : 'Generation action not executable.',
            'code' => ContentProjectActionCodes::VALIDATION_FAILED,
            'recovered' => $decision->staleRecovered,
            'task_id' => $taskId,
            'existing_article_id' => $decision->existingArticleId,
        ];
    }
}
