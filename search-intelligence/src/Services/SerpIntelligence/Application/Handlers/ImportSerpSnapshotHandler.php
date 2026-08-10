<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Services\SerpIntelligence\Application\Handlers;

use Omnichannel\Addons\SearchIntelligence\Enums\Serp\SerpQueryStatus;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\ActorContext;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\ContentProjectActionResult;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Contracts\ContentProjectCommand;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Support\ContentProjectPreviewToken;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\Application\Support\KeywordIntelligenceTenantGuard;
use Omnichannel\Addons\SearchIntelligence\Services\SerpIntelligence\Application\Commands\ImportSerpSnapshotCommand;
use Omnichannel\Addons\SearchIntelligence\Services\SerpIntelligence\Application\SerpIntelligenceActionCodes;
use Omnichannel\Addons\SearchIntelligence\Services\SerpIntelligence\SerpImportSnapshotService;
use InvalidArgumentException;

final class ImportSerpSnapshotHandler extends AbstractSerpIntelligenceHandler
{
    public function __construct(
        KeywordIntelligenceTenantGuard $tenantGuard,
        ContentProjectPreviewToken $previewToken,
        private readonly SerpImportSnapshotService $importer,
    ) {
        parent::__construct($tenantGuard, $previewToken);
    }

    public function handle(ContentProjectCommand $command, ActorContext $actor): ContentProjectActionResult
    {
        if (! $command instanceof ImportSerpSnapshotCommand) {
            throw new InvalidArgumentException('Expected ImportSerpSnapshotCommand.');
        }

        return $this->wrap(function () use ($command, $actor): ContentProjectActionResult {
            $workspace = $this->resolveWorkspace($command->workspaceRef);
            $this->tenantGuard->assertCanAccessWorkspace($workspace, $actor);
            $this->assertNotArchived($workspace);

            $query = $this->resolveQuery($command->queryRef, $workspace);

            if ($command->preview) {
                $preview = $this->importer->preview($query, $command->payload, $command->format);

                return ContentProjectActionResult::ok(
                    SerpIntelligenceActionCodes::PREVIEW_READY,
                    'Import preview generated.',
                    metadata: [
                        'workspace_ref' => $workspace->public_ref,
                        'query_ref' => $query->public_ref,
                        'preview' => true,
                        'checksum' => $preview['checksum'],
                        'summary' => $preview['preview']->summary,
                    ],
                );
            }

            $result = $this->importer->import($query, $command->payload, $command->format);

            if ($result['already_imported']) {
                return ContentProjectActionResult::ok(
                    SerpIntelligenceActionCodes::SNAPSHOT_ALREADY_IMPORTED,
                    'Snapshot already imported.',
                    metadata: [
                        'workspace_ref' => $workspace->public_ref,
                        'query_ref' => $query->public_ref,
                        'snapshot_ref' => $result['snapshot_ref'],
                        'checksum' => $result['checksum'],
                    ],
                );
            }

            return ContentProjectActionResult::ok(
                SerpIntelligenceActionCodes::SNAPSHOT_IMPORTED,
                'Snapshot imported.',
                metadata: [
                    'workspace_ref' => $workspace->public_ref,
                    'query_ref' => $query->public_ref,
                    'snapshot_ref' => $result['snapshot_ref'],
                    'checksum' => $result['checksum'],
                ],
            );
        });
    }
}
