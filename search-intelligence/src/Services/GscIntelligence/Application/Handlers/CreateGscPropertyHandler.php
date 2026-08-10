<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Services\GscIntelligence\Application\Handlers;

use Omnichannel\Addons\SearchIntelligence\Enums\Gsc\GscPropertyStatus;
use Omnichannel\Addons\SearchIntelligence\Enums\Gsc\GscPropertyType;
use Omnichannel\Addons\SearchIntelligence\Enums\Gsc\GscSearchType;
use Omnichannel\Addons\SearchIntelligence\Models\SeoGscProperty;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\ActorContext;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\ContentProjectActionResult;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Contracts\ContentProjectCommand;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Support\ContentProjectPreviewToken;
use Omnichannel\Addons\SearchIntelligence\Services\GscIntelligence\Application\Commands\CreateGscPropertyCommand;
use Omnichannel\Addons\SearchIntelligence\Services\GscIntelligence\Application\GscIntelligenceActionCodes;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\Application\KeywordIntelligencePublicRef;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\Application\Support\KeywordIntelligenceTenantGuard;
use Omnichannel\Addons\Seo\Support\SeoAccessControl;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class CreateGscPropertyHandler extends AbstractGscIntelligenceHandler
{
    public function __construct(
        KeywordIntelligenceTenantGuard $tenantGuard,
        ContentProjectPreviewToken $previewToken,
    ) {
        parent::__construct($tenantGuard, $previewToken);
    }

    public function handle(ContentProjectCommand $command, ActorContext $actor): ContentProjectActionResult
    {
        if (! $command instanceof CreateGscPropertyCommand) {
            throw new InvalidArgumentException('Expected CreateGscPropertyCommand.');
        }

        return $this->wrap(function () use ($command, $actor): ContentProjectActionResult {
            $siteId = $command->siteId > 0 ? $command->siteId : (int) ($actor->siteId ?? 0);
            if ($siteId <= 0) {
                return ContentProjectActionResult::fail(GscIntelligenceActionCodes::VALIDATION_FAILED, 'site_id is required.');
            }

            if ($actor->siteId !== null && $actor->siteId > 0 && $actor->siteId !== $siteId) {
                return ContentProjectActionResult::fail(GscIntelligenceActionCodes::FORBIDDEN, 'Cannot create property for another site.');
            }

            if (in_array($actor->actorType, ['user', 'api', 'agent'], true) && ! SeoAccessControl::canAccessSite($siteId)) {
                return ContentProjectActionResult::fail(GscIntelligenceActionCodes::FORBIDDEN, 'No access to site.');
            }

            $attrs = $command->attributes;
            $propertyUri = trim((string) ($attrs['property_uri'] ?? ''));
            if ($propertyUri === '') {
                return ContentProjectActionResult::fail(GscIntelligenceActionCodes::VALIDATION_FAILED, 'property_uri is required.');
            }

            $providerKey = trim((string) ($attrs['provider_key'] ?? 'manual_import'));
            $identityHash = hash('sha256', $siteId.'|'.$propertyUri.'|'.$providerKey);

            $property = DB::connection('omi_seo_ai')->transaction(function () use ($attrs, $siteId, $propertyUri, $providerKey, $identityHash, $actor): SeoGscProperty {
                $property = new SeoGscProperty([
                    'public_ref' => 'pending',
                    'tenant_id' => $attrs['tenant_id'] ?? null,
                    'site_id' => $siteId,
                    'provider_key' => $providerKey,
                    'property_uri' => $propertyUri,
                    'identity_hash' => $identityHash,
                    'property_type' => GscPropertyType::tryFrom((string) ($attrs['property_type'] ?? 'manual')) ?? GscPropertyType::Manual,
                    'display_name' => (string) ($attrs['display_name'] ?? $propertyUri),
                    'status' => GscPropertyStatus::Active,
                    'sync_enabled' => (bool) ($attrs['sync_enabled'] ?? true),
                    'default_country' => $attrs['default_country'] ?? null,
                    'default_search_type' => GscSearchType::tryFrom((string) ($attrs['default_search_type'] ?? 'web')) ?? GscSearchType::Web,
                    'timezone' => $attrs['timezone'] ?? null,
                    'settings' => is_array($attrs['settings'] ?? null) ? $attrs['settings'] : [],
                    'metadata' => is_array($attrs['metadata'] ?? null) ? $attrs['metadata'] : [],
                    'created_by' => $actor->actorId,
                ]);
                $property->save();
                $property->public_ref = KeywordIntelligencePublicRef::gscProperty((int) $property->id);
                $property->save();

                return $property;
            });

            return ContentProjectActionResult::ok(
                GscIntelligenceActionCodes::PROPERTY_CREATED,
                'GSC property created.',
                metadata: ['property_ref' => $property->public_ref, 'site_id' => $siteId],
            );
        });
    }
}
