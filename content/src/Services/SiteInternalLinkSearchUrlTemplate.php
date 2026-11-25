<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Content\Services;

use App\Models\Site;
use App\Models\SiteMeta;

/**
 * Per-site Search URL template for Link Assistant fallback.
 *
 * Meta key: internal_link_search_url_template
 * Example: https://congtybalo.com/?s={keyword}&post_type=product
 *
 * Never invents a WordPress search endpoint — only uses an explicit site template.
 */
final class SiteInternalLinkSearchUrlTemplate
{
    public const META_KEY = 'internal_link_search_url_template';

    public const PLACEHOLDER = '{keyword}';

    public function get(int $siteId): ?string
    {
        if ($siteId <= 0) {
            return null;
        }

        $site = Site::query()->find($siteId);
        if (! $site instanceof Site) {
            return null;
        }

        $raw = trim((string) $site->getMeta(self::META_KEY, ''));

        return $this->normalize($raw);
    }

    public function set(int $siteId, ?string $template): bool
    {
        if ($siteId <= 0) {
            return false;
        }

        $normalized = $this->normalize(trim((string) $template));
        if ($normalized === null) {
            SiteMeta::query()
                ->where('site_id', $siteId)
                ->where('meta_key', self::META_KEY)
                ->delete();

            return true;
        }

        SiteMeta::query()->updateOrCreate(
            ['site_id' => $siteId, 'meta_key' => self::META_KEY],
            ['meta_value' => $normalized],
        );

        return true;
    }

    /**
     * Build a concrete Search URL, or null when template missing/invalid.
     */
    public function buildUrl(int $siteId, string $keyword): ?string
    {
        $template = $this->get($siteId);
        if ($template === null) {
            return null;
        }

        $keyword = trim($keyword);
        if ($keyword === '') {
            return null;
        }

        return str_replace(
            self::PLACEHOLDER,
            rawurlencode($keyword),
            $template,
        );
    }

    public function normalize(string $template): ?string
    {
        $template = trim($template);
        if ($template === '') {
            return null;
        }

        if (! str_contains($template, self::PLACEHOLDER)) {
            return null;
        }

        if (filter_var(
            str_replace(self::PLACEHOLDER, 'x', $template),
            FILTER_VALIDATE_URL,
        ) === false) {
            return null;
        }

        if (! preg_match('#^https?://#i', $template)) {
            return null;
        }

        return $template;
    }
}
