<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Enums;

enum PerformanceHubSectionKey: string
{
    case GscKpis = 'gsc_kpis';
    case GscChart = 'gsc_chart';
    case GscQueries = 'gsc_queries';
    case RankKpis = 'rank_kpis';
    case RankDistribution = 'rank_distribution';
    case RankingsTable = 'rankings_table';
    case AllintitleMetric = 'allintitle_metric';
    case KeywordMetricsTable = 'keyword_metrics_table';
    case KeywordTrend = 'keyword_trend';
    case SerpChanges = 'serp_changes';
    case OrganicVisibility = 'organic_visibility';
    case ProviderComparison = 'provider_comparison';
    case IntegrationState = 'integration_state';
}
