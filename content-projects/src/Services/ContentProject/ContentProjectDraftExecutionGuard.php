<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Services\ContentProject;

use Omnichannel\Addons\ContentProjects\Models\SeoProject;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\ContentProjectActionCodes;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\ContentProjectActionResult;

/**
 * Fail-closed: Draft planning projects cannot execute generation/publishing/scheduling.
 */
final class ContentProjectDraftExecutionGuard
{
    public static function blocks(SeoProject $project): bool
    {
        return $project->isDraftPlanning();
    }

    public static function rejectIfDraft(SeoProject $project, int $projectId = 0): ?ContentProjectActionResult
    {
        if (! self::blocks($project)) {
            return null;
        }

        $id = $projectId > 0 ? $projectId : (int) $project->getKey();

        return ContentProjectActionResult::fail(
            ContentProjectActionCodes::PROJECT_DRAFT_NOT_EXECUTABLE,
            'Draft project is planning-only — generation and publishing are disabled.',
            $id > 0 ? $id : null,
        );
    }
}
