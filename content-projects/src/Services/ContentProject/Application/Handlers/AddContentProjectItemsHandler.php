<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Handlers;

use Omnichannel\Addons\ContentProjects\Models\SeoProjectTask;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\ActorContext;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Commands\AddContentProjectItemsCommand;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\ContentProjectActionCodes;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\ContentProjectActionResult;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Contracts\ContentProjectCommand;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Support\ContentProjectBusinessLock;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Support\ContentProjectPreviewToken;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Support\ContentProjectTenantGuard;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\LocalArticleAssociationGuard;
use Omnichannel\Addons\ContentProjects\Support\ContentProject\ContentProjectItemIdentity;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class AddContentProjectItemsHandler extends AbstractPublishingHandler
{
    public function __construct(
        ContentProjectTenantGuard $tenantGuard,
        ContentProjectBusinessLock $businessLock,
        ContentProjectPreviewToken $previewToken,
    ) {
        parent::__construct($tenantGuard, $businessLock, $previewToken);
    }

    public function handle(ContentProjectCommand $command, ActorContext $actor): ContentProjectActionResult
    {
        if (! $command instanceof AddContentProjectItemsCommand) {
            throw new InvalidArgumentException('Expected AddContentProjectItemsCommand.');
        }

        return $this->wrap(null, function () use ($command, $actor): ContentProjectActionResult {
            $project = $this->resolveProject($command->projectRef);
            $projectId = (int) $project->getKey();
            $this->tenantGuard->assertCanAccessProject($project, $actor);

            if ($project->archived_at !== null) {
                return ContentProjectActionResult::fail(
                    ContentProjectActionCodes::PROJECT_ARCHIVED_BLOCK,
                    'Cannot add items to archived project.',
                    $projectId,
                );
            }

            if ($command->items === []) {
                return ContentProjectActionResult::fail(
                    ContentProjectActionCodes::VALIDATION_FAILED,
                    'Item list is empty.',
                    $projectId,
                );
            }

            foreach ($command->items as $row) {
                if (! is_array($row)) {
                    continue;
                }
                $type = SeoProjectTask::normalizeType($row['type'] ?? SeoProjectTask::TYPE_CREATE);
                if (! in_array($type, [SeoProjectTask::TYPE_CREATE, SeoProjectTask::TYPE_REWRITE], true)) {
                    continue;
                }
                $keyword = ContentProjectItemIdentity::normalize(
                    isset($row['keyword']) ? (string) $row['keyword'] : null,
                );
                $title = ContentProjectItemIdentity::normalize(
                    isset($row['title']) ? (string) $row['title'] : (isset($row['post_title']) ? (string) $row['post_title'] : null),
                );
                if (! ContentProjectItemIdentity::isValid($keyword, $title)) {
                    return ContentProjectActionResult::fail(
                        ContentProjectActionCodes::VALIDATION_FAILED,
                        ContentProjectItemIdentity::failureMessage(),
                        $projectId,
                    );
                }
            }

            $createdIds = DB::connection('omi_seo_ai')->transaction(function () use ($project, $command): array {
                $ids = [];
                foreach ($command->items as $row) {
                    if (! is_array($row)) {
                        continue;
                    }

                    $type = SeoProjectTask::normalizeType($row['type'] ?? SeoProjectTask::TYPE_CREATE);
                    $keyword = ContentProjectItemIdentity::normalize(
                        isset($row['keyword']) ? (string) $row['keyword'] : null,
                    );
                    $title = ContentProjectItemIdentity::normalize(
                        isset($row['title']) ? (string) $row['title'] : (isset($row['post_title']) ? (string) $row['post_title'] : null),
                    );

                    $projectSiteId = (int) ($project->site_id ?? 0);
                    $rawArticleId = isset($row['article_id']) ? (int) $row['article_id'] : 0;
                    $localArticleId = $rawArticleId > 0
                        ? LocalArticleAssociationGuard::resolveLocalArticleId(
                            $rawArticleId,
                            $projectSiteId > 0 ? $projectSiteId : null,
                        )
                        : null;

                    $task = SeoProjectTask::query()->create([
                        'project_id' => (int) $project->getKey(),
                        'site_id' => $projectSiteId,
                        'type' => $type,
                        'post_type' => (string) ($row['post_type'] ?? SeoProjectTask::POST_TYPE_ARTICLE),
                        'keyword' => $keyword !== '' ? $keyword : null,
                        'title' => $title !== '' ? $title : null,
                        'status' => SeoProjectTask::STATUS_PENDING,
                        'article_id' => $localArticleId,
                        'target_date' => $row['target_date'] ?? now()->toDateString(),
                    ]);
                    $ids[] = (int) $task->getKey();
                }

                $project->update([
                    'total_tasks' => (int) $project->tasks()->count(),
                ]);

                return $ids;
            });

            return ContentProjectActionResult::ok(
                ContentProjectActionCodes::ITEMS_ADDED,
                count($createdIds).' item(s) added.',
                $projectId,
                $createdIds,
                metadata: ['affected_count' => count($createdIds)],
            );
        });
    }
}
