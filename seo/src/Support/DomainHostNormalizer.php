<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Support;

use Omnichannel\Addons\SearchFoundation\Support\DomainHostNormalizer as FoundationDomainHostNormalizer;

/**
 * Proxy to canonical DomainHostNormalizer in SearchFoundation.
 */
final class DomainHostNormalizer
{
    public static function normalize(string $input, bool $allowWildcards = false): ?string
    {
        return FoundationDomainHostNormalizer::normalize($input, $allowWildcards);
    }

    public static function normalizeStrict(string $input): ?string
    {
        return FoundationDomainHostNormalizer::normalizeStrict($input);
    }

    public static function normalizeTrustedPattern(string $input): ?string
    {
        return FoundationDomainHostNormalizer::normalizeTrustedPattern($input);
    }

    /**
     * @param  iterable<mixed>  $inputs
     * @return list<string>
     */
    public static function normalizeStrictList(iterable $inputs): array
    {
        return FoundationDomainHostNormalizer::normalizeStrictList($inputs);
    }

    /**
     * @param  iterable<mixed>  $inputs
     * @return list<string>
     */
    public static function normalizeTrustedPatternList(iterable $inputs): array
    {
        return FoundationDomainHostNormalizer::normalizeTrustedPatternList($inputs);
    }

    public static function isValidStrict(string $input): bool
    {
        return FoundationDomainHostNormalizer::isValidStrict($input);
    }

    public static function isValidTrustedPattern(string $input): bool
    {
        return FoundationDomainHostNormalizer::isValidTrustedPattern($input);
    }
}
