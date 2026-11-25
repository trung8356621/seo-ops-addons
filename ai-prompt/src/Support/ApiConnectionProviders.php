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

    public const NVIDIA = 'nvidia';

    /**
     * Form-only sentinel for “Custom (OpenAI-Compatible)”. Never persist as connection.provider.
     */
    public const CUSTOM_OPENAI = 'custom_openai';

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

        try {
            $details = app(\Omnichannel\Addons\AiPrompt\Services\ProviderTemplates\ProviderConnectionResolver::class)
                ->technicalDetails((int) (auth()->id() ?? 0), $provider);
            $name = trim((string) ($details['provider_name'] ?? ''));
            if ($name !== '') {
                return $name;
            }
        } catch (\Throwable) {
        }

        return $provider;
    }

    /**
     * @return list<string>
     */
    public static function builtinAiKeys(): array
    {
        return [
            self::GEMINI,
            self::CLAUDE,
            self::DEEPSEEK,
            self::OPENROUTER,
            self::NVIDIA,
        ];
    }

    public static function isCustomFormMode(?string $provider): bool
    {
        return $provider === self::CUSTOM_OPENAI;
    }

    public static function isReservedProviderKey(string $provider): bool
    {
        $key = strtolower(trim($provider));

        return in_array($key, [
            self::CUSTOM_OPENAI,
            'custom',
            'openai_compatible',
            '_custom_openai_compatible',
        ], true) || in_array($key, self::builtinAiKeys(), true);
    }

    public static function isBuiltinAiProvider(?string $provider): bool
    {
        if ($provider === null || $provider === '') {
            return false;
        }

        return in_array(strtolower(trim($provider)), self::builtinAiKeys(), true);
    }

    /**
     * User-defined OpenAI-compatible provider (template-backed, not a built-in AI key).
     */
    public static function isCustomOpenAiProvider(?string $provider): bool
    {
        if ($provider === null || $provider === '' || self::isCustomFormMode($provider) || self::isBuiltinAiProvider($provider)) {
            return false;
        }
        if (self::isExternal($provider) || self::isSeo($provider)) {
            return false;
        }

        try {
            return app(\Omnichannel\Addons\AiPrompt\Services\ProviderTemplates\ProviderConnectionResolver::class)
                ->hasTemplate((int) (auth()->id() ?? 0), $provider);
        } catch (\Throwable) {
            return false;
        }
    }

    public static function connectionType(string $provider): ApiConnectionType
    {
        $registry = app(SeoProviderRegistry::class);

        return $registry->has($provider)
            ? $registry->connectionTypeFor($provider)
            : ApiConnectionType::Ai;
    }

    public static function isAi(?string $provider): bool
    {
        if ($provider === null || trim($provider) === '') {
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

    /**
     * True when PromptRunner may use the OpenAI-compatible adapter without a registered extension key.
     */
    public static function allowsTemplateBackedText(?string $provider, ?int $userId = null): bool
    {
        if ($provider === null || trim($provider) === '') {
            return false;
        }
        $userId = $userId ?? (int) (auth()->id() ?? 0);

        try {
            return app(\Omnichannel\Addons\AiPrompt\Services\ProviderTemplates\ProviderConnectionResolver::class)
                ->hasTemplate($userId, $provider);
        } catch (\Throwable) {
            return false;
        }
    }
}
