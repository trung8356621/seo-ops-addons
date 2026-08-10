<?php

declare(strict_types=1);

namespace Omnichannel\Addons\WordPress\Services;

use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\Content\Support\ArticlePostTypeResolver;
use Omnichannel\Addons\Seo\Support\CommentReviewRatingAssigner;
use Omnichannel\Addons\Seo\Support\SeoAccessControl;
use Omnichannel\Addons\WordPress\Support\WordPressRestResponseParser;
use Omnichannel\Addons\WordPress\Services\WordPressSlugFixRequiredException;
use Omnichannel\Addons\WordPress\Services\WordPressWriteReadinessGuard;
use App\Models\Site;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;
use Omnichannel\Addons\WordPress\Services\WordPressArticleContentService;

/**
 * Bình luận/review ảo lưu trên WordPress (post meta _omi_seo_virtual_comments).
 * Laravel chỉ đọc qua REST; không giữ bản sao local sau khi đăng.
 */
final class VirtualCommentService
{
    public const ARTICLE_META_KEY = 'virtual_comments';

    public const WP_META_KEY = '_omi_seo_virtual_comments';

    public function __construct(
        private readonly CommentReviewRatingAssigner $ratingAssigner,
        private readonly WordPressArticleContentService $contentService,
    ) {}

    /**
     * @param  list<array<string, mixed>>  $items
     * @return list<array{author: string, content: string, rating?: int, date: string}>
     */
    public function normalizeItems(array $items, bool $isProduct = false, ?SeoArticle $article = null): array
    {
        $validItems = [];
        foreach (array_values($items) as $item) {
            if (! is_array($item)) {
                continue;
            }

            $content = trim((string) ($item['content'] ?? $item['comment'] ?? ''));
            if ($content === '') {
                continue;
            }

            $validItems[] = $item;
        }

        $publishedAt = $this->resolvePostPublishedAt($article);
        $staggeredDates = $this->buildStaggeredCommentDates(count($validItems), $publishedAt);

        $normalized = [];

        foreach ($validItems as $index => $item) {
            $author = trim((string) ($item['author'] ?? $item['author_name'] ?? 'Khách mua hàng'));
            if ($author === '') {
                $author = 'Khách mua hàng';
            }

            $row = [
                'author' => $author,
                'content' => trim((string) ($item['content'] ?? $item['comment'] ?? '')),
                'date' => $this->resolveDate(
                    $item,
                    $staggeredDates[$index] ?? $staggeredDates[0] ?? $publishedAt->format('Y-m-d H:i:s'),
                ),
            ];

            if ($isProduct) {
                $row['rating'] = $this->ratingAssigner->resolve(
                    $this->resolveExplicitRating($item),
                    $index,
                );
            }

            $normalized[] = $row;
        }

        return $normalized;
    }

    /**
     * @param  list<array<string, mixed>>  $items
     */
    public function clearFromArticle(SeoArticle $article): void
    {
        $article->articleMetas()->where('meta_key', self::ARTICLE_META_KEY)->delete();
    }

    /**
     * @param  list<array<string, mixed>>  $items
     * @return array{success: bool, message: string, count?: int}
     */
    public function pushToWordPress(SeoArticle $article, array $items): array
    {
        if (! SeoAccessControl::canSyncArticlesToWordPress()) {
            return [
                'success' => false,
                'message' => 'Quản lý nội dung không được đăng bình luận ảo lên WordPress.',
            ];
        }

        $wpPostId = (int) ($article->wordpressLink?->wp_post_id ?? 0);
        if ($wpPostId <= 0) {
            return [
                'success' => false,
                'message' => 'Bài viết chưa có WordPress Post ID.',
            ];
        }

        $article->loadMissing('site');
        $site = $article->site;
        if (! $site instanceof Site) {
            return [
                'success' => false,
                'message' => 'Bài viết chưa gắn domain.',
            ];
        }

        $isProduct = ArticlePostTypeResolver::resolve($article) === 'product';
        $payloadComments = $this->normalizeItems($items, $isProduct, $article);

        if ($payloadComments === []) {
            return [
                'success' => false,
                'message' => 'Không có bình luận hợp lệ để lưu.',
            ];
        }

        $result = $this->postVirtualCommentsToWordPress($site, $wpPostId, $payloadComments, $isProduct, (int) $article->id);

        if ($result['success'] ?? false) {
            $this->clearFromArticle($article);
        }

        return $result;
    }

    /**
     * @param  list<array<string, mixed>>  $items
     */
    public function storeOnArticle(SeoArticle $article, array $items, bool $isProduct = false): void
    {
        $payload = $this->normalizeItems($items, $isProduct, $article);

        if ($payload === []) {
            $article->articleMetas()->where('meta_key', self::ARTICLE_META_KEY)->delete();

            return;
        }

        $article->articleMetas()->updateOrCreate(
            ['meta_key' => self::ARTICLE_META_KEY],
            ['meta_value' => json_encode($payload, JSON_UNESCAPED_UNICODE)],
        );
    }

