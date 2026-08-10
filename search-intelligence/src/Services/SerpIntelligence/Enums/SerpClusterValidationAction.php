<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Services\SerpIntelligence\Enums;

enum SerpClusterValidationAction: string
{
    case KeepCluster = 'keep_cluster';
    case SplitCluster = 'split_cluster';
    case ReviewKeyword = 'review_keyword';
    case ChangePrimaryKeyword = 'change_primary_keyword';
    case DifferentiateLocation = 'differentiate_location';
    case DifferentiatePageType = 'differentiate_page_type';
    case InsufficientData = 'insufficient_data';
    case RemoveOutlier = 'remove_outlier';
    case MergeWithCluster = 'merge_with_cluster';
    case ResampleSerp = 'resample_serp';
    case NoAction = 'no_action';
}
