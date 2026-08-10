<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SiteSync\Services\Orchestration;

use Omnichannel\Addons\SiteSync\Models\SeoSiteCapability;
use Omnichannel\Addons\SiteSync\Models\SeoSiteSyncInboundEvent;
use Omnichannel\Addons\SiteSync\Models\SeoSiteSyncRun;
use Omnichannel\Addons\SiteSync\Services\Contracts\SiteSyncSchema;
use Omnichannel\Addons\SiteSync\Services\Support\SiteSyncSiteMeta;
use App\Models\Site;

final class SiteSyncCutoverReadinessService
{
    public function __construct(
        private readonly SiteSyncFeatureFlags $flags,
    ) {}

    /**
     * @return array{status: string, score: int, checks: list<array{key: string, ok: bool, detail: string}>, recommendation: string}
     */
    public function evaluate(Site $site): array
    {
        $checks = [];
        $site->loadMissing('metas');

        $pluginInfo = $site->getMeta('seo_wp_plugin_info');
        $decoded = is_string($pluginInfo) ? json_decode($pluginInfo, true) : (is_array($pluginInfo) ? $pluginInfo : []);
        $bridge = (string) ($decoded['bridge_version'] ?? '');
        $checks[] = [
            'key' => 'plugin_version',
            'ok' => version_compare($bridge !== '' ? $bridge : '0.0.0', SiteSyncSchema::MIN_BRIDGE_VERSION, '>='),
            'detail' => $bridge !== '' ? $bridge : 'unknown',
        ];

        $cap = SeoSiteCapability::query()->where('site_id', (int) $site->id)->first();
        $checks[] = [
            'key' => 'capability_endpoint',
            'ok' => $cap !== null,
            'detail' => $cap ? 'manifest stored' : 'missing',
        ];

        $handshake = SiteSyncSiteMeta::getJson($site, SiteSyncSchema::META_HANDSHAKE);
        $handshakeOk = in_array((string) ($handshake['status'] ?? ''), ['healthy', 'degraded'], true);
        $checks[] = [
            'key' => 'callback_healthy',
            'ok' => $handshakeOk || ! $this->flags->requireSignedCallbacks(),
            'detail' => (string) ($handshake['status'] ?? 'not_configured'),
        ];

        $checks[] = [
            'key' => 'signed_callbacks_ready',
            'ok' => ! $this->flags->requireSignedCallbacks()
                || trim((string) ($site->getMeta('seo_sync_callback_secret') ?? '')) !== ''
                || trim((string) ($site->getMeta('seo_read_token') ?? '')) !== '',
            'detail' => $this->flags->requireSignedCallbacks() ? 'required' : 'optional',
        ];

        $bootstrapped = trim((string) ($site->getMeta(SiteSyncSchema::META_BOOTSTRAPPED_AT) ?? '')) !== '';
        $checks[] = [
            'key' => 'bootstrap_completed',
            'ok' => $bootstrapped,
            'detail' => $bootstrapped ? (string) $site->getMeta(SiteSyncSchema::META_BOOTSTRAPPED_AT) : 'none',
        ];

        $backfill = SiteSyncSiteMeta::getJson($site, SiteSyncSchema::META_BACKFILL_REPORT);
        $checks[] = [
            'key' => 'backfill_completed',
            'ok' => is_array($backfill),
            'detail' => is_array($backfill) ? 'report present' : 'not_run',
        ];

        $completed = SeoSiteSyncRun::query()
            ->where('site_id', (int) $site->id)
            ->where('status', 'completed')
            ->orderByDesc('id')
            ->first();
        $checks[] = [
            'key' => 'initial_sync_completed',
            'ok' => $completed !== null,
            'detail' => $completed?->public_ref ?? 'none',
        ];

        $dead = SeoSiteSyncInboundEvent::query()
            ->where('site_id', (int) $site->id)
            ->where('status', SeoSiteSyncInboundEvent::STATUS_DEAD_LETTER)
            ->count();
        $checks[] = [
            'key' => 'no_unresolved_dead_letter',
            'ok' => $dead === 0,
            'detail' => (string) $dead,
        ];

        $recentOk = SeoSiteSyncRun::query()
            ->where('site_id', (int) $site->id)
            ->where('status', 'completed')
            ->where('finished_at', '>=', now()->subDays(7))
            ->exists();
        $checks[] = [
            'key' => 'recent_incremental_success',
            'ok' => $recentOk,
            'detail' => $recentOk ? 'within_7d' : 'stale_or_missing',
        ];

        $checks[] = [
            'key' => 'legacy_dual_auto_disabled',
            'ok' => ! $this->flags->dualRunShadowEnabled() && ! $this->flags->legacyActionsVisible(),
            'detail' => $this->flags->legacyActionsVisible() ? 'legacy UI visible' : 'legacy auto off',
        ];

        $checks[] = [
            'key' => 'emergency_rollback_off',
            'ok' => ! $this->flags->emergencyRollback(),
            'detail' => $this->flags->emergencyRollback() ? 'ON' : 'off',
        ];

        $okCount = count(array_filter($checks, static fn (array $c): bool => $c['ok']));
        $score = (int) round(($okCount / max(1, count($checks))) * 100);
        $failed = array_values(array_filter($checks, static fn (array $c): bool => ! $c['ok']));

        $status = match (true) {
            $failed === [] => 'ready_for_manual_cutover',
            $score >= 70 => 'ready_with_warnings',
            default => 'not_ready',
        };

        return [
            'status' => $status,
            'score' => $score,
            'checks' => $checks,
            'recommendation' => $status,
        ];
    }
}
