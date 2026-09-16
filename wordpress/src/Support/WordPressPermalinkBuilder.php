<?php

declare(strict_types=1);

namespace Omnichannel\Addons\WordPress\Support;

use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\Content\Support\ArticleWordPressPostType;
use Omnichannel\Addons\WordPress\Services\WordPressSiteInfoService;
use App\Models\Site;
use Carbon\CarbonInterface;

/**
 * Candidate permalinks from cached WordPress site-info routing (read-only).
 * Observed remote URLs live in article_meta.wp_permalink — never invent those here.
 */
final class WordPressPermalinkBuilder
{
    public function __construct(
        private readonly WordPressSiteInfoService $siteInfo,
    ) {}

    /**
     * Prefer observed remote permalink when the article is linked to WordPress.
     * Otherwise (or when reconstructing a plain ?p= URL) build a candidate from site routing.
     */
    public function resolve(SeoArticle $article, string $cachedPermalink = '', ?string $slug = null): string
    {
        $cached = trim($cachedPermalink);
        $slug = trim($slug ?? (string) ($article->slug ?? ''));
        $wpPostId = (int) ($article->wordpressLink?->wp_post_id ?? $article->getAttribute('wp_post_id') ?? 0);

        // Linked + observed remote URL: WordPress wins. Do not rewrite from local slug/type.
        if ($wpPostId > 0 && $cached !== '' && ! $this->isPlainPermalinkUrl($cached)) {
            return $cached;
        }

        if ($wpPostId > 0 && $cached !== '' && $this->isPlainPermalinkUrl($cached)) {
            $candidate = $this->candidatePermalink($article, $slug !== '' ? $slug : null);
            if ($candidate !== '') {
                return $candidate;
            }

            return $cached;
        }

        $candidate = $this->candidatePermalink($article, $slug !== '' ? $slug : null);
        if ($candidate !== '') {
            return $candidate;
        }

        return $cached;
    }

    /**
     * Local expected URL for editor display — never writes wp_permalink.
     */
    public function preview(SeoArticle $article, string $slug): string
    {
        return $this->candidatePermalink($article, $slug);
    }

    /**
     * Candidate permalink from the article's own site routing profile + raw WP post type.
     * Returns empty string when the site profile cannot confidently resolve this type
     * (never falls back to an unrelated post template such as /tin-tuc/...).
     */
    public function candidatePermalink(SeoArticle $article, ?string $slug = null): string
    {
        $slug = trim($slug ?? (string) ($article->slug ?? ''));
        if ($slug === '') {
            return '';
        }

        $article->loadMissing('site');
        $site = $article->site;
        if (! $site instanceof Site) {
            return '';
        }

        $settings = $this->permalinkSettings($site);
        if (! $this->hasUsableRoutingProfile($settings)) {
            return '';
        }

        $wpPostType = ArticleWordPressPostType::resolve($article);

        return $this->buildPrettyPermalinkForType(
            $site,
            $slug,
            $wpPostType,
            $settings,
            $article->publishingState?->published_at,
            (int) ($article->wordpressLink?->wp_post_id ?? $article->getAttribute('wp_post_id') ?? 0),
            $article,
        );
    }

