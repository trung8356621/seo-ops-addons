<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Enums;

enum SeoProviderCapabilityKey: string
{
    case GscPerformance = 'gsc_performance';
    case RankTracking = 'rank_tracking';
    case LiveSerp = 'live_serp';
    case RankHistory = 'rank_history';
    case SerpChanges = 'serp_changes';
    case OrganicVisibility = 'organic_visibility';
    case ProviderComparison = 'provider_comparison';
    case Allintitle = 'allintitle';
    case SearchVolume = 'search_volume';
    case Cpc = 'cpc';
    case Competition = 'competition';
    case KeywordDifficulty = 'keyword_difficulty';
    case MonthlyTrend = 'monthly_trend';
    case RelatedKeywords = 'related_keywords';
    case Location = 'location';
    case Language = 'language';
    case Device = 'device';
    case TargetDomain = 'target_domain';

    public function matrixLabel(): string
    {
        return match ($this) {
            self::RankTracking => __('seo-content-ai::filament.api_connections.capability_rank'),
            self::LiveSerp => __('seo-content-ai::filament.api_connections.capability_live_serp'),
            self::SerpChanges => __('seo-content-ai::filament.api_connections.capability_serp_changes'),
            self::Allintitle => __('seo-content-ai::filament.api_connections.capability_allintitle'),
            self::SearchVolume => __('seo-content-ai::filament.api_connections.capability_volume'),
            self::Cpc => __('seo-content-ai::filament.api_connections.capability_cpc'),
            self::Competition => __('seo-content-ai::filament.api_connections.capability_competition'),
            self::MonthlyTrend => __('seo-content-ai::filament.api_connections.capability_trend'),
            default => $this->value,
        };
    }
}
