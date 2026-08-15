<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Services\ContentProject\Operations;

use Omnichannel\Addons\ContentProjects\Enums\ContentProjectPublishQueueStatus;
use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\ContentProjects\Models\SeoProjectTask;
use Omnichannel\Addons\SiteSync\Models\SeoLinkHealthRun;
use Omnichannel\Addons\SiteSync\Models\SeoSiteCapability;
use Omnichannel\Addons\SiteSync\Models\SeoSiteSyncRun;
use Omnichannel\Addons\SiteSync\Services\Contracts\SiteSyncSchema;
use Omnichannel\Addons\SiteSync\Services\Support\SiteSyncInfrastructure;
use App\Models\Site;
use App\Support\RuntimeLogger;
use Carbon\Carbon;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Per-site health snapshot from stored Site Sync / handshake evidence (no live HTTP by default).
 */
final class ContentProjectSiteHealthService
{
    public const FRESHNESS_DAYS = 14;

    /**
     * @param  list<int>  $siteIds
     * @return list<array<string, mixed>>
     */
    public function snapshot(array $siteIds): array
    {
        if ($siteIds === []) {
            return [];
        }

        $hasQueue = Schema::connection('omi_seo_ai')->hasColumn('seo_project_tasks', 'publish_queue_status');
        $out = [];

        foreach ($siteIds as $siteId) {
            $siteId = (int) $siteId;
            if ($siteId <= 0) {
                continue;
            }

            try {
                $out[] = $this->buildForSite($siteId, $hasQueue);
            } catch (Throwable $e) {
                RuntimeLogger::report($e, [
                    'endpoint' => 'content_project.site_health',
                    'site_id' => $siteId,
                ]);
                $out[] = [
                    'site_id' => $siteId,
                    'name' => 'Site #'.$siteId,
                    'domain' => null,
                    'waiting_articles' => 0,
                    'publishing' => 0,
                    'publish_failed' => 0,
                    'last_publish' => null,
                    'last_sync' => null,
                    'wp_reachable' => 'unknown_due_to_missing_data',
                    'token_ok' => 'unknown_due_to_missing_data',
                    'health' => 'unknown',
                    'message' => 'Không đọc được health evidence: '.$e->getMessage(),
                    'evidence' => [],
                ];
            }
        }

        return $out;
    }

