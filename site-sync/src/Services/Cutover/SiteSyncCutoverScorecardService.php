<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SiteSync\Services\Cutover;

use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\SiteSync\Models\SeoSiteCapability;
use Omnichannel\Addons\SiteSync\Models\SeoSiteManualLink;
use Omnichannel\Addons\SiteSync\Models\SeoSiteSyncHeartbeat;
use Omnichannel\Addons\SiteSync\Models\SeoSiteSyncInboundEvent;
use Omnichannel\Addons\SiteSync\Models\SeoSiteSyncRun;
use Omnichannel\Addons\SiteSync\Services\Bootstrap\SiteSyncBootstrapService;
use Omnichannel\Addons\SiteSync\Services\Contracts\SiteSyncSchema;
use Omnichannel\Addons\SiteSync\Services\Handshake\SiteSyncHandshakeService;
use Omnichannel\Addons\SiteSync\Services\Orchestration\SiteSyncFeatureFlags;
use App\Models\Site;

final class SiteSyncCutoverScorecardService
{
    public function __construct(
        private readonly SiteSyncFeatureFlags $flags,
        private readonly SiteSyncHandshakeService $handshake,
        private readonly SiteSyncBootstrapService $bootstrap,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function evaluate(Site $site, string $mode): array
    {
        $items = [];
        $siteId = (int) $site->id;
        $site->loadMissing('metas');

        $pluginInfo = $site->getMeta('seo_wp_plugin_info');
        $decoded = is_string($pluginInfo) ? json_decode($pluginInfo, true) : (is_array($pluginInfo) ? $pluginInfo : []);
        $bridge = (string) ($decoded['bridge_version'] ?? '');
        $items[] = $this->item(
            'contract_compatibility',
            version_compare($bridge !== '' ? $bridge : '0.0.0', SiteSyncSchema::MIN_BRIDGE_VERSION, '>=') ? 'pass' : 'fail',
            $bridge !== '' ? $bridge : 'unknown',
        );

        $cap = SeoSiteCapability::query()->where('site_id', $siteId)->first();
        $items[] = $this->item('plugin_health', $cap !== null ? 'pass' : 'warning', $cap ? 'manifest' : 'missing');

        $hs = $this->handshake->current($site);
        $hsStatus = (string) ($hs['status'] ?? 'not_configured');
        $items[] = $this->item(
            'callback_health',
            in_array($hsStatus, ['healthy', 'degraded'], true) ? ($hsStatus === 'healthy' ? 'pass' : 'warning') : 'fail',
            $hsStatus,
        );

        $bootstrapped = ! $this->bootstrap->needsBootstrap($site);
        $items[] = $this->item('bootstrap_completeness', $bootstrapped ? 'pass' : 'fail', $bootstrapped ? 'yes' : 'no');

        $articles = SeoArticle::query()->where('site_id', $siteId)->count();
        $items[] = $this->item('article_coverage', $articles > 0 ? 'pass' : 'warning', (string) $articles);

        $manual = SeoSiteManualLink::query()->where('site_id', $siteId)->count();
        $items[] = $this->item('manual_preservation', 'pass', 'manual_links='.$manual);

        $dead = SeoSiteSyncInboundEvent::query()
            ->where('site_id', $siteId)
            ->where('status', SeoSiteSyncInboundEvent::STATUS_DEAD_LETTER)
            ->count();
        $items[] = $this->item('dead_letter_status', $dead === 0 ? 'pass' : ($dead > 20 ? 'fail' : 'warning'), (string) $dead);

        $recent = SeoSiteSyncRun::query()
            ->where('site_id', $siteId)
            ->where('status', 'completed')
            ->where('finished_at', '>=', now()->subDays(7))
            ->exists();
        $items[] = $this->item('reconciliation_drift', $recent || $mode === SiteSyncCutoverModes::LEGACY_ACTIVE ? 'pass' : 'warning', $recent ? 'recent_ok' : 'stale');

        $queueHb = SeoSiteSyncHeartbeat::query()->where('channel', 'queue')->first();
        $schedHb = SeoSiteSyncHeartbeat::query()->where('channel', 'scheduler')->first();
        $items[] = $this->item(
            'queue_inbound_health',
            $queueHb?->last_seen_at && $queueHb->last_seen_at->gte(now()->subHours(6)) ? 'pass' : 'warning',
            $queueHb?->last_seen_at?->toIso8601String() ?? 'no_heartbeat',
        );
        $items[] = $this->item(
            'scheduler_state',
            $schedHb?->last_seen_at && $schedHb->last_seen_at->gte(now()->subHours(2)) ? 'pass' : 'warning',
            $schedHb?->last_seen_at?->toIso8601String() ?? 'no_heartbeat',
        );

        $dualOk = ! ($this->flags->dualRunShadowEnabled() && $mode !== SiteSyncCutoverModes::LEGACY_ACTIVE);
        $items[] = $this->item('dual_run_protection', $dualOk ? 'pass' : 'fail', $dualOk ? 'protected' : 'dual_risk');

        $items[] = $this->item(
            'provider_accuracy',
            $cap !== null ? 'pass' : 'not_applicable',
            (string) (($cap?->manifest['provider']['id'] ?? null) ?? 'n/a'),
        );

        $hasBlocking = false;
        $failCount = 0;
        $warnCount = 0;
        foreach ($items as $item) {
            if ($item['result'] === 'fail') {
                $failCount++;
                if (in_array($item['key'], ['contract_compatibility', 'bootstrap_completeness', 'dead_letter_status', 'dual_run_protection', 'callback_health'], true)) {
                    $hasBlocking = true;
                }
            }
            if ($item['result'] === 'warning') {
                $warnCount++;
            }
        }

        $status = match (true) {
            $this->flags->emergencyRollback() => 'rollback_recommended',
            $hasBlocking || $failCount >= 3 => 'not_ready',
            $mode === SiteSyncCutoverModes::LEGACY_ACTIVE && $failCount === 0 && $warnCount <= 2 => 'ready_for_shadow',
            $mode === SiteSyncCutoverModes::V2_SHADOW && $failCount === 0 => 'ready_for_manual_cutover',
            $mode === SiteSyncCutoverModes::V2_SHADOW => 'shadow_observation_required',
            $mode === SiteSyncCutoverModes::V2_ACTIVE && $failCount === 0 => 'ready_for_manual_cutover',
            default => 'shadow_observation_required',
        };

        return [
            'status' => $status,
            'mode' => $mode,
            'has_blocking' => $hasBlocking,
            'fail_count' => $failCount,
            'warn_count' => $warnCount,
            'items' => $items,
        ];
    }

    /**
     * @return array{key: string, result: string, detail: string}
     */
    private function item(string $key, string $result, string $detail): array
    {
        return ['key' => $key, 'result' => $result, 'detail' => $detail];
    }
}
