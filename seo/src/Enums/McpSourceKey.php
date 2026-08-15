<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Enums;

enum McpSourceKey: string
{
    case Site = 'site';
    case Keywords = 'keywords';

    public function schema(): string
    {
        return match ($this) {
            self::Site => 'site.mcp.v1',
            self::Keywords => 'keywords.mcp.v1',
        };
    }
}
