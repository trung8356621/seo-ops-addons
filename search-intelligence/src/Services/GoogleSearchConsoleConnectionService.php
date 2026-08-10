<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Services;

use Omnichannel\Addons\AiPrompt\Filament\Resources\AiConnectionResource;
use Omnichannel\Addons\SearchIntelligence\Models\SeoGscMasterConnection;
use Omnichannel\Addons\SearchIntelligence\Models\SeoGscPropertyMapping;
use Omnichannel\Addons\SearchIntelligence\Services\GoogleSearchConsoleOAuthService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

final class GoogleSearchConsoleConnectionService
{
    private ?bool $masterTableExists = null;

    private ?bool $mappingTableExists = null;

    public function resolveForUser(int $userId): ?SeoGscMasterConnection
    {
        return $this->allForUser($userId)->first();
    }

    public function resolveForSite(?int $siteId, ?int $userId = null): ?SeoGscMasterConnection
    {
        if (! $this->hasMasterTable()) {
            return null;
        }

        $userId ??= (int) auth()->id();

        if ($siteId !== null && $siteId > 0 && $this->hasMappingTable()) {
            $mapping = SeoGscPropertyMapping::query()
                ->where('site_id', $siteId)
                ->orderByDesc('id')
                ->first();

            if ($mapping instanceof SeoGscPropertyMapping) {
                $connection = $this->resolveByIdForUser($userId, (int) $mapping->gsc_connection_id);
                if ($connection !== null) {
                    return $connection;
                }
            }
        }

        return $this->resolveForUser($userId);
    }

    /**
     * @return Collection<int, SeoGscMasterConnection>
     */
    public function allForUser(int $userId): Collection
    {
        if (! $this->hasMasterTable()) {
            return new Collection();
        }

        return SeoGscMasterConnection::query()
            ->where(function ($query) use ($userId): void {
                $query->where('user_id', $userId)
                    ->orWhere('is_global', true);
            })
            ->orderByDesc('is_global')
            ->orderBy('name')
            ->orderBy('id')
            ->get();
    }

    public function resolveByIdForUser(int $userId, int $connectionId): ?SeoGscMasterConnection
    {
        if ($connectionId <= 0) {
            return null;
        }

        if (! $this->hasMasterTable()) {
            return null;
        }

        return SeoGscMasterConnection::query()
            ->where('id', $connectionId)
            ->where(function ($query) use ($userId): void {
                $query->where('user_id', $userId)
                    ->orWhere('is_global', true);
            })
            ->first();
    }

    public function createForUser(int $userId, array $payload): SeoGscMasterConnection
    {
        return $this->saveMasterConnection($userId, $payload, null);
    }

    /**
     * @return array{status: string, label: string, property_url: string|null, last_checked_at: string|null, last_synced_at: string|null, has_snapshot: bool, configured: bool}
     */
    public function statusForSite(?int $siteId, ?SeoGscMasterConnection $connection = null): array
    {
        if (! $this->hasMasterTable()) {
            return $this->notConfiguredStatus();
        }

        $mapping = null;
        if ($siteId !== null && $siteId > 0 && $this->hasMappingTable()) {
            $mapping = SeoGscPropertyMapping::query()
                ->where('site_id', $siteId)
                ->orderByDesc('id')
                ->first();
        }

        if ($connection === null && $mapping instanceof SeoGscPropertyMapping) {
            $connection = $this->resolveByIdForUser((int) auth()->id(), (int) $mapping->gsc_connection_id);
        }

        $connection ??= $this->resolveForSite($siteId);

        if ($connection === null) {
            return $this->notConfiguredStatus();
        }

        if ($mapping === null && $siteId !== null && $siteId > 0 && $this->hasMappingTable()) {
            $mapping = SeoGscPropertyMapping::query()
                ->where('gsc_connection_id', $connection->id)
                ->where('site_id', $siteId)
                ->first();
        }

        $effectiveStatus = $this->resolveEffectiveStatus($connection);
        $hasSnapshot = $siteId !== null
            && $siteId > 0
            && app(GoogleSearchConsoleSyncService::class)->hasStoredSnapshot($siteId);

        $uiStatus = $effectiveStatus;
        if (in_array($effectiveStatus, ['connected', 'token_expired'], true)) {
            if ($mapping === null || trim((string) ($mapping->property_url ?? '')) === '') {
                $uiStatus = 'mapping_required';
            } elseif (! $hasSnapshot) {
                $uiStatus = 'sync_required';
            }
        }

        return [
            'status' => $uiStatus,
            'connection_status' => $effectiveStatus,
            'label' => $this->statusLabel($uiStatus),
            'property_url' => $mapping?->property_url,
            'last_checked_at' => $connection->last_checked_at?->toDateTimeString(),
            'last_synced_at' => $mapping?->last_synced_at?->toDateTimeString(),
            'has_snapshot' => $hasSnapshot,
            'configured' => $this->hasSavedConfig($connection),
            'connection_id' => $connection->id,
            'gsc_edit_url' => AiConnectionResource::gscEditUrl($connection->id),
        ];
    }

