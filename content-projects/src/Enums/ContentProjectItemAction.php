<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Enums;

/** Canonical item actions — must match ContentProjectItemActionGuard. */
enum ContentProjectItemAction: string
{
    case Generate = 'generate';
    case Rerun = 'rerun';
    case StartReview = 'start_review';
    case Approve = 'approve';
    case Schedule = 'schedule';
    case Unschedule = 'unschedule';
    case PublishNow = 'publish_now';
    case RetryPublish = 'retry_publish';
    case SkipPublish = 'skip_publish';
    case CancelPublish = 'cancel_publish';
    case Archive = 'archive';
    case SendToPublishingQueue = 'send_to_publishing_queue';
    case ReturnToContentProject = 'return_to_content_project';
}
