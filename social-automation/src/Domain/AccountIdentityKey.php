<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SocialAutomation\Domain;

/**
 * Strong identity key when enough metadata exists; otherwise null (unknown).
 * Declared email/username alone is never a verified identity key.
 */
final class AccountIdentityKey
{
    /**
     * @param  array{
     *     platform?: string|null,
     *     domain?: string|null,
     *     platform_account_id?: string|null,
     *     verified?: bool|null,
     *     identity_source?: string|null,
     *     declared_username?: string|null,
     *     declared_email?: string|null
     * }  $meta
     */
    public static function strongKey(array $meta): ?string
    {
        $platform = strtolower(trim((string) ($meta['platform'] ?? $meta['domain'] ?? '')));
        $platformAccountId = trim((string) ($meta['platform_account_id'] ?? ''));
        $verified = (bool) ($meta['verified'] ?? false);
        $source = strtolower(trim((string) ($meta['identity_source'] ?? '')));

        if ($platform === '' || $platformAccountId === '') {
            return null;
        }

        if (! $verified && $source !== 'platform_id') {
            return null;
        }

        return $platform.'|'.$platformAccountId;
    }

    /**
     * Two incomplete identities must not be treated as distinct for dedupe.
     *
     * @param  array<string, mixed>  $a
     * @param  array<string, mixed>  $b
     */
    public static function definitelySame(array $a, array $b): bool
    {
        $ka = self::strongKey($a);
        $kb = self::strongKey($b);

        return $ka !== null && $kb !== null && hash_equals($ka, $kb);
    }

    /**
     * @param  array<string, mixed>  $a
     * @param  array<string, mixed>  $b
     */
    public static function definitelyDifferent(array $a, array $b): bool
    {
        $ka = self::strongKey($a);
        $kb = self::strongKey($b);

        return $ka !== null && $kb !== null && ! hash_equals($ka, $kb);
    }
}
