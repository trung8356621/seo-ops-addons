<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Handlers;

use Omnichannel\Addons\ContentProjects\Models\SeoProject;
use Omnichannel\Addons\ContentProjects\Models\SeoProjectTask;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\ActorContext;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Commands\SetItemGenerationKeywordOverrideCommand;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\ContentProjectActionCodes;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\ContentProjectActionResult;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\ContentProjectPublicRef;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Contracts\ContentProjectCommand;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Support\ContentProjectBusinessLock;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Support\ContentProjectPreviewToken;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Support\ContentProjectTenantGuard;
use Omnichannel\Addons\ContentProjects\Support\ContentProject\ContentProjectGenerationKeyword;
use Omnichannel\Addons\ContentProjects\Support\ContentProject\ContentProjectItemIdentity;
use InvalidArgumentException;
use RuntimeException;

final class SetItemGenerationKeywordOverrideHandler extends AbstractPublishingHandler
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
        if (! $command instanceof SetItemGenerationKeywordOverrideCommand) {
            throw new InvalidArgumentException('Expected SetItemGenerationKeywordOverrideCommand.');
        }

        return $this->wrap(null, function () use ($command, $actor): ContentProjectActionResult {
            $itemId = ContentProjectPublicRef::resolveItemId($command->itemRef);
            $task = SeoProjectTask::query()->find($itemId);
            if (! $task instanceof SeoProjectTask) {
                return ContentProjectActionResult::fail(
                    ContentProjectActionCodes::ITEMS_NOT_FOUND,
                    'Item not found.',
                );
            }

            $project = SeoProject::query()->find((int) $task->project_id);
            if (! $project instanceof SeoProject) {
                throw new RuntimeException('Project không tồn tại.');
            }

            $projectId = (int) $project->getKey();
            $this->tenantGuard->assertCanAccessProject($project, $actor);

            if ($project->archived_at !== null) {
                return ContentProjectActionResult::fail(
                    ContentProjectActionCodes::PROJECT_ARCHIVED_BLOCK,
                    'Cannot update keyword override on archived project.',
                    $projectId,
                );
            }

            $previousOverride = ContentProjectGenerationKeyword::overrideKeyword($task);
            $originalKeyword = ContentProjectGenerationKeyword::originalKeyword($task);

            $nextOverride = $command->generationKeywordOverride === null
                ? null
                : ContentProjectGenerationKeyword::normalizeOverrideInput($task, $command->generationKeywordOverride);

            if ($nextOverride !== null) {
                $itemType = SeoProjectTask::normalizeType($task->type);
                if (in_array($itemType, [SeoProjectTask::TYPE_CREATE, SeoProjectTask::TYPE_REWRITE], true)
                    && ! ContentProjectItemIdentity::isValid($nextOverride, ContentProjectGenerationKeyword::normalize($task->title ?? null))
                ) {
                    return ContentProjectActionResult::fail(
                        ContentProjectActionCodes::VALIDATION_FAILED,
                        ContentProjectItemIdentity::failureMessage(),
                        $projectId,
                        affectedItemIds: [$itemId],
                    );
                }
            }

            $task->generation_keyword_override = $nextOverride;
            $task->save();

            return ContentProjectActionResult::ok(
                ContentProjectActionCodes::ITEMS_UPDATED,
                $nextOverride === null ? 'Keyword override reverted.' : 'Generation keyword override saved.',
                $projectId,
                [$itemId],
                metadata: [
                    'original_keyword' => $originalKeyword,
                    'previous_override' => $previousOverride !== '' ? $previousOverride : null,
                    'generation_keyword_override' => $nextOverride,
                    'effective_generation_keyword' => ContentProjectGenerationKeyword::effective($task->fresh() ?? $task),
                ],
            );
        });
    }
}
