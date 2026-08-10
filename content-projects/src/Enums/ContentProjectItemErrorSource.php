<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Enums;

enum ContentProjectItemErrorSource: string
{
    case None = 'none';
    case Generation = 'generation';
    case Publish = 'publish';
    case Execution = 'execution';
}
