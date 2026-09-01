<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Services\ContentProject\Operations;

use Omnichannel\Addons\Content\Support\SystemDateTime;
use Omnichannel\Addons\SiteSync\Services\Heartbeat\WordPressHeartbeatPollService;
use Omnichannel\Addons\SiteSync\Services\Preflight\SiteSyncPreflightService;
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
        $linkAnalysis = app(\Omnichannel\Addons\SiteSync\Services\LinkAnalysis\DomainLinkInventoryReadModel::class)
            ->forSite($site);
        $dictionary = $this->jsonMeta($site, 'seo_keyword_dictionary');

        $wpOnline = ($heartbeat['status'] ?? '') === 'ok'
            || in_array((string) ($raw['wp_reachable'] ?? ''), ['yes'], true);
        $heartbeatAge = $this->relative($heartbeat['observed_at'] ?? $raw['last_heartbeat_at'] ?? null);
        $pluginVersion = (string) ($heartbeat['plugin_version'] ?? $raw['plugin_version'] ?? '');

        $publishingHealthy = (int) ($raw['publish_failed'] ?? 0) === 0
            && (string) ($raw['health'] ?? '') !== 'unhealthy';
        $syncStatus = (string) ($raw['sync_status'] ?? '');
        $syncCurrent = in_array($syncStatus, ['completed', 'completed_with_warnings', ''], true);
        $syncRelative = $this->relative($raw['last_sync_at'] ?? null);
        $syncLines = [
            match (true) {
                $syncStatus === 'failed' => 'Failed',
                $syncStatus === 'needs_attention' => 'Needs attention',
                in_array($syncStatus, ['running', 'queued', 'pending'], true) => 'Syncing',
                $syncStatus === '' => 'Never synced',
                $syncRelative !== null => 'Last success '.$syncRelative,
                default => 'Healthy',
            },
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

        $linkInventoryReady = (bool) ($linkAnalysis['inventory_available'] ?? false);
        $remoteChecked = (bool) ($linkAnalysis['remote_health_checked'] ?? false);
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
                ],
            ],
            'site_sync' => [
                'label' => 'Site Sync',
                'ok' => $syncCurrent && $syncStatus !== 'failed',
                'lines' => array_values(array_filter([
                    ...$syncLines,
                ])),
            ],
            'link_health' => [
                'label' => 'Links',
                'ok' => $linkInventoryReady,
                'lines' => array_values(array_filter([
                    (string) ($linkAnalysis['inventory_state'] ?? 'Not available'),
                    'Remote health: '.(string) ($linkAnalysis['remote_health_state'] ?? 'Not checked'),
                    $remoteChecked && ($linkAnalysis['last_remote_analyzed_at'] ?? null)
                        ? 'Last checked '.$this->relative($linkAnalysis['last_remote_analyzed_at'])
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

        $dataHealth = $this->dataHealthSection($site);
        if ($dataHealth !== null) {
            $fields = is_array($dataHealth['payload']['data_health']['fields'] ?? null)
                ? $dataHealth['payload']['data_health']['fields']
                : [];
            $present = 0;
            $total = 0;
            $missing = 0;
            $missingParts = [];
            foreach ($fields as $field) {
                if (! is_array($field)) {
                    continue;
                }
                $present += (int) ($field['present'] ?? 0);
                $total += (int) ($field['total'] ?? 0);
                $fieldMissing = (int) ($field['missing'] ?? 0);
                $missing += $fieldMissing;
                if ($fieldMissing > 0) {
                    $missingParts[] = number_format($fieldMissing).' missing '
                        .strtolower((string) ($field['label'] ?? $field['key'] ?? 'field'));
                }
            }
            $summary = $total > 0
                ? number_format($present).' / '.number_format($total).' complete'
                : ($dataHealth['lines'][0] ?? 'Healthy');
            $dataHealth['lines'] = array_values(array_filter([
                $summary,
                $missing > 0
                    ? (count($missingParts) <= 3
                        ? implode('; ', $missingParts)
                        : number_format($missing).' missing')
                    : 'Healthy',
            ]));
            $sections['seo_ops_data'] = $dataHealth;
        }

        $focusCoverage = $this->focusKeywordCoverageSection($site);
        if ($focusCoverage !== null) {
            $sections['focus_keywords'] = $focusCoverage;
        }

        return [
            'domain' => (string) ($raw['name'] ?? $site->domain ?? ''),
            'health' => $raw['health'] ?? 'unknown',
            'sections' => $sections,
            'data_health' => $dataHealth['payload'] ?? null,
            'focus_keyword_coverage' => $focusCoverage['payload'] ?? null,
            'link_opportunities' => $linkAnalysis['link_opportunities'],
            'link_opportunities_checked' => (bool) ($linkAnalysis['opportunities_checked'] ?? false),
            'orphan_pages' => (int) ($linkAnalysis['orphan_pages'] ?? 0),
            'broken_links' => $linkAnalysis['broken_links'],
            'broken_links_checked' => (bool) ($linkAnalysis['remote_health_checked'] ?? false),
            'internal_links' => (int) ($linkAnalysis['internal_links'] ?? 0),
            'external_links' => (int) ($linkAnalysis['external_links'] ?? 0),
            'link_inventory' => $linkAnalysis,
            'dictionary_version' => $dictionary['version'] ?? null,
            'raw' => $raw,
        ];
    }

    /**
     * Article-level Focus Keyword coverage (not Dictionary unique-phrase count).
     *
     * @return array{label: string, ok: bool, lines: list<string>, payload?: array<string, mixed>}|null
     */
    private function focusKeywordCoverageSection(Site $site): ?array
    {
        try {
            $filterUrl = app(\Omnichannel\Addons\Seo\Services\DomainOverviewService::class)
                ->buildArticlesFilterUrlForMissingFocusKeyword((int) $site->id);
            $coverage = app(\Omnichannel\Addons\Seo\Services\FocusKeywordCoverageService::class)
                ->forSite((int) $site->id, $filterUrl);
        } catch (\Throwable) {
            return null;
        }

        $eligible = (int) ($coverage['eligible_article_count'] ?? 0);
        $with = (int) ($coverage['articles_with_focus_keyword'] ?? 0);
        $missing = (int) ($coverage['missing_focus_keyword_articles'] ?? 0);
        $ok = $missing === 0;

        $lines = [
            number_format($with).' / '.number_format($eligible).' articles',
            $ok ? 'Complete' : (number_format($missing).' missing'),
        ];

        return [
            'label' => 'Focus Keywords',
            'ok' => $ok,
            'lines' => $lines,
            'payload' => $coverage,
        ];
    }

    /**
     * Local-only SEO Ops required-field audit (no live WP crawl).
     *
     * @return array{label: string, ok: bool, lines: list<string>, payload?: array<string, mixed>}|null
     */
    private function dataHealthSection(Site $site): ?array
    {
        try {
            $preflight = app(SiteSyncPreflightService::class)->evaluateLocalOnly($site);
        } catch (\Throwable) {
            return null;
        }

        $fields = is_array($preflight['data_health']['fields'] ?? null)
            ? $preflight['data_health']['fields']
            : [];
        $lines = [];
        foreach ($fields as $field) {
            if (! is_array($field)) {
                continue;
            }
            $missing = (int) ($field['missing'] ?? 0);
            $present = (int) ($field['present'] ?? 0);
            $total = (int) ($field['total'] ?? 0);
            $na = (int) ($field['not_applicable'] ?? 0);
            $sourceAbsent = (int) ($field['source_absent'] ?? 0);
            $mark = match ((string) ($field['severity'] ?? 'green')) {
                'red' => '🔴',
                'yellow' => '⚠',
                default => '✓',
            };
            $line = sprintf(
                '%s %s  %s / %s',
                $mark,
                (string) ($field['label'] ?? $field['key'] ?? ''),
                number_format($present),
                number_format($total),
            );
            if ($missing > 0) {
                $line .= '  Missing '.number_format($missing);
            }
            if ($na > 0 || $sourceAbsent > 0) {
                $line .= sprintf('  (N/A %s · source absent %s)', number_format($na), number_format($sourceAbsent));
            }
            $lines[] = $line;
        }

        $lines[] = 'Khuyến nghị: '.(string) ($preflight['recommendation_label'] ?? 'NORMAL SYNC');

        $severity = (string) ($preflight['severity'] ?? 'green');

        return [
            'label' => 'SEO Ops data health',
            'ok' => $severity === 'green',
            'lines' => $lines,
            'payload' => $preflight,
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
}