    /**
     * @return array<string, mixed>
     */
    private function buildForSite(int $siteId, bool $hasQueue): array
    {
        $site = Site::query()->find($siteId);
        $domain = $site instanceof Site ? (string) ($site->domain ?: '') : '';
        $name = $domain !== '' ? $domain : ('Site #'.$siteId);

        $taskBase = SeoProjectTask::query()->where('site_id', $siteId)->active();
        $waiting = (int) (clone $taskBase)
            ->whereIn('status', [SeoProjectTask::STATUS_PENDING, SeoProjectTask::STATUS_WRITING])
            ->count();

        $publishing = 0;
        $publishFailed = 0;
        if ($hasQueue) {
            $publishing = (int) (clone $taskBase)
                ->whereIn('publish_queue_status', [
                    ContentProjectPublishQueueStatus::Waiting->value,
                    ContentProjectPublishQueueStatus::Processing->value,
                    ContentProjectPublishQueueStatus::Retrying->value,
                ])
                ->count();
            $publishFailed = (int) (clone $taskBase)
                ->where('publish_queue_status', ContentProjectPublishQueueStatus::Failed->value)
                ->count();
        }

        $evidence = $this->collectEvidence($siteId, $site instanceof Site ? $site : null);
        $wp = $this->deriveWpReachable($evidence);
        $token = $this->deriveTokenOk($evidence);

        $health = match (true) {
            $wp['value'] === 'no' || $token['value'] === 'no' => 'unhealthy',
            $wp['value'] === 'never_checked' && $token['value'] === 'never_checked' => 'never_checked',
            $wp['value'] === 'stale' || $token['value'] === 'stale' => 'stale',
            $wp['value'] === 'yes' && in_array($token['value'], ['yes', 'configured'], true) => 'healthy',
            default => 'unknown',
        };

        return [
            'site_id' => $siteId,
            'name' => $name,
            'domain' => $domain !== '' ? $domain : null,
            'waiting_articles' => $waiting,
            'publishing' => $publishing,
            'publish_failed' => $publishFailed,
            'last_publish' => null,
            'last_sync' => $evidence['last_sync_at'],
            'wp_reachable' => $wp['value'],
            'token_ok' => $token['value'],
            'wp_reachable_reason' => $wp['reason'],
            'token_ok_reason' => $token['reason'],
            'checked_at' => $evidence['checked_at'],
            'plugin_version' => $evidence['plugin_version'],
            'sync_status' => $evidence['sync_status'],
            'sync_started_at' => $evidence['sync_started_at'],
            'sync_finished_at' => $evidence['sync_finished_at'],
            'capabilities_loaded' => $evidence['capabilities_loaded'],
            'snapshot_received' => $evidence['snapshot_received'],
            'last_heartbeat_at' => $evidence['last_heartbeat_at'],
            'heartbeat_status' => $evidence['heartbeat_status'],
            'capability_gaps' => $evidence['capability_gaps'],
            'link_health_status' => $evidence['link_health_status'],
            'link_health_last_finished_at' => $evidence['link_health_last_finished_at'],
            'link_health_broken_candidates' => $evidence['link_health_broken_candidates'],
            'health' => $health,
            'message' => $wp['reason'],
            'evidence' => [
                'sources' => $evidence['sources'],
            ],
        ];
    }

