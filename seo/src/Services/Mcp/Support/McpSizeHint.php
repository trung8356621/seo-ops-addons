<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Services\Mcp\Support;

/**
 * Non-binding relative size hint for AI part selection (not token estimation).
 */
enum McpSizeHint: string
{
    case Small = 'small';
    case Medium = 'medium';
    case Large = 'large';
}
