<?php

declare(strict_types=1);

namespace Omnichannel\Addons\WordPress\Services;

use Omnichannel\Addons\Seo\Support\SeoAccessControl;
use Omnichannel\Addons\SearchFoundation\Filament\Resources\DomainResource;
use App\Models\Site;
use Illuminate\Database\Eloquent\Builder;

final class WordPressPluginDomainsOverviewService
{
    public function __construct(
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
            $siteLatest = $this->resolveSiteLatestVersion($site);
            $status = $this->resolveUpdateStatus($installedVersion, $siteLatest ?? $latestVersion);

            $rows[] = [
                'id' => (int) $site->getKey(),
                'domain' => (string) $site->domain,
                'installed_version' => $installedVersion,
                'installed_label' => $installedVersion ?? '—',
                'latest_version' => $siteLatest ?? $latestVersion,
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
        $latest = null;
        foreach ($this->visibleSitesQuery()->get() as $site) {
            if (! $site instanceof Site) {
                continue;
            }
            $candidate = $this->resolveSiteLatestVersion($site);
            if ($candidate === null) {
                continue;
            }
            if ($latest === null || version_compare($candidate, $latest, '>')) {
                $latest = $candidate;
            }
        }

        return $latest;
    }

    private function resolveSiteLatestVersion(Site $site): ?string
    {
        $update = $this->pluginUpdateMeta($site);
        $latest = trim((string) ($update['latest_version'] ?? ''));

        return $this->isValidVersion($latest) ? $latest : null;
    }

    private function resolveInstalledVersion(Site $site): ?string
    {
        $update = $this->pluginUpdateMeta($site);
        $version = trim((string) ($update['installed_version'] ?? ''));
        if ($this->isValidVersion($version)) {
            return $version;
        }

        $siteInfo = $this->siteInfoService->getStoredSiteInfo($site);
        $version = trim((string) ($siteInfo['bridge_version'] ?? ''));

        return $this->isValidVersion($version) ? $version : null;
    }

    /**
     * @return array<string, mixed>
     */
    private function pluginUpdateMeta(Site $site): array
    {
        $raw = trim((string) ($site->getMeta(WordPressPluginUpdateService::META_KEY) ?? ''));
        if ($raw === '') {
            return [];
        }
        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : [];
    }

    private function isValidVersion(string $version): bool
    {
        return $version !== '' && preg_match('/^\d+\.\d+(\.\d+)?$/', $version) === 1;
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
            'needs_update' => __('seo-content-ai::filament.wp_plugin.action_open_site_health'),
            'up_to_date' => __('seo-content-ai::filament.wp_plugin.action_open_site_health'),
            default => __('seo-content-ai::filament.wp_plugin.action_open_site_health'),
        };
    }

    private function buildWpPluginSettingsUrl(Site $site): string
    {
        try {
            return DomainResource::getUrl('general', ['record' => $site]);
        } catch (\Throwable) {
            return '#';
        }
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
        $query = Site::query()->with('metas')->orderBy('domain');

        if (SeoAccessControl::shouldScopeToAccountOwner()) {
            $ownerId = SeoAccessControl::accountOwnerId() ?? (int) auth()->id();
            $query->where('user_id', $ownerId);
        }

        return $query;
    }
}
