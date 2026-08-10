<?php

declare(strict_types=1);

namespace Omnichannel\Addons\WordPress\Services;

use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\WordPress\Services\WordPressSlugFixRequiredException;
use Omnichannel\Addons\WordPress\Services\WordPressWriteReadinessGuard;
use App\Models\Site;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;
use Omnichannel\Addons\WordPress\Services\WordPressArticleContentService;

final class WordPressArticleMediaService
{
    public function __construct(
        private readonly WordPressArticleContentService $wpContent,
    ) {
    }

    /**
     * @return array{success: bool, message: string, featured_image_url?: string, product_gallery?: list<array{id: int, url: string}>}
     */
    public function setFeaturedImage(SeoArticle $article, int $attachmentId): array
    {
        if ($attachmentId <= 0) {
            return [
                'success' => false,
                'message' => 'Attachment không hợp lệ.',
            ];
        }

        return $this->pushMedia($article, [
            'featured_attachment_id' => $attachmentId,
        ]);
    }

    /**
     * @return array{success: bool, message: string, featured_image_url?: string, product_gallery?: list<array{id: int, url: string}>}
     */
    public function clearFeaturedImage(SeoArticle $article): array
    {
        return $this->pushMedia($article, [
            'featured_attachment_id' => 0,
        ]);
    }

    /**
     * @param  list<int>  $attachmentIds
     * @return array{success: bool, message: string, featured_image_url?: string, product_gallery?: list<array{id: int, url: string}>}
     */
    public function setProductGallery(SeoArticle $article, array $attachmentIds): array
    {
        $ids = array_values(array_filter(array_map(static fn ($id): int => (int) $id, $attachmentIds), static fn (int $id): bool => $id > 0));

        return $this->pushMedia($article, [
            'product_gallery_ids' => $ids,
        ]);
    }

    /**
     * @param  list<int>  $attachmentIds
     * @return array{success: bool, message: string, featured_image_url?: string, product_gallery?: list<array{id: int, url: string}>}
     */
    public function appendToProductGallery(SeoArticle $article, int $attachmentId, array $currentGalleryIds): array
    {
        if ($attachmentId <= 0) {
            return [
                'success' => false,
                'message' => 'Attachment không hợp lệ.',
            ];
        }

        $ids = array_values(array_filter(array_map(static fn ($id): int => (int) $id, $currentGalleryIds), static fn (int $id): bool => $id > 0));
        if (! in_array($attachmentId, $ids, true)) {
            $ids[] = $attachmentId;
        }

        return $this->setProductGallery($article, $ids);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{success: bool, message: string, featured_image_url?: string, product_gallery?: list<array{id: int, url: string}>}
     */
    private function pushMedia(SeoArticle $article, array $payload): array
    {
        if ($blocked = $this->blockWhenSlugFixRequired($article, 'article.media_update')) {
            return $blocked;
        }

        $wpId = (int) ($article->wordpressLink?->wp_post_id ?? 0);
        if ($wpId <= 0) {
            return [
                'success' => false,
                'message' => 'Bài chưa liên kết WordPress.',
            ];
        }

        $article->loadMissing('site');
        $site = $article->site;
        if (! $site instanceof Site) {
            return [
                'success' => false,
                'message' => 'Không tìm thấy domain.',
            ];
        }

        $site->loadMissing('metas');
        $writeToken = trim((string) ($site->getMeta('seo_migration_token') ?? ''));
        if ($writeToken === '') {
            return [
                'success' => false,
                'message' => 'Thiếu Migration/Write token trên domain.',
            ];
        }

        $url = $this->buildMediaUrl($site, $article, $wpId);
        if ($url === '') {
            return [
                'success' => false,
                'message' => 'Không xác định được endpoint media WordPress.',
            ];
        }

        try {
            $response = Http::timeout(45)
                ->acceptJson()
                ->withToken($writeToken)
                ->post($url, $payload);

            if (! $response->successful()) {
                $message = (string) ($response->json('message') ?? $response->body());

                return [
                    'success' => false,
                    'message' => 'WordPress trả lỗi HTTP ' . $response->status() . ': ' . mb_substr($message, 0, 300),
                ];
            }

            $body = $response->json();
            if (! is_array($body) || ! ($body['success'] ?? false)) {
                return [
                    'success' => false,
                    'message' => (string) ($body['message'] ?? 'WordPress từ chối cập nhật media.'),
                ];
            }

            $this->persistMediaMeta($article, $body);

            return [
                'success' => true,
                'message' => (string) ($body['message'] ?? 'Đã cập nhật media trên WordPress.'),
                'featured_image_url' => (string) ($body['featured_image_url'] ?? ''),
                'product_gallery' => is_array($body['product_gallery'] ?? null)
                    ? $this->wpContent->normalizeProductGallery($body['product_gallery'])
                    : [],
            ];
        } catch (Throwable $e) {
            Log::warning('WordPress article media update failed', [
                'article_id' => $article->id,
                'wp_post_id' => $wpId,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'message' => 'Không kết nối được WordPress: ' . $e->getMessage(),
            ];
        }
    }

    /**
     * @param  array<string, mixed>  $body
     */
    private function persistMediaMeta(SeoArticle $article, array $body): void
    {
        $featuredUrl = trim((string) ($body['featured_image_url'] ?? ''));
        if ($featuredUrl !== '') {
            $article->articleMetas()->updateOrCreate(
                ['meta_key' => 'wp_featured_image_url'],
                ['meta_value' => $featuredUrl],
            );
            $article->unsetRelation('articleMetas');
            app(\Omnichannel\Addons\Media\Services\ArticleFeaturedImageProjection::class)->rebuildAndPersist($article);
        }

        if (is_array($body['product_gallery'] ?? null) && $body['product_gallery'] !== []) {
            $article->articleMetas()->updateOrCreate(
                ['meta_key' => 'wp_product_gallery'],
                ['meta_value' => json_encode($body['product_gallery'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)],
            );
        }
    }

    private function buildMediaUrl(Site $site, SeoArticle $article, int $wpId): string
    {
        $base = $this->wpContent->getPermalinkBase($site);
        if ($base === '') {
            return '';
        }

        $taxonomy = $this->wpContent->resolveWpTaxonomy($article);
        if ($taxonomy !== null) {
            return $base . '/wp-json/omi-seo-ai/v1/terms/' . rawurlencode($taxonomy) . '/' . $wpId . '/media';
        }

        return $base . '/wp-json/omi-seo-ai/v1/posts/' . $wpId . '/media';
    }

    /**
     * @return array{success: false, message: string, error_code: string}|null
     */
    private function blockWhenSlugFixRequired(SeoArticle $article, string $operation): ?array
    {
        try {
            app(WordPressWriteReadinessGuard::class)->assertCanWriteToWordPress($article, $operation);

            return null;
        } catch (WordPressSlugFixRequiredException) {
            return [
                'success' => false,
                'message' => WordPressSlugFixRequiredException::MESSAGE,
                'error_code' => WordPressSlugFixRequiredException::ERROR_CODE,
            ];
        }
    }
}
