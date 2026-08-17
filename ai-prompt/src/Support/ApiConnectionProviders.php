<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Support;

use Omnichannel\Addons\AiPrompt\Enums\ApiConnectionType;
use Omnichannel\Addons\SearchIntelligence\Services\SeoProviderRegistry;

final class ApiConnectionProviders
{
    public const GEMINI = 'gemini';

    public const CLAUDE = 'claude';

    public const DEEPSEEK = 'deepseek';

    public const OPENROUTER = 'openrouter';

    public const GOOGLE_SEARCH_CONSOLE = 'google_search_console';

    public const DATAFORSEO = 'dataforseo';

    public const SERPER = 'serper';

    public const SERPAPI = 'serpapi';

    public const SEARCHAPI = 'searchapi';

    public const KEYWORDS_EVERYWHERE = 'keywords_everywhere';

    public const SE_RANKING = 'seranking';

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        return app(SeoProviderRegistry::class)->groupedProviderOptions();
    }

    /**
     * @return array<string, string>
     */
    public static function flatOptions(): array
    {
        $options = [];
        foreach (app(SeoProviderRegistry::class)->settingsProviders() as $definition) {
            $options[$definition->key] = $definition->label;
        }

        return $options;
    }

    public static function label(string $provider): string
    {
        if (app(SeoProviderRegistry::class)->has($provider)) {
            return app(SeoProviderRegistry::class)->label($provider);
        }

        return $provider;
    }

    public static function connectionType(string $provider): ApiConnectionType
    {
        return app(SeoProviderRegistry::class)->connectionTypeFor($provider);
    }

    public static function isAi(?string $provider): bool
    {
        if ($provider === null || ! app(SeoProviderRegistry::class)->has($provider)) {
            return false;
        }

        return self::connectionType($provider) === ApiConnectionType::Ai;
    }

    public static function isSeo(?string $provider): bool
    {
        if ($provider === null || ! app(SeoProviderRegistry::class)->has($provider)) {
            return false;
        }

        return self::connectionType($provider) === ApiConnectionType::Seo;
    }

    public static function isExternal(?string $provider): bool
    {
        return in_array($provider, [
            self::GOOGLE_SEARCH_CONSOLE,
            self::DATAFORSEO,
            self::SERPER,
            self::SERPAPI,
            self::SEARCHAPI,
            self::KEYWORDS_EVERYWHERE,
            self::SE_RANKING,
        ], true);
    }

    public static function isSerpProvider(?string $provider): bool
    {
        return in_array($provider, [self::SERPER, self::SERPAPI, self::SEARCHAPI], true);
    }

    public static function isExtendedProvider(?string $provider): bool
    {
        return in_array($provider, [self::KEYWORDS_EVERYWHERE, self::SE_RANKING], true);
    }

    public static function isAggregator(?string $provider): bool
    {
        return in_array($provider, [self::OPENROUTER, 'openai_compatible'], true);
    }
}
