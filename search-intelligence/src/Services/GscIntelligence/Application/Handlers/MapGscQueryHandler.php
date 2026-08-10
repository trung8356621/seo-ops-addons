<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Services\GscIntelligence\Application\Handlers;

use Omnichannel\Addons\SearchIntelligence\Enums\Gsc\GscMappingStatus;
use Omnichannel\Addons\SearchIntelligence\Enums\Gsc\GscQueryMappingType;
use Omnichannel\Addons\SearchIntelligence\Models\SeoGscQueryMapping;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\ActorContext;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\ContentProjectActionResult;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Contracts\ContentProjectCommand;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Support\ContentProjectPreviewToken;
use Omnichannel\Addons\SearchIntelligence\Services\GscIntelligence\Application\Commands\MapGscQueryCommand;
use Omnichannel\Addons\SearchIntelligence\Services\GscIntelligence\Application\GscIntelligenceActionCodes;
use Omnichannel\Addons\SearchIntelligence\Services\GscIntelligence\GscQueryNormalizationService;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\Application\KeywordIntelligencePublicRef;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\Application\Support\KeywordIntelligenceTenantGuard;
use InvalidArgumentException;

final class MapGscQueryHandler extends AbstractGscIntelligenceHandler
{
    public function __construct(
        KeywordIntelligenceTenantGuard $tenantGuard,
        ContentProjectPreviewToken $previewToken,
        private readonly GscQueryNormalizationService $queryNormalizer,
    ) {
        parent::__construct($tenantGuard, $previewToken);
    }

    public function handle(ContentProjectCommand $command, ActorContext $actor): ContentProjectActionResult
    {
        if (! $command instanceof MapGscQueryCommand) {
            throw new InvalidArgumentException('Expected MapGscQueryCommand.');
        }

        return $this->wrap(function () use ($command, $actor): ContentProjectActionResult {
            $property = $this->resolveProperty($command->propertyRef);
            $this->assertCanAccessProperty($property, $actor);
            $this->assertPropertyActive($property);

            $analysis = $this->queryNormalizer->analyze($command->normalizedQuery);
            if (! $analysis->isValid) {
                return ContentProjectActionResult::fail(GscIntelligenceActionCodes::VALIDATION_FAILED, 'Invalid query.');
            }

            $identityHash = hash('sha256', $property->public_ref.'|'.$analysis->normalized);

            $existing = SeoGscQueryMapping::query()
                ->where('property_id', $property->id)
                ->where('identity_hash', $identityHash)
                ->first();

            if ($existing instanceof SeoGscQueryMapping && ($existing->metadata['manual'] ?? false) === true) {
                return ContentProjectActionResult::ok(
                    GscIntelligenceActionCodes::QUERY_MAPPED,
                    'Manual query mapping preserved.',
                    metadata: ['mapping_ref' => $existing->public_ref, 'preserved_manual' => true],
                );
            }

            $mapping = $existing instanceof SeoGscQueryMapping ? $existing : new SeoGscQueryMapping([
                'public_ref' => 'pending',
                'tenant_id' => $property->tenant_id,
                'site_id' => $property->site_id,
                'property_id' => $property->id,
                'identity_hash' => $identityHash,
            ]);

            $mapping->normalized_query = $analysis->normalized;
            $mapping->sample_query = $analysis->displayValue;
            $mapping->keyword_id = $command->keywordRef !== null
                ? KeywordIntelligencePublicRef::resolveKeywordIdStrict($command->keywordRef)
                : null;
            $mapping->cluster_id = $command->clusterRef !== null
                ? KeywordIntelligencePublicRef::resolveClusterIdStrict($command->clusterRef)
                : null;
            $mapping->topic_id = $command->topicRef !== null
                ? KeywordIntelligencePublicRef::resolveTopicIdStrict($command->topicRef)
                : null;
            $mapping->mapping_type = GscQueryMappingType::Manual;
            $mapping->confidence = 1.0;
            $mapping->source = 'manual';
            $mapping->status = GscMappingStatus::Approved;
            $mapping->metadata = array_merge((array) ($mapping->metadata ?? []), ['manual' => true]);
            $mapping->reviewed_by = $actor->actorId;
            $mapping->reviewed_at = now();
            $mapping->save();

            if ($mapping->public_ref === 'pending') {
                $mapping->public_ref = KeywordIntelligencePublicRef::gscQueryMapping((int) $mapping->id);
                $mapping->save();
            }

            return ContentProjectActionResult::ok(
                GscIntelligenceActionCodes::QUERY_MAPPED,
                'GSC query mapped.',
                metadata: ['mapping_ref' => $mapping->public_ref, 'property_ref' => $property->public_ref],
            );
        });
    }
}
