<?php

declare(strict_types=1);

namespace Omnichannel\Addons\WordPress\Services;

use Omnichannel\Addons\Seo\Support\SeoAccessControl;
use App\Models\Site;
use App\Services\ExternalPlugin\ExternalPluginRegistry;
use App\Services\ExternalPlugin\WordPressPluginReleaseService;
use Illuminate\Database\Eloquent\Builder;

final class WordPressPluginDomainsOverviewService
{
    public function __construct(
        private readonly ExternalPluginRegistry $pluginRegistry,
        private readonly WordPressSiteInfoService $siteInfoService,
    ) {}

    /**
     * @return array{
     *     latest_version: ?string,
     *     rows: list<array{
     *         id: int,
     *         domain: string,
     *         installed_version: ?string,
     *         installed_label: string,
     *         latest_version: ?string,
     *         status: string,
     *         status_label: string,
     *         status_color: string,
     *         settings_url: string,
     *         can_auto_update: bool,
     *         action_label: string,
     *     }>,
     * }
     */
    public function overview(): array
    {
        $latestVersion = $this->resolveLatestPublishedVersion();
        $rows = [];

        foreach ($this->visibleSitesQuery()->get() as $site) {
            if (! $site instanceof Site) {
                continue;
            }

            $installedVersion = $this->resolveInstalledVersion($site);
            $status = $this->resolveUpdateStatus($installedVersion, $latestVersion);

            $rows[] = [
                'id' => (int) $site->getKey(),
                'domain' => (string) $site->domain,
                'installed_version' => $installedVersion,
                'installed_label' => $installedVersion ?? '—',
                'latest_version' => $latestVersion,
                'status' => $status,
                'status_label' => $this->statusLabel($status),
                'status_color' => $this->statusColor($status),
                'settings_url' => $this->buildWpPluginSettingsUrl($site),
                'can_auto_update' => false,
                'action_label' => $this->actionLabel($status),
            ];
        }

        return [
            'latest_version' => $latestVersion,
            'rows' => $rows,
        ];
    }

    private function resolveLatestPublishedVersion(): ?string
    {
        $releaseService = $this->releaseService();
        $overview = $releaseService->overview();
        $latest = trim((string) ($overview['latest']['version'] ?? ''));
        if ($latest !== '' && $releaseService->isValidVersion($latest)) {
            return $latest;
        }

        $published = trim((string) ($overview['metadata']['version'] ?? ''));
        if ($published !== '' && $releaseService->isValidVersion($published)) {
            return $published;
        }

        return null;
    }

    private function releaseService(): WordPressPluginReleaseService
    {
        $manifest = $this->pluginRegistry->resolveOrFail('omi-seo-ai-bridge');

        return WordPressPluginReleaseService::forManifest($manifest);
    }

    private function resolveInstalledVersion(Site $site): ?string
    {
        $releaseService = $this->releaseService();
        $siteInfo = $this->siteInfoService->getStoredSiteInfo($site);
        $version = trim((string) ($siteInfo['bridge_version'] ?? ''));

        if ($version === '' || ! $releaseService->isValidVersion($version)) {
            return null;
        }

        return $version;
    }

    private function resolveUpdateStatus(?string $installedVersion, ?string $latestVersion): string
    {
        if ($installedVersion === null) {
            return 'unknown';
        }

        if ($latestVersion === null) {
            return 'unknown';
        }

        if (version_compare($installedVersion, $latestVersion, '>=')) {
            return 'up_to_date';
        }

        return 'needs_update';
    }

    private function statusLabel(string $status): string
    {
        return match ($status) {
            'needs_update' => __('seo-content-ai::filament.wp_plugin.status_needs_update'),
            'up_to_date' => __('seo-content-ai::filament.wp_plugin.status_up_to_date'),
            default => __('seo-content-ai::filament.wp_plugin.status_unknown'),
        };
    }

    private function statusColor(string $status): string
    {
        return match ($status) {
            'needs_update' => 'warning',
            'up_to_date' => 'success',
            default => 'gray',
        };
    }

    private function actionLabel(string $status): string
    {
        return match ($status) {
            'needs_update' => __('seo-content-ai::filament.wp_plugin.action_update_on_wp'),
            'up_to_date' => __('seo-content-ai::filament.wp_plugin.action_open_wp_settings'),
            default => __('seo-content-ai::filament.wp_plugin.action_check_on_wp'),
        };
    }

    private function buildWpPluginSettingsUrl(Site $site): string
    {
        $base = $this->buildSiteBaseUrl($site);
        if ($base === '') {
            return '#';
        }

        return $base.'/wp-admin/admin.php?page=omi-seo-ai&view=settings';
    }

    private function buildSiteBaseUrl(Site $site): string
    {
        $domain = trim((string) $site->domain);
        if ($domain === '') {
            return '';
        }

        if (preg_match('#^https?://#i', $domain)) {
            return rtrim($domain, '/');
        }

        $scheme = ! empty($site->ssl) ? 'https' : 'http';

        return $scheme.'://'.rtrim($domain, '/');
    }

    /**
     * @return Builder<Site>
     */
    private function visibleSitesQuery(): Builder
    {
        $query = Site::query()->orderBy('domain');

        if (SeoAccessControl::shouldScopeToAccountOwner()) {
            $ownerId = SeoAccessControl::accountOwnerId() ?? (int) auth()->id();
            $query->where('user_id', $ownerId);
        }

        return $query;
    }
}
