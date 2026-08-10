<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Enums\Gsc;

enum GscOpportunityType: string
{
    case HighImpressionLowCtr = 'high_impression_low_ctr';
    case NearPageOne = 'near_page_one';
    case PositionDecline = 'position_decline';
    case ClickDecline = 'click_decline';
    case ImpressionGrowth = 'impression_growth';
    case ContentDecay = 'content_decay';
    case QueryCannibalization = 'query_cannibalization';
    case PageCannibalization = 'page_cannibalization';
    case IntentMismatch = 'intent_mismatch';
    case SerpGscMismatch = 'serp_gsc_mismatch';
    case UnmappedQuery = 'unmapped_query';
    case UnmappedPage = 'unmapped_page';
    case NewQueryOpportunity = 'new_query_opportunity';
    case TopicGap = 'topic_gap';
    case ContentProjectUnderperformance = 'content_project_underperformance';
    case ContentProjectWinner = 'content_project_winner';
}
