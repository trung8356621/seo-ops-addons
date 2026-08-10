<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Services\ContentProject\Application;

use InvalidArgumentException;

/**
 * Stable public refs — opaque với client; decode nội bộ về numeric ID.
 * Không phá vỡ tương thích: numeric ID nội bộ vẫn dùng trong service.
 */
final class ContentProjectPublicRef
{
    public static function project(int $id): string
    {
        return self::encode('cpj', $id);
    }

    public static function item(int $id): string
    {
        return self::encode('cpi', $id);
    }

    public static function article(int $id): string
    {
        return self::encode('cpa', $id);
    }

    public static function execution(int $id): string
    {
        return self::encode('cpx', $id);
    }

    public static function site(int $id): string
    {
        return self::encode('cps', $id);
    }

    public static function decodeProject(string $ref): int
    {
        return self::decode('cpj', $ref);
    }

    public static function decodeItem(string $ref): int
    {
        return self::decode('cpi', $ref);
    }

    public static function decodeArticle(string $ref): int
    {
        return self::decode('cpa', $ref);
    }

    public static function decodeExecution(string $ref): int
    {
        return self::decode('cpx', $ref);
    }

    public static function decodeSite(string $ref): int
    {
        return self::decode('cps', $ref);
    }

    /**
     * Public API — reject bare numeric IDs; only opaque cps_* refs.
     */
    public static function resolveSiteIdStrict(string $ref): int
    {
        $ref = trim($ref);
        if ($ref === '' || ctype_digit($ref)) {
            throw new InvalidArgumentException('Site ref must be opaque cps_* identifier.');
        }

        if (! str_starts_with($ref, 'cps_')) {
            throw new InvalidArgumentException('Site ref must be opaque cps_* identifier.');
        }

        return self::decodeSite($ref);
    }

    /**
     * Chấp nhận ref opaque hoặc numeric string legacy.
     */
    public static function resolveProjectId(string|int $refOrId): int
    {
        if (is_int($refOrId) || ctype_digit((string) $refOrId)) {
            return (int) $refOrId;
        }

        return self::decodeProject((string) $refOrId);
    }

    /**
     * Public API — reject bare numeric IDs; only opaque cpj_* refs.
     */
    public static function resolveProjectIdStrict(string $ref): int
    {
        $ref = trim($ref);
        if ($ref === '' || ctype_digit($ref)) {
            throw new InvalidArgumentException('Project ref must be opaque cpj_* identifier.');
        }

        if (! str_starts_with($ref, 'cpj_')) {
            throw new InvalidArgumentException('Project ref must be opaque cpj_* identifier.');
        }

        return self::decodeProject($ref);
    }

    /**
     * Public API — reject bare numeric IDs; only opaque cpi_* refs.
     */
    public static function resolveItemIdStrict(string $ref): int
    {
        $ref = trim($ref);
        if ($ref === '' || ctype_digit($ref)) {
            throw new InvalidArgumentException('Item ref must be opaque cpi_* identifier.');
        }

        if (! str_starts_with($ref, 'cpi_')) {
            throw new InvalidArgumentException('Item ref must be opaque cpi_* identifier.');
        }

        return self::decodeItem($ref);
    }

    public static function resolveItemId(string|int $refOrId): int
    {
        if (is_int($refOrId) || ctype_digit((string) $refOrId)) {
            return (int) $refOrId;
        }

        return self::decodeItem((string) $refOrId);
    }

    private static function encode(string $prefix, int $id): string
    {
        if ($id <= 0) {
            throw new InvalidArgumentException('Invalid id for public ref.');
        }

        $payload = rtrim(strtr(base64_encode(pack('N', $id)), '+/', '-_'), '=');
        $checksum = substr(hash('xxh3', $prefix.'|'.$id), 0, 6);

        return $prefix.'_'.$payload.'_'.$checksum;
    }

    private static function decode(string $prefix, string $ref): int
    {
        $ref = trim($ref);
        if (! preg_match('/^'.preg_quote($prefix, '/').'_([A-Za-z0-9_-]+)_([a-f0-9]{6})$/', $ref, $m)) {
            throw new InvalidArgumentException("Invalid {$prefix} ref.");
        }

        $raw = base64_decode(strtr($m[1], '-_', '+/'), true);
        if ($raw === false || strlen($raw) < 4) {
            throw new InvalidArgumentException("Invalid {$prefix} ref payload.");
        }

        $unpacked = unpack('Nid', substr($raw, 0, 4));
        $id = (int) ($unpacked['id'] ?? 0);
        if ($id <= 0 || substr(hash('xxh3', $prefix.'|'.$id), 0, 6) !== $m[2]) {
            throw new InvalidArgumentException("Invalid {$prefix} ref checksum.");
        }

        return $id;
    }
}
