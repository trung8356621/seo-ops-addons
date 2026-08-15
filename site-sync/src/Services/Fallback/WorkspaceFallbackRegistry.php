<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SiteSync\Services\Fallback;

use Omnichannel\Addons\SiteSync\Models\SeoSiteLinkCatalog;
use Omnichannel\Addons\SiteSync\Services\Capability\SiteCapabilityResolver;
use Omnichannel\Addons\SiteSync\Services\Contracts\SiteSyncSchema;
use Omnichannel\Addons\SiteSync\Services\Orchestration\SiteSyncFeatureFlags;
use Omnichannel\Addons\SiteSync\Services\Reconciliation\ProviderKeywordReconciler;
use App\Models\Site;
use Illuminate\Support\Facades\Http;

/**
 * Workspace fallbacks when WP capability missing — capability-driven, not plugin-name hardcoded.
 */
final class WorkspaceFallbackRegistry
{
    public function __construct(
        private readonly SiteCapabilityResolver $capabilities,
        private readonly ProviderKeywordReconciler $keywords,
        private readonly SiteSyncFeatureFlags $flags,
    ) {}

    /**
     * @return array{warnings: list<string>, metrics: array<string, mixed>}
     */
    public function runMissing(Site $site): array
    {
        if (! $this->flags->workspaceFallbackEnabled()) {
            return ['warnings' => [], 'metrics' => ['skipped' => true]];
        }
        $warnings = [];
        $metrics = [];
        $missing = $this->capabilities->missingCapabilities($site);

        if (in_array('http_404', $missing, true)) {
            $warnings[] = 'Link health: capability http_404 missing — chạy LinkHealthRun riêng, không HEAD trong Site Sync.';
            $metrics['http_404'] = [
                'source' => SiteSyncSchema::SOURCE_WORKSPACE,
                'skipped' => true,
                'deferred_to' => 'link_health_run',
            ];
        } else {
            $metrics['http_404'] = [
                'source' => $this->capabilities->provider($site, 'http_404') ?? 'provider',
                'skipped' => true,
            ];
        }

        if (in_array('focus_keyword', $missing, true)) {
            $result = $this->runWorkspaceKeywordFallback($site);
            $warnings[] = 'Keywords: Workspace fallback (provider focus_keyword missing)';
            $metrics['focus_keyword'] = $result;
        }

        if (in_array('internal_link', $missing, true)) {
            $warnings[] = 'Link health: capability internal_link missing — dùng LinkHealthRun, không block Site Sync.';
            $metrics['internal_link'] = ['fallback' => 'link_health_run'];
        }

        if (in_array('redirect', $missing, true)) {
            $warnings[] = 'Redirect capability missing — Workspace redirect auditor idle (no HTML parse)';
            $metrics['redirect'] = ['fallback' => 'idle'];
        }

        return ['warnings' => $warnings, 'metrics' => $metrics];
    }

    /**
     * @return array{checked: int, broken: int, source: string}
     */
    private function runHttp404Checker(Site $site): array
    {
        $urls = SeoSiteLinkCatalog::query()
            ->forSite((int) $site->id)
            ->where('source', SiteSyncSchema::SOURCE_WORDPRESS)
            ->orderByDesc('updated_at')
            ->limit(50)
            ->pluck('url');

        $checked = 0;
        $broken = 0;
        foreach ($urls as $url) {
            $checked++;
            try {
                $response = Http::timeout(8)->withOptions(['allow_redirects' => true])->head((string) $url);
                if ($response->status() >= 400) {
                    $broken++;
                }
            } catch (\Throwable) {
                $broken++;
            }
        }

        return [
            'checked' => $checked,
            'broken' => $broken,
            'source' => SiteSyncSchema::SOURCE_WORKSPACE,
        ];
    }

    /**
     * Workspace keywords only when provider missing — never overwrite manual.
     *
     * @return array<string, int>
     */
    private function runWorkspaceKeywordFallback(Site $site): array
    {
        $titles = SeoSiteLinkCatalog::query()
            ->forSite((int) $site->id)
            ->where('source', SiteSyncSchema::SOURCE_WORDPRESS)
            ->whereNotNull('title')
            ->orderByDesc('id')
            ->limit(20)
            ->get(['wordpress_id', 'title']);

        $rows = [];
        foreach ($titles as $row) {
            $phrase = trim((string) $row->title);
            if ($phrase === '') {
                continue;
            }
            $rows[] = [
                'wordpress_id' => $row->wordpress_id !== null ? (int) $row->wordpress_id : 0,
                'phrase' => $phrase,
                'source' => SiteSyncSchema::SOURCE_WORKSPACE,
            ];
        }

        $result = $this->keywords->reconcile($site, $rows);

        return [
            'workspace_keywords_generated' => $result['provider_updated'],
            'skipped_manual' => $result['skipped_manual'],
        ];
    }
}
