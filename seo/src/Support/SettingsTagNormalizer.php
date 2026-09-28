<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Support;

use Omnichannel\Addons\SearchFoundation\Support\DomainHostNormalizer;

/**
 * Pure PHP normalizer and validation helper for Settings list/tag fields.
 * Used for server-side validation, typed normalization, backend persistence safety, and tests.
 */
final class SettingsTagNormalizer
{
    public const TYPE_TRUSTED_DOMAIN = 'trusted_domain';
    public const TYPE_STRICT_DOMAIN = 'strict_domain';
    public const TYPE_EXTENSION = 'extension';
    public const TYPE_PHRASE = 'phrase';

    /**
     * Normalize a single trusted domain or wildcard pattern (*.gov, *.edu, *.example.com).
     * Returns null if invalid.
     */
    public static function normalizeTrustedDomainTag(?string $tag): ?string
    {
        if ($tag === null) {
            return null;
        }

        return DomainHostNormalizer::normalizeTrustedPattern($tag);
    }

    /**
     * Normalize a single domain in strict mode (no wildcards).
     * Returns null if invalid.
     */
    public static function normalizeStrictDomainTag(?string $tag): ?string
    {
        if ($tag === null) {
            return null;
        }

        return DomainHostNormalizer::normalizeStrict($tag);
    }

    /**
     * Normalize a single file extension (lowercase, strip leading dots).
     * Returns null if invalid.
     */
    public static function normalizeExtensionTag(?string $tag): ?string
    {
        if ($tag === null) {
            return null;
        }

        $clean = strtolower(ltrim(trim($tag), '.'));
        if ($clean === '' || ! preg_match('/^[a-z0-9]{1,12}$/', $clean)) {
            return null;
        }

        return $clean;
    }

    /**
     * Normalize a phrase (preserve internal words/spaces, lowercase, trim).
     * Returns null if empty.
     */
    public static function normalizePhraseTag(?string $tag): ?string
    {
        if ($tag === null) {
            return null;
        }

        $clean = trim($tag);
        if ($clean === '') {
            return null;
        }

        $clean = mb_strtolower($clean);
        $clean = (string) preg_replace('/\s+/u', ' ', $clean);

        return $clean !== '' ? $clean : null;
    }

    /**
     * @param  iterable<mixed>  $tags
     * @return list<string>
     */
    public static function normalizeTrustedDomainTags(iterable $tags): array
    {
        return DomainHostNormalizer::normalizeTrustedPatternList($tags);
    }

    /**
     * @param  iterable<mixed>  $tags
     * @return list<string>
     */
    public static function normalizeStrictDomainTags(iterable $tags): array
    {
        return DomainHostNormalizer::normalizeStrictList($tags);
    }

    /**
     * @param  iterable<mixed>  $tags
     * @return list<string>
     */
    public static function normalizeExtensionTags(iterable $tags): array
    {
        $normalized = [];
        foreach ($tags as $tag) {
            $clean = self::normalizeExtensionTag(is_string($tag) ? $tag : (string) $tag);
            if ($clean !== null && ! in_array($clean, $normalized, true)) {
                $normalized[] = $clean;
            }
        }

        return $normalized;
    }

    /**
     * @param  iterable<mixed>  $tags
     * @return list<string>
     */
    public static function normalizePhraseTags(iterable $tags): array
    {
        $normalized = [];
        foreach ($tags as $tag) {
            $clean = self::normalizePhraseTag(is_string($tag) ? $tag : (string) $tag);
            if ($clean !== null && ! in_array($clean, $normalized, true)) {
                $normalized[] = $clean;
            }
        }

        return $normalized;
    }
}