    /**
     * Template with %slug% for live editor updates (article site only).
     */
    public function candidateTemplate(SeoArticle $article): string
    {
        $article->loadMissing('site');
        $site = $article->site;
        if (! $site instanceof Site) {
            return '';
        }

        $settings = $this->permalinkSettings($site);
        if (! $this->hasUsableRoutingProfile($settings)) {
            return '';
        }

        $wpPostType = ArticleWordPressPostType::resolve($article);
        $templateKey = $this->templateKeyForWpPostType($wpPostType);
        $template = trim((string) (($settings['templates'] ?? [])[$templateKey] ?? ''));
        if ($template !== '' && str_contains($template, '%slug%')) {
            return $template;
        }

        // Derive a template from a successful candidate build using a sentinel slug.
        $sentinel = '__omi_slug__';
        $built = $this->buildPrettyPermalinkForType(
            $site,
            $sentinel,
            $wpPostType,
            $settings,
            $article->publishingState?->published_at,
            0,
            $article,
        );
        if ($built === '' || ! str_contains($built, $sentinel)) {
            return '';
        }

        return str_replace($sentinel, '%slug%', $built);
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
        if (! $this->hasUsableRoutingProfile($settings) || $this->isPlainStructure($settings)) {
            return $permalink;
        }

        $wpPostType = ArticleWordPressPostType::normalizeEditorInput(
            (string) ($item['wp_post_type'] ?? $item['type'] ?? 'post'),
        );
        $publishedAt = $this->parsePublishedAt($item['published_at'] ?? null);
        $wpId = (int) ($item['wp_id'] ?? 0);

        $built = $this->buildPrettyPermalinkForType(
            $site,
            $slug,
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
        return trim((string) ($settings['structure'] ?? '')) === '';
    }

    /**
     * @param  array<string, mixed>  $settings
     */
    private function hasUsableRoutingProfile(array $settings): bool
    {
        if (array_key_exists('structure', $settings)) {
            return true;
        }

        if (is_array($settings['templates'] ?? null) && $settings['templates'] !== []) {
            return true;
        }

        return is_array($settings['woocommerce'] ?? null);
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

        // Missing profile: empty structure marker so callers can detect unavailability.
        return [];
    }

    /**
     * @return array<string, mixed>
     */
    private function readPermalinkFromStoredSiteInfo(Site $site): array
    {
        $info = $this->siteInfo->getStoredSiteInfo($site) ?? [];
        $permalink = is_array($info['permalink'] ?? null) ? $info['permalink'] : [];

        return $permalink;
    }

    private function templateKeyForWpPostType(string $wpPostType): string
    {
        return match ($wpPostType) {
            'product' => 'product',
            'product_cat', 'product_category' => 'product_category',
            'category' => 'category',
            'page' => 'page',
            'post' => 'post',
            default => $wpPostType,
        };
    }

    /**
     * @param  array<string, mixed>  $settings
     */
    private function buildPrettyPermalinkForType(
        Site $site,
        string $slug,
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

        $wpPostType = ArticleWordPressPostType::normalizeEditorInput($wpPostType);
        $templateKey = $this->templateKeyForWpPostType($wpPostType);
        $template = trim((string) (($settings['templates'] ?? [])[$templateKey] ?? ''));
        if ($template !== '' && str_contains($template, '%slug%')) {
            return str_replace('%slug%', rawurlencode($slug), $template);
        }

        $path = match (true) {
            $wpPostType === 'product' => $this->buildProductPath($slug, $settings, $article),
            in_array($wpPostType, ['category', 'product_cat', 'product_category'], true) => $this->buildTermPath(
                $slug,
                $wpPostType,
                $settings,
            ),
            $wpPostType === 'page' => $this->buildPagePath($slug, $settings, $article),
            $wpPostType === 'post' => $this->buildPostPath($slug, $settings, $publishedAt, $wpId, $article),
            default => $this->buildNativeCptPath($slug, $wpPostType, $settings),
        };

        if ($path === null) {
            return '';
        }

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
    ): ?string {
        $structure = trim((string) ($settings['structure'] ?? ''));
        if ($structure === '') {
            return null;
        }

        return $this->expandPermalinkStructure($structure, $slug, $publishedAt, $wpId, $article);
    }

    /**
     * @param  array<string, mixed>  $settings
     */
    private function buildProductPath(string $slug, array $settings, ?SeoArticle $article): ?string
    {
        // Prefer explicit WooCommerce permalink config (empty product_base = root URLs).
        if (! array_key_exists('woocommerce', $settings) || ! is_array($settings['woocommerce'])) {
            // No Woo config and no product template → unknown (do not use post structure).
            return null;
        }

        $wc = $settings['woocommerce'];
        if (! array_key_exists('product_base', $wc)) {
            return null;
        }

        $base = trim((string) $wc['product_base'], '/');
        $categorySlug = $article instanceof SeoArticle ? $this->resolvePrimaryCategorySlug($article) : '';

        if ($base === '') {
            return $slug;
        }

        $path = str_replace(
            ['%product_cat%', '%product%'],
            [$categorySlug, $slug],
            $base,
        );

        $path = trim((string) (preg_replace('#/+#', '/', $path) ?? $path), '/');

        if (! str_contains($base, '%product%')) {
            $path = $path !== '' ? $path . '/' . $slug : $slug;
        }

        return trim($path, '/');
    }

    /**
     * @param  array<string, mixed>  $settings
     */
    private function buildTermPath(string $slug, string $wpPostType, array $settings): ?string
    {
        $wc = is_array($settings['woocommerce'] ?? null) ? $settings['woocommerce'] : [];

        if (in_array($wpPostType, ['product_cat', 'product_category'], true)) {
            if (! array_key_exists('category_base', $wc)) {
                return null;
            }
            $base = trim((string) $wc['category_base'], '/');
        } else {
            if (! array_key_exists('category_base', $settings)) {
                return null;
            }
            $base = trim((string) ($settings['category_base'] ?? ''), '/');
        }

        if ($base === '') {
            return $slug;
        }

        return trim($base . '/' . $slug, '/');
    }

    /**
     * @param  array<string, mixed>  $settings
     */
    private function buildPagePath(string $slug, array $settings, ?SeoArticle $article): ?string
    {
        // Page URLs are hierarchical and not driven by permalink_structure.
        // Without a site-info page template, only build when we can use parent path meta.
        $parentPath = '';
        if ($article instanceof SeoArticle) {
            $article->loadMissing('articleMetas');
            $parentPath = trim((string) ($article->articleMetas
                ->firstWhere('meta_key', 'wp_page_path')?->meta_value
                ?? $article->articleMetas->firstWhere('meta_key', 'wp_parent_path')?->meta_value
                ?? ''), '/');
        }

        if ($parentPath !== '') {
            return trim($parentPath . '/' . $slug, '/');
        }

        // Flat page: allow /{slug}/ only when templates already covered — otherwise unknown.
        // Many WP sites use root pages; expose via templates['page'] from the bridge.
        // If post_types.page.rewrite is present with empty slug, treat as root page.
        $postTypes = is_array($settings['post_types'] ?? null) ? $settings['post_types'] : [];
        $pageMeta = is_array($postTypes['page'] ?? null) ? $postTypes['page'] : null;
        if ($pageMeta !== null) {
            $rewrite = trim((string) ($pageMeta['rewrite_slug'] ?? ''), '/');

            return $rewrite !== '' ? trim($rewrite . '/' . $slug, '/') : $slug;
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $settings
     */
    private function buildNativeCptPath(string $slug, string $wpPostType, array $settings): ?string
    {
        $postTypes = is_array($settings['post_types'] ?? null) ? $settings['post_types'] : [];
        $meta = is_array($postTypes[$wpPostType] ?? null) ? $postTypes[$wpPostType] : null;
        if ($meta === null) {
            return null;
        }

        $rewrite = trim((string) ($meta['rewrite_slug'] ?? $wpPostType), '/');
        $withFront = (bool) ($meta['with_front'] ?? true);
        $front = '';
        if ($withFront) {
            $structure = trim((string) ($settings['structure'] ?? ''), '/');
            // WordPress with_front prefixes the front base from permalink_structure before %postname%.
            // When structure is /tin-tuc/%postname%.html, front base is tin-tuc — only apply when rewrite is set.
            if (preg_match('#^([^%]+)/#', $structure, $m) === 1) {
                $front = trim($m[1], '/');
            }
        }

        $parts = array_values(array_filter([$front, $rewrite, $slug], static fn (string $p): bool => $p !== ''));

        return implode('/', $parts);
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

        return trim((string) ($article->articleMetas
            ->firstWhere('meta_key', 'wp_primary_category_slug')?->meta_value ?? ''));
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
