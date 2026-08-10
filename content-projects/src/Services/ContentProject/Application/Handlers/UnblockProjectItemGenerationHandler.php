<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Handlers;

use Omnichannel\Addons\ContentProjects\Models\SeoProjectTask;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\ActorContext;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Commands\UnblockProjectItemGenerationCommand;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\ContentProjectActionCodes;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\ContentProjectActionResult;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Contracts\ContentProjectCommand;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Support\ContentProjectBusinessLock;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Support\ContentProjectPreviewToken;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Support\ContentProjectTenantGuard;
use Omnichannel\Addons\Seo\Support\SeoAccessControl;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;

/**
 * Clear generation block so item may be selected by Generate / Retry again.
 */
final class UnblockProjectItemGenerationHandler extends AbstractPublishingHandler
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
        if (! $command instanceof UnblockProjectItemGenerationCommand) {
            throw new InvalidArgumentException('Expected UnblockProjectItemGenerationCommand.');
        }

        return $this->wrap(null, function () use ($command, $actor): ContentProjectActionResult {
            $project = $this->resolveProject($command->projectRef);
            $projectId = (int) $project->getKey();
            $this->tenantGuard->assertCanAccessProject($project, $actor);

            if (! SeoAccessControl::canAccessContentProjectRun($project)) {
                return $this->tenantGuard->failForbidden($projectId);
            }

            $itemIds = $this->resolveItemIds($command->itemRefs);
            if ($itemIds === []) {
                return ContentProjectActionResult::fail(
                    ContentProjectActionCodes::VALIDATION_FAILED,
                    'Item list is empty.',
                    $projectId,
                );
            }

            $this->tenantGuard->assertTasksBelongToProject($project, $itemIds);

            if (! Schema::connection('omi_seo_ai')->hasColumn('seo_project_tasks', 'generation_blocked_at')) {
                return ContentProjectActionResult::fail(
                    ContentProjectActionCodes::FAILED,
                    'generation_blocked columns unavailable — run migrations.',
                    $projectId,
                );
            }

            $updated = 0;
            foreach ($itemIds as $taskId) {
                $task = SeoProjectTask::query()
                    ->where('project_id', $projectId)
                    ->whereKey((int) $taskId)
                    ->first();
                if (! $task instanceof SeoProjectTask) {
                    continue;
                }

                $task->forceFill([
                    'generation_blocked_at' => null,
                    'generation_blocked_by' => null,
                    'generation_block_reason' => null,
                ])->save();
                $updated++;
            }

            if ($updated === 0) {
                return ContentProjectActionResult::fail(
                    ContentProjectActionCodes::ITEMS_NOT_FOUND,
                    'No items updated.',
                    $projectId,
                );
            }

            return ContentProjectActionResult::ok(
                ContentProjectActionCodes::ITEMS_UPDATED,
                'Item đã được cho phép tạo lại.',
                $projectId,
                $itemIds,
                metadata: ['unblocked_count' => $updated],
            );
        });
    }
}