    public function disconnectById(int $userId, int $connectionId): void
    {
        $connection = $this->resolveByIdForUser($userId, $connectionId);
        if ($connection === null) {
            return;
        }

        app(GoogleSearchConsoleOAuthService::class)->disconnect($connection);
    }

    public function deleteById(int $userId, int $connectionId): bool
    {
        $connection = $this->resolveByIdForUser($userId, $connectionId);
        if ($connection === null || $connection->is_global) {
            return false;
        }

        SeoGscPropertyMapping::query()
            ->where('gsc_connection_id', $connection->id)
            ->delete();

        $connection->delete();

        return true;
    }

    public function hasSavedConfig(?SeoGscMasterConnection $connection): bool
    {
        if ($connection === null) {
            return false;
        }

        return $this->hasOAuthAppCredentials($connection) || $this->hasUsableTokens($connection);
    }

    public function hasOAuthAppCredentials(?SeoGscMasterConnection $connection): bool
    {
        if ($connection === null) {
            return false;
        }

        return filled($connection->oauth_client_id) && filled($connection->oauth_client_secret);
    }

    public function hasApiTokens(?SeoGscMasterConnection $connection): bool
    {
        if ($connection === null) {
            return false;
        }

        $credentials = $connection->credentials;
        if (! is_array($credentials)) {
            return false;
        }

        $accessToken = trim((string) ($credentials['access_token'] ?? ''));
        $refreshToken = trim((string) ($credentials['refresh_token'] ?? ''));

        return $accessToken !== '' || $refreshToken !== '';
    }

    public function canCallGscApi(?SeoGscMasterConnection $connection): bool
    {
        return $this->hasOAuthAppCredentials($connection) && $this->hasApiTokens($connection);
    }

    public function hasUsableTokens(?SeoGscMasterConnection $connection): bool
    {
        if ($connection === null) {
            return false;
        }

        $credentials = $connection->credentials;
        if (! is_array($credentials)) {
            return false;
        }

        $accessToken = trim((string) ($credentials['access_token'] ?? ''));
        $refreshToken = trim((string) ($credentials['refresh_token'] ?? ''));

        return $accessToken !== '' && $refreshToken !== '';
    }

    public function hasCredentials(?SeoGscMasterConnection $connection): bool
    {
        return $this->hasUsableTokens($connection);
    }

    public function isConnected(?SeoGscMasterConnection $connection): bool
    {
        if ($connection === null) {
            return false;
        }

        return $this->resolveEffectiveStatus($connection) === 'connected';
    }

    public function resolveEffectiveStatus(SeoGscMasterConnection $connection): string
    {
        if (! $this->hasOAuthAppCredentials($connection)) {
            return 'not_configured';
        }

        if (! $this->hasUsableTokens($connection)) {
            return 'not_configured';
        }

        $credentials = $connection->credentials;
        if (! is_array($credentials)) {
            return 'not_configured';
        }

        if ((string) ($connection->status ?? '') === 'reauthorization_required') {
            return 'reauthorization_required';
        }

        $refreshToken = trim((string) ($credentials['refresh_token'] ?? ''));
        if ($refreshToken === '') {
            return 'reauthorization_required';
        }

        $oauth = app(GoogleSearchConsoleOAuthService::class);
        if ($oauth->isAccessTokenExpired($credentials)) {
            return 'token_expired';
        }

        return 'connected';
    }

