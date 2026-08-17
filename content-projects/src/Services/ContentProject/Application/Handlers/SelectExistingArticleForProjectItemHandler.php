<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Handlers;

use Omnichannel\Addons\Agent\Automation\Migration\ProjectTaskCallerBridge;
use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\ContentProjects\Models\SeoProjectTask;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\ActorContext;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Commands\SelectExistingArticleForProjectItemCommand;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\ContentProjectActionCodes;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\ContentProjectActionResult;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Contracts\ContentProjectCommand;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Support\ContentProjectBusinessLock;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Support\ContentProjectPreviewToken;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Support\ContentProjectTenantGuard;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\ContentProjectExistingArticleReconciler;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\ContentProjectGenerationCapabilityResolver;
use Omnichannel\Addons\Seo\Support\SeoAccessControl;
use InvalidArgumentException;

/**
 * Validate + attach Existing Article via ProjectTaskCallerBridge (AttachArticleToProjectTaskAction).
 * Never starts generation.
 */
final class SelectExistingArticleForProjectItemHandler extends AbstractPublishingHandler
{
    public function __construct(
        ContentProjectTenantGuard $tenantGuard,
        ContentProjectBusinessLock $businessLock,
        ContentProjectPreviewToken $previewToken,
        private readonly ContentProjectExistingArticleReconciler $articleReconciler,
        private readonly ProjectTaskCallerBridge $taskCallerBridge,
        private readonly ContentProjectGenerationCapabilityResolver $capabilityResolver,
    ) {
        parent::__construct($tenantGuard, $businessLock, $previewToken);
    }

    public function handle(ContentProjectCommand $command, ActorContext $actor): ContentProjectActionResult
    {
        if (! $command instanceof SelectExistingArticleForProjectItemCommand) {
            throw new InvalidArgumentException('Expected SelectExistingArticleForProjectItemCommand.');
        }

        return $this->wrap(null, function () use ($command, $actor): ContentProjectActionResult {
            $project = $this->resolveProject($command->projectRef);
            $projectId = (int) $project->getKey();
            $this->tenantGuard->assertCanAccessProject($project, $actor);

            if (! SeoAccessControl::canManageContentProjectWorkflow()) {
                return ContentProjectActionResult::fail(
                    ContentProjectActionCodes::FORBIDDEN,
                    'Forbidden.',
                    $projectId,
                );
            }

            $itemIds = $this->resolveItemIds([$command->itemRef]);
            if ($itemIds === []) {
                return ContentProjectActionResult::fail(
                    ContentProjectActionCodes::VALIDATION_FAILED,
                    'Item list is empty.',
                    $projectId,
                );
            }
            $taskId = $itemIds[0];
            $this->tenantGuard->assertTasksBelongToProject($project, [$taskId]);

            $articleId = (int) $command->articleId;
            if ($articleId <= 0) {
                return ContentProjectActionResult::fail(
                    ContentProjectActionCodes::VALIDATION_FAILED,
                    'Article id is required.',
                    $projectId,
                    affectedItemIds: [$taskId],
                );
            }

            return $this->businessLock->withLock(
                $this->businessLock->projectGenerate($projectId),
                function () use ($project, $projectId, $taskId, $articleId, $actor): ContentProjectActionResult {
                    $task = SeoProjectTask::query()->find($taskId);
                    if (! $task instanceof SeoProjectTask) {
                        return ContentProjectActionResult::fail(
                            ContentProjectActionCodes::ITEMS_NOT_FOUND,
                            'Item not found.',
                            $projectId,
                        );
                    }

                    $type = SeoProjectTask::normalizeType($task->type);
                    $allowsManualAttach = SeoProjectTask::isNewArticleType($type)
                        || in_array($type, SeoProjectTask::typesRequiringExistingArticle(), true);
                    if (! $allowsManualAttach) {
                        return ContentProjectActionResult::fail(
                            ContentProjectActionCodes::VALIDATION_FAILED,
                            'Item type does not support linking an existing article.',
                            $projectId,
                            affectedItemIds: [$taskId],
                        );
                    }

                    $capability = $this->capabilityResolver->decide($project, $task, [
                        'recover_stale' => false,
                        'persist_article_repair' => false,
                    ]);
                    if ($capability->isActive()) {
                        return ContentProjectActionResult::fail(
                            ContentProjectActionCodes::OPERATION_ALREADY_PROCESSING,
                            'Bài viết đang được tạo.',
                            $projectId,
                            affectedItemIds: [$taskId],
                        );
                    }

                    $siteId = (int) ($project->site_id ?? 0);
                    if ($siteId <= 0 || ! $this->articleReconciler->articleBelongsToSite($articleId, $siteId)) {
                        return ContentProjectActionResult::fail(
                            ContentProjectActionCodes::VALIDATION_FAILED,
                            'Article must belong to the same site as this project.',
                            $projectId,
                            affectedItemIds: [$taskId],
                            metadata: ['reason' => 'article_wrong_site'],
                        );
                    }

                    $article = SeoArticle::query()->find($articleId);
                    if (! $article instanceof SeoArticle) {
                        return ContentProjectActionResult::fail(
                            ContentProjectActionCodes::VALIDATION_FAILED,
                            "Article [{$articleId}] not found.",
                            $projectId,
                            affectedItemIds: [$taskId],
                            metadata: ['reason' => 'article_not_found'],
                        );
                    }

                    $ownership = $this->articleReconciler->ownershipConflictMessage($taskId, $articleId);
                    if ($ownership !== null) {
                        return ContentProjectActionResult::fail(
                            ContentProjectActionCodes::VALIDATION_FAILED,
                            $ownership,
                            $projectId,
                            affectedItemIds: [$taskId],
                            metadata: ['reason' => 'article_owned_by_active_task'],
                        );
                    }

                    // Canonical attach path only — no second direct DB writer.
                    $this->taskCallerBridge->attachArticle(
                        $task,
                        $articleId,
                        $actor->actorId,
                        $siteId > 0 ? $siteId : null,
                    );
                    $task->refresh();

                    if ((int) ($task->article_id ?? 0) !== $articleId) {
                        return ContentProjectActionResult::fail(
                            ContentProjectActionCodes::FAILED,
                            'Failed to attach Existing Article.',
                            $projectId,
                            affectedItemIds: [$taskId],
                        );
                    }

                    $after = $this->capabilityResolver->decide($project, $task, [
                        'recover_stale' => false,
                        'persist_article_repair' => false,
                    ]);

                    return ContentProjectActionResult::ok(
                        ContentProjectActionCodes::ITEMS_EXISTING_ARTICLE_SELECTED,
                        'Đã liên kết Existing Article #'.$articleId.'.',
                        $projectId,
                        [$taskId],
                        metadata: [
                            'article_id' => $articleId,
                            'generation_recovery_action' => $after->action,
                            'generation_recovery_reason' => $after->reason,
                            'resumable_from_step' => $after->resumableFromStep,
                            // Explicit: attach must not auto-start AI.
                            'generation_started' => false,
                            'article_content_unchanged' => true,
                        ],
                    );
                },
            );
        });
    }
}
