<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Services\GscIntelligence\Application\Handlers;

use Omnichannel\Addons\SearchIntelligence\Enums\Gsc\GscMappingStatus;
use Omnichannel\Addons\SearchIntelligence\Enums\Gsc\GscQueryMappingType;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\ActorContext;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\ContentProjectActionResult;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Contracts\ContentProjectCommand;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Support\ContentProjectPreviewToken;
use Omnichannel\Addons\SearchIntelligence\Services\GscIntelligence\Application\Commands\UnmapGscQueryCommand;
use Omnichannel\Addons\SearchIntelligence\Services\GscIntelligence\Application\GscIntelligenceActionCodes;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\Application\Support\KeywordIntelligenceTenantGuard;
use InvalidArgumentException;

final class UnmapGscQueryHandler extends AbstractGscIntelligenceHandler
{
    public function __construct(
        KeywordIntelligenceTenantGuard $tenantGuard,
        ContentProjectPreviewToken $previewToken,
    ) {
        parent::__construct($tenantGuard, $previewToken);
    }

    public function handle(ContentProjectCommand $command, ActorContext $actor): ContentProjectActionResult
    {
        if (! $command instanceof UnmapGscQueryCommand) {
            throw new InvalidArgumentException('Expected UnmapGscQueryCommand.');
        }

        return $this->wrap(function () use ($command, $actor): ContentProjectActionResult {
            $property = $this->resolveProperty($command->propertyRef);
            $this->assertCanAccessProperty($property, $actor);
            $mapping = $this->resolveQueryMapping($command->mappingRef, $property);

            $mapping->keyword_id = null;
            $mapping->cluster_id = null;
            $mapping->topic_id = null;
            $mapping->mapping_type = GscQueryMappingType::Unmapped;
            $mapping->status = GscMappingStatus::Stale;
            $mapping->metadata = array_merge((array) ($mapping->metadata ?? []), ['manual' => false, 'unmapped_at' => date('c')]);
            $mapping->save();

            return ContentProjectActionResult::ok(
                GscIntelligenceActionCodes::QUERY_UNMAPPED,
                'GSC query unmapped.',
                metadata: ['mapping_ref' => $mapping->public_ref],
            );
        });
    }
}
