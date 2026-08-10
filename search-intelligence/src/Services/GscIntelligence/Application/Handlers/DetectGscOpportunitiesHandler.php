<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Services\GscIntelligence\Application\Handlers;

use Omnichannel\Addons\SearchIntelligence\Enums\Gsc\GscOpportunityStatus;
use Omnichannel\Addons\SearchIntelligence\Enums\Gsc\GscOpportunityType;
use Omnichannel\Addons\SearchIntelligence\Enums\Gsc\GscScopeType;
use Omnichannel\Addons\SearchIntelligence\Models\SeoGscOpportunity;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\ActorContext;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\ContentProjectActionResult;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Contracts\ContentProjectCommand;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Support\ContentProjectPreviewToken;
use Omnichannel\Addons\SearchIntelligence\Services\GscIntelligence\Application\Commands\DetectGscOpportunitiesCommand;
use Omnichannel\Addons\SearchIntelligence\Services\GscIntelligence\Application\GscIntelligenceActionCodes;
use Omnichannel\Addons\SearchIntelligence\Services\GscIntelligence\GscDailyMetricPersistService;
use Omnichannel\Addons\SearchIntelligence\Services\GscIntelligence\GscOpportunityDetectionService;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\Application\KeywordIntelligencePublicRef;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\Application\Support\KeywordIntelligenceTenantGuard;
use InvalidArgumentException;

final class DetectGscOpportunitiesHandler extends AbstractGscIntelligenceHandler
{
    public function __construct(
        KeywordIntelligenceTenantGuard $tenantGuard,
        ContentProjectPreviewToken $previewToken,
        private readonly GscDailyMetricPersistService $persistService,
        private readonly GscOpportunityDetectionService $detector,
    ) {
        parent::__construct($tenantGuard, $previewToken);
    }

    public function handle(ContentProjectCommand $command, ActorContext $actor): ContentProjectActionResult
    {
        if (! $command instanceof DetectGscOpportunitiesCommand) {
            throw new InvalidArgumentException('Expected DetectGscOpportunitiesCommand.');
        }

        return $this->wrap(function () use ($command, $actor): ContentProjectActionResult {
            $property = $this->resolveProperty($command->propertyRef);
            $this->assertCanAccessProperty($property, $actor);
            $this->assertPropertyActive($property);

            $facts = $this->persistService->factsForProperty(
                $property->public_ref,
                (int) $property->id,
            );

            $queries = array_values(array_unique(array_map(
                static fn (array $r): string => (string) ($r['normalized_query'] ?? ''),
                $facts,
            )));

            $this->detector->resetFingerprints();
            $createdRefs = [];

            foreach ($queries as $normalizedQuery) {
                if ($normalizedQuery === '') {
                    continue;
                }

                $queryRows = array_values(array_filter(
                    $facts,
                    static fn (array $r): bool => (string) ($r['normalized_query'] ?? '') === $normalizedQuery,
                ));

                $detected = $this->detector->detect($queryRows, [], [
                    'normalized_query' => $normalizedQuery,
                ]);

                foreach ($detected as $row) {
                    if (! is_array($row)) {
                        continue;
                    }

                    $fingerprint = (string) ($row['fingerprint'] ?? hash('sha256', json_encode($row)));
                    $existing = SeoGscOpportunity::query()
                        ->where('property_id', $property->id)
                        ->where('fingerprint', $fingerprint)
                        ->first();

                    if ($existing instanceof SeoGscOpportunity) {
                        $createdRefs[] = $existing->public_ref;
                        continue;
                    }

                    $opportunity = new SeoGscOpportunity([
                        'public_ref' => 'pending',
                        'tenant_id' => $property->tenant_id,
                        'site_id' => $property->site_id,
                        'property_id' => $property->id,
                        'opportunity_type' => GscOpportunityType::tryFrom((string) ($row['type'] ?? '')) ?? GscOpportunityType::HighImpressionLowCtr,
                        'scope_type' => GscScopeType::Keyword,
                        'scope_ref' => $normalizedQuery,
                        'priority_score' => $row['priority_score'] ?? null,
                        'confidence' => $row['confidence'] ?? null,
                        'date_from' => $command->dateFrom,
                        'date_to' => $command->dateTo,
                        'evidence' => $row,
                        'reason_codes' => is_array($row['reason_codes'] ?? null) ? $row['reason_codes'] : [],
                        'recommended_action' => $row['recommended_action'] ?? null,
                        'status' => GscOpportunityStatus::Open,
                        'fingerprint' => $fingerprint,
                    ]);
                    $opportunity->save();
                    $opportunity->public_ref = KeywordIntelligencePublicRef::gscOpportunity((int) $opportunity->id);
                    $opportunity->save();
                    $createdRefs[] = $opportunity->public_ref;
                }
            }

            return ContentProjectActionResult::ok(
                GscIntelligenceActionCodes::OPPORTUNITIES_DETECTED,
                'GSC opportunities detected.',
                metadata: [
                    'property_ref' => $property->public_ref,
                    'opportunity_refs' => $createdRefs,
                    'count' => count($createdRefs),
                ],
            );
        });
    }
}
