<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Support;

final class SerpAllintitleQuery
{
    public static function build(string $keyword): string
    {
        $normalized = trim(preg_replace('/\s+/u', ' ', $keyword) ?? $keyword);
        $escaped = str_replace(['\\', '"'], ['\\\\', '\\"'], $normalized);

        return 'allintitle:"'.$escaped.'"';
    }
}
