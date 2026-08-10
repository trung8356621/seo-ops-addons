<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Services;

use Omnichannel\Addons\SearchIntelligence\Jobs\SyncGscSiteSnapshotJob;
use Omnichannel\Addons\SearchIntelligence\Models\SeoGscMasterConnection;
use Omnichannel\Addons\SearchIntelligence\Models\SeoGscPropertyMapping;
use Omnichannel\Addons\Seo\Support\SeoAccessControl;
use App\Models\Site;

final class GoogleSearchConsoleBulkSyncService
{
    public function __construct(
        private readonly GoogleSearchConsoleConnectionService $connectionService,
        private readonly GoogleSearchConsoleSyncService $syncService,
        private readonly GoogleSearchConsoleDomainMatcherService $matcher,
    ) {}

    /**
     * @return array{ok: bool, message: string, summary: array<string, int>, rows: list<array<string, mixed>>}
     */
    public function ensureSiteMapped(int $siteId, int $connectionId, int $userId): array
    {
        $connection = $this->connectionService->resolveByIdForUser($userId, $connectionId);
        if ($connection === null) {
            return [
                'ok' => false,
                'status' => 'no_connection',
                'message' => __('seo-content-ai::filament.api_connections.gsc_sync_failed'),
                'mapping' => null,
            ];
        }

        if (! $this->connectionService->hasApiTokens($connection)) {
            return [
                'ok' => false,
                'status' => 'no_credentials',
                'message' => __('seo-content-ai::filament.api_connections.gsc_missing_credentials'),
                'mapping' => null,
            ];
        }

        $properties = $this->connectionService->availableProperties($connection);
        if ($properties === []) {
            $properties = $this->resolveAvailableProperties($connection);
        }

        if ($properties === []) {
            return [
                'ok' => false,
                'status' => 'no_properties',
                'message' => __('seo-content-ai::filament.api_connections.gsc_properties_sync_failed'),
                'mapping' => null,
            ];
        }

        $existing = SeoGscPropertyMapping::query()
            ->where('gsc_connection_id', $connection->id)
            ->where('site_id', $siteId)
            ->first();

        if ($existing instanceof SeoGscPropertyMapping) {
            $propertyUrl = trim((string) $existing->property_url);
            if ($propertyUrl !== '' && $this->matcher->isPropertyAvailable($propertyUrl, $properties)) {
                return [
                    'ok' => true,
                    'status' => 'already_mapped',
                    'message' => __('seo-content-ai::filament.api_connections.gsc_mapping_exists'),
                    'mapping' => $existing,
                ];
            }
        }

        $site = Site::query()->find($siteId);
        if (! $site instanceof Site) {
            return [
                'ok' => false,
                'status' => 'site_not_found',
                'message' => __('seo-content-ai::filament.api_connections.gsc_sync_failed'),
                'mapping' => null,
            ];
        }

        $match = $this->matcher->findBestPropertyForSite((string) ($site->domain ?? ''), $properties);
        if ($match === null) {
            return [
                'ok' => false,
                'status' => 'unmatched',
                'message' => __('seo-content-ai::filament.api_connections.gsc_auto_map_unmatched', [
                    'domain' => (string) ($site->domain ?? ''),
                ]),
                'mapping' => null,
            ];
        }

        if (($match['status'] ?? '') === 'ambiguous') {
            return [
                'ok' => false,
                'status' => 'ambiguous',
                'message' => __('seo-content-ai::filament.api_connections.gsc_auto_map_ambiguous', [
                    'domain' => (string) ($site->domain ?? ''),
                ]),
                'mapping' => null,
            ];
        }

        $mapping = SeoGscPropertyMapping::query()->updateOrCreate(
            [
                'gsc_connection_id' => $connection->id,
                'site_id' => $siteId,
            ],
            [
                'property_url' => (string) $match['property_url'],
                'property_type' => str_starts_with((string) $match['property_url'], 'sc-domain:') ? 'domain' : 'url_prefix',
                'metadata' => [
                    'match_source' => 'auto',
                    'match_status' => 'auto_matched',
                    'matched_at' => now()->toIso8601String(),
                ],
            ],
        );

        return [
            'ok' => true,
            'status' => 'auto_matched',
            'message' => __('seo-content-ai::filament.api_connections.gsc_auto_map_success', [
                'domain' => (string) ($site->domain ?? ''),
                'property' => (string) $match['property_url'],
            ]),
            'mapping' => $mapping,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function autoMapAndSyncAll(int $userId, int $connectionId, bool $queueSync = true): array
    {
        $connection = $this->connectionService->resolveByIdForUser($userId, $connectionId);
        if ($connection === null) {
            return $this->emptySummary(__('seo-content-ai::filament.api_connections.gsc_sync_failed'));
        }

        if (! $this->connectionService->canCallGscApi($connection)) {
            return $this->emptySummary(__('seo-content-ai::filament.api_connections.gsc_missing_credentials'));
        }

        try {
            $properties = $this->connectionService->syncPropertiesMetadata($connection);
        } catch (\Throwable $exception) {
            return $this->emptySummary($exception->getMessage());
        }

        $mapResult = $this->autoMapAccessibleSites($connection, $properties, $userId);
        $syncResult = $this->syncMappedSitesForConnection($connection, $userId, $queueSync);
        $summary = array_merge($mapResult['summary'], $syncResult['summary']);

        return [
            'ok' => $this->isBulkSyncSuccessful($summary),
            'message' => $this->formatSummaryMessage($summary),
            'summary' => $summary,
            'rows' => array_merge($mapResult['rows'], $syncResult['rows']),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function syncAllMappedSites(int $userId, ?int $connectionId = null, bool $queueSync = true, bool $autoMapFirst = true): array
    {
        if ($autoMapFirst && $connectionId !== null && $connectionId > 0) {
            return $this->autoMapAndSyncAll($userId, $connectionId, $queueSync);
        }

        $sites = SeoAccessControl::accessibleSitesQuery()->get(['id', 'domain']);
        $rows = [];
        $summary = [
            'total_domains' => $sites->count(),
            'synced' => 0,
            'empty_success' => 0,
            'failed' => 0,
            'skipped_unmapped' => 0,
        ];

        foreach ($sites as $site) {
            $mapping = $this->resolveMappingForSite((int) $site->id, $connectionId);
            if ($mapping === null) {
                $summary['skipped_unmapped']++;
                $rows[] = $this->buildRow($site, null, 'unmapped', 'skipped', null, null);

                continue;
            }

            $syncStatus = $this->dispatchSiteSync((int) $site->id, $userId, $queueSync);
            $rows[] = $this->buildRow(
                $site,
                $mapping,
                (string) ($mapping->metadata['match_status'] ?? 'mapped'),
                $syncStatus['status'],
                $syncStatus['message'],
                $syncStatus['query_count'],
            );

            if ($syncStatus['status'] === 'synced') {
                $summary['synced']++;
            } elseif ($syncStatus['status'] === 'empty_success') {
                $summary['empty_success']++;
            } else {
                $summary['failed']++;
            }
        }

        return [
            'ok' => $this->isBulkSyncSuccessful($summary),
            'message' => $this->formatSummaryMessage($summary),
            'summary' => $summary,
            'rows' => $rows,
        ];
    }

    /**
     * @param  list<string>  $properties
     * @return array{summary: array<string, int>, rows: list<array<string, mixed>>}
     */
    public function autoMapAccessibleSites(
        SeoGscMasterConnection $connection,
        array $properties,
        int $userId,
    ): array {
        $sites = SeoAccessControl::accessibleSitesQuery()->get(['id', 'domain']);
        $rows = [];
        $summary = [
            'total_domains' => $sites->count(),
            'newly_matched' => 0,
            'already_mapped' => 0,
            'unmatched' => 0,
            'ambiguous' => 0,
            'invalid' => 0,
        ];

        foreach ($sites as $site) {
            $siteId = (int) $site->id;
            $existing = SeoGscPropertyMapping::query()
                ->where('gsc_connection_id', $connection->id)
                ->where('site_id', $siteId)
                ->first();

            if ($existing instanceof SeoGscPropertyMapping) {
                $isManual = ($existing->metadata['match_source'] ?? '') === 'manual';
                $propertyValid = $this->matcher->isPropertyAvailable((string) $existing->property_url, $properties);

                if ($propertyValid) {
                    $summary['already_mapped']++;
                    $rows[] = $this->buildRow(
                        $site,
                        $existing,
                        $isManual ? 'manual' : (string) ($existing->metadata['match_status'] ?? 'mapped'),
                        'already_mapped',
                        null,
                        null,
                    );

                    continue;
                }

                $this->markMappingInvalid($existing);
                $summary['invalid']++;
            }

            $match = $this->matcher->findBestPropertyForSite((string) ($site->domain ?? ''), $properties);

            if ($match === null) {
                $summary['unmatched']++;
                $rows[] = $this->buildRow($site, null, 'unmatched', 'unmatched', null, null);

                continue;
            }

            if ($match['status'] === 'ambiguous') {
                $summary['ambiguous']++;
                $rows[] = $this->buildRow($site, null, 'ambiguous', 'ambiguous', null, null);

                continue;
            }

            $mapping = SeoGscPropertyMapping::query()->updateOrCreate(
                [
                    'gsc_connection_id' => $connection->id,
                    'site_id' => $siteId,
                ],
                [
                    'property_url' => (string) $match['property_url'],
                    'property_type' => str_starts_with((string) $match['property_url'], 'sc-domain:') ? 'domain' : 'url_prefix',
                    'metadata' => [
                        'match_source' => 'auto',
                        'match_status' => 'auto_matched',
                        'matched_at' => now()->toIso8601String(),
                    ],
                ],
            );

            $summary['newly_matched']++;
            $rows[] = $this->buildRow($site, $mapping, 'auto_matched', 'newly_matched', null, null);
        }

        return ['summary' => $summary, 'rows' => $rows];
    }

    /**
     * @return array{summary: array<string, int>, rows: list<array<string, mixed>>}
     */
    private function syncMappedSitesForConnection(
        SeoGscMasterConnection $connection,
        int $userId,
        bool $queueSync,
    ): array {
        $mappings = SeoGscPropertyMapping::query()
            ->where('gsc_connection_id', $connection->id)
            ->with('site')
            ->get();

        $rows = [];
        $summary = [
            'synced' => 0,
            'empty_success' => 0,
            'failed' => 0,
        ];

        foreach ($mappings as $mapping) {
            $site = $mapping->site;
            if (! $site instanceof Site) {
                continue;
            }

            if (! SeoAccessControl::canAccessSite((int) $site->id)) {
                continue;
            }

            $syncStatus = $this->dispatchSiteSync((int) $site->id, $userId, $queueSync);
            $rows[] = $this->buildRow(
                $site,
                $mapping,
                (string) ($mapping->metadata['match_status'] ?? 'mapped'),
                $syncStatus['status'],
                $syncStatus['message'],
                $syncStatus['query_count'],
            );

            if ($syncStatus['status'] === 'synced') {
                $summary['synced']++;
            } elseif ($syncStatus['status'] === 'empty_success') {
                $summary['empty_success']++;
            } else {
                $summary['failed']++;
            }
        }

        return ['summary' => $summary, 'rows' => $rows];
    }

    /**
     * @return array{status: string, message: string|null, query_count: int|null}
     */
    private function dispatchSiteSync(int $siteId, int $userId, bool $queueSync): array
    {
        if ($queueSync) {
            SyncGscSiteSnapshotJob::dispatch($siteId, $userId);

            return [
                'status' => 'queued',
                'message' => __('seo-content-ai::filament.api_connections.gsc_sync_queued'),
                'query_count' => null,
            ];
        }

        $result = $this->syncService->syncSiteWithDetails($siteId, $userId);

        if (! $result['ok']) {
            return [
                'status' => 'failed',
                'message' => $result['message'],
                'query_count' => 0,
            ];
        }

        if (($result['query_count'] ?? 0) === 0) {
            return [
                'status' => 'empty_success',
                'message' => $result['message'],
                'query_count' => 0,
            ];
        }

        return [
            'status' => 'synced',
            'message' => $result['message'],
            'query_count' => (int) $result['query_count'],
        ];
    }

    private function resolveMappingForSite(int $siteId, ?int $connectionId): ?SeoGscPropertyMapping
    {
        $query = SeoGscPropertyMapping::query()->where('site_id', $siteId);

        if ($connectionId !== null && $connectionId > 0) {
            $query->where('gsc_connection_id', $connectionId);
        }

        return $query->orderByDesc('id')->first();
    }

    private function markMappingInvalid(SeoGscPropertyMapping $mapping): void
    {
        $metadata = is_array($mapping->metadata) ? $mapping->metadata : [];
        $metadata['match_status'] = 'invalid';
        $metadata['invalidated_at'] = now()->toIso8601String();
        $mapping->metadata = $metadata;
        $mapping->save();
    }

    /**
     * @return array<string, mixed>
     */
    private function buildRow(
        Site $site,
        ?SeoGscPropertyMapping $mapping,
        string $mappingStatus,
        string $syncStatus,
        ?string $error,
        ?int $queryCount,
    ): array {
        return [
            'site_id' => (int) $site->id,
            'domain' => (string) ($site->domain ?? ''),
            'property_url' => $mapping?->property_url,
            'mapping_status' => $mappingStatus,
            'sync_status' => $syncStatus,
            'last_synced_at' => $mapping?->last_synced_at?->toDateTimeString(),
            'error' => $error,
            'query_count' => $queryCount,
        ];
    }

    /**
     * @return list<string>
     */
    private function resolveAvailableProperties(SeoGscMasterConnection $connection): array
    {
        $properties = $this->connectionService->availableProperties($connection);
        if ($properties !== []) {
            return $properties;
        }

        try {
            return $this->connectionService->syncPropertiesMetadata($connection);
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * @param  array<string, int>  $summary
     */
    private function isBulkSyncSuccessful(array $summary): bool
    {
        $synced = (int) ($summary['synced'] ?? 0);
        $emptySuccess = (int) ($summary['empty_success'] ?? 0);
        $failed = (int) ($summary['failed'] ?? 0);
        $newlyMatched = (int) ($summary['newly_matched'] ?? 0);

        if ($failed > 0) {
            return false;
        }

        return ($synced + $emptySuccess + $newlyMatched) > 0;
    }

    /**
     * @param  array<string, int>  $summary
     */
    private function formatSummaryMessage(array $summary): string
    {
        $parts = [];

        foreach ([
            'newly_matched' => 'gsc_bulk_newly_matched',
            'synced' => 'gsc_bulk_synced',
            'empty_success' => 'gsc_bulk_empty_success',
            'failed' => 'gsc_bulk_failed',
            'unmatched' => 'gsc_bulk_unmatched',
            'ambiguous' => 'gsc_bulk_ambiguous',
            'skipped_unmapped' => 'gsc_bulk_skipped_unmapped',
        ] as $key => $translationKey) {
            if (! isset($summary[$key]) || (int) $summary[$key] <= 0) {
                continue;
            }

            $parts[] = __('seo-content-ai::filament.api_connections.'.$translationKey, [
                'count' => (int) $summary[$key],
            ]);
        }

        if ($parts === []) {
            return __('seo-content-ai::filament.api_connections.gsc_bulk_sync_no_changes');
        }

        return implode(' · ', $parts);
    }

    /**
     * @return array<string, mixed>
     */
    private function emptySummary(string $message): array
    {
        return [
            'ok' => false,
            'message' => $message,
            'summary' => [],
            'rows' => [],
        ];
    }
}
