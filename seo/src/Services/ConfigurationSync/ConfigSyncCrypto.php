<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Services\ConfigurationSync;

/**
 * HMAC-SHA-256 for seo-ops-config/1 only.
 * Signing-string shape matches the shared ops primitive, with this protocol id.
 */
final class ConfigSyncCrypto
{
    public static function contentSha256(string $rawBody): string
    {
        return self::base64UrlEncode(hash('sha256', $rawBody, true));
    }

    public static function requestSigningString(
        string $method,
        string $path,
        string $timestamp,
        string $nonce,
        string $contentSha256,
    ): string {
        return implode("\n", [
            ConfigProtocol::ID,
            strtoupper($method),
            $path,
            $timestamp,
            $nonce,
            $contentSha256,
        ]);
    }

    public static function responseSigningString(
        int $statusCode,
        string $requestNonce,
        string $responseTimestamp,
        string $responseNonce,
        string $contentSha256,
    ): string {
        return implode("\n", [
            ConfigProtocol::ID,
            (string) $statusCode,
            $requestNonce,
            $responseTimestamp,
            $responseNonce,
            $contentSha256,
        ]);
    }

    public static function sign(string $signingString, string $secretBase64Url): string
    {
        $mac = hash_hmac('sha256', $signingString, self::base64UrlDecode($secretBase64Url), true);

        return 'v1='.self::base64UrlEncode($mac);
    }

    public static function signaturesMatch(string $expected, string $presented): bool
    {
        if (strlen($expected) !== strlen($presented)) {
            return false;
        }

        return hash_equals($expected, $presented);
    }

    public static function nonce(int $bytes = 16): string
    {
        return self::base64UrlEncode(random_bytes(max(16, $bytes)));
    }

    public static function timestamp(): string
    {
        return gmdate('Y-m-d\TH:i:s\Z');
    }

    public static function base64UrlEncode(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }

    public static function base64UrlDecode(string $encoded): string
    {
        $b64 = strtr($encoded, '-_', '+/');
        $pad = strlen($b64) % 4;
        if ($pad > 0) {
            $b64 .= str_repeat('=', 4 - $pad);
        }
        $raw = base64_decode($b64, true);
        if (! is_string($raw)) {
            throw new ConfigurationSyncException('invalid_secret', 'Machine credential secret is not valid base64url.');
        }

        return $raw;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function encodePayload(array $payload): string
    {
        $json = json_encode(self::sortKeys($payload), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (! is_string($json)) {
            throw new ConfigurationSyncException('invalid_payload', 'Unable to encode configuration sync payload.');
        }

        return $json;
    }

    public static function sortKeys(mixed $node): mixed
    {
        if ($node instanceof \stdClass) {
            return $node;
        }
        if (! is_array($node)) {
            return $node;
        }
        if (array_is_list($node)) {
            return array_map(static fn (mixed $item): mixed => self::sortKeys($item), $node);
        }
        ksort($node);
        foreach ($node as $key => $value) {
            $node[$key] = self::sortKeys($value);
        }

        return $node;
    }
}
