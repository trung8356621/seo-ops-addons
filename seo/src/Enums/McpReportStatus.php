<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Enums;

enum McpReportStatus: string
{
    case Ready = 'ready';
    case Incomplete = 'incomplete';
    case Missing = 'missing';
}
