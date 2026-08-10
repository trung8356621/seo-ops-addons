<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Handlers;

use Omnichannel\Addons\Content\Enums\ArticleReviewStatus;
use Omnichannel\Addons\ContentProjects\Enums\ContentProjectItemAction;
use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\ContentProjects\Models\SeoProject;
use Omnichannel\Addons\ContentProjects\Models\SeoProjectTask;
use Omnichannel\Addons\Content\Services\ArticleReviewService;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\ActorContext;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Commands\ApproveProjectItemsCommand;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\ContentProjectActionCodes;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\ContentProjectActionResult;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Contracts\ContentProjectCommand;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Events\ContentProjectDomainEvents;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Events\ContentProjectItemsApproved;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Quotas\ContentProjectQuotaGuard;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Support\ContentProjectBusinessLock;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Support\ContentProjectPreviewToken;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Support\ContentProjectTenantGuard;
use Omnichannel\Addons\Content\Exceptions\ArticleReviewException;
use Omnichannel\Addons\ContentProjects\Support\ContentProject\ContentProjectItemActionGuard;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

final class ApproveProjectItemsHandler extends AbstractPublishingHandler
{
    public function __construct(
        ContentProjectTenantGuard $tenantGuard,
        ContentProjectBusinessLock $businessLock,
        ContentProjectPreviewToken $previewToken,
        private readonly ContentProjectQuotaGuard $quota,
        private readonly ContentProjectDomainEvents $domainEvents,
        private readonly ArticleReviewService $articleReview,
        private readonly ContentProjectItemActionGuard $actionGuard = new ContentProjectItemActionGuard,
    ) {
        parent::__construct($tenantGuard, $businessLock, $previewToken);
    }

    public function handle(ContentProjectCommand $command, ActorContext $actor): ContentProjectActionResult
    {
        if (! $command instanceof ApproveProjectItemsCommand) {
            throw new InvalidArgumentException('Expected ApproveProjectItemsCommand.');
        }

        return $this->wrap(null, function () use ($command, $actor): ContentProjectActionResult {
            $project = $this->resolveProject($command->projectRef);
            $projectId = (int) $project->getKey();
            $this->tenantGuard->assertCanAccessProject($project, $actor);

            if ($project->archived_at !== null) {
                return ContentProjectActionResult::fail(
                    ContentProjectActionCodes::PROJECT_ARCHIVED_BLOCK,
                    'Project archived.',
                    $projectId,
                );
            }

            $user = $this->resolveActorUser($actor);
            if (! $user instanceof User) {
                return ContentProjectActionResult::fail(
                    ContentProjectActionCodes::VALIDATION_FAILED,
                    'Approve requires an authenticated actor.',
                    $projectId,
                );
            }

            $requestedIds = $this->resolveItemIds($command->itemRefs);
            if ($requestedIds !== []) {
                $this->tenantGuard->assertTasksBelongToProject($project, $requestedIds);
            }

            $query = SeoProjectTask::query()
                ->where('project_id', $projectId)
                ->active();

            if ($requestedIds !== []) {
                $query->whereIn('id', $requestedIds);
            }

            $tasks = $query->get(['id', 'article_id']);
            if ($tasks->isEmpty()) {
                return ContentProjectActionResult::fail(
                    ContentProjectActionCodes::ITEMS_NOT_FOUND,
                    'No items eligible for approve.',
                    $projectId,
                );
            }

            if (! $this->quota->canPublishItems($actor, $project, $tasks->count())) {
                // approve trước publish — dùng publish quota hook làm placeholder
            }

            /** @var list<array{task: SeoProjectTask, article: SeoArticle}> $eligible */
            $eligible = [];
            $rejected = [];

            foreach ($tasks as $task) {
                $taskId = (int) $task->id;
                $articleId = (int) ($task->article_id ?? 0);
                if ($articleId <= 0) {
                    $rejected[] = [
                        'task_id' => $taskId,
                        'reason' => 'Item has no attached article — cannot approve.',
                    ];

                    continue;
                }

                $article = SeoArticle::query()->find($articleId);
                if (! $article instanceof SeoArticle) {
                    $rejected[] = [
                        'task_id' => $taskId,
                        'reason' => 'Attached article #'.$articleId.' not found.',
                    ];

                    continue;
                }

                $status = $this->articleReview->resolveStatus($article);
                if ($status === ArticleReviewStatus::Archived) {
                    $rejected[] = [
                        'task_id' => $taskId,
                        'reason' => 'Article is archived (hoàn tất duyệt); reopen before approve.',
                    ];

                    continue;
                }

                try {
                    $this->actionGuard->assertCan(ContentProjectItemAction::Approve, $task, $article);
                } catch (RuntimeException $e) {
                    $rejected[] = [
                        'task_id' => $taskId,
                        'reason' => $e->getMessage(),
                    ];

                    continue;
                }

                $eligible[] = ['task' => $task, 'article' => $article];
            }

            if ($rejected !== []) {
                return ContentProjectActionResult::fail(
                    ContentProjectActionCodes::VALIDATION_FAILED,
                    'Batch approve rejected — fix all items before retry.',
                    $projectId,
                    metadata: ['rejected' => $rejected],
                );
            }

            $affectedIds = [];

            try {
                DB::connection('omi_seo_ai')->transaction(function () use (
                    $eligible,
                    $project,
                    $user,
                    &$affectedIds,
                ): void {
                    foreach ($eligible as $row) {
                        $this->articleReview->ensureApproved(
                            $row['article'],
                            $user,
                            note: null,
                            source: 'content_project.approve',
                        );
                        $affectedIds[] = (int) $row['task']->id;
                    }

                    if ($affectedIds !== [] && $project->status !== SeoProject::STATUS_APPROVED) {
                        $project->update(['status' => SeoProject::STATUS_APPROVED]);
                    }
                });
            } catch (ArticleReviewException $exception) {
                return ContentProjectActionResult::fail(
                    ContentProjectActionCodes::VALIDATION_FAILED,
                    $exception->getMessage(),
                    $projectId,
                );
            }

            $this->domainEvents->dispatchAfterCommit(new ContentProjectItemsApproved($projectId, $affectedIds));

            return ContentProjectActionResult::ok(
                ContentProjectActionCodes::ITEMS_APPROVED,
                count($affectedIds).' item(s) approved.',
                $projectId,
                $affectedIds,
                metadata: [
                    'affected_count' => count($affectedIds),
                    'rejected' => [],
                    'canonical_status' => ArticleReviewStatus::Approved->value,
                ],
            );
        });
    }

    private function resolveActorUser(ActorContext $actor): ?User
    {
        $userId = $actor->actorId;
        if ($userId === null || $userId <= 0) {
            return null;
        }

        $user = User::query()->find($userId);

        return $user instanceof User ? $user : null;
    }
}
