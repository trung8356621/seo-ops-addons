<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchFoundation\Enums;

enum KeywordMetricStatus: string
{
    case Pending = 'pending';
    case Running = 'running';
    case Success = 'success';
    case NotFound = 'not_found';
    case NotSupported = 'not_supported';
    case NotConfigured = 'not_configured';
    case Failed = 'failed';

    public function isTerminal(): bool
    {
        return $this !== self::Pending && $this !== self::Running;
    }
}
