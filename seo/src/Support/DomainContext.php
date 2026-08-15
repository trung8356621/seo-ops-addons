<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Support;

/**
 * Canonical Global Domain Selector context.
 *
 * This is a UI filter, not an authorization boundary.
 * "All domains" is a first-class state (`all`), never 0 / -1 / ''.
 */
final class DomainContext
{
    public const ALL_KEY = 'all';

    public const QUERY_KEY = 'domain';

    public const HEADER_KEY = 'X-Seo-Domain-Context';

    public function __construct(
        public readonly bool $isAllDomains,
        public readonly ?int $siteId,
        public readonly string $domainKey,
    ) {}

    public static function all(): self
    {
        return new self(true, null, self::ALL_KEY);
    }

    public static function forSite(int $siteId, string $domainKey): self
    {
        $key = self::normalizeKey($domainKey);

        return new self(false, $siteId, $key !== self::ALL_KEY ? $key : (string) $siteId);
    }

    public static function normalizeKey(?string $key): string
    {
        $normalized = strtolower(trim((string) $key));

        if ($normalized === '' || $normalized === '0' || $normalized === '-1' || $normalized === 'null') {
            return self::ALL_KEY;
        }

        return $normalized;
    }

    public static function isAllKey(?string $key): bool
    {
        return self::normalizeKey($key) === self::ALL_KEY;
    }
}
