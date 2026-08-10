<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Services;

use Omnichannel\Addons\SearchIntelligence\Models\SeoGscMasterConnection;
use Omnichannel\Addons\SearchIntelligence\Models\SeoGscPropertyMapping;
use App\Models\Site;
use App\Models\SiteMeta;
use Illuminate\Support\Facades\Http;

final class GoogleSearchConsoleSyncService
{
    private const API_BASE = 'https://www.googleapis.com/webmasters/v3';

    private const GSC_SNAPSHOT_META = 'gsc_query_snapshot';

    private const PERIOD_DAYS = 28;

    private const DATA_LAG_DAYS = 2;

    /**
     * @return list<string>
     */
    public function listProperties(SeoGscMasterConnection $connection): array
    {
        $token = $this->resolveAccessToken($connection);
        if ($token === null) {
            throw new \RuntimeException(__('seo-content-ai::filament.api_connections.gsc_missing_credentials'));
        }

        $response = Http::withToken($token)
            ->timeout(25)
            ->get(self::API_BASE.'/sites');

        if (! $response->successful()) {
            throw new \RuntimeException($this->sanitizeMessage($response->json('error.message') ?? $response->body()));
        }

        $entries = $response->json('siteEntry') ?? [];

        return collect($entries)
            ->filter(static fn (mixed $entry): bool => is_array($entry))
            ->map(static fn (array $entry): string => (string) ($entry['siteUrl'] ?? ''))
            ->filter(static fn (string $url): bool => $url !== '')
            ->values()
            ->all();
    }

    public function syncSite(int $siteId, ?int $userId = null): bool
    {
        return $this->syncSiteWithDetails($siteId, $userId)['ok'];
    }

    /**
     * @return array{ok: bool, message: string, query_count: int}
     */
    public function syncSiteWithDetails(int $siteId, ?int $userId = null): array
    {
        $userId ??= (int) auth()->id();

        if ($siteId <= 0) {
            return [
                'ok' => false,
                'message' => __('seo-content-ai::filament.performance_hub.no_domain'),
                'query_count' => 0,
            ];
        }

        $mapping = $this->resolveMappingForSite($siteId);
        if ($mapping === null || trim((string) $mapping->property_url) === '') {
            return [
                'ok' => false,
                'message' => __('seo-content-ai::filament.api_connections.gsc_mapping_missing'),
                'query_count' => 0,
            ];
        }

        $connections = app(GoogleSearchConsoleConnectionService::class);
        $connection = $connections->resolveByIdForUser($userId, (int) $mapping->gsc_connection_id);
        if ($connection === null) {
            return [
                'ok' => false,
                'message' => __('seo-content-ai::filament.api_connections.gsc_sync_failed'),
                'query_count' => 0,
            ];
        }

        $token = $this->resolveAccessToken($connection);
        if ($token === null) {
            return [
                'ok' => false,
                'message' => __('seo-content-ai::filament.api_connections.gsc_missing_credentials'),
                'query_count' => 0,
            ];
        }

        $property = rawurlencode((string) $mapping->property_url);
        $period = $this->resolveSyncPeriod();

        $response = $this->fetchSearchAnalytics(
            token: $token,
            encodedProperty: $property,
            startDate: $period['current_start'],
            endDate: $period['current_end'],
            dimensions: ['query'],
            rowLimit: 1000,
        );

        if ($response['ok'] !== true) {
            $errorMessage = $this->sanitizeMessage((string) ($response['message'] ?? ''));
            app(GoogleSearchConsoleConnectionService::class)->markReauthorizationRequired($connection, $errorMessage);

            return [
                'ok' => false,
                'message' => __('seo-content-ai::filament.api_connections.gsc_sync_api_error', [
                    'message' => $errorMessage,
                ]),
                'query_count' => 0,
            ];
        }

        $rows = $response['rows'];
        $queries = [];
        $totalClicks = 0;
        $totalImpressions = 0;
        $positionSum = 0.0;
        $positionCount = 0;

        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }

            $query = trim((string) ($row['keys'][0] ?? ''));
            if ($query === '') {
                continue;
            }

            $clicks = (int) ($row['clicks'] ?? 0);
            $impressions = (int) ($row['impressions'] ?? 0);
            $ctr = round((float) ($row['ctr'] ?? 0) * 100, 2);
            $position = round((float) ($row['position'] ?? 0), 1);

