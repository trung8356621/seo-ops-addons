<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Support;

final class SerpProviderKeys
{
    public const SERPER = 'serper';

    public const SERPAPI = 'serpapi';

    public const SEARCHAPI = 'searchapi';

    /**
     * @return list<string>
     */
    public static function all(): array
    {
        return [self::SERPER, self::SERPAPI, self::SEARCHAPI];
    }

    public static function isValid(?string $key): bool
    {
        return in_array($key, self::all(), true);
    }

    public static function label(string $key): string
    {
        return match ($key) {
            self::SERPER => __('seo-content-ai::filament.api_connections.provider_serper'),
            self::SERPAPI => __('seo-content-ai::filament.api_connections.provider_serpapi'),
            self::SEARCHAPI => __('seo-content-ai::filament.api_connections.provider_searchapi'),
            default => $key,
        };
    }
}
