<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Agent\Automation\Support;

/**
 * Canonical automation IDs — cấm website_id / domain_id trong ActionContext.
 */
final class CanonicalIds
{
    public const TEAM_ID = 'team_id';

    public const SITE_ID = 'site_id';

    public const CONNECTION_ID = 'connection_id';

    public const ARTICLE_ID = 'article_id';

    public const WP_POST_ID = 'wp_post_id';

    /** @var list<string> */
    private const FORBIDDEN_ALIASES = ['website_id', 'domain_id', 'wp_id'];

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    public static function normalizeContextAttributes(array $attributes): array
    {
        if (isset($attributes['website_id']) && ! isset($attributes['site_id'])) {
            $attributes['site_id'] = $attributes['website_id'];
        }

        if (isset($attributes['domain_id']) && ! isset($attributes['site_id'])) {
            $attributes['site_id'] = $attributes['domain_id'];
        }

        foreach (self::FORBIDDEN_ALIASES as $alias) {
            unset($attributes[$alias]);
        }

        return $attributes;
    }

    public static function nullableInt(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        $int = (int) $value;

        return $int > 0 ? $int : null;
    }

    public static function assertActionKey(string $key): void
    {
        if ($key === '' || ! preg_match('/^[a-z][a-z0-9_]*(\.[a-z][a-z0-9_]*)+$/', $key)) {
            throw new \InvalidArgumentException("Invalid automation action key [{$key}].");
        }
    }

    public static function assertEventKey(string $key): void
    {
        if ($key === '' || ! preg_match('/^[a-z][a-z0-9_]*(\.[a-z][a-z0-9_]*)+$/', $key)) {
            throw new \InvalidArgumentException("Invalid automation event key [{$key}].");
        }
    }
}
