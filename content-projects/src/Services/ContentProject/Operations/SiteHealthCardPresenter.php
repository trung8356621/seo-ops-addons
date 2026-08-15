<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Services\ContentProject\Operations;

use Omnichannel\Addons\Content\Support\SystemDateTime;
use Omnichannel\Addons\SiteSync\Services\Heartbeat\WordPressHeartbeatPollService;
use Omnichannel\Addons\SiteSync\Services\Presentation\SiteSyncStatusPresenter;
use App\Models\Site;

/**
 * Human Site Health card from stored evidence (no live crawl).
 */
final class SiteHealthCardPresenter
{
    public function __construct(
        private readonly ContentProjectSiteHealthService $health,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function forSite(Site $site): array
    {
        $raw = $this->health->snapshot([(int) $site->id])[0] ?? [];
        $heartbeat = $this->heartbeat($site);
        $linkAnalysis = $this->jsonMeta($site, 'seo_link_analysis_snapshot');
        $dictionary = $this->jsonMeta($site, 'seo_keyword_dictionary');

        $wpOnline = ($heartbeat['status'] ?? '') === 'ok'
            || in_array((string) ($raw['wp_reachable'] ?? ''), ['yes'], true);
        $heartbeatAge = $this->relative($heartbeat['observed_at'] ?? $raw['last_heartbeat_at'] ?? null);
        $pluginVersion = (string) ($heartbeat['plugin_version'] ?? $raw['plugin_version'] ?? '');

        $publishingHealthy = (int) ($raw['publish_failed'] ?? 0) === 0
            && (string) ($raw['health'] ?? '') !== 'unhealthy';
        $syncStatus = (string) ($raw['sync_status'] ?? '');
        $syncCurrent = in_array($syncStatus, ['completed', 'completed_with_warnings', ''], true);
        $syncLines = [
            $syncStatus !== '' ? $this->humanSync($syncStatus) : 'Chưa chạy',
        ];
        try {
            $syncUi = app(SiteSyncStatusPresenter::class)->forSite($site);
            if (($syncUi['running'] ?? false) || ($syncUi['stuck'] ?? false)) {
                $syncLines = array_values(array_filter([
                    (string) ($syncUi['phase_label'] ?? 'Đang chạy'),
                    isset($syncUi['progress'], $syncUi['total']) && (int) $syncUi['total'] > 0
                        ? ((int) $syncUi['progress']).' / '.(int) $syncUi['total']
                        : null,
                    $syncUi['elapsed_label'] ?? null,
                ]));
            }
        } catch (\Throwable) {
            // Health card stays available if Site Sync presenter fails.
        }

        $linkCheckedAt = $linkAnalysis['last_analyzed_at'] ?? $raw['link_health_last_finished_at'] ?? null;
        $linkStale = $linkCheckedAt !== null && $this->isStale($linkCheckedAt, 48);
        $seoStale = $this->isStale($raw['last_sync_at'] ?? null, 72);

        $sections = [
            'wordpress' => [
                'label' => 'WordPress',
                'ok' => $wpOnline,
                'lines' => array_values(array_filter([
                    $wpOnline ? 'Online' : 'Offline / degraded',
                    $pluginVersion !== '' ? 'Bridge '.$pluginVersion : null,
                    $heartbeatAge !== null ? 'Checked '.$heartbeatAge : 'Chưa có heartbeat',
                ])),
            ],
            'publishing' => [
                'label' => 'Publishing',
                'ok' => $publishingHealthy,
                'lines' => [
                    $publishingHealthy ? 'Healthy' : 'Needs attention',
                    'Failed: '.(int) ($raw['publish_failed'] ?? 0),
                ],
            ],
            'site_sync' => [
                'label' => 'Site Sync',
                'ok' => $syncCurrent && $syncStatus !== 'failed',
                'lines' => array_values(array_filter([
                    ...$syncLines,
                    $this->relative($raw['last_sync_at'] ?? null) !== null
                        ? 'Last sync: '.$this->relative($raw['last_sync_at'] ?? null)
                        : null,
                ])),
            ],
            'link_health' => [
                'label' => 'Link Health',
                'ok' => ! $linkStale,
                'lines' => array_values(array_filter([
                    $linkStale ? 'Phân tích liên kết đã cũ' : $this->humanLinkHealth($raw, $linkAnalysis),
                    $this->relative($linkCheckedAt) !== null
                        ? 'Last checked: '.$this->relative($linkCheckedAt)
                        : null,
                ])),
            ],
            'seo_snapshot' => [
                'label' => 'SEO Snapshot',
                'ok' => ! $seoStale,
                'lines' => [
                    $seoStale ? 'Stale' : 'Current',
                ],
            ],
            'capabilities' => [
                'label' => 'Capabilities',
                'ok' => (bool) ($raw['capabilities_loaded'] ?? false),
                'lines' => [
                    ($raw['capabilities_loaded'] ?? false) ? 'Loaded' : 'Missing',
                ],
            ],
        ];

        return [
            'domain' => (string) ($raw['name'] ?? $site->domain ?? ''),
            'health' => $raw['health'] ?? 'unknown',
            'sections' => $sections,
            'link_opportunities' => (int) ($linkAnalysis['opportunities'] ?? 0),
            'orphan_pages' => (int) ($linkAnalysis['orphan_pages'] ?? 0),
            'broken_links' => (int) ($linkAnalysis['broken_links'] ?? $raw['link_health_broken_candidates'] ?? 0),
            'internal_links' => (int) ($linkAnalysis['internal_links'] ?? 0),
            'dictionary_version' => $dictionary['version'] ?? null,
            'raw' => $raw,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function heartbeat(Site $site): array
    {
        $decoded = $this->jsonMeta($site, WordPressHeartbeatPollService::META_KEY);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @return array<string, mixed>
     */
    private function jsonMeta(Site $site, string $key): array
    {
        $raw = $site->getMeta($key);
        if (is_array($raw)) {
            return $raw;
        }
        if (! is_string($raw) || $raw === '') {
            return [];
        }
        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : [];
    }

    private function relative(mixed $value): ?string
    {
        return SystemDateTime::formatRelative($value);
    }

    private function isStale(mixed $value, int $hours): bool
    {
        $relative = SystemDateTime::formatRelative($value);
        if ($relative === null || $value === null) {
            return true;
        }
        try {
            return \Carbon\Carbon::parse((string) $value)->lt(now()->subHours($hours));
        } catch (\Throwable) {
            return true;
        }
    }

    private function humanSync(string $status): string
    {
        return match ($status) {
            'completed', 'completed_with_warnings' => 'Completed',
            'running', 'queued' => 'Đang chạy',
            'stuck' => 'Không có tiến triển',
            'canceled', 'cancelled' => 'Đã hủy',
            'failed' => 'Failed',
            default => ucfirst(str_replace('_', ' ', $status)),
        };
    }

    /**
     * @param  array<string, mixed>  $raw
     * @param  array<string, mixed>  $analysis
     */
    private function humanLinkHealth(array $raw, array $analysis): string
    {
        $links = (int) ($analysis['internal_links'] ?? 0);
        $broken = (int) ($analysis['broken_links'] ?? $raw['link_health_broken_candidates'] ?? 0);
        if ($links <= 0 && $broken <= 0) {
            $status = (string) ($raw['link_health_status'] ?? '');

            return $status !== '' ? ucfirst($status) : 'Chưa phân tích';
        }

        return $links.' links · '.$broken.' broken';
    }
}
