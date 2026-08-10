<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Enums;

/** Generation dimension for a Content Project item. */
enum ContentProjectItemGenerationState: string
{
    case Idle = 'idle';
    case Pending = 'pending';
    case Writing = 'writing';
    case Processing = 'processing';
    case Completed = 'completed';
    case Failed = 'failed';
    case Cancelled = 'cancelled';
}
