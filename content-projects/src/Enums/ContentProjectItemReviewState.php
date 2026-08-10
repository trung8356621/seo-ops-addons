<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Enums;

/** Review dimension — mirrors ArticleReviewStatus (+ none when no article). */
enum ContentProjectItemReviewState: string
{
    case None = 'none';
    case Draft = 'draft';
    case PendingReview = 'pending_review';
    case Approved = 'approved';
    case ReviewArchived = 'review_archived';
}
