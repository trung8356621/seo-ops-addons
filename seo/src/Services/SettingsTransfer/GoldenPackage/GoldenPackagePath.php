<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Services\SettingsTransfer\GoldenPackage;

/**
 * Owner-fixed logical baseline path for Golden Configuration packages.
 */
final class GoldenPackagePath
{
    public const RELATIVE = 'resources/golden-configuration';

    public static function absolute(?string $basePath = null): string
    {
        $root = $basePath ?? base_path();

        return rtrim(str_replace('\\', '/', $root), '/').'/'.self::RELATIVE;
    }

    public static function entrypoint(?string $basePath = null): string
    {
        return self::absolute($basePath).'/payload/full-configuration.json';
    }

    public static function manifest(?string $basePath = null): string
    {
        return self::absolute($basePath).'/manifest.json';
    }

    public static function checksums(?string $basePath = null): string
    {
        return self::absolute($basePath).'/metadata/checksums.json';
    }
}