    public function markReauthorizationRequired(SeoGscMasterConnection $connection, ?string $message = null): void
    {
        $connection->status = 'reauthorization_required';
        $connection->last_error = $message;
        $connection->last_checked_at = now();
        $connection->save();
    }

    /**
     * @return list<string>
     */
    public function availableProperties(?SeoGscMasterConnection $connection): array
    {
        if ($connection === null) {
            return [];
        }

        $properties = $connection->metadata['properties'] ?? [];

        return is_array($properties)
            ? array_values(array_filter(array_map(
                static fn (mixed $property): string => is_string($property) ? trim($property) : '',
                $properties,
            ), static fn (string $property): bool => $property !== ''))
            : [];
    }

    /**
     * @return array<string, string>
     */
    public function propertyOptionsForForm(?SeoGscMasterConnection $connection): array
    {
        $options = [];
        foreach ($this->availableProperties($connection) as $property) {
            $options[$property] = $property;
        }

        return $options;
    }

    public function syncPropertiesMetadata(SeoGscMasterConnection $connection): array
    {
        $properties = app(GoogleSearchConsoleSyncService::class)->listProperties($connection);
        $connection->metadata = array_merge($connection->metadata ?? [], [
            'properties' => $properties,
        ]);
        $connection->last_synced_at = now();
        $connection->save();

        return $properties;
    }

    public function tokenExpiresAt(?SeoGscMasterConnection $connection): ?string
    {
        if ($connection === null) {
            return null;
        }

        $credentials = $connection->credentials;
        if (! is_array($credentials)) {
            return null;
        }

        $expiresAt = trim((string) ($credentials['expires_at'] ?? ''));

        return $expiresAt !== '' ? $expiresAt : null;
    }

    public function saveMasterConnection(int $userId, array $payload, ?int $connectionId = null): SeoGscMasterConnection
    {
        $connection = $connectionId !== null
            ? $this->resolveByIdForUser($userId, $connectionId)
            : $this->resolveForUser($userId);

        if ($connection === null) {
            $connection = new SeoGscMasterConnection([
                'user_id' => $userId,
                'is_global' => false,
                'status' => 'not_configured',
            ]);
        }

        $connection->name = trim((string) ($payload['name'] ?? $connection->name ?: 'Google Search Console'));

        $clientId = trim((string) ($payload['oauth_client_id'] ?? ''));
        if ($clientId !== '') {
            $connection->oauth_client_id = $clientId;
        }

        if (array_key_exists('oauth_client_secret', $payload)) {
            $clientSecret = trim((string) ($payload['oauth_client_secret'] ?? ''));
            if ($clientSecret !== '') {
                $connection->oauth_client_secret = $clientSecret;
            }
        }

        if (! $this->hasOAuthAppCredentials($connection)) {
            $connection->status = 'not_configured';
        } elseif (! $this->hasUsableTokens($connection)) {
            $connection->status = 'not_configured';
        } else {
            $connection->status = $this->resolveEffectiveStatus($connection);
        }

        $connection->save();

        return $connection;
    }

    public function mapSiteProperty(SeoGscMasterConnection $connection, int $siteId, string $propertyUrl): SeoGscPropertyMapping
    {
        $propertyUrl = trim($propertyUrl);
        if ($propertyUrl === '') {
            throw new \InvalidArgumentException(__('seo-content-ai::filament.api_connections.gsc_property_invalid'));
        }

        if (! in_array($propertyUrl, $this->availableProperties($connection), true)) {
            throw new \InvalidArgumentException(__('seo-content-ai::filament.api_connections.gsc_property_invalid'));
        }

        return SeoGscPropertyMapping::query()->updateOrCreate(
            [
                'gsc_connection_id' => $connection->id,
                'site_id' => $siteId,
            ],
            [
                'property_url' => $propertyUrl,
                'metadata' => [
                    'match_source' => 'manual',
                    'match_status' => 'manual',
                    'mapped_at' => now()->toIso8601String(),
                ],
            ],
        );
    }

