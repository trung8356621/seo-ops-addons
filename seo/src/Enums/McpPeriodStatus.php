<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Enums;

enum McpPeriodStatus: string
{
    case Open = 'open';
    case Finalized = 'finalized';

    public function isOpen(): bool
    {
        return $this === self::Open;
    }
}
