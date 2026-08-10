<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Services\GscIntelligence\Application\Handlers;

use Omnichannel\Addons\SearchIntelligence\Enums\Gsc\GscMappingStatus;
use Omnichannel\Addons\SearchIntelligence\Enums\Gsc\GscPageMappingType;
use Omnichannel\Addons\SearchIntelligence\Models\SeoGscPageMapping;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\ActorContext;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\ContentProjectActionResult;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Contracts\ContentProjectCommand;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Support\ContentProjectPreviewToken;
use Omnichannel\Addons\SearchIntelligence\Services\GscIntelligence\Application\Commands\MapGscPageCommand;
use Omnichannel\Addons\SearchIntelligence\Services\GscIntelligence\Application\GscIntelligenceActionCodes;
use Omnichannel\Addons\SearchIntelligence\Services\GscIntelligence\GscPageNormalizationService;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\Application\KeywordIntelligencePublicRef;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\Application\Support\KeywordIntelligenceTenantGuard;
use InvalidArgumentException;

final class MapGscPageHandler extends AbstractGscIntelligenceHandler
{
    public function __construct(
        KeywordIntelligenceTenantGuard $tenantGuard,
        ContentProjectPreviewToken $previewToken,
        private readonly GscPageNormalizationService $pageNormalizer,
    ) {
        parent::__construct($tenantGuard, $previewToken);
    }

    public function handle(ContentProjectCommand $command, ActorContext $actor): ContentProjectActionResult
    {
        if (! $command instanceof MapGscPageCommand) {
            throw new InvalidArgumentException('Expected MapGscPageCommand.');
        }

        return $this->wrap(function () use ($command, $actor): ContentProjectActionResult {
            $property = $this->resolveProperty($command->propertyRef);
            $this->assertCanAccessProperty($property, $actor);
            $this->assertPropertyActive($property);

            $pageNorm = $this->pageNormalizer->normalize($command->normalizedPage);
            if ($pageNorm['normalized_url'] === '') {
                return ContentProjectActionResult::fail(GscIntelligenceActionCodes::VALIDATION_FAILED, 'Invalid page URL.');
            }

            $identityHash = hash('sha256', $property->public_ref.'|'.$pageNorm['normalized_url']);

            $existing = SeoGscPageMapping::query()
                ->where('property_id', $property->id)
                ->where('identity_hash', $identityHash)
                ->first();

            if ($existing instanceof SeoGscPageMapping && ($existing->metadata['manual'] ?? false) === true) {
                return ContentProjectActionResult::ok(
                    GscIntelligenceActionCodes::PAGE_MAPPED,
                    'Manual page mapping preserved.',
                    metadata: ['mapping_ref' => $existing->public_ref, 'preserved_manual' => true],
                );
            }

            $mapping = $existing instanceof SeoGscPageMapping ? $existing : new SeoGscPageMapping([
                'public_ref' => 'pending',
                'tenant_id' => $property->tenant_id,
                'site_id' => $property->site_id,
                'property_id' => $property->id,
                'identity_hash' => $identityHash,
            ]);

            $mapping->page = $pageNorm['url'];
            $mapping->normalized_page = $pageNorm['normalized_url'];
            $mapping->article_ref = $command->articleRef;
            $mapping->mapping_type = GscPageMappingType::Manual;
            $mapping->confidence = 1.0;
            $mapping->source = 'manual';
            $mapping->status = GscMappingStatus::Approved;
            $mapping->metadata = array_merge((array) ($mapping->metadata ?? []), ['manual' => true]);
            $mapping->reviewed_by = $actor->actorId;
            $mapping->reviewed_at = now();
            $mapping->save();

            if ($mapping->public_ref === 'pending') {
                $mapping->public_ref = KeywordIntelligencePublicRef::gscPageMapping((int) $mapping->id);
                $mapping->save();
            }

            return ContentProjectActionResult::ok(
                GscIntelligenceActionCodes::PAGE_MAPPED,
                'GSC page mapped.',
                metadata: ['mapping_ref' => $mapping->public_ref],
            );
        });
    }
}
