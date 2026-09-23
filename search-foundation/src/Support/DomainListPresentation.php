<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchFoundation\Support;

use Omnichannel\Addons\Content\Support\SystemDateTime;
use Omnichannel\Addons\SiteSync\Models\SeoSiteSyncRun;
use Omnichannel\Addons\SiteSync\Services\Heartbeat\WordPressHeartbeatPollService;
use Omnichannel\Addons\SiteSync\Services\Support\SiteSyncInfrastructure;
use Omnichannel\Addons\WordPress\Services\WordPressSiteInfoService;
use App\Models\Site;

/**
 * Presentation helpers for Domain list / form labels (UI only — no DB migration).
 *
 * Website type mapping SSOT for agents: docs/modules/WEBSITE_TYPE.md
 * Manufacturer (UI) ↔ production (internal key). Do not rename production casually.
 */
final class DomainListPresentation
{
    /** @var array<int, array{label: string, key: string, last_label: string, last_title: ?string}> */
    private static array $syncSummaryCache = [];

    /**
     * Canonical UI labels; DB may still store legacy values.
     *
     * @return array<string, string> value => label (form options keep legacy keys)
     */
    public static function websiteTypeFormOptions(): array
    {
        return [
            'news' => 'News',
            'production' => 'Manufacturer',
            'e-commerce' => 'Ecommerce',
        ];
    }

    public static function websiteTypeLabel(?string $raw): string
    {
        $key = mb_strtolower(trim((string) $raw));

        return match ($key) {
            'news' => 'News',
            'production', 'manufacturer' => 'Manufacturer',
            'e-commerce', 'ecommerce', 'e_commerce' => 'Ecommerce',
            '' => '—',
            default => ucfirst(str_replace(['_', '-'], ' ', $key)),
        };
    }

    public static function platformLabel(?string $raw): string
    {
        return match (mb_strtolower(trim((string) $raw))) {
            'wordpress', 'wp' => 'WordPress',
            'shopify' => 'Shopify',
            'custom' => 'Custom',
            '' => '—',
            default => ucfirst((string) $raw),
        };
    }

    /**
     * Observed installed Bridge version (not Laravel updater capability).
     *
     * Priority: heartbeat plugin_version → stored site-info bridge_version → null.
     */
    public static function observedBridgeVersion(Site $site): ?string
    {
        $heartbeat = self::jsonMeta($site, WordPressHeartbeatPollService::META_KEY);
        $fromHeartbeat = trim((string) ($heartbeat['plugin_version'] ?? ''));
        if ($fromHeartbeat !== '') {
            return $fromHeartbeat;
        }

        $info = self::jsonMeta($site, WordPressSiteInfoService::META_PLUGIN_INFO);
        $fromInfo = trim((string) ($info['bridge_version'] ?? ''));

        return $fromInfo !== '' ? $fromInfo : null;
    }

    /**
     * @return array{line: string, detail: ?string, title: ?string}
     */
    public static function bridgeVersion(Site $site): array
    {
        $platform = mb_strtolower(trim((string) ($site->getMeta('seo_platform') ?? 'wordpress')));
        if ($platform !== 'wordpress' && $platform !== 'wp') {
            return ['line' => '—', 'detail' => null, 'title' => null];
        }

        $installed = self::observedBridgeVersion($site);
        if ($installed === null) {
            return ['line' => '—', 'detail' => null, 'title' => null];
        }

        return ['line' => $installed, 'detail' => null, 'title' => $installed];
    }

    /**
     * Compact sync column — never expose raw phase keys.
     *
     * @return array{label: string, key: string, last_label: string, last_title: ?string}
     */
    public static function syncSummary(Site $site): array
    {
        $siteId = (int) $site->id;
        if (isset(self::$syncSummaryCache[$siteId])) {
            return self::$syncSummaryCache[$siteId];
        }

        if (! SiteSyncInfrastructure::tablesReady()) {
            return self::$syncSummaryCache[$siteId] = [
                'label' => 'Unavailable',
                'key' => 'unavailable',
                'last_label' => 'Never',
                'last_title' => null,
            ];
        }

        try {
            $run = SeoSiteSyncRun::query()
                ->where('site_id', $siteId)
                ->orderByDesc('id')
                ->first(['id', 'status', 'finished_at', 'updated_at', 'started_at']);
        } catch (\Throwable) {
            return self::$syncSummaryCache[$siteId] = [
                'label' => 'Unavailable',
                'key' => 'unavailable',
                'last_label' => 'Never',
                'last_title' => null,
            ];
        }

        if ($run === null) {
            return self::$syncSummaryCache[$siteId] = [
                'label' => 'Never synced',
                'key' => 'never',
                'last_label' => 'Never',
                'last_title' => null,
            ];
        }

        $status = (string) $run->status;
        $label = match ($status) {
            'completed', 'completed_with_warnings' => 'Healthy',
            'pending', 'running', 'queued' => 'Syncing',
            'needs_attention', 'stuck' => 'Needs attention',
            'failed' => 'Failed',
            'canceled', 'cancelled' => 'Needs attention',
            default => 'Needs attention',
        };

        $at = $run->finished_at ?? $run->updated_at ?? $run->started_at;
        $relative = SystemDateTime::formatRelative($at);
        $absolute = SystemDateTime::formatDateTime($at);

        return self::$syncSummaryCache[$siteId] = [
            'label' => $label,
            'key' => match ($status) {
                'completed', 'completed_with_warnings' => 'healthy',
                'pending', 'running', 'queued' => 'syncing',
                'failed' => 'failed',
                default => 'attention',
            },
            'last_label' => $relative ?? 'Never',
            'last_title' => $absolute,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function jsonMeta(Site $site, string $key): array
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
}
