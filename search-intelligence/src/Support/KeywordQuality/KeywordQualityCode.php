<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Support\KeywordQuality;

final class KeywordQualityCode
{
    public const CTA_ANCHOR = 'CTA_ANCHOR';

    public const GENERIC_ANCHOR = 'GENERIC_ANCHOR';

    public const MALFORMED_TEXT = 'MALFORMED_TEXT';

    public const ENCODING_ERROR = 'ENCODING_ERROR';

    public const SENTENCE_LIKE = 'SENTENCE_LIKE';

    public const DUPLICATE_VARIANT = 'DUPLICATE_VARIANT';

    public const TRAILING_SEQUENCE_NUMBER = 'TRAILING_SEQUENCE_NUMBER';

    public const LANGUAGE_MISMATCH = 'LANGUAGE_MISMATCH';

    public const CLASSIFIER_VERSION = 6;

    /**
     * @return list<string>
     */
    public static function all(): array
    {
        return [
            self::CTA_ANCHOR,
            self::GENERIC_ANCHOR,
            self::MALFORMED_TEXT,
            self::ENCODING_ERROR,
            self::SENTENCE_LIKE,
            self::DUPLICATE_VARIANT,
            self::TRAILING_SEQUENCE_NUMBER,
            self::LANGUAGE_MISMATCH,
        ];
    }

    public static function isKnown(string $code): bool
    {
        return in_array($code, self::all(), true);
    }

    public static function label(string $code): string
    {
        $key = 'seo-content-ai::filament.keyword.quality_tag_'.strtolower($code);

        $label = __($key);

        return $label === $key ? $code : $label;
    }

    public static function description(string $code): string
    {
        if ($code === self::LANGUAGE_MISMATCH) {
            $key = 'seo-content-ai::filament.keyword.quality_tag_language_mismatch_help';
            $help = __($key);

            return $help === $key
                ? __('seo-content-ai::filament.keyword.quality_exclude_help')
                : $help;
        }

        return __('seo-content-ai::filament.keyword.quality_exclude_help');
    }
}