    /**
     * @return list<array{author: string, content: string, rating?: int, date: string}>
     */
    public function getFromArticle(SeoArticle $article): array
    {
        $article->loadMissing('articleMetas');
        $meta = $article->articleMetas->firstWhere('meta_key', self::ARTICLE_META_KEY);
        if ($meta === null || ! filled($meta->meta_value)) {
            return [];
        }

        try {
            $decoded = json_decode((string) $meta->meta_value, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return [];
        }

        if (! is_array($decoded)) {
            return [];
        }

        $isProduct = ArticlePostTypeResolver::resolve($article) === 'product';

        $result = [];
        foreach (array_values($decoded) as $index => $row) {
            if (! is_array($row)) {
                continue;
            }

            $content = trim((string) ($row['content'] ?? $row['comment'] ?? ''));
            if ($content === '') {
                continue;
            }

            $date = trim((string) ($row['date'] ?? ''));
            if ($date === '') {
                $date = now()->format('Y-m-d H:i:s');
            }

            $entry = [
                'author' => trim((string) ($row['author'] ?? 'Khách mua hàng')) ?: 'Khách mua hàng',
                'content' => $content,
                'date' => $date,
            ];

            if ($isProduct) {
                $entry['rating'] = $this->ratingAssigner->resolve(
                    $this->resolveExplicitRating($row),
                    $index,
                );
            } elseif (isset($row['rating']) && is_numeric($row['rating'])) {
                $entry['rating'] = max(1, min(5, (int) $row['rating']));
            }

            $result[] = $entry;
        }

        return array_values($result);
    }

    /**
     * @return list<array{author: string, content: string, rating?: int, date: string}>
     */
    public function getFromWordPress(SeoArticle $article): array
    {
        $wpPostId = (int) ($article->wordpressLink?->wp_post_id ?? 0);
        if ($wpPostId <= 0) {
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

        $base = $this->contentService->getPermalinkBase($site);
        if ($base === '') {
            return [];
        }

        $url = $base . '/wp-json/omi-seo-ai/v1/posts/' . $wpPostId . '/comment-reviews';

        try {
            $response = Http::timeout(30)
                ->acceptJson()
                ->withToken($readToken)
                ->get($url);
        } catch (Throwable) {
            return [];
        }

        if (! $response->successful()) {
            return [];
        }

        $body = $response->json();
        if (! is_array($body)) {
            return [];
        }

        $items = $body['items'] ?? null;
        if (! is_array($items)) {
            return [];
        }

        $isProduct = ArticlePostTypeResolver::resolve($article) === 'product';
        $result = [];
        foreach (array_values($items) as $index => $row) {
            if (! is_array($row)) {
                continue;
            }

            $content = trim((string) ($row['content'] ?? $row['comment'] ?? ''));
            if ($content === '') {
                continue;
            }

            $date = trim((string) ($row['date'] ?? ''));
            if ($date === '') {
                $date = now()->format('Y-m-d H:i:s');
            }

            $entry = [
                'author' => trim((string) ($row['author'] ?? 'Khách mua hàng')) ?: 'Khách mua hàng',
                'content' => $content,
                'date' => $date,
            ];

            if ($isProduct) {
                $entry['rating'] = $this->ratingAssigner->resolve(
                    $this->resolveExplicitRating($row),
                    $index,
                );
            } elseif (isset($row['rating']) && is_numeric($row['rating'])) {
                $entry['rating'] = max(1, min(5, (int) $row['rating']));
            }

            $result[] = $entry;
        }

        return array_values($result);
    }

    /**
     * Đọc bình luận ảo từ WordPress. Chỉ fallback meta Laravel cũ khi chưa đọc được WP.
     *
     * @return list<array{author: string, content: string, rating?: int, date: string}>
     */
    public function getForEditor(SeoArticle $article): array
    {
        $fromWordPress = $this->getFromWordPress($article);
        if ($fromWordPress !== []) {
            return $fromWordPress;
        }

        $legacyLocal = $this->getFromArticle($article);
        if ($legacyLocal === []) {
            return [];
        }

        // Full cutover: không silent migrate-push WP khi đọc editor.
        // Manual sync qua syncToWordPress(); automation qua HookAction.

        return $legacyLocal;
    }

    /**
     * @param  list<array<string, mixed>>|null  $items  null = migrate bản Laravel cũ (nếu còn)
     * @return array{success: bool, message: string, count?: int}
     */
    public function syncToWordPress(SeoArticle $article, ?array $items = null): array
    {
        if ($items !== null) {
            return $this->pushToWordPress($article, $items);
        }

        $legacyLocal = $this->getFromArticle($article);
        if ($legacyLocal !== []) {
            return $this->pushToWordPress($article, $legacyLocal);
        }

        return [
            'success' => false,
            'message' => 'Bình luận ảo được lưu trực tiếp trên WordPress. Không còn bản sao trên Laravel.',
        ];
    }

    /**
     * @param  list<array{author: string, content: string, rating?: int, date: string}>  $payloadComments
     * @return array{success: bool, message: string, count?: int}
     */
    private function postVirtualCommentsToWordPress(
        Site $site,
        int $wpPostId,
        array $payloadComments,
        bool $isProduct,
        int $articleId,
    ): array {
        if ($blocked = $this->blockWhenSlugFixRequired($articleId, $site, 'virtual_comments.sync')) {
            return $blocked;
        }

        $site->loadMissing('metas');
        $writeToken = trim((string) ($site->getMeta('seo_migration_token') ?? ''));
        if ($writeToken === '') {
            return [
                'success' => false,
                'message' => 'Thiếu Migration/Write token trên domain.',
            ];
        }

        $url = $this->buildSyncUrl($site, $wpPostId);
        if ($url === '') {
            return [
                'success' => false,
                'message' => 'Không xác định được URL WordPress.',
            ];
        }

        try {
            $response = Http::timeout(60)
                ->acceptJson()
                ->withToken($writeToken)
                ->post($url, [
                    'virtual_comments' => $payloadComments,
                    'meta_input' => [
                        self::WP_META_KEY => json_encode(
                            $payloadComments,
                            JSON_UNESCAPED_UNICODE | (defined('JSON_INVALID_UTF8_SUBSTITUTE') ? JSON_INVALID_UTF8_SUBSTITUTE : 0),
                        ),
                    ],
                ]);

            if (! $response->successful()) {
                return [
                    'success' => false,
                    'message' => WordPressRestResponseParser::formatHttpErrorMessage(
                        $response->status(),
                        $response,
                    ),
                ];
            }

            $body = $response->json();
            if (! is_array($body) || ! ($body['success'] ?? false)) {
                return [
                    'success' => false,
                    'message' => (string) ($body['message'] ?? 'WordPress từ chối lưu bình luận ảo.'),
                ];
            }

            $count = (int) ($body['virtual_count'] ?? $body['count'] ?? count($payloadComments));
            $kind = $isProduct ? 'review ảo' : 'bình luận ảo';

            return [
                'success' => true,
                'message' => sprintf('Đã lưu %d %s trên WordPress.', $count, $kind),
                'count' => $count,
            ];
        } catch (Throwable $e) {
            Log::error('Virtual comments sync failed', [
                'article_id' => $articleId,
                'wp_post_id' => $wpPostId,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'message' => 'Không kết nối được WordPress: ' . $e->getMessage(),
            ];
        }
    }

    private function buildSyncUrl(Site $site, int $wpPostId): string
    {
        $base = $this->contentService->getPermalinkBase($site);
        if ($base === '') {
            return '';
        }

        return $base . '/wp-json/omi-seo-ai/v1/posts/' . $wpPostId . '/virtual-comments';
    }

    private function resolvePostPublishedAt(?SeoArticle $article): Carbon
    {
        if ($article !== null) {
            if ($article->publishingState?->published_at instanceof Carbon) {
                return $article->publishingState->published_at->copy();
            }

            if ($article->created_at instanceof Carbon) {
                return $article->created_at->copy();
            }
        }

        return Carbon::now();
    }

    /**
     * Mỗi comment: +2..+6 ngày sau ngày đăng bài, offset khác nhau khi có thể.
     *
     * @return list<string>
     */
    private function buildStaggeredCommentDates(int $count, Carbon $publishedAt): array
    {
        if ($count <= 0) {
            return [];
        }

        $pool = [2, 3, 4, 5, 6];
        shuffle($pool);

        $dates = [];

        for ($i = 0; $i < $count; $i++) {
            $days = $i < count($pool)
                ? $pool[$i]
                : random_int(2, 6);

            $dates[] = $publishedAt->copy()
                ->addDays($days)
                ->setTime(random_int(8, 21), random_int(0, 59), 0)
                ->format('Y-m-d H:i:s');
        }

        sort($dates);

        return $dates;
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private function resolveExplicitRating(array $item): ?int
    {
        foreach (['rating', 'star_ranking', 'stars', 'star'] as $key) {
            if (isset($item[$key]) && is_numeric($item[$key])) {
                return max(1, min(5, (int) $item[$key]));
            }
        }

        return null;
    }

    /**
     * @return array{success: false, message: string, error_code: string}|null
     */
    private function blockWhenSlugFixRequired(int $articleId, Site $site, string $operation): ?array
    {
        try {
            $article = SeoArticle::query()->find($articleId);
            app(WordPressWriteReadinessGuard::class)->assertCanWriteToWordPress(
                $article instanceof SeoArticle ? $article : $site,
                $operation,
            );

            return null;
        } catch (WordPressSlugFixRequiredException) {
            return [
                'success' => false,
                'message' => WordPressSlugFixRequiredException::MESSAGE,
                'error_code' => WordPressSlugFixRequiredException::ERROR_CODE,
            ];
        }
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private function resolveDate(array $item, string $fallbackDate): string
    {
        $raw = trim((string) ($item['date'] ?? $item['comment_date'] ?? ''));
        if ($raw !== '') {
            try {
                return Carbon::parse($raw)->format('Y-m-d H:i:s');
            } catch (\Throwable) {
                // fall through
            }
        }

        return $fallbackDate;
    }
}
