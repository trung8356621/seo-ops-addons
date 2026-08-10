<?php

declare(strict_types=1);

namespace Omnichannel\Addons\WordPress\Services;

use Omnichannel\Addons\SearchFoundation\Models\Keyword;
use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\Content\Support\ArticleContentSslUrlNormalizer;
use Omnichannel\Addons\SearchIntelligence\Support\KeywordFocusAttach;
use Omnichannel\Addons\WordPress\Support\WordPressPermalinkBuilder;
use App\Models\Site;
use Carbon\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;
use Omnichannel\Addons\Media\Services\ArticlePostImagesService;

class WordPressArticleContentService
{
    public function __construct(
        private readonly WordPressPermalinkBuilder $permalinkBuilder,
        private readonly WordPressArticleTimestampService $timestampService,
        private readonly ArticleContentSslUrlNormalizer $sslUrlNormalizer,
    ) {}

    public function resolveEditorHtml(SeoArticle $article): string
    {
        $article->loadMissing('site');

        $body = trim((string) ($article->body ?? ''));
        if ($body !== '') {
            return $this->normalizeEditorHtmlForSite($body, $article->site);
        }

        $cached = trim((string) $this->getMeta($article, 'wp_post_content', ''));
        $entity = trim((string) $this->getMeta($article, 'wp_entity', ''));

        // With no local body, WordPress remains the content source until the next local save.
        if ((int) ($article->wordpressLink?->wp_post_id ?? 0) > 0) {
            $remote = $this->fetchFromWordPress($article, importFaqs: false);
            $scoring = is_array($remote['scoring'] ?? null) ? $remote['scoring'] : [];
            $prepared = app(ArticlePostImagesService::class)->prepareEditorHtmlFromWordPressSources(
                $article,
                trim((string) ($remote['post_content'] ?? '')),
                trim((string) ($scoring['body'] ?? '')),
                is_array($remote['post_images'] ?? null) ? $remote['post_images'] : [],
            );
            if ($prepared !== '') {
                $article->articleMetas()->updateOrCreate(
                    ['meta_key' => 'wp_post_content'],
                    ['meta_value' => $prepared],
                );
                app(ArticleFaqWordPressRestoreService::class)->persistWordPressSourceSnapshot($article, $prepared);

                return $this->normalizeEditorHtmlForSite($prepared, $article->site);
            }
        }

        // Danh mục (product_cat / category): chỉ tin cache sau khi đồng bộ đúng entity=term.
        // Tránh hiển thị nhầm nội dung post WP trùng term_id (vd. JSON font family).
        if ($this->isTaxonomyRecord($article)) {
            if ($entity === 'term' && $cached !== '') {
                return $this->normalizeEditorHtmlForSite($cached, $article->site);
            }

            return $this->normalizeEditorHtmlForSite($cached, $article->site);
        }

        if ($cached !== '') {
            return $this->normalizeEditorHtmlForSite($cached, $article->site);
        }

        return '';
    }

    private function normalizeEditorHtmlForSite(string $html, mixed $site): string
    {
        return $this->sslUrlNormalizer->normalizeForSite(
            $html,
            $site instanceof Site ? $site : null,
        );
    }

    public function resolveSlug(SeoArticle $article): string
    {
        if (filled($article->slug)) {
            return (string) $article->slug;
        }

        $remote = $this->fetchFromWordPress($article);

        return trim((string) ($remote['slug'] ?? ''));
    }

    /**
     * URL công khai theo cấu trúc permalink WordPress (không ghép domain + slug).
     */
    public function resolvePermalink(SeoArticle $article): string
    {
        $cached = trim((string) $this->getMeta($article, 'wp_permalink', ''));
        if ($cached !== '') {
            return (int) ($article->wordpressLink?->wp_post_id ?? 0) > 0
                ? $cached
                : $this->permalinkBuilder->resolve($article, $cached, $this->resolveSlug($article));
        }

        $remote = $this->fetchFromWordPress($article);
        $remotePermalink = trim((string) ($remote['permalink'] ?? ''));

        return (int) ($article->wordpressLink?->wp_post_id ?? 0) > 0
            ? $remotePermalink
            : $this->permalinkBuilder->resolve($article, $remotePermalink, $this->resolveSlug($article));
    }