            $queries[] = [
                'query' => $query,
                'clicks' => $clicks,
                'impressions' => $impressions,
                'ctr' => $ctr,
                'position' => $position,
            ];

            $totalClicks += $clicks;
            $totalImpressions += $impressions;
            if ($position > 0) {
                $positionSum += $position;
                $positionCount++;
            }
        }

        $currentTimeseriesResponse = $this->fetchSearchAnalytics(
            token: $token,
            encodedProperty: $property,
            startDate: $period['current_start'],
            endDate: $period['current_end'],
            dimensions: ['date'],
            rowLimit: 1000,
        );

        $previousTimeseriesResponse = $this->fetchSearchAnalytics(
            token: $token,
            encodedProperty: $property,
            startDate: $period['previous_start'],
            endDate: $period['previous_end'],
            dimensions: ['date'],
            rowLimit: 1000,
        );

        $currentTimeseries = $currentTimeseriesResponse['ok'] === true
            ? $this->normalizeDateTimeseries($currentTimeseriesResponse['rows'])
            : [];
        $previousTimeseries = $previousTimeseriesResponse['ok'] === true
            ? $this->normalizeDateTimeseries($previousTimeseriesResponse['rows'])
            : [];

        $queryCount = count($queries);

        $chartStatus = 'ok';
        if ($currentTimeseries === [] && $previousTimeseries === []) {
            $chartStatus = $currentTimeseriesResponse['ok'] === true || $previousTimeseriesResponse['ok'] === true
                ? 'empty'
                : 'failed';
        } elseif ($currentTimeseriesResponse['ok'] !== true && $previousTimeseriesResponse['ok'] !== true) {
            $chartStatus = 'failed';
        }

        $snapshot = [
            'kpis' => [
                'total_clicks' => $totalClicks,
                'total_impressions' => $totalImpressions,
                'avg_ctr' => $totalImpressions > 0 ? round(($totalClicks / $totalImpressions) * 100, 2) : 0.0,
                'avg_position' => $positionCount > 0 ? round($positionSum / $positionCount, 1) : null,
                'total_queries' => count($queries),
            ],
            'queries' => $queries,
            'timeseries' => [
                'current' => $currentTimeseries,
                'previous' => $previousTimeseries,
                'period_days' => self::PERIOD_DAYS,
                'current_start' => $period['current_start'],
                'current_end' => $period['current_end'],
                'previous_start' => $period['previous_start'],
                'previous_end' => $period['previous_end'],
            ],
            'chart_status' => $chartStatus,
            'property_url' => (string) $mapping->property_url,
            'date_start' => $period['current_start'],
            'date_end' => $period['current_end'],
            'filters' => [
                'device' => null,
                'country' => null,
            ],
            'synced_at' => now()->toIso8601String(),
            'source' => 'gsc_api',
        ];

        $site = Site::query()->find($siteId);
        if (! $site instanceof Site) {
            return [
                'ok' => false,
                'message' => __('seo-content-ai::filament.api_connections.gsc_sync_failed'),
                'query_count' => 0,
            ];
        }

        $this->persistSnapshotMeta($site, $snapshot);
        $mapping->last_synced_at = now();
        $mapping->save();
        $connection->last_synced_at = now();
        $connection->last_error = null;
        $connection->save();

        $queryCount = count($queries);
        $message = $queryCount > 0
            ? __('seo-content-ai::filament.api_connections.gsc_sync_success_body', ['count' => $queryCount])
            : __('seo-content-ai::filament.api_connections.gsc_sync_empty_success');

        return [
            'ok' => true,
            'message' => $message,
            'query_count' => $queryCount,
        ];
    }

    public function syncFromLegacySnapshot(int $siteId): bool
    {
        return $this->hasStoredSnapshot($siteId);
    }

    public function hasStoredSnapshot(int $siteId): bool
    {
        $site = Site::query()->find($siteId);
        if (! $site instanceof Site) {
            return false;
        }

        $site->loadMissing('metas');
        $raw = trim((string) ($site->getMeta(self::GSC_SNAPSHOT_META) ?? ''));
        if ($raw === '') {
            return false;
        }

        $decoded = json_decode($raw, true);
        if (! is_array($decoded)) {
            return false;
        }

        $kpis = is_array($decoded['kpis'] ?? null) ? $decoded['kpis'] : [];
        if ($kpis !== []) {
            return true;
        }

        $queries = is_array($decoded['queries'] ?? null) ? $decoded['queries'] : [];

        return $queries !== [];
    }

    /**
     * @return array{
     *     current_start: string,
     *     current_end: string,
     *     previous_start: string,
     *     previous_end: string,
     * }
     */
    public function resolveSyncPeriod(): array
    {
        $currentEnd = now()->subDays(self::DATA_LAG_DAYS)->startOfDay();
        $currentStart = $currentEnd->copy()->subDays(self::PERIOD_DAYS - 1);
        $previousEnd = $currentStart->copy()->subDay();
        $previousStart = $previousEnd->copy()->subDays(self::PERIOD_DAYS - 1);

        return [
            'current_start' => $currentStart->toDateString(),
            'current_end' => $currentEnd->toDateString(),
            'previous_start' => $previousStart->toDateString(),
            'previous_end' => $previousEnd->toDateString(),
        ];
    }

    /**
     * @return array{ok: bool, message: string|null, rows: list<array<string, mixed>>}
     */
    public function fetchSearchAnalytics(
        string $token,
        string $encodedProperty,
        string $startDate,
        string $endDate,
        array $dimensions,
        int $rowLimit = 1000,
    ): array {
        $response = Http::withToken($token)
            ->timeout(40)
            ->post(self::API_BASE."/sites/{$encodedProperty}/searchAnalytics/query", [
                'startDate' => $startDate,
                'endDate' => $endDate,
                'dimensions' => $dimensions,
                'rowLimit' => $rowLimit,
            ]);

        if (! $response->successful()) {
            return [
                'ok' => false,
                'message' => $this->sanitizeMessage($response->json('error.message') ?? $response->body()),
                'rows' => [],
            ];
        }

        $rows = $response->json('rows') ?? [];

        return [
            'ok' => true,
            'message' => null,
            'rows' => is_array($rows) ? $rows : [],
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<array{date: string, clicks: int, impressions: int, ctr: float, position: float}>
     */
    public function normalizeDateTimeseries(array $rows): array
    {
        $normalized = [];

        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }

            $date = trim((string) ($row['keys'][0] ?? ''));
            if ($date === '') {
                continue;
            }

            $normalized[] = [
                'date' => $date,
                'clicks' => (int) ($row['clicks'] ?? 0),
                'impressions' => (int) ($row['impressions'] ?? 0),
                'ctr' => round((float) ($row['ctr'] ?? 0) * 100, 2),
                'position' => round((float) ($row['position'] ?? 0), 1),
            ];
        }

        usort($normalized, static fn (array $left, array $right): int => strcmp($left['date'], $right['date']));

        return $normalized;
    }

    private function resolveMappingForSite(int $siteId): ?SeoGscPropertyMapping
    {
        return SeoGscPropertyMapping::query()
            ->where('site_id', $siteId)
            ->orderByDesc('id')
            ->first();
    }

    private function resolveAccessToken(SeoGscMasterConnection $connection): ?string
    {
        $credentials = $connection->credentials;
        if (! is_array($credentials)) {
            return null;
        }

        $oauth = app(GoogleSearchConsoleOAuthService::class);

        if ($oauth->isAccessTokenExpired($credentials)) {
            try {
                $refreshed = $oauth->refreshAccessToken($connection);
                if ($refreshed !== null) {
                    $accessToken = trim((string) ($refreshed['access_token'] ?? ''));

                    return $accessToken !== '' ? $accessToken : null;
                }
            } catch (\Throwable $exception) {
                app(GoogleSearchConsoleConnectionService::class)->markReauthorizationRequired(
                    $connection,
                    mb_substr(trim($exception->getMessage()), 0, 240),
                );

                return null;
            }
        }

        $accessToken = trim((string) ($credentials['access_token'] ?? ''));

        return $accessToken !== '' ? $accessToken : null;
    }

    private function sanitizeMessage(string $message): string
    {
        return mb_substr(trim($message), 0, 240);
    }

    /**
     * @param  array<string, mixed>  $snapshot
     */
    private function persistSnapshotMeta(Site $site, array $snapshot): void
    {
        SiteMeta::query()->updateOrCreate(
            [
                'site_id' => $site->id,
                'meta_key' => self::GSC_SNAPSHOT_META,
            ],
            [
                'meta_value' => json_encode($snapshot, JSON_UNESCAPED_UNICODE),
            ],
        );
    }
}
