<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SiteSync\Services\Support;

use App\Models\Site;

final class SiteSyncSiteMeta
{
    public static function put(Site $site, string $key, string $value): void
    {
        $site->metas()->updateOrCreate(
            ['meta_key' => $key],
            ['meta_value' => $value],
        );
    }

    public static function getJson(Site $site, string $key): ?array
    {
        $raw = trim((string) ($site->getMeta($key) ?? ''));
        if ($raw === '') {
            return null;
        }
        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : null;
    }

    public static function putJson(Site $site, string $key, array $value): void
    {
        self::put($site, $key, (string) json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }
}