    /**
     * @return array{
     *   last_sync_at: ?string,
     *   checked_at: ?string,
     *   plugin_version: ?string,
     *   sync_status: ?string,
     *   sync_started_at: ?string,
     *   sync_finished_at: ?string,
     *   capabilities_loaded: bool,
     *   snapshot_received: bool,
     *   handshake_status: ?string,
     *   handshake_checked_at: ?string,
     *   handshake_capability_ok: ?bool,
     *   handshake_identity_ok: ?bool,
     *   plugin_info_fetched_at: ?string,
     *   has_token: bool,
     *   capability_detected_at: ?string,
     *   sources: list<string>
     * }
     */
    private function collectEvidence(int $siteId, ?Site $site): array
    {
        $sources = [];
        $lastSyncAt = SeoArticle::query()->where('site_id', $siteId)->max('last_synced_at');
        if ($lastSyncAt !== null) {
            $sources[] = 'seo_articles.last_synced_at';
        }

        $syncStatus = null;
        $syncStarted = null;
        $syncFinished = null;
        $snapshotReceived = false;
        if (SiteSyncInfrastructure::tablesReady() && SiteSyncInfrastructure::hasTable('seo_site_sync_runs')) {
            $run = SeoSiteSyncRun::query()
                ->where('site_id', $siteId)
                ->orderByDesc('id')
                ->first();
            if ($run instanceof SeoSiteSyncRun) {
                $syncStatus = (string) ($run->status ?? '');
                $syncStarted = $run->started_at?->toIso8601String();
                $syncFinished = $run->finished_at?->toIso8601String();
                $snapshotReceived = in_array($syncStatus, ['completed', 'completed_with_warnings'], true);
                $sources[] = 'seo_site_sync_runs';
                if ($syncFinished !== null && ($lastSyncAt === null || (string) $lastSyncAt < $syncFinished)) {
                    $lastSyncAt = $run->finished_at?->toDateTimeString();
                }
            }
        }

        $capabilityDetectedAt = null;
        $pluginVersion = null;
        $capabilitiesLoaded = false;
        if (SiteSyncInfrastructure::tablesReady() && SiteSyncInfrastructure::hasTable('seo_site_capabilities')) {
            $cap = SeoSiteCapability::query()->where('site_id', $siteId)->first();
            if ($cap instanceof SeoSiteCapability) {
                $capabilitiesLoaded = true;
                $capabilityDetectedAt = $cap->detected_at?->toIso8601String();
                $pluginVersion = $cap->bridge_version !== null && $cap->bridge_version !== ''
                    ? (string) $cap->bridge_version
                    : null;
                $sources[] = 'seo_site_capabilities';
            }
        }

        $handshakeStatus = null;
        $handshakeCheckedAt = null;
        $handshakeCapabilityOk = null;
        $handshakeIdentityOk = null;
        $pluginInfoFetchedAt = null;
        $hasToken = false;

        if ($site instanceof Site) {
            $token = trim((string) ($site->getMeta('seo_read_token') ?? ''));
            $hasToken = $token !== '';
            if ($hasToken) {
                $sources[] = 'site_metas.seo_read_token';
            }

            $handshake = $site->getMeta(SiteSyncSchema::META_HANDSHAKE);
            if (is_string($handshake) && $handshake !== '') {
                $decoded = json_decode($handshake, true);
                $handshake = is_array($decoded) ? $decoded : null;
            }
            if (is_array($handshake)) {
                $handshakeStatus = isset($handshake['status']) ? (string) $handshake['status'] : null;
                $handshakeCheckedAt = isset($handshake['checked_at']) ? (string) $handshake['checked_at'] : null;
                if ($pluginVersion === null && ! empty($handshake['bridge_version'])) {
                    $pluginVersion = (string) $handshake['bridge_version'];
                }
                $checks = is_array($handshake['checks'] ?? null) ? $handshake['checks'] : [];
                foreach ($checks as $check) {
                    if (! is_array($check)) {
                        continue;
                    }
                    $key = (string) ($check['key'] ?? '');
                    $ok = (bool) ($check['ok'] ?? false);
                    if ($key === 'capability_endpoint') {
                        $handshakeCapabilityOk = $ok;
                    }
                    if ($key === 'connection_identity') {
                        $handshakeIdentityOk = $ok;
                    }
                }
                $sources[] = 'site_metas.'.SiteSyncSchema::META_HANDSHAKE;
            }

            $pluginInfoFetchedAt = $site->getMeta('seo_wp_plugin_info_fetched_at');
            $pluginInfoFetchedAt = is_string($pluginInfoFetchedAt) && $pluginInfoFetchedAt !== ''
                ? $pluginInfoFetchedAt
                : null;
            if ($pluginInfoFetchedAt !== null) {
                $sources[] = 'site_metas.seo_wp_plugin_info_fetched_at';
            }

            if ($pluginVersion === null) {
                $pluginInfo = $site->getMeta('seo_wp_plugin_info');
                if (is_string($pluginInfo) && $pluginInfo !== '') {
                    $decoded = json_decode($pluginInfo, true);
                    $pluginInfo = is_array($decoded) ? $decoded : null;
                }
                if (is_array($pluginInfo) && ! empty($pluginInfo['bridge_version'])) {
                    $pluginVersion = (string) $pluginInfo['bridge_version'];
                    $sources[] = 'site_metas.seo_wp_plugin_info';
                }
            }
        }

        $lastHeartbeatAt = null;
        $heartbeatStatus = null;
        if ($site instanceof Site) {
            $rawHeartbeat = $site->getMeta('seo_wp_heartbeat');
            if (is_string($rawHeartbeat) && $rawHeartbeat !== '') {
                $decodedHb = json_decode($rawHeartbeat, true);
                if (is_array($decodedHb)) {
                    $heartbeatStatus = (string) ($decodedHb['status'] ?? 'ok');
                    $lastHeartbeatAt = isset($decodedHb['observed_at'])
                        ? (string) $decodedHb['observed_at']
                        : null;
                    $sources[] = 'site_metas.seo_wp_heartbeat';
                    if ($pluginVersion === null && ! empty($decodedHb['plugin_version'])) {
                        $pluginVersion = (string) $decodedHb['plugin_version'];
                    }
                }
            }
        }

        $capabilityGaps = [];
        if ($capabilitiesLoaded) {
            $capRow = SeoSiteCapability::query()->where('site_id', $siteId)->first();
            if ($capRow instanceof SeoSiteCapability && is_array($capRow->manifest)) {
                try {
                    $manifest = \Omnichannel\Addons\SiteSync\Services\Contracts\CapabilityManifestData::fromArray($capRow->manifest);
                    $capabilityGaps = $manifest->localEngineGaps();
                } catch (Throwable) {
                    $capabilityGaps = [];
                }
            }
        }

        $linkHealthStatus = null;
        $linkHealthFinishedAt = null;
        $linkHealthBroken = null;
        if (SiteSyncInfrastructure::tablesReady() && SiteSyncInfrastructure::hasTable('seo_link_health_runs')) {
            $lh = SeoLinkHealthRun::query()
                ->where('site_id', $siteId)
                ->orderByDesc('id')
                ->first();
            if ($lh instanceof SeoLinkHealthRun) {
                $linkHealthStatus = (string) $lh->status;
                $linkHealthFinishedAt = $lh->finished_at?->toIso8601String();
                $linkHealthBroken = (int) $lh->broken_candidates;
                $sources[] = 'seo_link_health_runs';
            }
        }

        $checkedAt = $this->maxTimestamp([
            $handshakeCheckedAt,
            $pluginInfoFetchedAt,
            $capabilityDetectedAt,
            $syncFinished,
            $lastHeartbeatAt,
        ]);

        return [
            'last_sync_at' => $lastSyncAt !== null ? (string) $lastSyncAt : null,
            'checked_at' => $checkedAt,
            'plugin_version' => $pluginVersion,
            'sync_status' => $syncStatus,
            'sync_started_at' => $syncStarted,
            'sync_finished_at' => $syncFinished,
            'capabilities_loaded' => $capabilitiesLoaded,
            'snapshot_received' => $snapshotReceived,
            'handshake_status' => $handshakeStatus,
            'handshake_checked_at' => $handshakeCheckedAt,
            'handshake_capability_ok' => $handshakeCapabilityOk,
            'handshake_identity_ok' => $handshakeIdentityOk,
            'plugin_info_fetched_at' => $pluginInfoFetchedAt,
            'has_token' => $hasToken,
            'capability_detected_at' => $capabilityDetectedAt,
            'last_heartbeat_at' => $lastHeartbeatAt,
            'heartbeat_status' => $heartbeatStatus,
            'capability_gaps' => $capabilityGaps,
            'link_health_status' => $linkHealthStatus,
            'link_health_last_finished_at' => $linkHealthFinishedAt,
            'link_health_broken_candidates' => $linkHealthBroken,
            'sources' => array_values(array_unique($sources)),
        ];
    }