    public function resolveWordPressAdminEditUrl(SeoArticle $article): ?string
    {
        $wpPostId = (int) ($article->wordpressLink?->wp_post_id ?? 0);
        if ($wpPostId <= 0) {
            return null;
        }

        $article->loadMissing('site');
        $site = $article->site;
        if ($site === null) {
            return null;
        }

        $base = $this->getPermalinkBase($site);
        if ($base === '') {
            return null;
        }

        return $base.'/wp-admin/post.php?post='.$wpPostId.'&action=edit';
    }

    public function resolveStoredWordPressPermalink(SeoArticle $article): string
    {
        return trim((string) $this->getMeta($article, 'wp_permalink', ''));
    }

    /**
     * Sau khi đẩy slug lên WordPress: fetch lại slug + permalink thật (WP có thể chuẩn hóa slug / đổi URL).
     *
     * @return array{success: bool, slug: string, permalink: string}
     */
    public function refreshSlugAndPermalinkFromWordPress(SeoArticle $article): array
    {
        $post = $this->fetchFromWordPress($article, importFaqs: false);
        if ($post === []) {
            $article->refresh();

            return [
                'success' => false,
                'slug' => $this->resolveSlug($article),
                'permalink' => $this->resolvePermalink($article),
            ];
        }

        $slug = trim((string) ($post['slug'] ?? ''));
        $permalink = trim((string) ($post['permalink'] ?? ''));

        $updates = [];
        if ($slug !== '' && $slug !== trim((string) ($article->slug ?? ''))) {
            $updates['slug'] = $slug;
        }
        if ($updates !== []) {
            $article->update($updates);
        }

        $article->refresh();

        if ($slug === '') {
            $slug = $this->resolveSlug($article);
        }
        if ($permalink === '') {
            $permalink = $this->resolvePermalink($article);
        }

        if ($slug !== '') {
            $article->update(['slug' => $slug]);
        }
        // wp_slug meta retired — articles.slug is SoT.
        $article->articleMetas()->where('meta_key', 'wp_slug')->delete();
        if ($permalink !== '') {
            $article->articleMetas()->updateOrCreate(
                ['meta_key' => 'wp_permalink'],
                ['meta_value' => $permalink],
            );
        }

        return [
            'success' => true,
            'slug' => $slug,
            'permalink' => $permalink,
        ];
    }

    public function resolveFeaturedImageUrl(SeoArticle $article): ?string
    {
        $cached = trim((string) $this->getMeta($article, 'wp_featured_image_url', ''));
        if ($cached !== '') {
            return $cached;
        }

        $remote = $this->fetchFromWordPress($article);

        $url = trim((string) ($remote['featured_image_url'] ?? ''));

        return $url !== '' ? $url : null;
    }

    /**
     * Album ảnh sản phẩm WooCommerce (đồng bộ từ WordPress).
     *
     * @return array<int, array{id: int, url: string, source?: string, asset_key?: string}>
     */
    public function resolveProductGallery(SeoArticle $article): array
    {
        if ($this->isTaxonomyRecord($article)) {
            return [];
        }

        $cached = $this->getMetaJson($article, 'wp_product_gallery');
        if ($cached !== []) {
            return $this->normalizeProductGallery($cached);
        }

        $remote = $this->fetchFromWordPress($article);
        $gallery = $remote['product_gallery'] ?? null;

        return is_array($gallery) ? $this->normalizeProductGallery($gallery) : [];
    }

    public function getPermalinkBase(Site $site): string
    {
        $domain = trim((string) $site->domain);
        if ($domain === '') {
            return '';
        }

        if (preg_match('#^https?://#i', $domain)) {
            return rtrim($domain, '/');
        }

        $scheme = ! empty($site->ssl) ? 'https' : 'http';

        return $scheme.'://'.rtrim($domain, '/');
    }

