<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchFoundation\Support;

/**
 * Canonical normalizer for hostnames and domain patterns.
 * Supports two policies:
 * - Strict Domain: canonical hostname only, no wildcards.
 * - Trusted Pattern: canonical hostname or prefix wildcard pattern (*.gov, *.edu, *.example.com).
 */
final class DomainHostNormalizer
{
    /**
     * Normalize a single domain or URL to its canonical host or wildcard pattern.
     * Returns null if the input is invalid.
     */
    public static function normalize(string $input, bool $allowWildcards = false): ?string
    {
        $value = trim($input);
        if ($value === '' || preg_match('/\s/', $value)) {
            return null;
        }

        // Reject non-http/https URI schemes (e.g., javascript:, mailto:, tel:)
        if (preg_match('/^[a-z][a-z0-9+.-]*:/i', $value, $matches)) {
            $scheme = strtolower($matches[0]);
            if ($scheme !== 'http:' && $scheme !== 'https:') {
                return null;
            }
        }

        // Non-URL with @ is an email or malformed user string
        if (! str_contains($value, '://') && ! str_starts_with($value, '//') && str_contains($value, '@')) {
            return null;
        }

        // Scheme-relative URLs
        if (str_starts_with($value, '//')) {
            $value = 'https:'.$value;
        } elseif (str_contains($value, '://') || (str_contains($value, '/') && ! str_starts_with($value, '*.'))) {
            if (! str_contains($value, '://')) {
                $value = 'https://'.$value;
            }
        }

        // URL parsing for scheme, host, path
        if (str_contains($value, '://')) {
            $parts = parse_url($value);
            if ($parts === false || ! isset($parts['host']) || trim((string) $parts['host']) === '') {
                return null;
            }

            $scheme = strtolower((string) ($parts['scheme'] ?? ''));
            if (! in_array($scheme, ['http', 'https'], true)) {
                return null;
            }

            $value = trim((string) $parts['host']);
        }

        $value = strtolower($value);
        $value = rtrim($value, '.');

        // Handle wildcard pattern if permitted (*.gov, *.edu, *.example.com)
        if ($allowWildcards && str_starts_with($value, '*.')) {
            $suffix = substr($value, 2);
            if ($suffix === '' || str_contains($suffix, '*') || str_contains($suffix, '/') || str_contains($suffix, '?') || str_contains($suffix, '#')) {
                return null;
            }

            $suffix = rtrim($suffix, '.');
            if (preg_match('/^[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?(?:\.[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?)*$/', $suffix) !== 1) {
                return null;
            }

            // Must have a dot (e.g. example.com) or be a valid TLD of at least 2 characters (e.g. gov, edu)
            if (! str_contains($suffix, '.') && strlen($suffix) < 2) {
                return null;
            }

            return '*.'.$suffix;
        }

        // Wildcards are not allowed in strict mode or non-prefix wildcard format
        if (str_contains($value, '*')) {
            return null;
        }

        // Strip leading www.
        if (str_starts_with($value, 'www.')) {
            $value = substr($value, 4);
        }

        $value = rtrim($value, '.');

        if ($value === '' || ! self::isValidDomainLabel($value)) {
            return null;
        }

        return $value;
    }

    public static function normalizeStrict(string $input): ?string
    {
        return self::normalize($input, false);
    }

    public static function normalizeTrustedPattern(string $input): ?string
    {
        return self::normalize($input, true);
    }

    /**
     * @param  iterable<mixed>  $inputs
     * @return list<string>
     */
    public static function normalizeStrictList(iterable $inputs): array
    {
        $domains = [];
        foreach ($inputs as $input) {
            $normalized = self::normalizeStrict(is_string($input) ? $input : (string) $input);
            if ($normalized === null) {
                continue;
            }

            if (! in_array($normalized, $domains, true)) {
                $domains[] = $normalized;
            }
        }

        return $domains;
    }

    /**
     * @param  iterable<mixed>  $inputs
     * @return list<string>
     */
    public static function normalizeTrustedPatternList(iterable $inputs): array
    {
        $patterns = [];
        foreach ($inputs as $input) {
            $normalized = self::normalizeTrustedPattern(is_string($input) ? $input : (string) $input);
            if ($normalized === null) {
                continue;
            }

            if (! in_array($normalized, $patterns, true)) {
                $patterns[] = $normalized;
            }
        }

        return $patterns;
    }

    public static function isValidStrict(string $input): bool
    {
        return self::normalizeStrict($input) !== null;
    }

    public static function isValidTrustedPattern(string $input): bool
    {
        return self::normalizeTrustedPattern($input) !== null;
    }

    private static function isValidDomainLabel(string $domain): bool
    {
        return preg_match(
            '/^[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?(?:\.[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?)+$/',
            $domain,
        ) === 1;
    }
}
