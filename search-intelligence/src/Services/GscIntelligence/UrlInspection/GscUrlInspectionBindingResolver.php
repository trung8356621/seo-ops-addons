<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Services\GscIntelligence\UrlInspection;

use Omnichannel\Addons\SearchIntelligence\Enums\Gsc\GscPropertyStatus;
use Omnichannel\Addons\SearchIntelligence\Models\SeoGscMasterConnection;
use Omnichannel\Addons\SearchIntelligence\Models\SeoGscProperty;
use Omnichannel\Addons\SearchIntelligence\Models\SeoGscPropertyMapping;
use Omnichannel\Addons\SearchIntelligence\Services\GoogleSearchConsoleConnectionService;
use Omnichannel\Addons\SearchIntelligence\Services\GoogleSearchConsoleOAuthService;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Resolve exact site → GSC property + OAuth connection for URL Inspection.
 * Fail closed — never invent property from article host.
 */
class GscUrlInspectionBindingResolver
{
    public function __construct(
        private readonly GoogleSearchConsoleOAuthService $oauth = new GoogleSearchConsoleOAuthService,
        private readonly GoogleSearchConsoleConnectionService $connections = new GoogleSearchConsoleConnectionService,
    ) {}

    /**
     * @return array{
     *   site_id: int,
     *   property_uri: string,
     *   connection: SeoGscMasterConnection,
     *   property: ?SeoGscProperty,
     *   mapping: ?SeoGscPropertyMapping,
     * }
     */
    public function resolveForSite(int $siteId): array
    {
        if ($siteId <= 0) {
            throw GscUrlInspectionApiException::missingBinding('Site is required for GSC URL Inspection.');
        }

        $property = $this->resolveIntelligenceProperty($siteId);
        $mapping = $this->resolveLegacyMapping($siteId);

        $propertyUri = '';
        if ($property instanceof SeoGscProperty) {
            $propertyUri = trim((string) ($property->property_uri ?? ''));
        }
        if ($propertyUri === '' && $mapping instanceof SeoGscPropertyMapping) {
            $propertyUri = trim((string) ($mapping->property_url ?? ''));
        }

        if ($propertyUri === '') {
            throw GscUrlInspectionApiException::missingBinding(
                'GSC property is not bound for this site. Configure Google Search Console first.'
            );
        }

        $connection = $this->resolveConnection($property, $mapping);
        if (! $connection instanceof SeoGscMasterConnection) {
            throw GscUrlInspectionApiException::missingBinding(
                'GSC connection is not configured for this site.'
            );
        }

        if (! $this->connections->isConnected($connection)) {
            throw GscUrlInspectionApiException::permission(
                'GSC connection is not authorized. Reconnect Google Search Console.'
            );
        }

        return [
            'site_id' => $siteId,
            'property_uri' => $propertyUri,
            'connection' => $connection,
            'property' => $property,
            'mapping' => $mapping,
        ];
    }

    public function hasBinding(int $siteId): bool
    {
        try {
            $this->resolveForSite($siteId);

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    public function resolveAccessToken(SeoGscMasterConnection $connection): string
    {
        $credentials = $connection->credentials;
        if (! is_array($credentials)) {
            throw GscUrlInspectionApiException::permission('GSC credentials missing.');
        }

        if ($this->oauth->isAccessTokenExpired($credentials)) {
            try {
                $refreshed = $this->oauth->refreshAccessToken($connection);
                $token = trim((string) ($refreshed['access_token'] ?? ''));
                if ($token === '') {
                    throw GscUrlInspectionApiException::permission('Failed to refresh GSC access token.');
                }

                return $token;
            } catch (GscUrlInspectionApiException $e) {
                throw $e;
            } catch (Throwable $e) {
                $this->connections->markReauthorizationRequired(
                    $connection,
                    mb_substr(trim($e->getMessage()), 0, 240),
                );
                throw GscUrlInspectionApiException::permission(
                    'GSC property is not authorized for URL Inspection.',
                    401,
                );
            }
        }

        $token = trim((string) ($credentials['access_token'] ?? ''));
        if ($token === '') {
            throw GscUrlInspectionApiException::permission('GSC access token missing.');
        }

        return $token;
    }

    private function resolveIntelligenceProperty(int $siteId): ?SeoGscProperty
    {
        try {
            if (! Schema::connection('omi_seo_ai')->hasTable('seo_gsc_properties')) {
                return null;
            }

            return SeoGscProperty::query()
                ->where('site_id', $siteId)
                ->whereNull('archived_at')
                ->where('status', '!=', GscPropertyStatus::Archived->value)
                ->orderByDesc('id')
                ->first();
        } catch (Throwable) {
            return null;
        }
    }

    private function resolveLegacyMapping(int $siteId): ?SeoGscPropertyMapping
    {
        try {
            if (! Schema::connection('mysql')->hasTable('seo_gsc_property_mappings')) {
                return null;
            }

            return SeoGscPropertyMapping::query()
                ->where('site_id', $siteId)
                ->orderByDesc('id')
                ->first();
        } catch (Throwable) {
            return null;
        }
    }

    private function resolveConnection(
        ?SeoGscProperty $property,
        ?SeoGscPropertyMapping $mapping,
    ): ?SeoGscMasterConnection {
        if ($mapping instanceof SeoGscPropertyMapping) {
            $connection = SeoGscMasterConnection::query()->find((int) $mapping->gsc_connection_id);
            if ($connection instanceof SeoGscMasterConnection) {
                return $connection;
            }
        }

        $legacyId = (int) ($property?->legacy_mapping_id ?? 0);
        if ($legacyId > 0) {
            try {
                $legacy = SeoGscPropertyMapping::query()->find($legacyId);
                if ($legacy instanceof SeoGscPropertyMapping) {
                    $connection = SeoGscMasterConnection::query()->find((int) $legacy->gsc_connection_id);
                    if ($connection instanceof SeoGscMasterConnection) {
                        return $connection;
                    }
                }
            } catch (Throwable) {
                // fall through
            }
        }

        return null;
    }
}
