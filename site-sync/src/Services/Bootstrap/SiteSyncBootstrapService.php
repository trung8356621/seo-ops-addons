<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SiteSync\Services\Bootstrap;

use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\SiteSync\Models\SeoSiteSyncRun;
use Omnichannel\Addons\SiteSync\Services\Contracts\SiteSyncSchema;
use Omnichannel\Addons\SiteSync\Services\Inbound\WordPressSiteSyncClient;
use Omnichannel\Addons\SiteSync\Services\Orchestration\RunSiteSyncOrchestrator;
use Omnichannel\Addons\SiteSync\Services\Orchestration\RunSiteSyncV3Orchestrator;
use Omnichannel\Addons\SiteSync\Services\Orchestration\SiteSyncFeatureFlags;
use Omnichannel\Addons\SiteSync\Services\Orchestration\SiteSyncProtocolRouter;
use Omnichannel\Addons\SiteSync\Services\Presentation\SiteSyncSourceLabelPresenter;
use Omnichannel\Addons\SiteSync\Services\Support\SiteSyncInfrastructure;
use Omnichannel\Addons\SiteSync\Services\Support\SiteSyncSiteMeta;
use App\Models\Site;
use App\Support\RuntimeLogger;

/**
 * First-time Site Sync V2 bootstrap — queue-driven snapshot, not one mega request.
 */
final class SiteSyncBootstrapService
{
    public const BATCH_SIZE = 50;

    public function __construct(
        private readonly SiteSyncFeatureFlags $flags,
        private readonly WordPressSiteSyncClient $client,
        private readonly SiteSyncSourceLabelPresenter $labels,
    ) {}

    public function needsBootstrap(Site $site): bool
    {
        $bootstrappedAt = trim((string) ($site->getMeta(SiteSyncSchema::META_BOOTSTRAPPED_AT) ?? ''));
        if ($bootstrappedAt !== '') {
            return false;
        }

        if (! SiteSyncInfrastructure::tablesReady()) {
            return true;
        }

        return ! SeoSiteSyncRun::query()
            ->where('site_id', (int) $site->id)
            ->where('status', 'completed')
            ->where('meta->bootstrap', true)
            ->exists();
    }

