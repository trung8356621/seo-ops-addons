<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Services\GscIntelligence\Application\Handlers;

use Omnichannel\Addons\SearchIntelligence\Models\SeoGscOpportunity;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\ActorContext;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\ContentProjectActionResult;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Contracts\ContentProjectCommand;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Support\ContentProjectPreviewToken;
use Omnichannel\Addons\SearchIntelligence\Services\GscIntelligence\Application\Commands\PreviewCreateContentProjectFromGscOpportunitiesCommand;
use Omnichannel\Addons\SearchIntelligence\Services\GscIntelligence\Application\GscIntelligenceActionCodes;
use Omnichannel\Addons\SearchIntelligence\Services\GscIntelligence\GscOpportunityContentProjectConverter;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\Application\Support\KeywordIntelligenceTenantGuard;
use InvalidArgumentException;

final class PreviewCreateContentProjectFromGscOpportunitiesHandler extends AbstractGscIntelligenceHandler
{
    public function __construct(
        KeywordIntelligenceTenantGuard $tenantGuard,
        ContentProjectPreviewToken $previewToken,
        private readonly GscOpportunityContentProjectConverter $converter,
    ) {
        parent::__construct($tenantGuard, $previewToken);
    }

    public function handle(ContentProjectCommand $command, ActorContext $actor): ContentProjectActionResult
    {
        if (! $command instanceof PreviewCreateContentProjectFromGscOpportunitiesCommand) {
            throw new InvalidArgumentException('Expected PreviewCreateContentProjectFromGscOpportunitiesCommand.');
        }

        return $this->wrap(function () use ($command, $actor): ContentProjectActionResult {
            $property = $this->resolveProperty($command->propertyRef);
            $this->assertCanAccessProperty($property, $actor);
            $opportunities = $this->loadOpportunities($property, $command->opportunityRefs);
            $preview = $this->converter->preview($property, $opportunities, $command->projectAttributes);

            return ContentProjectActionResult::ok(
                GscIntelligenceActionCodes::CONVERSION_PREVIEW,
                'GSC content project preview ready.',
                metadata: ['preview' => $preview],
            );
        });
    }

    /**
     * @param  list<string>  $refs
     * @return list<SeoGscOpportunity>
     */
    private function loadOpportunities(\Omnichannel\Addons\SearchIntelligence\Models\SeoGscProperty $property, array $refs): array
    {
        if ($refs === []) {
            return SeoGscOpportunity::query()->where('property_id', $property->id)->limit(100)->get()->all();
        }

        return array_map(fn (string $ref): SeoGscOpportunity => $this->resolveOpportunity($ref, $property), $refs);
    }
}
