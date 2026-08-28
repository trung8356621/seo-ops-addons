<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Services\GscIntelligence\UrlInspection;

use Omnichannel\Addons\SearchIntelligence\Enums\Gsc\GscPropertyStatus;
use Omnichannel\Addons\SearchIntelligence\Models\SeoGscMasterConnection;
use Omnichannel\Addons\SearchIntelligence\Models\SeoGscProperty;
use Omnichannel\Addons\SearchIntelligence\Models\SeoGscPropertyMapping;
use Omnichannel\Addons\SearchIntelligence\Services\GoogleSearchConsoleConnectionService;
use Omnichannel\Addons\SearchIntelligence\Services\GoogleSearchConsoleOAuthService;
use App\Models\Site;
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
        $diagnosis = $this->diagnoseForSite($siteId);

        return match ($diagnosis['status']) {
            'ok' => [
                'site_id' => $siteId,
                'property_uri' => (string) $diagnosis['property_uri'],
                'connection' => $diagnosis['connection'],
                'property' => $diagnosis['property'],
                'mapping' => $diagnosis['mapping'],
            ],
            'oauth_missing' => throw GscUrlInspectionApiException::missingOAuth(
                (string) $diagnosis['message']
            ),
            'property_unmapped' => throw GscUrlInspectionApiException::missingBinding(
                (string) $diagnosis['message']
            ),
            default => throw GscUrlInspectionApiException::permission(
                (string) $diagnosis['message']
            ),
        };
    }

    /**
     * Distinguish master OAuth vs Site↔property mapping failures.
     *
     * @return array{
     *   status: 'ok'|'oauth_missing'|'property_unmapped'|'permission',
     *   site_id: int,
     *   domain: string,
     *   property_uri: string|null,
     *   connection: ?SeoGscMasterConnection,
     *   property: ?SeoGscProperty,
     *   mapping: ?SeoGscPropertyMapping,
     *   message: string,
     *   error_code: string,
     * }
     */
    public function diagnoseForSite(int $siteId): array
    {
        $domain = $this->resolveSiteDomain($siteId);

        if ($siteId <= 0) {
            return $this->diagnosis(
                'oauth_missing',
                $siteId,
                $domain,
                null,
                null,
                null,
                null,
                'Site is required for GSC URL Inspection.',
                'gsc.oauth_missing',
            );
        }

        $connection = $this->resolveHealthyMasterConnection($siteId);
        if (! $connection instanceof SeoGscMasterConnection) {
            return $this->diagnosis(
                'oauth_missing',
                $siteId,
                $domain,
                null,
                null,
                null,
                null,
                'Google Search Console chưa được kết nối.',
                'gsc.oauth_missing',
            );
        }

        if (! $this->connections->isConnected($connection)) {
            $effective = $this->connections->resolveEffectiveStatus($connection);

            return $this->diagnosis(
                'permission',
                $siteId,
                $domain,
                $connection,
                null,
                null,
                null,
                $effective === 'reauthorization_required'
                    ? 'GSC connection requires reauthorization. Reconnect Google Search Console.'
                    : 'Google Search Console chưa được kết nối.',
                'gsc.permission_denied',
            );
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
            return $this->diagnosis(
                'property_unmapped',
                $siteId,
                $domain,
                $connection,
                $property,
                $mapping,
                null,
                $domain !== ''
                    ? 'Tên miền '.$domain.' chưa được liên kết với GSC property.'
                    : 'Tên miền hiện tại chưa được liên kết với Google Search Console property.',
                'gsc.property_missing',
            );
        }

        // Prefer mapping-bound connection when present; otherwise healthy master already resolved.
        if ($mapping instanceof SeoGscPropertyMapping) {
            $mapped = SeoGscMasterConnection::query()->find((int) $mapping->gsc_connection_id);
            if ($mapped instanceof SeoGscMasterConnection && $this->connections->isConnected($mapped)) {
                $connection = $mapped;
            }
        }

        return $this->diagnosis(
            'ok',
            $siteId,
            $domain,
            $connection,
            $property,
            $mapping,
            $propertyUri,
            'ok',
            'gsc.ok',
        );
    }

    public function hasBinding(int $siteId): bool
    {
        return $this->diagnoseForSite($siteId)['status'] === 'ok';
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

    private function resolveHealthyMasterConnection(int $siteId): ?SeoGscMasterConnection
    {
        try {
            $viaSite = $this->connections->resolveForSite($siteId);
            if ($viaSite instanceof SeoGscMasterConnection) {
                return $viaSite;
            }
        } catch (Throwable) {
            // fall through
        }

        try {
            if (! Schema::connection('mysql')->hasTable('seo_gsc_master_connections')) {
                return null;
            }

            /** @var SeoGscMasterConnection|null $global */
            $global = SeoGscMasterConnection::query()
                ->where('is_global', true)
                ->orderByDesc('id')
                ->first();
            if ($global instanceof SeoGscMasterConnection) {
                return $global;
            }

            return SeoGscMasterConnection::query()->orderByDesc('id')->first();
        } catch (Throwable) {
            return null;
        }
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

    private function resolveSiteDomain(int $siteId): string
    {
        if ($siteId <= 0) {
            return '';
        }

        try {
            $domain = Site::query()->whereKey($siteId)->value('domain');

            return is_string($domain) ? trim($domain) : '';
        } catch (Throwable) {
            return '';
        }
    }

    /**
     * @return array{
     *   status: 'ok'|'oauth_missing'|'property_unmapped'|'permission',
     *   site_id: int,
     *   domain: string,
     *   property_uri: string|null,
     *   connection: ?SeoGscMasterConnection,
     *   property: ?SeoGscProperty,
     *   mapping: ?SeoGscPropertyMapping,
     *   message: string,
     *   error_code: string,
     * }
     */
    private function diagnosis(
        string $status,
        int $siteId,
        string $domain,
        ?SeoGscMasterConnection $connection,
        ?SeoGscProperty $property,
        ?SeoGscPropertyMapping $mapping,
        ?string $propertyUri,
        string $message,
        string $errorCode,
    ): array {
        return [
            'status' => $status,
            'site_id' => $siteId,
            'domain' => $domain,
            'property_uri' => $propertyUri,
            'connection' => $connection,
            'property' => $property,
            'mapping' => $mapping,
            'message' => $message,
            'error_code' => $errorCode,
        ];
    }
}
