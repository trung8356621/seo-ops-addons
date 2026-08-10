<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Enums;

/**
 * Dashboard counter buckets — derived from ContentProjectItemState (Batch D verify).
 * SQL in ContentProjectDashboardStatsService must match ContentProjectItemDashboardBucketMapper.
 */
enum ContentProjectItemDashboardBucket: string
{
    case WaitingAi = 'waiting_ai';
    case AiRunning = 'ai_running';
    case WaitingReview = 'waiting_review';
    case Approved = 'approved';
    case WaitingPublish = 'waiting_publish';
    case Published = 'published';
    case Failed = 'failed';
    case Archived = 'archived';
    case Other = 'other';
}
