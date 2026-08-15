<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SiteSync\Services\Reconciliation;

use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\SiteSync\Models\SeoArticleRemoteSnapshot;
use Omnichannel\Addons\SiteSync\Services\Capability\SiteCapabilityResolver;
use Omnichannel\Addons\SiteSync\Services\Contracts\SiteSyncSchema;
use Omnichannel\Addons\SiteSync\Services\Inbound\WordPressSiteSyncClient;
use Omnichannel\Addons\SiteSync\Services\Orchestration\RunSiteSyncOrchestrator;
use Omnichannel\Addons\SiteSync\Services\Orchestration\SiteSyncFeatureFlags;
use Omnichannel\Addons\SiteSync\Services\Orchestration\SiteSyncLockService;
use App\Models\Site;
use App\Support\RuntimeLogger;
use Illuminate\Support\Facades\Http;

final class SiteSyncReconciliationService
{
    public const MODE_QUICK = 'quick';

    public const MODE_STANDARD = 'standard';

    public const MODE_FULL_REBUILD = 'full_rebuild';

    public function __construct(
        private readonly SiteSyncFeatureFlags $flags,
        private readonly SiteSyncLockService $locks,
        private readonly WordPressSiteSyncClient $client,
        private readonly SiteCapabilityResolver $capabilities,
    ) {}

    private function orchestrator(): RunSiteSyncOrchestrator
    {
        return app(RunSiteSyncOrchestrator::class);
    }

    /**
     * @return array{success: bool, message: string, drift?: array<string, mixed>, run_id?: int}
     */
    public function reconcile(Site $site, string $mode = self::MODE_STANDARD): array
    {
        if (! $this->flags->reconciliationEnabled()) {
            return ['success' => false, 'message' => 'Reconciliation disabled.'];
        }

        if ($this->locks->isLocked($site)) {
            return ['success' => false, 'message' => 'Site sync lock active — skip reconciliation.'];
        }

        $token = $this->locks->acquire($site, 'reconcile', 900);
        if ($token === null) {
            return ['success' => false, 'message' => 'Could not acquire reconcile lock.'];
        }

        try {
            if ($mode === self::MODE_FULL_REBUILD) {
                $result = $this->orchestrator()->start($site, [
                    'force_snapshot' => true,
                    'mode' => SiteSyncSchema::MODE_SNAPSHOT,
                    'trigger_source' => 'reconcile',
                ]);

                return [
                    'success' => (bool) ($result['success'] ?? false),
                    'message' => (string) ($result['message'] ?? ''),
                    'run_id' => isset($result['run_id']) ? (int) $result['run_id'] : null,
                ];
            }

            $manifest = $this->fetchLightweightManifest($site);
            if (! ($manifest['success'] ?? false)) {
                return ['success' => false, 'message' => (string) ($manifest['message'] ?? 'manifest failed')];
            }

            /** @var list<array<string, mixed>> $entries */
            $entries = $manifest['entries'] ?? [];
            $drift = $this->detectDrift($site, $entries, $mode);

            if ($drift['changed_ids'] !== []) {
                $this->orchestrator()->start($site, [
                    'mode' => SiteSyncSchema::MODE_DELTA,
                    'trigger_source' => 'reconcile',
                    'steps' => [
                        'detect_capability',
                        'request_snapshot_delta',
                        'sync_url_catalog',
                        'sync_provider_keywords',
                        'missing_capability_fallback',
                        'finalize',
                    ],
                    'meta' => ['reconcile_changed_ids' => $drift['changed_ids']],
                ]);
            }

            $cap = $this->capabilities->forSite($site);
            if ($cap === null) {
                $this->orchestrator()->start($site, [
                    'steps' => ['detect_capability', 'finalize'],
                    'trigger_source' => 'reconcile_capability',
                ]);
            }

            return [
                'success' => true,
                'message' => 'Reconciliation complete.',
                'drift' => $drift,
            ];
        } catch (\Throwable $e) {
            RuntimeLogger::warning('site_sync.reconcile_failed', [
                'site_id' => $site->id,
                'error' => $e->getMessage(),
            ]);

            return ['success' => false, 'message' => $e->getMessage()];
        } finally {
            $this->locks->release($site, $token);
        }
    }

