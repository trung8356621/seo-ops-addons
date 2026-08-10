<?php

declare(strict_types=1);

namespace Omnichannel\Addons\WordPress\Support;

use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\WordPress\Services\WordPressSiteInfoService;
use App\Models\Site;
use Carbon\CarbonInterface;

/**
 * Khi wp_permalink là dạng plain (?p=ID), ghép URL theo cấu trúc permalink WordPress (site-info).
 */
final class WordPressPermalinkBuilder
{
    public function __construct(
        private readonly WordPressSiteInfoService $siteInfo,
    ) {}

    public function resolve(SeoArticle $article, string $cachedPermalink = '', ?string $slug = null): string
    {
        $cached = trim($cachedPermalink);
        $slug = trim($slug ?? (string) ($article->slug ?? ''));

        if ((int) ($article->wordpressLink?->wp_post_id ?? 0) > 0 && $cached !== '') {
            return $cached;
        }

        $article->loadMissing('site');
        $site = $article->site;
        if (! $site instanceof Site) {
            return $cached;
        }

        $settings = $this->permalinkSettings($site);
        if ($slug !== '' && $this->hasRuntimeTemplate($article, $settings)) {
            $built = $this->buildPrettyPermalink($site, $article, $slug, $settings);
            if ($built !== '') {
                return $built;
            }
        }

        if ($cached !== '' && ! $this->isPlainPermalinkUrl($cached)) {
            return $cached;
        }

        if ($this->isPlainStructure($settings)) {
            return $cached !== '' ? $cached : $this->buildPlainPermalink($site, $article);
        }

        if ($slug === '') {
            return $cached;
        }

        $built = $this->buildPrettyPermalink($site, $article, $slug, $settings);
        if ($built !== '') {
            return $built;
        }

        return $cached;
    }

    public function preview(SeoArticle $article, string $slug): string
    {
        return $this->resolve($article, '', $slug);
    }

    /**
     * @param  array<string, mixed>  $item
     */
    public function resolveFromSyncItem(Site $site, array $item): string
    {
        $permalink = trim((string) ($item['permalink'] ?? ''));
        if ($permalink !== '' && ! $this->isPlainPermalinkUrl($permalink)) {
            return $permalink;
        }

        $slug = trim((string) ($item['slug'] ?? ''));
        if ($slug === '') {
            return $permalink;
        }

        $settings = $this->permalinkSettings($site);
        if ($this->isPlainStructure($settings)) {
            return $permalink;
        }

        $type = strtolower(trim((string) ($item['type'] ?? 'article')));
        $wpPostType = trim((string) ($item['wp_post_type'] ?? ''));
        $publishedAt = $this->parsePublishedAt($item['published_at'] ?? null);
        $wpId = (int) ($item['wp_id'] ?? 0);

        $built = $this->buildPrettyPermalinkForType(
            $site,
            $slug,
            $type,
            $wpPostType,
            $settings,
            $publishedAt,
            $wpId,
            null,
        );

        return $built !== '' ? $built : $permalink;
    }

    public function isPlainPermalinkUrl(string $url): bool
    {
        $url = trim($url);
        if ($url === '') {
            return false;
        }

        if (preg_match('#\?(?:.*&)?(p|page_id|attachment_id)=\d+#i', $url)) {
            return true;
        }

        return (bool) preg_match('#/\?(p|page_id|attachment_id)=\d+#i', $url);
    }

    /**
     * @param  array<string, mixed>  $settings
     */
    private function isPlainStructure(array $settings): bool
    {
        $structure = trim((string) ($settings['structure'] ?? ''));

        return $structure === '';
    }

