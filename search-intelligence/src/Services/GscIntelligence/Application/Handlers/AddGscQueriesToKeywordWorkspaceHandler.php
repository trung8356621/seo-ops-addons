<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Services\GscIntelligence\Application\Handlers;

use Omnichannel\Addons\SearchIntelligence\Models\SeoGscQueryMapping;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\ActorContext;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\ContentProjectActionResult;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Contracts\ContentProjectCommand;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Support\ContentProjectPreviewToken;
use Omnichannel\Addons\SearchIntelligence\Services\GscIntelligence\Application\Commands\AddGscQueriesToKeywordWorkspaceCommand;
use Omnichannel\Addons\SearchIntelligence\Services\GscIntelligence\Application\GscIntelligenceActionCodes;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\Application\Support\KeywordIntelligenceTenantGuard;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\KeywordImportService;
use InvalidArgumentException;

final class AddGscQueriesToKeywordWorkspaceHandler extends AbstractGscIntelligenceHandler
{
    public function __construct(
        KeywordIntelligenceTenantGuard $tenantGuard,
        ContentProjectPreviewToken $previewToken,
        private readonly KeywordImportService $keywordImport,
    ) {
        parent::__construct($tenantGuard, $previewToken);
    }

    public function handle(ContentProjectCommand $command, ActorContext $actor): ContentProjectActionResult
    {
        if (! $command instanceof AddGscQueriesToKeywordWorkspaceCommand) {
            throw new InvalidArgumentException('Expected AddGscQueriesToKeywordWorkspaceCommand.');
        }

        return $this->wrap(function () use ($command, $actor): ContentProjectActionResult {
            $property = $this->resolveProperty($command->propertyRef);
            $this->assertCanAccessProperty($property, $actor);
            $workspace = $this->resolveWorkspace($command->workspaceRef);
            $this->tenantGuard->assertCanAccessWorkspace($workspace, $actor);

            $mappings = $command->queryRefs === []
                ? SeoGscQueryMapping::query()->where('property_id', $property->id)->whereNull('keyword_id')->limit(200)->get()->all()
                : array_map(fn (string $ref): SeoGscQueryMapping => $this->resolveQueryMapping($ref, $property), $command->queryRefs);

            $rows = [];
            foreach ($mappings as $mapping) {
                $rows[] = ['keyword' => $mapping->sample_query ?? $mapping->normalized_query];
            }

            if ($rows === []) {
                return ContentProjectActionResult::fail(GscIntelligenceActionCodes::VALIDATION_FAILED, 'No GSC queries to add.');
            }

            $result = $this->keywordImport->import(
                $workspace,
                $rows,
                $command->keepDuplicates,
                'gsc_intelligence',
                $actor->actorId,
            );

            return ContentProjectActionResult::ok(
                GscIntelligenceActionCodes::QUERIES_ADDED,
                'GSC queries added to workspace.',
                metadata: [
                    'property_ref' => $property->public_ref,
                    'workspace_ref' => $workspace->public_ref,
                    'import' => $result,
                ],
            );
        });
    }
}