    /**
     * @return array<string, mixed>
     */
    public function preview(Site $site): array
    {
        RuntimeLogger::warning('seo.site_sync.bootstrap_preview_start', ['site_id' => (int) $site->id]);
        $caps = $this->client->fetchCapabilities($site);
        RuntimeLogger::warning('seo.site_sync.bootstrap_preview_caps', [
            'site_id' => (int) $site->id,
            'success' => (bool) ($caps['success'] ?? false),
            'message' => (string) ($caps['message'] ?? ''),
        ]);
        // summary=1: counts only — tránh Livewire timeout (~27s full manifest).
        $manifest = $this->client->fetchLightweightManifest($site, true);
        RuntimeLogger::warning('seo.site_sync.bootstrap_preview_manifest', [
            'site_id' => (int) $site->id,
            'success' => (bool) ($manifest['success'] ?? false),
            'message' => (string) ($manifest['message'] ?? ''),
            'entries' => is_array($manifest['entries'] ?? null) ? count($manifest['entries']) : 0,
            'totals' => $manifest['totals']['entries'] ?? null,
            'summary' => (bool) ($manifest['summary'] ?? false),
        ]);
        $localArticles = SeoArticle::query()->where('site_id', (int) $site->id)->count();

        $entries = is_array($manifest['entries'] ?? null) ? $manifest['entries'] : [];
        $byType = ['post' => 0, 'page' => 0, 'product' => 0, 'other' => 0];
        if (is_array($manifest['by_type'] ?? null) && $manifest['by_type'] !== []) {
            foreach ($manifest['by_type'] as $type => $count) {
                $key = (string) $type;
                if (! isset($byType[$key])) {
                    $key = 'other';
                }
                $byType[$key] += (int) $count;
            }
        } else {
            foreach ($entries as $row) {
                $type = (string) ($row['post_type'] ?? $row['type'] ?? 'other');
                if (! isset($byType[$type])) {
                    $type = 'other';
                }
                $byType[$type]++;
            }
        }

        $remoteCount = (int) ($manifest['totals']['entries'] ?? 0);
        if ($remoteCount <= 0) {
            $remoteCount = count($entries);
        }
        if ($remoteCount <= 0) {
            $remoteCount = array_sum($byType);
        }

        $provider = (string) ($caps['manifest']?->capabilities['seo_metadata']['provider'] ?? 'none');
        $bridge = (string) ($caps['manifest']?->bridgeVersion ?? '');
        $compatible = $bridge === ''
            || version_compare($bridge, SiteSyncSchema::MIN_BRIDGE_VERSION, '>=');
        $estimatedBatches = max(1, (int) ceil(max($remoteCount, 1) / self::BATCH_SIZE));

        $warnings = [];
        if (! ($caps['success'] ?? false)) {
            $warnings[] = 'Capability endpoint không phản hồi: '.((string) ($caps['message'] ?? ''));
        }
        if (! $compatible) {
            $warnings[] = 'Plugin cần nâng cấp (≥ '.SiteSyncSchema::MIN_BRIDGE_VERSION.')';
        }
        if (! ($manifest['success'] ?? false)) {
            $warnings[] = 'Lightweight manifest thất bại: '.((string) ($manifest['message'] ?? ''));
        }
        if ($provider === 'none' || $provider === '') {
            $warnings[] = 'Không phát hiện plugin SEO — sẽ dùng SEO Workspace fallback';
        }

        return [
            'needs_bootstrap' => $this->needsBootstrap($site),
            'articles_local' => $localArticles,
            'articles_remote' => $remoteCount,
            'by_type' => $byType,
            'urls_estimated' => $remoteCount,
            'provider' => $provider,
            'provider_label' => $this->labels->providerLabel($provider),
            'provider_version' => (string) ($caps['manifest']?->capabilities['seo_metadata']['provider_version'] ?? ''),
            'bridge_version' => $bridge,
            'contract' => SiteSyncSchema::VERSION,
            'compatible' => $compatible,
            'workspace_fallback' => $this->flags->workspaceFallbackEnabled()
                && ($provider === 'none' || $provider === ''),
            'estimated_batches' => $estimatedBatches,
            'batch_size' => self::BATCH_SIZE,
            'manual_preserved' => [
                'tone', 'cta', 'short_description', 'manual_links', 'manual_keywords', 'exclusions',
            ],
            'warnings' => $warnings,
            'capabilities' => $caps['manifest']?->capabilities ?? [],
            'summary_only' => (bool) ($manifest['summary'] ?? false),
        ];
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array{success: bool, message: string, run_id?: int, public_ref?: string, preview?: array<string, mixed>}
     */
    public function start(Site $site, array $options = []): array
    {
        if (! $this->flags->orchestratorEnabled() && ! $this->flags->protocolV3Enabled()) {
            return ['success' => false, 'message' => 'Site Sync disabled.'];
        }

        $preview = $this->preview($site);
        if (! ($preview['compatible'] ?? false) && ! ($options['force'] ?? false)) {
            return [
                'success' => false,
                'message' => 'Plugin/contract không tương thích — nâng cấp bridge trước.',
                'preview' => $preview,
            ];
        }

        if ($this->flags->protocolV3Enabled() && app(SiteSyncProtocolRouter::class)->shouldUseV3($site)) {
            $result = app(RunSiteSyncV3Orchestrator::class)->start($site, [
                'mode' => SiteSyncSchema::MODE_FORCE_FULL,
                'force_full' => true,
                'trigger_source' => (string) ($options['trigger_source'] ?? 'bootstrap'),
                'triggered_by' => $options['triggered_by'] ?? null,
                'sync' => (bool) ($options['sync'] ?? false),
                'meta' => [
                    'bootstrap' => true,
                    'preview' => [
                        'articles_remote' => $preview['articles_remote'],
                        'estimated_batches' => $preview['estimated_batches'],
                        'provider' => $preview['provider'],
                    ],
                ],
            ]);
        } else {
            $result = app(RunSiteSyncOrchestrator::class)->start($site, [
                'mode' => SiteSyncSchema::MODE_SNAPSHOT,
                'force_snapshot' => true,
                'trigger_source' => (string) ($options['trigger_source'] ?? 'bootstrap'),
                'triggered_by' => $options['triggered_by'] ?? null,
                'sync' => (bool) ($options['sync'] ?? false),
                'meta' => [
                    'bootstrap' => true,
                    'preview' => [
                        'articles_remote' => $preview['articles_remote'],
                        'estimated_batches' => $preview['estimated_batches'],
                        'provider' => $preview['provider'],
                    ],
                ],
            ]);
        }

        if ($result['success'] ?? false) {
            // Stamp empty until finalize succeeds — needsBootstrap still true until markBootstrapped.
            SiteSyncSiteMeta::put($site, SiteSyncSchema::META_BOOTSTRAPPED_AT, '');
        }

        return array_merge($result, ['preview' => $preview]);
    }

    public function markBootstrapped(Site $site): void
    {
        SiteSyncSiteMeta::put($site, SiteSyncSchema::META_BOOTSTRAPPED_AT, now()->toIso8601String());
    }
}
