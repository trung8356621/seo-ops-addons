<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Services\SiteContext\Readers;

use App\Models\Site;
use Omnichannel\Addons\Seo\Services\Context\ContextFreshness;
use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\SiteSync\Services\Heartbeat\WordPressHeartbeatPollService;
use Omnichannel\Addons\SiteSync\Services\Support\SiteSyncSiteMeta;
use Omnichannel\Addons\SiteSync\Models\SeoSiteSyncRun;
use Omnichannel\Addons\SiteSync\Services\Support\SiteSyncInfrastructure;
use Illuminate\Support\Facades\Schema;

/**
 * Site Sync + WordPress heartbeat freshness for Site Intelligence Context.
 *
 * Not Site Knowledge Profile (tone/CTA/links) — see search-foundation SiteMcp*.
 */
final class SiteSyncContextReader
{
    /**
     * @return array<string, mixed>
     */
    public function heartbeat(Site $site): array
    {
        return $this->jsonMeta($site, WordPressHeartbeatPollService::META_KEY);
    }

    public function lastSyncAt(int $siteId): ?string
    {
        $articleSync = null;
        $schema = Schema::connection('omi_seo_ai');
        if ($schema->hasTable('articles')) {
            $col = $schema->hasColumn('articles', 'last_synced_at')
                ? 'last_synced_at'
                : ($schema->hasColumn('articles', 'wp_synced_at') ? 'wp_synced_at' : null);
            if ($col !== null) {
                $articleSync = SeoArticle::query()->where('site_id', $siteId)->max($col);
            }
        }
        $runFinished = null;
        if (SiteSyncInfrastructure::tablesReady() && SiteSyncInfrastructure::hasTable('seo_site_sync_runs')) {
            $run = SeoSiteSyncRun::query()->where('site_id', $siteId)->orderByDesc('id')->first();
            $runFinished = $run?->finished_at?->toIso8601String();
        }

        return ContextFreshness::maxIso([
            is_string($articleSync) ? $articleSync : null,
            $runFinished,
        ]);
    }

    /**
     * @param  array<string, mixed>  $heartbeat
     */
    public function healthLabel(array $heartbeat): string
    {
        $status = (string) ($heartbeat['status'] ?? '');

        return match ($status) {
            'ok' => 'healthy',
            'degraded' => 'degraded',
            'error', 'failed' => 'unhealthy',
            default => $status !== '' ? $status : 'unknown',
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function jsonMeta(Site $site, string $key): array
    {
        $decoded = SiteSyncSiteMeta::getJson($site, $key);

        return is_array($decoded) ? $decoded : [];
    }
}