    /**
     * @param  array<string, mixed>  $evidence
     * @return array{value: string, reason: string}
     */
    private function deriveWpReachable(array $evidence): array
    {
        if ($evidence['handshake_capability_ok'] === false
            || in_array((string) ($evidence['handshake_status'] ?? ''), ['failed', 'incompatible'], true)) {
            return [
                'value' => 'no',
                'reason' => 'Handshake capability_endpoint failed (status='.(string) ($evidence['handshake_status'] ?? 'failed').').',
            ];
        }

        $proofAt = $this->maxTimestamp([
            $evidence['handshake_capability_ok'] === true ? $evidence['handshake_checked_at'] : null,
            $evidence['capability_detected_at'],
            $evidence['plugin_info_fetched_at'],
            in_array((string) ($evidence['sync_status'] ?? ''), ['completed', 'completed_with_warnings'], true)
                ? $evidence['sync_finished_at']
                : null,
        ]);

        if ($proofAt === null) {
            return [
                'value' => 'never_checked',
                'reason' => 'Chưa có evidence authenticated WP call / completed sync / capability snapshot.',
            ];
        }

        if ($this->isStale($proofAt)) {
            return [
                'value' => 'stale',
                'reason' => 'Last WP reachability proof is older than '.self::FRESHNESS_DAYS.' days (at '.$proofAt.').',
            ];
        }

        $source = match (true) {
            $evidence['handshake_capability_ok'] === true => 'handshake.capability_endpoint',
            $evidence['capability_detected_at'] !== null => 'seo_site_capabilities.detected_at',
            $evidence['plugin_info_fetched_at'] !== null => 'seo_wp_plugin_info_fetched_at',
            default => 'seo_site_sync_runs.completed',
        };

        return [
            'value' => 'yes',
            'reason' => 'Proven via '.$source.' at '.$proofAt.'.',
        ];
    }

