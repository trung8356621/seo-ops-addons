<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Handlers;

use Omnichannel\Addons\ContentProjects\Enums\ContentProjectPublishQueueStatus;
use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\ContentProjects\Models\SeoProject;
use Omnichannel\Addons\ContentProjects\Models\SeoProjectTask;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\ActorContext;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Commands\BulkResyncPublishedArticlesToWordPressCommand;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Commands\SyncPublishedArticleToWordPressCommand;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\ContentProjectActionCodes;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\ContentProjectActionResult;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\ContentProjectPublicRef;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Contracts\ContentProjectCommand;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Contracts\ContentProjectCommandHandler;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Support\ContentProjectTenantGuard;
use InvalidArgumentException;
use RuntimeException;

final class BulkResyncPublishedArticlesToWordPressHandler implements ContentProjectCommandHandler
{
    public function __construct(
        private readonly ContentProjectTenantGuard $tenantGuard,
        private readonly SyncPublishedArticleToWordPressHandler $syncHandler,
    ) {}

    public function handle(ContentProjectCommand $command, ActorContext $actor): ContentProjectActionResult
    {
        if (! $command instanceof BulkResyncPublishedArticlesToWordPressCommand) {
            throw new InvalidArgumentException('Expected BulkResyncPublishedArticlesToWordPressCommand.');
        }

        $projectId = ContentProjectPublicRef::resolveProjectId($command->projectRef);
        $project = SeoProject::query()->find($projectId);
        if (! $project instanceof SeoProject) {
            return ContentProjectActionResult::fail(
                ContentProjectActionCodes::PROJECT_NOT_FOUND,
                'Project không tồn tại.',
            );
        }

        try {
            $this->tenantGuard->assertCanAccessProject($project, $actor);
        } catch (RuntimeException $e) {
            return ContentProjectActionResult::fail(
                ContentProjectActionCodes::FORBIDDEN,
                $e->getMessage(),
                projectId: $projectId,
            );
        }

        $itemIds = array_values(array_unique(array_filter(array_map(
            static fn (int|string $ref): int => ContentProjectPublicRef::resolveItemId($ref),
            $command->itemRefs,
        ), static fn (int $id): bool => $id > 0)));

        if ($itemIds === []) {
            return ContentProjectActionResult::fail(
                ContentProjectActionCodes::VALIDATION_FAILED,
                'Item list is empty.',
                projectId: $projectId,
            );
        }

        try {
            $this->tenantGuard->assertTasksBelongToProject($project, $itemIds);
        } catch (RuntimeException $e) {
            return ContentProjectActionResult::fail(
                ContentProjectActionCodes::VALIDATION_FAILED,
                $e->getMessage(),
                projectId: $projectId,
            );
        }

        $updated = 0;
        $skipped = 0;
        $failed = 0;
        $details = [];

        $tasks = SeoProjectTask::query()
            ->where('project_id', $projectId)
            ->whereIn('id', $itemIds)
            ->get();

        foreach ($tasks as $task) {
            if (! $task instanceof SeoProjectTask) {
                continue;
            }

            $queue = (string) ($task->publish_queue_status ?? '');
            $articleId = (int) ($task->article_id ?? 0);
            if ($queue !== ContentProjectPublishQueueStatus::Published->value
                || $task->publish_published_at === null
                || $articleId <= 0
            ) {
                $skipped++;
                $details[] = [
                    'item_id' => (int) $task->id,
                    'status' => 'skipped',
                    'reason' => 'not_published_lifecycle',
                ];
                continue;
            }

            if (! SeoArticle::query()->whereKey($articleId)->exists()) {
                $failed++;
                $details[] = [
                    'item_id' => (int) $task->id,
                    'status' => 'failed',
                    'reason' => 'article_missing',
                ];
                continue;
            }

            $result = $this->syncHandler->handle(
                new SyncPublishedArticleToWordPressCommand(
                    articleId: $articleId,
                    projectRef: $projectId,
                    itemRef: (int) $task->id,
                    initiatedFrom: $command->initiatedFrom,
                ),
                $actor,
            );

            if ($result->success) {
                $updated++;
                $details[] = [
                    'item_id' => (int) $task->id,
                    'article_id' => $articleId,
                    'status' => 'updated',
                    'wp_post_id' => $result->metadata['wp_post_id'] ?? null,
                ];
                continue;
            }

            if (in_array($result->code, [
                ContentProjectActionCodes::PUBLISHED_ARTICLE_WP_SYNC_BLOCKED,
                ContentProjectActionCodes::LOCK_BUSY,
                ContentProjectActionCodes::PUBLISHING_ALREADY_PROCESSING,
            ], true)) {
                $skipped++;
                $details[] = [
                    'item_id' => (int) $task->id,
                    'status' => 'skipped',
                    'reason' => $result->code,
                    'message' => $result->message,
                ];
                continue;
            }

            $failed++;
            $details[] = [
                'item_id' => (int) $task->id,
                'status' => 'failed',
                'reason' => $result->code,
                'message' => $result->message,
            ];
        }

        return ContentProjectActionResult::ok(
            ContentProjectActionCodes::PUBLISHED_ARTICLES_BULK_RESYNCED,
            "updated={$updated}, skipped={$skipped}, failed={$failed}",
            projectId: $projectId,
            affectedItemIds: $itemIds,
            metadata: [
                'updated' => $updated,
                'skipped' => $skipped,
                'failed' => $failed,
                'details' => $details,
            ],
        );
    }
}
