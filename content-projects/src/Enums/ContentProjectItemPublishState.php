<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Enums;

/**
 * Publish revision / queue dimension.
 * Published revision stays Published even when a later publish attempt fails.
 */
enum ContentProjectItemPublishState: string
{
    case None = 'none';
    case Scheduled = 'scheduled';
    case Queued = 'queued';
    case Published = 'published';
    case PublishFailed = 'publish_failed';
    case Skipped = 'skipped';
    case Cancelled = 'cancelled';
}