    /**
     * @param  array<string, mixed>  $evidence
     * @return array{value: string, reason: string}
     */
    private function deriveTokenOk(array $evidence): array
    {
        if (! $evidence['has_token']) {
            if (($evidence['handshake_status'] ?? null) === 'not_configured'
                || ($evidence['plugin_info_fetched_at'] === null
                    && $evidence['handshake_checked_at'] === null
                    && $evidence['capability_detected_at'] === null)) {
                return [
                    'value' => 'never_checked',
                    'reason' => 'Chưa cấu hình seo_read_token và chưa có authentication evidence.',
                ];
            }

            return [
                'value' => 'no',
                'reason' => 'Thiếu seo_read_token trên site.',
            ];
        }

        if ($evidence['handshake_identity_ok'] === false
            || in_array((string) ($evidence['handshake_status'] ?? ''), ['failed'], true)) {
            return [
                'value' => 'no',
                'reason' => 'Handshake authentication failed (status='.(string) ($evidence['handshake_status'] ?? 'failed').').',
            ];
        }

        $authAt = $this->maxTimestamp([
            $evidence['handshake_identity_ok'] === true || in_array((string) ($evidence['handshake_status'] ?? ''), ['healthy', 'degraded'], true)
                ? $evidence['handshake_checked_at']
                : null,
            $evidence['plugin_info_fetched_at'],
            $evidence['capability_detected_at'],
            in_array((string) ($evidence['sync_status'] ?? ''), ['completed', 'completed_with_warnings'], true)
                ? $evidence['sync_finished_at']
                : null,
        ]);

        if ($authAt === null) {
            return [
                'value' => 'configured',
                'reason' => 'Token present nhưng chưa có successful authenticated call evidence.',
            ];
        }

        if ($this->isStale($authAt)) {
            return [
                'value' => 'stale',
                'reason' => 'Last successful auth evidence older than '.self::FRESHNESS_DAYS.' days (at '.$authAt.').',
            ];
        }

        return [
            'value' => 'yes',
            'reason' => 'Authenticated evidence at '.$authAt.'.',
        ];
    }

    /**
     * @param  list<?string>  $values
     */
    private function maxTimestamp(array $values): ?string
    {
        $best = null;
        $bestTs = null;
        foreach ($values as $value) {
            if (! is_string($value) || trim($value) === '') {
                continue;
            }
            try {
                $ts = Carbon::parse($value)->getTimestamp();
            } catch (Throwable) {
                continue;
            }
            if ($bestTs === null || $ts > $bestTs) {
                $bestTs = $ts;
                $best = Carbon::createFromTimestamp($ts)->toDateTimeString();
            }
        }

        return $best;
    }

    private function isStale(string $isoOrDatetime): bool
    {
        try {
            return Carbon::parse($isoOrDatetime)->lt(now()->subDays(self::FRESHNESS_DAYS));
        } catch (Throwable) {
            return true;
        }
    }
}
