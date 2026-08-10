<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Services\GscIntelligence\Application\Handlers;

use Omnichannel\Addons\SearchIntelligence\Models\SeoGscQueryMapping;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\ActorContext;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\ContentProjectActionResult;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Contracts\ContentProjectCommand;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Support\ContentProjectPreviewToken;
use Omnichannel\Addons\SearchIntelligence\Services\GscIntelligence\Application\Commands\PreviewAddGscQueriesToKeywordWorkspaceCommand;
use Omnichannel\Addons\SearchIntelligence\Services\GscIntelligence\Application\GscIntelligenceActionCodes;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\Application\KeywordIntelligencePublicRef;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\Application\Support\KeywordIntelligenceTenantGuard;
use InvalidArgumentException;

final class PreviewAddGscQueriesToKeywordWorkspaceHandler extends AbstractGscIntelligenceHandler
{
    public function __construct(
        KeywordIntelligenceTenantGuard $tenantGuard,
        ContentProjectPreviewToken $previewToken,
    ) {
        parent::__construct($tenantGuard, $previewToken);
    }

    public function handle(ContentProjectCommand $command, ActorContext $actor): ContentProjectActionResult
    {
        if (! $command instanceof PreviewAddGscQueriesToKeywordWorkspaceCommand) {
            throw new InvalidArgumentException('Expected PreviewAddGscQueriesToKeywordWorkspaceCommand.');
        }

        return $this->wrap(function () use ($command, $actor): ContentProjectActionResult {
            $property = $this->resolveProperty($command->propertyRef);
            $this->assertCanAccessProperty($property, $actor);
            $workspace = $this->resolveWorkspace($command->workspaceRef);
            $this->tenantGuard->assertCanAccessWorkspace($workspace, $actor);

            $mappings = $this->resolveMappings($property, $command->queryRefs);
            $candidates = [];
            foreach ($mappings as $mapping) {
                $candidates[] = [
                    'mapping_ref' => $mapping->public_ref,
                    'query' => $mapping->sample_query,
                    'normalized_query' => $mapping->normalized_query,
                    'keyword_ref' => $mapping->keyword_id !== null
                        ? KeywordIntelligencePublicRef::keyword((int) $mapping->keyword_id)
                        : null,
                ];
            }

            return ContentProjectActionResult::ok(
                GscIntelligenceActionCodes::QUERIES_PREVIEW,
                'GSC query import preview ready.',
                metadata: [
                    'property_ref' => $property->public_ref,
                    'workspace_ref' => $workspace->public_ref,
                    'candidates' => $candidates,
                    'count' => count($candidates),
                ],
            );
        });
    }

    /**
     * @param  list<string>  $queryRefs
     * @return list<SeoGscQueryMapping>
     */
    private function resolveMappings(\Omnichannel\Addons\SearchIntelligence\Models\SeoGscProperty $property, array $queryRefs): array
    {
        if ($queryRefs === []) {
            return SeoGscQueryMapping::query()
                ->where('property_id', $property->id)
                ->whereNull('keyword_id')
                ->limit(200)
                ->get()
                ->all();
        }

        $mappings = [];
        foreach ($queryRefs as $ref) {
            $mappings[] = $this->resolveQueryMapping((string) $ref, $property);
        }

        return $mappings;
    }
}
