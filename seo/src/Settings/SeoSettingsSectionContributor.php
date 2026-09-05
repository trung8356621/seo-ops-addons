<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Settings;

use App\Core\Settings\SettingsSection;
use App\Core\Settings\SettingsSectionContributor;
use Omnichannel\Addons\SearchIntelligence\Filament\Pages\SeoSettingsKeywords;
use Omnichannel\Addons\Seo\Filament\Pages\SeoSettingsConfigurationTransfer;
use Omnichannel\Addons\Seo\Filament\Pages\SeoSettingsEditor;
use Omnichannel\Addons\Seo\Filament\Pages\SeoSettingsScoring;
use Omnichannel\Addons\Seo\Filament\Pages\SeoSettingsWorkflows;
use Omnichannel\Addons\Seo\Support\SeoStackAvailability;

/**
 * SEO-owned settings sections hosted by the Core Settings shell.
 */
final class SeoSettingsSectionContributor implements SettingsSectionContributor
{
    public function ownerSlug(): string
    {
        return 'seo';
    }

    public function sections(): array
    {
        if (! $this->seoEnabled()) {
            return [];
        }

        return [
            new SettingsSection(
                id: 'workflows',
                label: 'Workflows',
                icon: 'heroicon-o-arrows-right-left',
                url: $this->url(SeoSettingsWorkflows::class, '/admin/settings/workflows'),
                owner: 'seo',
                sort: 20,
                coreShared: false,
            ),
            new SettingsSection(
                id: 'editor',
                label: 'Article editor',
                icon: 'heroicon-o-document-text',
                url: $this->url(SeoSettingsEditor::class, '/admin/settings/editor'),
                owner: 'seo',
                sort: 40,
                coreShared: false,
            ),
            new SettingsSection(
                id: 'keywords',
                label: 'Keywords',
                icon: 'heroicon-o-key',
                url: $this->url(SeoSettingsKeywords::class, '/admin/settings/keywords'),
                owner: 'seo',
                sort: 50,
                coreShared: false,
            ),
            new SettingsSection(
                id: 'scoring',
                label: 'SEO scoring',
                icon: 'heroicon-o-chart-bar',
                url: $this->url(SeoSettingsScoring::class, '/admin/settings/scoring'),
                owner: 'seo',
                sort: 70,
                coreShared: false,
            ),
            new SettingsSection(
                id: 'import-export',
                label: 'Import / Export',
                icon: 'heroicon-o-arrow-down-tray',
                url: $this->url(SeoSettingsConfigurationTransfer::class, '/admin/settings/import-export'),
                owner: 'seo',
                sort: 80,
                coreShared: false,
            ),
        ];
    }

    private function seoEnabled(): bool
    {
        return SeoStackAvailability::enabled();
    }

    /**
     * @param  class-string  $class
     */
    private function url(string $class, string $fallback): string
    {
        try {
            return $class::getUrl(panel: 'admin');
        } catch (\Throwable) {
            try {
                return url($fallback);
            } catch (\Throwable) {
                return $fallback;
            }
        }
    }
}
