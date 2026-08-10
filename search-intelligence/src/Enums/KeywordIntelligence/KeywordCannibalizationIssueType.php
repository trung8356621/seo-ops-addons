<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Enums\KeywordIntelligence;

/**
 * Phân loại rủi ro cannibalization (Phase 2).
 * C1: 1 keyword trỏ current_content tới nhiều bài viết khác nhau.
 * C2: nhiều keyword trong cùng 1 cluster trỏ tới nhiều bài viết khác nhau.
 * C3: 1 bài viết nhận primary keyword mapping từ nhiều cluster khác nhau.
 * C4: cùng keyword vừa có planned_target vừa có current_content (định làm bài mới trong khi đã có bài).
 * C5: 2 primary keyword của 2 cluster khác nhau bị near-duplicate (cùng ý định, khác cluster).
 * C6: nhiều mapping thủ công (is_manual) mâu thuẫn nhau trên cùng keyword.
 */
enum KeywordCannibalizationIssueType: string
{
    case SameKeywordMultiArticle = 'c1_same_keyword_multi_article';
    case ClusterMultiArticle = 'c2_cluster_multi_article';
    case MultiClusterSameArticle = 'c3_multi_cluster_same_article';
    case PlannedVsExisting = 'c4_planned_vs_existing';
    case NearPrimaryConflict = 'c5_near_primary_conflict';
    case ManualMappingConflict = 'c6_manual_mapping_conflict';
}