    /**
     * @return array<string, mixed>
     */
    private function permalinkSettings(Site $site): array
    {
        $permalink = $this->readPermalinkFromStoredSiteInfo($site);

        if (
            $permalink !== []
            && array_key_exists('structure', $permalink)
            && (int) ($permalink['templates_version'] ?? 0) >= 1
            && is_array($permalink['templates'] ?? null)
        ) {
            return $permalink;
        }

        $site->loadMissing('metas');
        if ($site->exists && trim((string) ($site->getMeta('seo_read_token') ?? '')) !== '') {
            $fetched = $this->siteInfo->fetchAndStore($site);
            if ($fetched['success'] ?? false) {
                $permalink = $this->readPermalinkFromStoredSiteInfo($site);
                if ($permalink !== []) {
                    return $permalink;
                }
            }
        }

        if ($permalink !== [] && array_key_exists('structure', $permalink)) {
            return $permalink;
        }

        return [
            'structure' => '',
            'category_base' => 'category',
            'tag_base' => 'tag',
            'woocommerce' => [],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function readPermalinkFromStoredSiteInfo(Site $site): array
    {
        $info = $this->siteInfo->getStoredSiteInfo($site);
        $permalink = is_array($info['permalink'] ?? null) ? $info['permalink'] : [];

        return $permalink;
    }

    /**
     * @param  array<string, mixed>  $settings
     */
    private function hasRuntimeTemplate(SeoArticle $article, array $settings): bool
    {
        $templates = is_array($settings['templates'] ?? null) ? $settings['templates'] : [];
        $type = strtolower(trim((string) ($article->type ?? 'article')));
        $wpPostType = $this->resolveWpPostType($article);
        $key = match (true) {
            $type === 'product' || $wpPostType === 'product' => 'product',
            $type === 'product_category' || $wpPostType === 'product_cat' => 'product_category',
            $type === 'category' || $wpPostType === 'category' => 'category',
            default => 'post',
        };

        return str_contains(trim((string) ($templates[$key] ?? '')), '%slug%');
    }

    /**
     * @param  array<string, mixed>  $settings
     */
    private function buildPrettyPermalink(Site $site, SeoArticle $article, string $slug, array $settings): string
    {
        $type = strtolower(trim((string) ($article->type ?? 'article')));
        $wpPostType = $this->resolveWpPostType($article);

        return $this->buildPrettyPermalinkForType(
            $site,
            $slug,
            $type,
            $wpPostType,
            $settings,
            $article->publishingState?->published_at,
            (int) ($article->wordpressLink?->wp_post_id ?? 0),
            $article,
        );
    }

    /**
     * @param  array<string, mixed>  $settings
     */
    private function buildPrettyPermalinkForType(
        Site $site,
        string $slug,
        string $type,
        string $wpPostType,
        array $settings,
        mixed $publishedAt,
        int $wpId,
        ?SeoArticle $article,
    ): string {
        $base = $this->siteBaseUrl($site);
        if ($base === '') {
            return '';
        }

        $templateKey = match (true) {
            $type === 'product' || $wpPostType === 'product' => 'product',
            $type === 'product_category' || $wpPostType === 'product_cat' => 'product_category',
            $type === 'category' || $wpPostType === 'category' => 'category',
            default => 'post',
        };
        $template = trim((string) (($settings['templates'] ?? [])[$templateKey] ?? ''));
        if ($template !== '' && str_contains($template, '%slug%')) {
            return str_replace('%slug%', rawurlencode($slug), $template);
        }

        $path = match (true) {
            $type === 'product' || $wpPostType === 'product' => $this->buildProductPath($slug, $settings, $article),
            in_array($type, ['category', 'product_category'], true)
                || in_array($wpPostType, ['category', 'product_cat'], true) => $this->buildTermPath($slug, $type, $wpPostType, $settings),
            default => $this->buildPostPath($slug, $settings, $publishedAt, $wpId, $article),
        };

        if ($path === '') {
            return '';
        }

        return rtrim($base, '/') . '/' . ltrim($path, '/');
    }

    /**
     * @param  array<string, mixed>  $settings
     */
    private function buildPostPath(
        string $slug,
        array $settings,
        mixed $publishedAt,
        int $wpId,
        ?SeoArticle $article,
    ): string {
        $structure = trim((string) ($settings['structure'] ?? ''));
        if ($structure === '') {
            return $slug;
        }

        return $this->expandPermalinkStructure($structure, $slug, $publishedAt, $wpId, $article);
    }

    /**
     * @param  array<string, mixed>  $settings
     */
    private function buildProductPath(string $slug, array $settings, ?SeoArticle $article): string
    {
        $wc = is_array($settings['woocommerce'] ?? null) ? $settings['woocommerce'] : [];
        $base = trim((string) ($wc['product_base'] ?? 'product'), '/');

        $categorySlug = $article instanceof SeoArticle ? $this->resolvePrimaryCategorySlug($article) : '';

        $path = str_replace(
            ['%product_cat%', '%product%'],
            [$categorySlug, $slug],
            $base,
        );

        $path = trim($path, '/');
        $path = preg_replace('#/+#', '/', $path) ?? $path;

        if ($base === '') {
            return $slug;
        }

        if (! str_contains($base, '%product%')) {
            $path = $path !== '' ? $path . '/' . $slug : $slug;
        }

        return trim($path, '/');
    }

    /**
     * @param  array<string, mixed>  $settings
     */
    private function buildTermPath(string $slug, string $type, string $wpPostType, array $settings): string
    {
        $wc = is_array($settings['woocommerce'] ?? null) ? $settings['woocommerce'] : [];

        if ($type === 'product_category' || $wpPostType === 'product_cat') {
            $base = trim((string) ($wc['category_base'] ?? 'product-category'), '/');
        } else {
            $base = trim((string) ($settings['category_base'] ?? 'category'), '/');
        }

        if ($base === '') {
            return $slug;
        }

        return trim($base . '/' . $slug, '/');
    }

    private function expandPermalinkStructure(
        string $structure,
        string $slug,
        mixed $publishedAt,
        int $wpId,
        ?SeoArticle $article,
    ): string {
        $date = $this->normalizePublishedAt($publishedAt);

        $categorySlug = $article instanceof SeoArticle ? $this->resolvePrimaryCategorySlug($article) : 'uncategorized';
        if ($categorySlug === '') {
            $categorySlug = 'uncategorized';
        }

        $replacements = [
            '%year%' => $date->format('Y'),
            '%monthnum%' => $date->format('m'),
            '%day%' => $date->format('d'),
            '%hour%' => $date->format('H'),
            '%minute%' => $date->format('i'),
            '%second%' => $date->format('s'),
            '%post_id%' => $wpId > 0 ? (string) $wpId : '',
            '%postname%' => $slug,
            '%category%' => $categorySlug,
        ];

        $path = str_replace(array_keys($replacements), array_values($replacements), $structure);
        $path = preg_replace('#/+#', '/', $path) ?? $path;

        return trim($path, '/');
    }

    private function resolvePrimaryCategorySlug(SeoArticle $article): string
    {
        $article->loadMissing('articleMetas');
        $slug = trim((string) ($article->articleMetas->firstWhere('meta_key', 'wp_primary_category_slug')?->meta_value ?? ''));

        return $slug;
    }

    private function resolveWpPostType(SeoArticle $article): string
    {
        $type = strtolower(trim((string) ($article->type ?? '')));

        return match ($type) {
            'product' => 'product',
            'product_category', 'product_cat' => 'product_cat',
            'category' => 'category',
            'article', 'post' => 'post',
            default => $type,
        };
    }

    private function buildPlainPermalink(Site $site, SeoArticle $article): string
    {
        $wpId = (int) ($article->wordpressLink?->wp_post_id ?? 0);
        if ($wpId <= 0) {
            return '';
        }

        $base = $this->siteBaseUrl($site);

        return rtrim($base, '/') . '/?p=' . $wpId;
    }

    private function siteBaseUrl(Site $site): string
    {
        $domain = trim((string) $site->domain);
        if ($domain === '') {
            return '';
        }

        if (preg_match('#^https?://#i', $domain)) {
            return rtrim($domain, '/');
        }

        $scheme = ! empty($site->ssl) ? 'https' : 'http';

        return $scheme . '://' . rtrim($domain, '/');
    }

    private function normalizePublishedAt(mixed $publishedAt): CarbonInterface
    {
        if ($publishedAt instanceof CarbonInterface) {
            return $publishedAt;
        }

        if (is_string($publishedAt) && trim($publishedAt) !== '') {
            try {
                return \Illuminate\Support\Carbon::parse($publishedAt);
            } catch (\Throwable) {
                // fall through
            }
        }

        return now();
    }

    private function parsePublishedAt(mixed $value): ?CarbonInterface
    {
        if ($value instanceof CarbonInterface) {
            return $value;
        }

        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return \Illuminate\Support\Carbon::parse($value);
        } catch (\Throwable) {
            return null;
        }
    }
}