    /**
     * @return array{ok: bool, message: string, properties: list<string>}
     */
    public function testConnection(SeoGscMasterConnection $connection): array
    {
        $connection = $connection->fresh() ?? $connection;

        if (! $this->hasOAuthAppCredentials($connection)) {
            return [
                'ok' => false,
                'message' => __('seo-content-ai::filament.api_connections.gsc_oauth_app_not_configured'),
                'properties' => [],
            ];
        }

        if (! $this->hasApiTokens($connection)) {
            $connection->status = 'not_configured';
            $connection->last_error = __('seo-content-ai::filament.api_connections.gsc_missing_credentials');
            $connection->last_checked_at = now();
            $connection->save();

            return [
                'ok' => false,
                'message' => $connection->last_error,
                'properties' => [],
            ];
        }

        try {
            $properties = $this->syncPropertiesMetadata($connection);
            $fresh = $connection->fresh() ?? $connection;
            $fresh->status = $this->resolveEffectiveStatus($fresh);
            $fresh->last_error = null;
            $fresh->last_checked_at = now();
            $fresh->save();

            return [
                'ok' => true,
                'message' => __('seo-content-ai::filament.api_connections.test_success'),
                'properties' => $properties,
            ];
        } catch (\Throwable $exception) {
            $this->markReauthorizationRequired($connection, $this->sanitizeError($exception->getMessage()));

            return [
                'ok' => false,
                'message' => (string) ($connection->fresh()?->last_error ?? $this->sanitizeError($exception->getMessage())),
                'properties' => [],
            ];
        }
    }

    /**
     * @return list<array{site_id: int, site_name: string, property_url: string|null}>
     */
    public function mappingRowsForUser(int $userId): array
    {
        if (! $this->hasMasterTable() || ! $this->hasMappingTable()) {
            return [];
        }

        $connection = $this->resolveForUser($userId);
        if ($connection === null) {
            return [];
        }

        return SeoGscPropertyMapping::query()
            ->where('gsc_connection_id', $connection->id)
            ->with('site')
            ->get()
            ->map(static fn (SeoGscPropertyMapping $mapping): array => [
                'site_id' => (int) $mapping->site_id,
                'site_name' => (string) ($mapping->site?->name ?? ('Site #'.$mapping->site_id)),
                'property_url' => $mapping->property_url,
            ])
            ->values()
            ->all();
    }

    private function statusLabel(string $status): string
    {
        return match ($status) {
            'connected' => __('seo-content-ai::filament.api_connections.connected'),
            'mapping_required' => __('seo-content-ai::filament.api_connections.mapping_required'),
            'sync_required' => __('seo-content-ai::filament.api_connections.sync_required'),
            'token_expired' => __('seo-content-ai::filament.api_connections.token_expired'),
            'reauthorization_required' => __('seo-content-ai::filament.api_connections.reauthorization_required'),
            default => __('seo-content-ai::filament.api_connections.not_configured'),
        };
    }

    private function sanitizeError(string $message): string
    {
        $message = Str::limit(trim($message), 240, '');

        return Str::replaceMatches('/(password|api[_ -]?key|secret|token|refresh_token|access_token)\s*[:=]\s*\S+/i', '$1=[redacted]', $message);
    }

    /**
     * @return array{status: string, label: string, property_url: string|null, last_checked_at: string|null, last_synced_at: string|null, has_snapshot: bool, configured: bool}
     */
    private function notConfiguredStatus(): array
    {
        return [
            'status' => 'not_configured',
            'label' => __('seo-content-ai::filament.api_connections.not_configured'),
            'property_url' => null,
            'last_checked_at' => null,
            'last_synced_at' => null,
            'has_snapshot' => false,
            'configured' => false,
        ];
    }

    private function hasMasterTable(): bool
    {
        return $this->masterTableExists ??= $this->hasMysqlTable('seo_gsc_master_connections');
    }

    private function hasMappingTable(): bool
    {
        return $this->mappingTableExists ??= $this->hasMysqlTable('seo_gsc_property_mappings');
    }

    private function hasMysqlTable(string $table): bool
    {
        try {
            return Schema::connection('mysql')->hasTable($table);
        } catch (\Throwable) {
            return false;
        }
    }
}
