<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Services\GscIntelligence\Application\Handlers;

use Omnichannel\Addons\SearchIntelligence\Enums\Gsc\GscPeriodType;
use Omnichannel\Addons\SearchIntelligence\Enums\Gsc\GscScopeType;
use Omnichannel\Addons\SearchIntelligence\Models\SeoGscPerformanceAggregate;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\ActorContext;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\ContentProjectActionResult;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Contracts\ContentProjectCommand;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Support\ContentProjectPreviewToken;
use Omnichannel\Addons\SearchIntelligence\Services\GscIntelligence\Application\Commands\RebuildGscAggregatesCommand;
use Omnichannel\Addons\SearchIntelligence\Services\GscIntelligence\Application\GscIntelligenceActionCodes;
use Omnichannel\Addons\SearchIntelligence\Services\GscIntelligence\GscDailyMetricPersistService;
use Omnichannel\Addons\SearchIntelligence\Services\GscIntelligence\GscPerformanceAggregationService;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\Application\KeywordIntelligencePublicRef;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\Application\Support\KeywordIntelligenceTenantGuard;
use InvalidArgumentException;

final class RebuildGscAggregatesHandler extends AbstractGscIntelligenceHandler
{
    public function __construct(
        KeywordIntelligenceTenantGuard $tenantGuard,
        ContentProjectPreviewToken $previewToken,
        private readonly GscDailyMetricPersistService $persistService,
        private readonly GscPerformanceAggregationService $aggregation,
    ) {
        parent::__construct($tenantGuard, $previewToken);
    }

    public function handle(ContentProjectCommand $command, ActorContext $actor): ContentProjectActionResult
    {
        if (! $command instanceof RebuildGscAggregatesCommand) {
            throw new InvalidArgumentException('Expected RebuildGscAggregatesCommand.');
        }

        return $this->wrap(function () use ($command, $actor): ContentProjectActionResult {
            $property = $this->resolveProperty($command->propertyRef);
            $this->assertCanAccessProperty($property, $actor);
            $this->assertPropertyActive($property);

            $facts = $this->persistService->factsForProperty(
                $property->public_ref,
                (int) $property->id,
            );

            $aggregated = $this->aggregation->aggregate($facts);
            $dataHash = hash('sha256', $property->public_ref.'|'.json_encode($aggregated));

            $aggregate = SeoGscPerformanceAggregate::query()
                ->where('property_id', $property->id)
                ->where('data_hash', $dataHash)
                ->first();

            if (! $aggregate instanceof SeoGscPerformanceAggregate) {
                $aggregate = new SeoGscPerformanceAggregate([
                    'public_ref' => 'pending',
                    'tenant_id' => $property->tenant_id,
                    'site_id' => $property->site_id,
                    'property_id' => $property->id,
                ]);
            }

            $aggregate->scope_type = GscScopeType::Site;
            $aggregate->scope_ref = $property->public_ref;
            $aggregate->period_type = GscPeriodType::Custom;
            $aggregate->date_from = $command->dateFrom;
            $aggregate->date_to = $command->dateTo;
            $aggregate->clicks = (int) ($aggregated['clicks'] ?? 0);
            $aggregate->impressions = (int) ($aggregated['impressions'] ?? 0);
            $aggregate->ctr = $aggregated['ctr'];
            $aggregate->position = $aggregated['position'];
            $aggregate->query_count = count(array_unique(array_map(
                static fn (array $r): string => (string) ($r['normalized_query'] ?? ''),
                $facts,
            )));
            $aggregate->page_count = count(array_unique(array_map(
                static fn (array $r): string => (string) ($r['normalized_page'] ?? ''),
                $facts,
            )));
            $aggregate->summary = $aggregated;
            $aggregate->calculated_at = now();
            $aggregate->algorithm_version = GscPerformanceAggregationService::ALGORITHM_VERSION;
            $aggregate->data_hash = $dataHash;
            $aggregate->save();

            if ($aggregate->public_ref === 'pending') {
                $aggregate->public_ref = KeywordIntelligencePublicRef::gscPerformanceAggregate((int) $aggregate->id);
                $aggregate->save();
            }

            return ContentProjectActionResult::ok(
                GscIntelligenceActionCodes::AGGREGATES_REBUILT,
                'GSC aggregates rebuilt.',
                metadata: [
                    'property_ref' => $property->public_ref,
                    'aggregate_ref' => $aggregate->public_ref,
                    'summary' => $aggregated,
                ],
            );
        });
    }
}
