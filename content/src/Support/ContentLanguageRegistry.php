<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Content\Support;

/**
 * SEO-Ops supported content languages (non-Polylang / global defaults).
 *
 * Polylang domains use synced languages instead — but still emit the same
 * canonical ISO 639-1 lowercase codes.
 */
final class ContentLanguageRegistry
{
    /** @var list<string> */
    public const SUPPORTED_CODES = ['vi', 'en'];

    /**
     * @return list<string>
     */
    public static function codes(): array
    {
        return self::SUPPORTED_CODES;
    }

    public static function isSupported(string $code): bool
    {
        $normalized = ArticleLanguageCode::normalize($code);

        return $normalized !== '' && in_array($normalized, self::SUPPORTED_CODES, true);
    }

    /**
     * UI select options: canonical code => translated label.
     *
     * @return array<string, string>
     */
    public static function selectOptions(): array
    {
        $out = [];
        foreach (self::SUPPORTED_CODES as $code) {
            $out[$code] = self::label($code);
        }

        return $out;
    }

    public static function label(string $code): string
    {
        $normalized = ArticleLanguageCode::normalize($code);
        if ($normalized === '') {
            return '—';
        }

        $key = 'seo-content-ai::filament.content_language.'.$normalized;
        try {
            if (function_exists('__')) {
                $translated = __($key);
                if (is_string($translated) && $translated !== '' && $translated !== $key) {
                    return $translated;
                }
            }
        } catch (\Throwable) {
            // fall through
        }

        return ArticleLanguageCode::defaultLabels()[$normalized] ?? ArticleLanguageCode::label($normalized);
    }

    public static function defaultCode(): string
    {
        return 'vi';
    }
}