    /**
     * @return array<string, mixed>
     */
    public function fetchFromWordPress(SeoArticle $article, bool $importFaqs = true, bool $forceSeoImport = false): array
    {
        $wpId = (int) ($article->wordpressLink?->wp_post_id ?? 0);
        if ($wpId <= 0) {
            return [];
        }

        $article->loadMissing('site');
        $site = $article->site;
        if (! $site instanceof Site) {
            return [];
        }

        $site->loadMissing('metas');

        $readToken = trim((string) ($site->getMeta('seo_read_token') ?? ''));
        if ($readToken === '') {
            return [];
        }

        $taxonomy = $this->resolveWpTaxonomy($article);
        $url = $taxonomy !== null
            ? $this->buildTermUrl($site, $taxonomy, $wpId)
            : $this->buildPostUrl($site, $wpId);

        if ($url === '') {
            return [];
        }

        try {
            $response = Http::timeout(30)
                ->acceptJson()
                ->withToken($readToken)
                ->withHeaders([
                    'Cache-Control' => 'no-cache, no-store, max-age=0',
                    'Pragma' => 'no-cache',
                ])
                ->get($url, [
                    '_seo_fresh' => now()->getTimestampMs(),
                ]);

            if (! $response->successful()) {
                return [];
            }

            $payload = $response->json();
            if (! is_array($payload) || ! ($payload['success'] ?? false)) {
                return [];
            }

            $post = is_array($payload['post'] ?? null) ? $payload['post'] : [];

            if ($taxonomy !== null) {
                $this->persistTaxonomyIdentityMeta(
                    $article,
                    $taxonomy,
                    array_merge($post, [
                        'parent_id' => (int) ($post['parent_id'] ?? 0),
                    ]),
                );
            }

            $this->persistFetchedMeta($article, $post, $taxonomy !== null, $importFaqs, $forceSeoImport);
            app(ArticleLastSavedTimestampService::class)->touchSynced($article);

            return $post;
        } catch (Throwable $e) {
            Log::warning('WordPress content fetch failed', [
                'article_id' => $article->id,
                'wp_post_id' => $wpId,
                'taxonomy' => $taxonomy,
                'error' => $e->getMessage(),
            ]);

            return [];
        }
    }

    /**
     * @deprecated Use fetchFromWordPress()
     *
     * @return array<string, mixed>
     */
    public function fetchPostFromWordPress(SeoArticle $article): array
    {
        return $this->fetchFromWordPress($article);
    }

    public function isTaxonomyRecord(SeoArticle $article): bool
    {
        return $this->resolveWpTaxonomy($article) !== null;
    }

    public function resolveWpTaxonomy(SeoArticle $article): ?string
    {
        $entity = trim((string) $this->getMeta($article, 'wp_entity', ''));
        if ($entity === 'term') {
            $wpTaxonomy = trim((string) $this->getMeta($article, 'wp_taxonomy', ''));
            if ($wpTaxonomy !== '') {
                $normalized = $this->normalizeTaxonomySlug($wpTaxonomy);
                if ($normalized !== null) {
                    return $normalized;
                }
            }

            $type = strtolower(trim((string) ($article->type ?? '')));

            return match ($type) {
                'product_category' => 'product_cat',
                'category' => 'category',
                default => null,
            };
        }

        if ($entity === 'post') {
            return null;
        }

        $type = strtolower(trim((string) ($article->type ?? '')));
        if ($type === 'product_category') {
            return 'product_cat';
        }
        if ($type === 'category') {
            return 'category';
        }

        return null;
    }

