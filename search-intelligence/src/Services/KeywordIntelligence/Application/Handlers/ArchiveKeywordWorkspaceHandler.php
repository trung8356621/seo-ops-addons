<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\Application\Handlers;

use Omnichannel\Addons\SearchIntelligence\Enums\KeywordIntelligence\KeywordWorkspaceStatus;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\ActorContext;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\ContentProjectActionResult;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Contracts\ContentProjectCommand;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Support\ContentProjectPreviewToken;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\Application\Commands\ArchiveKeywordWorkspaceCommand;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\Application\KeywordIntelligenceActionCodes;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\Application\Support\KeywordIntelligenceTenantGuard;
use InvalidArgumentException;

/**
 * Archive Keyword Workspace — KHÔNG liên quan Content Project Archive/Destroy.
 * Workspace archived vẫn giữ nguyên dữ liệu (read-only), chỉ chặn import/analyze/convert mới.
 */
final class ArchiveKeywordWorkspaceHandler extends AbstractKeywordIntelligenceHandler
{
    public function __construct(
        KeywordIntelligenceTenantGuard $tenantGuard,
        ContentProjectPreviewToken $previewToken,
    ) {
        parent::__construct($tenantGuard, $previewToken);
    }

    public function handle(ContentProjectCommand $command, ActorContext $actor): ContentProjectActionResult
    {
        if (! $command instanceof ArchiveKeywordWorkspaceCommand) {
            throw new InvalidArgumentException('Expected ArchiveKeywordWorkspaceCommand.');
        }

        return $this->wrap(function () use ($command, $actor): ContentProjectActionResult {
            $workspace = $this->resolveWorkspace($command->workspaceRef);
            $this->tenantGuard->assertCanAccessWorkspace($workspace, $actor);

            if ($workspace->status === KeywordWorkspaceStatus::Archived) {
                return ContentProjectActionResult::ok(
                    KeywordIntelligenceActionCodes::WORKSPACE_ARCHIVED_OK,
                    'Workspace đã archived từ trước.',
                    metadata: ['workspace_ref' => $workspace->public_ref],
                );
            }

            $workspace->status = KeywordWorkspaceStatus::Archived->value;
            $workspace->archived_at = now();
            $workspace->save();

            return ContentProjectActionResult::ok(
                KeywordIntelligenceActionCodes::WORKSPACE_ARCHIVED_OK,
                'Workspace archived.',
                metadata: ['workspace_ref' => $workspace->public_ref],
            );
        });
    }
}
