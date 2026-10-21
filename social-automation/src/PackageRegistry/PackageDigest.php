<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SocialAutomation\PackageRegistry;

/**
 * Canonical content digest for immutable package releases.
 */
final class PackageDigest
{
    /**
     * @param  array<string, mixed>  $canonicalPayload  payload without volatile fields
     */
    public static function sha256OfCanonicalJson(array $canonicalPayload): string
    {
        $json = json_encode($canonicalPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            throw new \InvalidArgumentException('Unable to encode package payload for digest.');
        }

        return hash('sha256', $json);
    }

    public static function isValidHex(string $digest): bool
    {
        return (bool) preg_match('/^[a-f0-9]{64}$/', $digest);
    }

    public static function equals(string $a, string $b): bool
    {
        return hash_equals(strtolower($a), strtolower($b));
    }
}
