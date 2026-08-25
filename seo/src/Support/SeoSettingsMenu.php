<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Support;

use Omnichannel\Addons\AiPrompt\Filament\Pages\SeoSettingsAiCenter;
use Omnichannel\Addons\AiPrompt\Filament\Resources\AiConnectionResource;
use Omnichannel\Addons\Seo\Filament\Pages\SeoSettingsConfigurationTransfer;
use Omnichannel\Addons\Seo\Filament\Pages\SeoSettingsEditor;
use Omnichannel\Addons\SearchIntelligence\Filament\Pages\SeoSettingsKeywords;
use Omnichannel\Addons\Seo\Filament\Pages\SeoSettingsGeneral;
use Omnichannel\Addons\Seo\Filament\Pages\SeoSettingsRecommendations;
use Omnichannel\Addons\Seo\Filament\Pages\SeoSettingsScoring;
use Omnichannel\Addons\Seo\Filament\Pages\SeoSettingsWorkflows;

final class SeoSettingsMenu
{
    /**
     * @return list<array{id: string, label: string, icon: string, url: string}>
     */
    public static function items(): array
    {
        return [
            [
                'id' => 'general',
                'label' => 'seo-content-ai::filament.settings_general.nav',
                'icon' => 'heroicon-o-cog-6-tooth',
                'url' => SeoSettingsGeneral::getUrl(),
            ],
            [
                'id' => 'workflows',
                'label' => 'Workflows',
                'icon' => 'heroicon-o-arrows-right-left',
                'url' => SeoSettingsWorkflows::getUrl(),
            ],
            [
                'id' => 'ai-center',
                'label' => 'AI Center',
                'icon' => 'heroicon-o-cpu-chip',
                'url' => SeoSettingsAiCenter::getUrl(),
            ],
            [
                'id' => 'editor',
                'label' => 'Article editor',
                'icon' => 'heroicon-o-document-text',
                'url' => SeoSettingsEditor::getUrl(),
            ],
            [
                'id' => 'keywords',
                'label' => 'Keywords',
                'icon' => 'heroicon-o-key',
                'url' => SeoSettingsKeywords::getUrl(),
            ],
            [
                'id' => 'api',
                'label' => 'API Connections',
                'icon' => 'heroicon-o-link',
                'url' => AiConnectionResource::getUrl(),
            ],
            [
                'id' => 'scoring',
                'label' => 'SEO scoring',
                'icon' => 'heroicon-o-chart-bar',
                'url' => SeoSettingsScoring::getUrl(),
            ],
            [
                'id' => 'recommendations',
                'label' => 'Recommendations',
                'icon' => 'heroicon-o-book-open',
                'url' => SeoSettingsRecommendations::getUrl(),
            ],
            [
                'id' => 'import-export',
                'label' => 'Import / Export',
                'icon' => 'heroicon-o-arrow-down-tray',
                'url' => SeoSettingsConfigurationTransfer::getUrl(),
            ],
        ];
    }
}
