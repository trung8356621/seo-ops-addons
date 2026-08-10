<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\Application\Handlers;

use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\ActorContext;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\ContentProjectActionResult;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Contracts\ContentProjectCommand;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Support\ContentProjectPreviewToken;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\Application\Commands\ImportKeywordsCommand;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\Application\KeywordIntelligenceActionCodes;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\Application\Quotas\KeywordIntelligenceQuotaGuard;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\Application\Support\KeywordIntelligenceTenantGuard;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\KeywordImportService;
use InvalidArgumentException;

final class ImportKeywordsHandler extends AbstractKeywordIntelligenceHandler
{
    public function __construct(
        KeywordIntelligenceTenantGuard $tenantGuard,
        ContentProjectPreviewToken $previewToken,
        private readonly KeywordImportService $importer,
        private readonly KeywordIntelligenceQuotaGuard $quota,
    ) {
        parent::__construct($tenantGuard, $previewToken);
    }

    public function handle(ContentProjectCommand $command, ActorContext $actor): ContentProjectActionResult
    {
        if (! $command instanceof ImportKeywordsCommand) {
            throw new InvalidArgumentException('Expected ImportKeywordsCommand.');
        }

        return $this->wrap(function () use ($command, $actor): ContentProjectActionResult {
            $workspace = $this->resolveWorkspace($command->workspaceRef);
            $this->tenantGuard->assertCanAccessWorkspace($workspace, $actor);
            $this->assertNotArchived($workspace);

            if (! $this->quota->canImport($workspace, count($command->keywords))) {
                return ContentProjectActionResult::fail(
                    KeywordIntelligenceActionCodes::IMPORT_TOO_LARGE,
                    'Import exceeds keyword quota for this workspace.',
                );
            }

            if ($command->preview) {
                $preview = $this->importer->preview($workspace, $command->keywords);

                return ContentProjectActionResult::ok(
                    KeywordIntelligenceActionCodes::IMPORTED,
                    'Import preview generated.',
                    metadata: array_merge(
                        ['workspace_ref' => $workspace->public_ref, 'preview' => true],
                        $preview,
                    ),
                );
            }

            $result = $this->importer->import(
                $workspace,
                $command->keywords,
                $command->keepDuplicates,
                $command->source,
                $actor->actorId,
            );

            return ContentProjectActionResult::ok(
                KeywordIntelligenceActionCodes::IMPORTED,
                'Keywords imported.',
                metadata: array_merge(['workspace_ref' => $workspace->public_ref], $result),
            );
        });
    }
}
