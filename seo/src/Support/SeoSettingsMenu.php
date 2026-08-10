<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Support;

use Omnichannel\Addons\AiPrompt\Filament\Pages\SeoSettingsAiAdvanced;
use Omnichannel\Addons\Seo\Filament\Pages\SeoSettingsDateTime;
use Omnichannel\Addons\Seo\Filament\Pages\SeoSettingsEditor;
use Omnichannel\Addons\SearchIntelligence\Filament\Pages\SeoSettingsKeywords;
use Omnichannel\Addons\Seo\Filament\Pages\SeoSettingsOverview;
use Omnichannel\Addons\AiPrompt\Filament\Pages\SeoSettingsPrompt;
use Omnichannel\Addons\Seo\Filament\Pages\SeoSettingsRecommendations;
use Omnichannel\Addons\Seo\Filament\Pages\SeoSettingsScoring;
use Omnichannel\Addons\Seo\Filament\Pages\SeoSettingsWorkflows;
use Omnichannel\Addons\AiPrompt\Filament\Resources\AiConnectionResource;

final class SeoSettingsMenu
{
    /**
     * @return list<array{id: string, label: string, icon: string, url: string}>
     */
    public static function items(): array
    {
        return [
            [
                'id' => 'overview',
                'label' => 'Overview',
                'icon' => 'heroicon-o-home',
                'url' => SeoSettingsOverview::getUrl(),
            ],
            [
                'id' => 'date-time',
                'label' => 'Date & Time',
                'icon' => 'heroicon-o-clock',
                'url' => SeoSettingsDateTime::getUrl(),
            ],
            [
                'id' => 'workflows',
                'label' => 'Workflows',
                'icon' => 'heroicon-o-arrows-right-left',
                'url' => SeoSettingsWorkflows::getUrl(),
            ],
            [
                'id' => 'ai-advanced',
                'label' => 'AI Advanced',
                'icon' => 'heroicon-o-cpu-chip',
                'url' => SeoSettingsAiAdvanced::getUrl(),
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
                'id' => 'prompt',
                'label' => 'Prompt settings',
                'icon' => 'heroicon-o-chat-bubble-left-ellipsis',
                'url' => SeoSettingsPrompt::getUrl(),
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
        ];
    }
}
