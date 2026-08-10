<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Enums;

enum ContentProjectItemArchiveState: string
{
    case None = 'none';
    case ContentArchived = 'content_archived';
}
