<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Content\Support;

use Omnichannel\Addons\Content\Enums\ContentType;
use Omnichannel\Addons\SiteSync\Services\Support\SiteSyncSiteMeta;
use App\Models\Site;

/**
 * Maps platform-native type/taxonomy slugs → canonical ContentType.
 *
 * Site-level map (connector-owned) lives in site meta `seo_wp_content_type_map`.
 * Article core never hardcodes CPT/taxonomy lists — only safe defaults for known natives.
 */
final class NativeContentTypeMapper
{
    public const SITE_META_KEY = 'seo_wp_content_type_map';

    /**
     * Built-in defaults (backward-safe). Connector map overrides these per site.
     *
     * @var array<string, string>
     */
    private const DEFAULTS = [
        'post' => 'post',
        'page' => 'page',
        'product' => 'product',
        'category' => 'post',
        'post_tag' => 'post',
        'product_cat' => 'product',
        'product_tag' => 'product',
        // Common CPT aliases used in contract tests / examples
        'portfolio' => 'post',
        'landing_page' => 'page',
        'machine' => 'product',
    ];

    /**
     * @param  array<string, string>|null  $siteMap  native => post|page|product
     */
    public static function map(string $nativeType, ?array $siteMap = null): ContentType
    {
        $native = strtolower(trim($nativeType));
        if ($native === '') {
            return ContentType::Post;
        }

        $fromSite = self::lookup($native, $siteMap);
        if ($fromSite !== null) {
            return $fromSite;
        }

        $fromDefault = self::lookup($native, self::DEFAULTS);
        if ($fromDefault !== null) {
            return $fromDefault;
        }

        // Unknown CPT / taxonomy → post (backward-safe).
        return ContentType::Post;
    }

    public static function mapForSite(string $nativeType, ?Site $site): ContentType
    {
        return self::map($nativeType, $site instanceof Site ? self::siteMap($site) : null);
    }

    /**
     * @return array<string, string>
     */
    public static function siteMap(?Site $site): array
    {
        if (! $site instanceof Site) {
            return [];
        }

        $raw = SiteSyncSiteMeta::getJson($site, self::SITE_META_KEY);
        if (! is_array($raw)) {
            return [];
        }

        $normalized = [];
        foreach ($raw as $native => $target) {
            $nativeKey = strtolower(trim((string) $native));
            $contentType = ContentType::tryFromString((string) $target);
            if ($nativeKey === '' || $contentType === null) {
                continue;
            }
            $normalized[$nativeKey] = $contentType->value;
        }

        return $normalized;
    }

    /**
     * Persist connector mapping (values already restricted to post|page|product).
     *
     * @param  array<string, string>  $map
     */
    public static function putSiteMap(Site $site, array $map): void
    {
        $normalized = [];
        foreach ($map as $native => $target) {
            $nativeKey = strtolower(trim((string) $native));
            $contentType = ContentType::tryFromString((string) $target);
            if ($nativeKey === '' || $contentType === null) {
                continue;
            }
            $normalized[$nativeKey] = $contentType->value;
        }

        SiteSyncSiteMeta::putJson($site, self::SITE_META_KEY, $normalized);
    }

    /**
     * Native platform slugs that map to the given canonical content_type.
     *
     * @param  array<string, string>|null  $siteMap
     * @return list<string>
     */
    public static function nativeSlugsFor(ContentType $type, ?array $siteMap = null): array
    {
        $merged = self::DEFAULTS;
        if (is_array($siteMap)) {
            foreach ($siteMap as $native => $target) {
                $nativeKey = strtolower(trim((string) $native));
                $contentType = ContentType::tryFromString((string) $target);
                if ($nativeKey === '' || $contentType === null) {
                    continue;
                }
                $merged[$nativeKey] = $contentType->value;
            }
        }

        $slugs = [$type->value];
        foreach ($merged as $native => $target) {
            if ($target === $type->value) {
                $slugs[] = strtolower(trim((string) $native));
            }
        }

        return array_values(array_unique($slugs));
    }

    /**
     * @param  array<string, string>|null  $map
     */
    private static function lookup(string $native, ?array $map): ?ContentType
    {
        if ($map === null || $map === []) {
            return null;
        }

        $target = $map[$native] ?? null;
        if ($target === null) {
            return null;
        }

        return ContentType::tryFromString((string) $target);
    }
}