    public function healTaxonomyMetaFromWordPress(SeoArticle $article): bool
    {
        if ($this->resolveWpTaxonomy($article) !== null) {
            return false;
        }

        $entity = trim((string) $this->getMeta($article, 'wp_entity', ''));
        if ($entity !== 'term') {
            return false;
        }

        $wpId = (int) ($article->wordpressLink?->wp_post_id ?? 0);
        if ($wpId <= 0) {
            return false;
        }

        $article->loadMissing('site');
        $site = $article->site;
        if (! $site instanceof Site) {
            return false;
        }

        $readToken = trim((string) ($site->getMeta('seo_read_token') ?? ''));
        if ($readToken === '') {
            return false;
        }

        foreach (['product_cat', 'category'] as $taxonomy) {
            $url = $this->buildTermUrl($site, $taxonomy, $wpId);
            if ($url === '') {
                continue;
            }

            try {
                $response = Http::timeout(15)
                    ->acceptJson()
                    ->withToken($readToken)
                    ->get($url);

                if (! $response->successful()) {
                    continue;
                }

                $payload = $response->json();
                if (! is_array($payload) || ! ($payload['success'] ?? false)) {
                    continue;
                }

                $post = is_array($payload['post'] ?? null) ? $payload['post'] : [];
                $this->persistTaxonomyIdentityMeta($article, $taxonomy, $post);

                return true;
            } catch (Throwable) {
                continue;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $post
     */
    private function persistTaxonomyIdentityMeta(SeoArticle $article, string $wpTaxonomy, array $post): void
    {
        $seoType = $wpTaxonomy === 'product_cat' ? 'product_category' : 'category';

        $article->update(['type' => $seoType]);
        $article->articleMetas()->updateOrCreate(
            ['meta_key' => 'wp_entity'],
            ['meta_value' => 'term'],
        );
        $article->articleMetas()->updateOrCreate(
            ['meta_key' => 'wp_taxonomy'],
            ['meta_value' => $wpTaxonomy],
        );
        $article->articleMetas()->where('meta_key', 'wp_post_type')->delete();

        $hasParent = array_key_exists('parent_term_id', $post) || array_key_exists('parent_id', $post);
        if (! $hasParent) {
            return;
        }

        $raw = array_key_exists('parent_term_id', $post)
            ? $post['parent_term_id']
            : $post['parent_id'];

        if ($raw === null || $raw === '') {
            $article->articleMetas()->where('meta_key', 'wp_parent_id')->delete();

            return;
        }

        // Preserve parent=0 as "0" — never delete on zero (Site MCP fail-closed).
        $parentId = (int) $raw;
        $article->articleMetas()->updateOrCreate(
            ['meta_key' => 'wp_parent_id'],
            ['meta_value' => (string) $parentId],
        );
    }

    private function normalizeTaxonomySlug(string $taxonomy): ?string
    {
        return match ($taxonomy) {
            'product_cat', 'product_category' => 'product_cat',
            'category' => 'category',
            default => null,
        };
    }

    /**
     * @param  array<string, mixed>  $post
     */
    private function persistFetchedMeta(SeoArticle $article, array $post, bool $isTaxonomy, bool $importFaqs = true, bool $forceSeoImport = false): void
    {
        $syncFlags = app(ArticleWordPressSyncFlagService::class);

        if (! $syncFlags->shouldBlockWordPressImport($article)) {
            $title = $syncFlags->decodeWordPressText($this->resolvePostTitle($post));
            if ($title !== '') {
                $article->update(['title' => $title]);
                $article->articleMetas()->updateOrCreate(
                    ['meta_key' => 'wp_post_title'],
                    ['meta_value' => $title],
                );
            }

            $updates = [];
            $remoteStatus = strtolower(trim((string) ($post['status'] ?? '')));
            if ($remoteStatus !== '') {
                $updates['status'] = match ($remoteStatus) {
                    'publish', 'published' => 'published',
                    'future', 'scheduled' => 'scheduled',
                    'private' => 'private',
                    default => 'draft',
                };
            }

            $publishedAt = $this->parseRemotePublishedAt($post['published_at'] ?? null);
            if ($publishedAt !== null) {
                $updates['published_at'] = $publishedAt;
            }

            if ($updates !== []) {
                $article->update($updates);
            }
        }

        if (array_key_exists('post_content', $post)) {
            $rawContent = (string) $post['post_content'];
            // Prefer typed body when empty; keep wp_post_content cache for COMPAT readers one release.
            if (trim((string) ($article->body ?? '')) === '' && trim($rawContent) !== '') {
                $article->articleMetas()->updateOrCreate(
                    ['meta_key' => 'wp_post_content'],
                    ['meta_value' => $rawContent],
                );
            }

            app(ArticleFaqWordPressRestoreService::class)->persistWordPressSourceSnapshot($article, $rawContent);
        }

        if (filled($post['slug'] ?? null)) {
            $slug = (string) $post['slug'];
            if (trim((string) ($article->slug ?? '')) === '') {
                $article->update(['slug' => $slug]);
            }
            $article->articleMetas()->where('meta_key', 'wp_slug')->delete();
        }

        if (filled($post['permalink'] ?? null)) {
            $permalink = trim((string) $post['permalink']);
            $article->articleMetas()->updateOrCreate(
                ['meta_key' => 'wp_permalink'],
                ['meta_value' => $permalink],
            );
        }

        if (filled($post['featured_image_url'] ?? null)) {
            $article->articleMetas()->updateOrCreate(
                ['meta_key' => 'wp_featured_image_url'],
                ['meta_value' => (string) $post['featured_image_url']],
            );
            $article->unsetRelation('articleMetas');
            app(\Omnichannel\Addons\Media\Services\ArticleFeaturedImageProjection::class)->rebuildAndPersist($article);
        }

        if (is_array($post['product_gallery'] ?? null) && $post['product_gallery'] !== []) {
            $article->articleMetas()->updateOrCreate(
                ['meta_key' => 'wp_product_gallery'],
                ['meta_value' => json_encode($post['product_gallery'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)],
            );
        }

        if (is_array($post['post_images'] ?? null) && $post['post_images'] !== []) {
            app(ArticlePostImagesService::class)->importFromSyncItem($article, $post);
        }

        if (is_array($post['faqs'] ?? null) && $post['faqs'] !== []) {
            $article->articleMetas()->updateOrCreate(
                ['meta_key' => 'wp_faqs'],
                ['meta_value' => json_encode($post['faqs'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)],
            );
        }

        $categoryIds = $this->extractCategoryIdsFromPost($post);
        if ($categoryIds !== []) {
            $article->articleMetas()->updateOrCreate(
                ['meta_key' => 'wp_category_ids'],
                ['meta_value' => json_encode($categoryIds, JSON_THROW_ON_ERROR)],
            );

            if (! $isTaxonomy) {
                $article->articleMetas()->updateOrCreate(
                    ['meta_key' => 'category_ids'],
                    ['meta_value' => json_encode($categoryIds, JSON_THROW_ON_ERROR)],
                );
            }
        }

        if ($importFaqs && is_array($post['faqs'] ?? null) && $article->faqs()->count() === 0) {
            app(ArticleFaqWordPressImportService::class)->importFromWordPressSyncItem($article, $post);
        }

        $this->importSeoFromFetchedPost($article, $post, $forceSeoImport);
        $this->timestampService->sync($article, $post);

        if (! $isTaxonomy) {
            app(ArticleKeywordLinkReconcileService::class)->reconcileForArticle($article->fresh());
        }
    }

    /**
     * @param  array<string, mixed>  $post
     */
    private function importSeoFromFetchedPost(SeoArticle $article, array $post, bool $force = false): void
    {
        $seo = is_array($post['seo'] ?? null) ? $post['seo'] : [];
        if ($seo === []) {
            return;
        }

        $article->loadMissing(['articleMetas', 'site']);

        $focusKeyword = Keyword::preparePhraseForStorage((string) ($seo['focus_keyword'] ?? ''));
        $existingFocus = trim((string) ($article->articleMetas->firstWhere('meta_key', 'seo_focus_keyword')?->meta_value ?? ''));

        if ($focusKeyword !== '' && ($force || $existingFocus === '')) {
            $article->articleMetas()->updateOrCreate(
                ['meta_key' => 'seo_focus_keyword'],
                ['meta_value' => $focusKeyword],
            );

            $siteId = (int) ($article->site_id ?? 0);
            $userId = (int) (auth()->id() ?? $article->user_id ?? $article->site?->user_id ?? 0);
            if ($siteId > 0 && $userId > 0) {
                KeywordFocusAttach::syncMainKeyword($article, $siteId, $userId, $focusKeyword);
            }
        }

        if (app(ArticleWordPressSyncFlagService::class)->shouldBlockWordPressImport($article) && ! $force) {
            return;
        }

        $metaMap = [
            'seo_meta_description' => (string) ($seo['meta_description'] ?? ''),
        ];

        $seoTitle = trim((string) ($seo['seo_title'] ?? ''));
        if ($seoTitle !== '' && ($force || trim((string) ($article->title ?? '')) === '')) {
            $article->update(['title' => $seoTitle]);
        }
        $article->articleMetas()->where('meta_key', 'seo_title')->delete();

        foreach ($metaMap as $metaKey => $metaValue) {
            $metaValue = trim($metaValue);
            if ($metaValue === '') {
                continue;
            }

            $existing = trim((string) ($article->articleMetas->firstWhere('meta_key', $metaKey)?->meta_value ?? ''));
            if (! $force && $existing !== '') {
                continue;
            }

            $article->articleMetas()->updateOrCreate(
                ['meta_key' => $metaKey],
                ['meta_value' => $metaValue],
            );
        }
    }

    /**
     * @param  array<string, mixed>  $post
     */
    public function resolvePostTitle(array $post): string
    {
        return trim((string) ($post['title'] ?? $post['post_title'] ?? ''));
    }

    private function getMeta(SeoArticle $article, string $key, ?string $default = null): ?string
    {
        $article->loadMissing('articleMetas');
        $value = $article->articleMetas->firstWhere('meta_key', $key)?->meta_value;

        return $value !== null && $value !== '' ? (string) $value : $default;
    }

    /**
     * @return array<int, mixed>
     */
    private function getMetaJson(SeoArticle $article, string $key): array
    {
        $raw = $this->getMeta($article, $key, '');
        if ($raw === null || $raw === '') {
            return [];
        }

        $decoded = json_decode((string) $raw, true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @param  array<int, mixed>  $items
     * @return array<int, array{id: int, url: string}>
     */
    public function normalizeProductGallery(array $items): array
    {
        $gallery = [];

        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }

            $url = trim((string) ($item['url'] ?? ''));
            if ($url === '') {
                continue;
            }

            $row = [
                'id' => (int) ($item['id'] ?? 0),
                'url' => $url,
            ];
            if (isset($item['source'])) {
                $row['source'] = (string) $item['source'];
            }
            if (isset($item['asset_key'])) {
                $row['asset_key'] = (string) $item['asset_key'];
            }
            $gallery[] = $row;
        }

        return $gallery;
    }

    private function buildPostUrl(Site $site, int $wpPostId): string
    {
        $base = $this->getPermalinkBase($site);
        if ($base === '') {
            return '';
        }

        return $base.'/wp-json/omi-seo-ai/v1/posts/'.$wpPostId;
    }

    private function buildTermUrl(Site $site, string $taxonomy, int $termId): string
    {
        $base = $this->getPermalinkBase($site);
        if ($base === '') {
            return '';
        }

        return $base.'/wp-json/omi-seo-ai/v1/terms/'.rawurlencode($taxonomy).'/'.$termId;
    }

    private function parseRemotePublishedAt(mixed $value): ?Carbon
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return Carbon::parse($value)->timezone(config('app.timezone'));
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * URL đẩy nội dung từ SEO editor lên WordPress (post hoặc taxonomy term).
     */
    public function buildEditorSyncUrl(Site $site, SeoArticle $article): string
    {
        $wpId = (int) ($article->wordpressLink?->wp_post_id ?? 0);
        if ($wpId <= 0) {
            return '';
        }

        $base = $this->getPermalinkBase($site);
        if ($base === '') {
            return '';
        }

        $taxonomy = $this->resolveWpTaxonomy($article);
        if ($taxonomy !== null) {
            return $base.'/wp-json/omi-seo-ai/v1/terms/'.rawurlencode($taxonomy).'/'.$wpId.'/editor-sync';
        }

        return $base.'/wp-json/omi-seo-ai/v1/posts/'.$wpId.'/editor-sync';
    }

    /**
     * @param  array<string, mixed>  $post
     * @return list<int>
     */
    public function extractCategoryIdsFromPost(array $post): array
    {
        $raw = $post['category_ids'] ?? [];
        if (! is_array($raw)) {
            return [];
        }

        return collect($raw)
            ->map(static fn (mixed $id): int => (int) $id)
            ->filter(static fn (int $id): bool => $id > 0)
            ->unique()
            ->values()
            ->all();
    }
}
