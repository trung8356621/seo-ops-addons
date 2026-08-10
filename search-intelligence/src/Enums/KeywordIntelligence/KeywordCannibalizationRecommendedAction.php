<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Enums\KeywordIntelligence;

/**
 * Hành động đề xuất để xử lý một cannibalization issue.
 */
enum KeywordCannibalizationRecommendedAction: string
{
    case MapToExisting = 'map_to_existing';
    case MergeKeywords = 'merge_keywords';
    case MergeClusters = 'merge_clusters';
    case DifferentiateIntent = 'differentiate_intent';
    case ChangePrimaryKeyword = 'change_primary_keyword';
    case RewriteExisting = 'rewrite_existing';
    case CreateNewPage = 'create_new_page';
    case ExcludeKeyword = 'exclude_keyword';
    case KeepAsIs = 'keep_as_is';
    case ManualReview = 'manual_review';
}
