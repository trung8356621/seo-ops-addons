<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Enums;

enum McpSnapshotStatus: string
{
    case Current = 'current';
    case Stale = 'stale';
    case Failed = 'failed';
}