    /**
     * @return array{success: bool, message: string, entries?: list<array<string, mixed>>}
     */
    private function fetchLightweightManifest(Site $site): array
    {
        $site->loadMissing('metas');
        $token = trim((string) ($site->getMeta('seo_read_token') ?? ''));
        $domain = trim((string) $site->domain);
        if ($token === '' || $domain === '') {
            return ['success' => false, 'message' => 'Missing token/domain'];
        }
        $base = preg_match('#^https?://#i', $domain) === 1
            ? rtrim($domain, '/')
            : 'https://'.ltrim($domain, '/');

        try {
            $response = Http::timeout(60)
                ->acceptJson()
                ->withToken($token)
                ->get($base.'/wp-json/omi-seo-ai/v1/sync/v2/manifest');
            if (! $response->successful()) {
                return ['success' => false, 'message' => 'manifest HTTP '.$response->status()];
            }
            $json = $response->json();
            $entries = is_array($json['manifest']['entries'] ?? null)
                ? $json['manifest']['entries']
                : [];

            return ['success' => true, 'message' => 'ok', 'entries' => $entries];
        } catch (\Throwable $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * @param  list<array<string, mixed>>  $entries
     * @return array{missing_local: int, missing_remote: int, hash_mismatch: int, changed_ids: list<int>}
     */
    private function detectDrift(Site $site, array $entries, string $mode): array
    {
        $wpIds = [];
        $changed = [];
        $hashMismatch = 0;

        foreach ($entries as $entry) {
            $wpId = (int) ($entry['wordpress_id'] ?? 0);
            if ($wpId <= 0) {
                continue;
            }
            $wpIds[] = $wpId;
            $local = SeoArticle::withTrashed()
                ->where('site_id', (int) $site->id)
                ->whereWpPostId($wpId)
                ->first();
            if ($local === null) {
                $changed[] = $wpId;
                continue;
            }
            $snap = SeoArticleRemoteSnapshot::query()
                ->where('site_id', (int) $site->id)
                ->where('wordpress_id', $wpId)
                ->first();
            $remoteHash = (string) ($entry['content_hash'] ?? '');
            $localHash = (string) ($snap?->content_hash ?? '');
            if ($remoteHash !== '' && $localHash !== '' && ! hash_equals($localHash, $remoteHash)) {
                $hashMismatch++;
                $changed[] = $wpId;
            } elseif ($mode === self::MODE_STANDARD && $snap === null) {
                $changed[] = $wpId;
            }
        }

        $localCount = SeoArticle::query()->where('site_id', (int) $site->id)->hasWpPostId()->count();
        $missingRemote = 0;
        if ($mode !== self::MODE_QUICK) {
            $locals = SeoArticle::query()
                ->leftJoin('wordpress_article_links as wal_recon', 'wal_recon.article_id', '=', 'articles.id')
                ->where('articles.site_id', (int) $site->id)
                ->where('wal_recon.wp_post_id', '>', 0)
                ->pluck('wal_recon.wp_post_id')
                ->map(static fn ($id): int => (int) $id)
                ->all();
            foreach ($locals as $id) {
                if (! in_array($id, $wpIds, true)) {
                    $missingRemote++;
                }
            }
        }

        return [
            'missing_local' => count(array_unique(array_filter(
                $changed,
                static fn (int $id): bool => ! SeoArticle::query()
                    ->where('site_id', (int) $site->id)
                    ->whereWpPostId($id)
                    ->exists()
            ))),
            'missing_remote' => $missingRemote,
            'hash_mismatch' => $hashMismatch,
            'changed_ids' => array_values(array_unique($changed)),
            'remote_total' => count($wpIds),
            'local_total' => $localCount,
        ];
    }
}
