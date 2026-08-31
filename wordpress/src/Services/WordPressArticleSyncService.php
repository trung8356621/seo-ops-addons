<?php

declare(strict_types=1);

namespace Omnichannel\Addons\WordPress\Services;


use Omnichannel\Addons\Seo\Services\SeoAnalyzerService;
use Omnichannel\Addons\AiPrompt\Services\WorkflowParserService;
use Omnichannel\Addons\AiPrompt\Models\PromptResult;
use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\Content\Services\ArticleCtaPlaceholderService;
use Omnichannel\Addons\Content\Services\ArticleEditorHtmlSanitizeService;
use Omnichannel\Addons\Content\Services\ArticleFaqExtractDebugService;
use Omnichannel\Addons\Content\Services\ArticleLastSavedTimestampService;
use Omnichannel\Addons\Content\Services\ArticlePendingInternalLinkService;
use Omnichannel\Addons\Content\Services\ArticlePostContentFaqPlaceholder;
use Omnichannel\Addons\WordPress\Models\SeoArticleWpSyncJob;
use Omnichannel\Addons\Media\Models\SeoMedia;
use Omnichannel\Addons\ContentProjects\Models\SeoProjectTask;
use Omnichannel\Addons\AiPrompt\Models\SeoPromptResultLink;
use Omnichannel\Addons\WordPress\Services\SideEffect\UnauthorizedWordPressSideEffectException;
use Omnichannel\Addons\WordPress\Services\SideEffect\WordPressExecutionContext;
use Omnichannel\Addons\WordPress\Services\SideEffect\WordPressGateway;
use Omnichannel\Addons\WordPress\Services\WordPressSlugFixRequiredException;
use Omnichannel\Addons\Content\Enums\ContentType;
use Omnichannel\Addons\Content\Support\ArticleContentClassification;
use Omnichannel\Addons\Content\Support\NativeContentTypeMapper;
use Omnichannel\Addons\Seo\Support\SeoAccessControl;
use Omnichannel\Addons\ContentProjects\Support\SeoQueueContext;
use Omnichannel\Addons\WordPress\Support\WordPressRestResponseParser;
use App\Models\Site;
use App\Support\RuntimeLogger;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;
use Omnichannel\Addons\Media\Services\ArticleMediaLocalService;
use Omnichannel\Addons\Media\Services\ArticlePostImagesService;

final class WordPressArticleSyncService
{
    private const EDITOR_SYNC_HTTP_TIMEOUT_SECONDS = 120;

    public const META_WP_EDITOR_SYNC_FINGERPRINT = 'wp_editor_sync_fingerprint';

    public const META_WP_LOCAL_SAVE_FINGERPRINT = 'wp_local_save_fingerprint';

    public function __construct(
        private readonly WordPressArticleTimestampService $timestampService,
        private readonly WordPressGateway $gateway,
    ) {}

    /**
     * Tạo post/product mới trên WordPress và liên kết lại với bản ghi Laravel.
     *
     * @param  array<string, mixed>|null  $editorPayload  post_content, faqs, seo, category_ids (plugin ≥ 1.0.49)
     * @return array{success: bool, message: string, wp_post_id?: int, permalink?: string}
     */
    public function createForArticle(
        SeoArticle $article,
        WordPressExecutionContext $sideEffect,
        ?array $editorPayload = null,
    ): array {
        $this->assertSideEffectArticle($sideEffect, $article);

        if ($blocked = $this->blockContentManagerWordPressSync()) {
            return $blocked;
        }

        if ((int) ($article->wordpressLink?->wp_post_id ?? 0) > 0) {
            return [
                'success' => true,
                'message' => 'Bài viết đã liên kết WordPress.',
                'wp_post_id' => (int) $article->wordpressLink?->wp_post_id,
            ];
        }

        $article->loadMissing('site', 'articleMetas');
        $site = $article->site;
        if (! $site instanceof Site) {
            return [
                'success' => false,
                'message' => 'Không tìm thấy tên miền của bài viết.',
            ];
        }

        if (ArticleContentClassification::for($article)->isTerm()) {
            return [
                'success' => false,
                'message' => 'Danh mục phải được tạo bằng luồng taxonomy WordPress, không thể đăng như bài viết.',
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

        $base = app(WordPressArticleContentService::class)->getPermalinkBase($site);
        if ($base === '') {
            return [
                'success' => false,
                'message' => 'Không xác định được URL WordPress.',
            ];
        }

        $syncKey = \Omnichannel\Addons\WordPress\Models\SeoArticleWpSyncJob::makeIdempotencyKey(
            (int) ($article->site_id ?? 0),
            (int) $article->id,
        );
        $linked = $this->linkExistingWordPressPostByArticleMeta(
            $article,
            $sideEffect,
            $base,
            $writeToken,
            $syncKey,
        );
        if ($linked !== null) {
            return $linked;
        }

        $postType = $this->resolveWordPressPostTypeForPush($article);

        try {
            $requestBody = [
                'title' => trim((string) ($article->title ?? '')),
                // Luôn gửi slug từ focus keyword — không để WordPress tự sinh slug từ tiêu đề.
                'slug' => $this->resolveSlugForNewPost($article),
                ...$this->resolveWordPressStatusPayload($article),
                'post_type' => $postType,
                'teamvia_article_id' => (int) $article->id,
                '_teamvia_article_id' => (int) $article->id,
                'teamvia_sync_key' => $syncKey,
                '_teamvia_sync_key' => $syncKey,
                'operation_id' => $syncKey,
                'publish_operation_key' => $syncKey,
            ];
            if (is_array($editorPayload)) {
                foreach (['post_content', 'faqs', 'seo', 'category_ids'] as $field) {
                    if (! array_key_exists($field, $editorPayload)) {
                        continue;
                    }
                    $value = $editorPayload[$field];
                    if ($value === null || $value === '' || $value === []) {
                        continue;
                    }
                    $requestBody[$field] = $value;
                }
            }

            $response = $this->gateway->postJson(
                $sideEffect,
                'article.create_post',
                $base.'/wp-json/omi-seo-ai/v1/posts',
                $writeToken,
                $requestBody,
                self::EDITOR_SYNC_HTTP_TIMEOUT_SECONDS,
                (int) $article->id,
                (int) ($article->site_id ?? 0),
            );

            if (! $response->successful()) {
                return [
                    'success' => false,
                    'message' => WordPressRestResponseParser::formatHttpErrorMessage($response->status(), $response),
                ];
            }

            $decoded = $response->json();
            $wpPostId = is_array($decoded) ? (int) ($decoded['wp_post_id'] ?? 0) : 0;
            if (! is_array($decoded) || ! ($decoded['success'] ?? false) || $wpPostId <= 0) {
                return [
                    'success' => false,
                    'message' => (string) ($decoded['message'] ?? 'WordPress không trả về ID bài viết mới.'),
                ];
            }

            $permalink = trim((string) ($decoded['permalink'] ?? ''));
            $remoteSlug = trim((string) ($decoded['slug'] ?? ''));
            $article->forceFill(array_filter([
                'wp_post_id' => $wpPostId,
                'slug' => $remoteSlug !== '' ? $remoteSlug : null,
            ], static fn (mixed $value): bool => $value !== null))->save();
            $article->unsetRelation('wordpressLink');
            $this->timestampService->sync($article, $decoded);
            $article->articleMetas()->where('meta_key', 'wp_slug')->delete();
            ArticleContentClassification::persist($article, [
                'content_type' => NativeContentTypeMapper::mapForSite($postType, $site),
                'wp_is_term' => false,
                'wp_post_type' => $postType,
            ]);
            if ($permalink !== '') {
                $article->articleMetas()->updateOrCreate(
                    ['meta_key' => 'wp_permalink'],
                    ['meta_value' => $permalink],
                );
                app(ArticlePendingInternalLinkService::class)->resolveForMainArticle($article->fresh());
            }

            return [
                'success' => true,
                'message' => (string) ($decoded['message'] ?? 'Đã tạo bài viết mới trên WordPress.'),
                'wp_post_id' => $wpPostId,
                'permalink' => $permalink,
            ];
        } catch (WordPressSlugFixRequiredException $e) {
            return $this->slugFixRequiredResponse();
        } catch (Throwable $e) {
            Log::warning('WordPress article create exception', [
                'article_id' => $article->id,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'message' => 'Không kết nối được WordPress: '.$e->getMessage(),
            ];
        }
    }

    /**
     * Read-only reconcile probe for Publishing Queue recovery / post-publish sync.
     *
     * @return array{
     *     found: bool,
     *     wp_post_id?: int|null,
     *     permalink?: string,
     *     status?: string,
     *     slug?: string,
     *     match_count?: int,
     *     ambiguous?: bool
     * }|null
     */
    public function findPublishedPostForReconcile(
        SeoArticle $article,
        WordPressExecutionContext $sideEffect,
        ?string $operationKey = null,
    ): ?array {
        $site = $article->site;
        if ($site === null) {
            $article->loadMissing('site');
            $site = $article->site;
        }
        if ($site === null) {
            return null;
        }

        $site->loadMissing('metas');
        $writeToken = trim((string) ($site->getMeta('seo_migration_token') ?? ''));
        if ($writeToken === '') {
            return null;
        }

        $base = app(WordPressArticleContentService::class)->getPermalinkBase($site);
        if ($base === '') {
            return null;
        }

        $syncKey = SeoArticleWpSyncJob::makeIdempotencyKey(
            (int) ($article->site_id ?? 0),
            (int) $article->id,
        );

        $query = [
            'article_id' => (int) $article->id,
            'sync_key' => $syncKey,
        ];
        $operationKey = trim((string) $operationKey);
        if ($operationKey !== '') {
            $query['operation_key'] = $operationKey;
        }

        try {
            $response = $this->gateway->getJson(
                $sideEffect,
                'article.find_post_by_meta',
                $base.'/wp-json/omi-seo-ai/v1/posts/find-by-article',
                $writeToken,
                self::EDITOR_SYNC_HTTP_TIMEOUT_SECONDS,
                $query,
                (int) $article->id,
                (int) ($article->site_id ?? 0),
            );
        } catch (Throwable $e) {
            RuntimeLogger::warning('publishing.wordpress_find_by_article_failed', [
                'article_id' => $article->id,
                'error' => $e->getMessage(),
            ]);

            return null;
        }

        if (! $response->successful()) {
            return null;
        }

        $decoded = $response->json();
        if (! is_array($decoded)) {
            return null;
        }

        $matchCount = (int) ($decoded['match_count'] ?? (($decoded['found'] ?? false) ? 1 : 0));
        $ambiguous = (bool) ($decoded['ambiguous'] ?? false) || $matchCount > 1;

        return [
            'found' => (bool) ($decoded['found'] ?? false) && ! $ambiguous,
            'wp_post_id' => $ambiguous ? null : ((int) ($decoded['wp_post_id'] ?? 0) ?: null),
            'permalink' => trim((string) ($decoded['permalink'] ?? '')),
            'status' => trim((string) ($decoded['status'] ?? '')),
            'slug' => trim((string) ($decoded['slug'] ?? '')),
            'match_count' => $matchCount,
            'ambiguous' => $ambiguous,
        ];
    }

    /**
     * Idempotency: tìm post WP theo _teamvia_article_id / sync key trước khi create.
     *
     * @return array{success: bool, message: string, wp_post_id?: int, permalink?: string}|null
     */
    private function linkExistingWordPressPostByArticleMeta(
        SeoArticle $article,
        WordPressExecutionContext $sideEffect,
        string $base,
        string $writeToken,
        string $syncKey,
    ): ?array {
        try {
            $response = $this->gateway->getJson(
                $sideEffect,
                'article.find_post_by_meta',
                $base.'/wp-json/omi-seo-ai/v1/posts/find-by-article',
                $writeToken,
                self::EDITOR_SYNC_HTTP_TIMEOUT_SECONDS,
                [
                    'article_id' => (int) $article->id,
                    'sync_key' => $syncKey,
                ],
                (int) $article->id,
                (int) ($article->site_id ?? 0),
            );
        } catch (Throwable $e) {
            Log::warning('WordPress find-by-article failed', [
                'article_id' => $article->id,
                'error' => $e->getMessage(),
            ]);

            return null;
        }

        if (! $response->successful()) {
            return null;
        }

        $decoded = $response->json();
        if (! is_array($decoded) || ! ($decoded['found'] ?? false)) {
            return null;
        }

        $wpPostId = (int) ($decoded['wp_post_id'] ?? 0);
        if ($wpPostId <= 0) {
            return null;
        }

        $permalink = trim((string) ($decoded['permalink'] ?? ''));
        $remoteSlug = trim((string) ($decoded['slug'] ?? ''));
        $article->forceFill(array_filter([
            'wp_post_id' => $wpPostId,
            'slug' => $remoteSlug !== '' ? $remoteSlug : null,
        ], static fn (mixed $value): bool => $value !== null))->save();
        $article->unsetRelation('wordpressLink');

        if ($permalink !== '') {
            $article->articleMetas()->updateOrCreate(
                ['meta_key' => 'wp_permalink'],
                ['meta_value' => $permalink],
            );
        }

        return [
            'success' => true,
            'message' => 'Đã liên kết bài WordPress hiện có (idempotent).',
            'wp_post_id' => $wpPostId,
            'permalink' => $permalink,
        ];
    }

    /**
     * Tạo (nếu chưa có wp_post_id) rồi đồng bộ nội dung lên WordPress trong một lock — tránh race tạo bài trùng.
     *
     * @param  array{seo_title?: string, meta_description?: string, focus_keyword?: string}|null  $seoOverride
     * @return array<string, mixed>
     */
    public function publishForArticle(
        SeoArticle $article,
        WordPressExecutionContext $sideEffect,
        ?array $seoOverride = null,
    ): array {
        $this->assertSideEffectArticle($sideEffect, $article);

        if ($blocked = $this->blockContentManagerWordPressSync()) {
            return $blocked;
        }

        $lock = Cache::lock('seo-wp-publish-article-'.(int) $article->id, 120);

        try {
            $lock->block(30);
        } catch (LockTimeoutException) {
            return [
                'success' => false,
                'message' => 'Hết thời gian chờ đồng bộ WordPress. Vui lòng thử lại.',
            ];
        }

        try {
            $article = $article->fresh() ?? $article;

            if ((int) ($article->wordpressLink?->wp_post_id ?? 0) <= 0) {
                $prepared = $this->buildEditorSyncPayload($article, $seoOverride);
                $created = $this->createForArticle($article, $sideEffect, $prepared['request_payload']);
                if (! ($created['success'] ?? false)) {
                    return $created;
                }

                $article = $article->fresh() ?? $article;
                $article->unsetRelation('wordpressLink');
                $article->loadMissing('wordpressLink');
            }

            return $this->syncForArticle($article, $sideEffect, $seoOverride);
        } catch (WordPressSlugFixRequiredException $e) {
            return $this->slugFixRequiredResponse();
        } finally {
            $lock->release();
        }
    }

    /**
     * Cập nhật slug lên WordPress ngay khi sửa permalink trên editor (không cần đồng bộ full).
     *
     * @return array{success: bool, message: string}
     */
    public function syncSlugForArticle(
        SeoArticle $article,
        WordPressExecutionContext $sideEffect,
        string $slug,
    ): array {
        $this->assertSideEffectArticle($sideEffect, $article);

        if ($blocked = $this->blockContentManagerWordPressSync()) {
            return $blocked;
        }

        $slug = trim($slug);
        if ($slug === '') {
            return [
                'success' => false,
                'message' => 'Slug không hợp lệ.',
            ];
        }

        $context = $this->resolveEditorSyncContext($article);
        if (! ($context['success'] ?? false)) {
            return [
                'success' => false,
                'message' => (string) ($context['message'] ?? 'Không thể đồng bộ slug lên WordPress.'),
            ];
        }

        try {
            $response = $this->gateway->postJson(
                $sideEffect,
                'article.sync_slug',
                (string) $context['url'],
                (string) $context['write_token'],
                ['slug' => $slug],
                30,
                (int) $article->id,
                (int) ($article->site_id ?? 0),
            );

            if (! $response->successful()) {
                $message = WordPressRestResponseParser::formatHttpErrorMessage(
                    $response->status(),
                    $response,
                );

                Log::warning('WordPress slug sync failed', [
                    'article_id' => $article->id,
                    'wp_post_id' => $context['wp_post_id'] ?? null,
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);

                return [
                    'success' => false,
                    'message' => $message,
                ];
            }

            $decoded = $response->json();
            if (! is_array($decoded) || ! ($decoded['success'] ?? false)) {
                return [
                    'success' => false,
                    'message' => (string) ($decoded['message'] ?? 'WordPress từ chối cập nhật slug.'),
                ];
            }

            return [
                'success' => true,
                'message' => (string) ($decoded['message'] ?? 'Đã cập nhật slug trên WordPress.'),
            ];
        } catch (WordPressSlugFixRequiredException $e) {
            return $this->slugFixRequiredResponse();
        } catch (Throwable $e) {
            Log::warning('WordPress slug sync exception', [
                'article_id' => $article->id,
                'wp_post_id' => $context['wp_post_id'] ?? null,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'message' => 'Không kết nối được WordPress: '.$e->getMessage(),
            ];
        }
    }

    /**
     * Đẩy SEO meta (title / description / focus keyword) lên WordPress qua editor-sync.
     *
     * @param  array{seo_title?: string, meta_description?: string, focus_keyword?: string}  $seoOverride
     * @return array{success: bool, message: string, seo_applied?: bool}
     */
    public function syncSeoMetaForArticle(
        SeoArticle $article,
        WordPressExecutionContext $sideEffect,
        array $seoOverride,
    ): array {
        $this->assertSideEffectArticle($sideEffect, $article);

        if ($blocked = $this->blockContentManagerWordPressSync()) {
            return $blocked;
        }

        $seo = [
            'seo_title' => array_key_exists('seo_title', $seoOverride)
                ? trim((string) $seoOverride['seo_title'])
                : '',
        ];
        if (array_key_exists('meta_description', $seoOverride)) {
            $seo['meta_description'] = trim((string) $seoOverride['meta_description']);
        }
        if (array_key_exists('focus_keyword', $seoOverride)) {
            $seo['focus_keyword'] = trim((string) $seoOverride['focus_keyword']);
        }

        if ($seo === []) {
            return [
                'success' => false,
                'message' => 'Không có dữ liệu SEO để đồng bộ lên WordPress.',
            ];
        }

        $context = $this->resolveEditorSyncContext($article);
        if (! ($context['success'] ?? false)) {
            return [
                'success' => false,
                'message' => (string) ($context['message'] ?? 'Không thể đồng bộ SEO lên WordPress.'),
            ];
        }

        try {
            $response = $this->gateway->postJson(
                $sideEffect,
                'article.sync_seo_meta',
                (string) $context['url'],
                (string) $context['write_token'],
                ['seo' => $seo],
                30,
                (int) $article->id,
                (int) ($article->site_id ?? 0),
            );

            if (! $response->successful()) {
                $message = WordPressRestResponseParser::formatHttpErrorMessage(
                    $response->status(),
                    $response,
                );

                Log::warning('WordPress SEO meta sync failed', [
                    'article_id' => $article->id,
                    'wp_post_id' => $context['wp_post_id'] ?? null,
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);

                return [
                    'success' => false,
                    'message' => $message,
                ];
            }

            $decoded = $response->json();
            if (! is_array($decoded) || ! ($decoded['success'] ?? false)) {
                return [
                    'success' => false,
                    'message' => (string) ($decoded['message'] ?? 'WordPress từ chối cập nhật SEO meta.'),
                ];
            }

            if (($decoded['seo_error'] ?? '') !== '') {
                return [
                    'success' => false,
                    'message' => (string) $decoded['seo_error'],
                ];
            }

            if (($decoded['seo_applied'] ?? false) !== true) {
                return [
                    'success' => false,
                    'message' => 'WordPress không ghi được SEO meta (Rank Math/Yoast có thể chưa bật).',
                ];
            }

            return [
                'success' => true,
                'message' => (string) ($decoded['message'] ?? 'Đã đồng bộ SEO meta lên WordPress.'),
                'seo_applied' => true,
            ];
        } catch (WordPressSlugFixRequiredException $e) {
            return $this->slugFixRequiredResponse();
        } catch (Throwable $e) {
            Log::warning('WordPress SEO meta sync exception', [
                'article_id' => $article->id,
                'wp_post_id' => $context['wp_post_id'] ?? null,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'message' => 'Không kết nối được WordPress: '.$e->getMessage(),
            ];
        }
    }

    /**
     * Đẩy tiêu đề, slug, trạng thái, nội dung và FAQ lên WordPress (nút «Đồng bộ»).
     *
     * @param  array{seo_title?: string, meta_description?: string, focus_keyword?: string}|null  $seoOverride
     * @return array{
     *     success: bool,
     *     message: string,
     *     faq_count?: int,
     *     faq_extract_debug?: array<string, mixed>|null,
     *     post_type?: string,
     *     post_type_changed?: bool
     * }
     */
    public function syncForArticle(
        SeoArticle $article,
        WordPressExecutionContext $sideEffect,
        ?array $seoOverride = null,
    ): array {
        $this->assertSideEffectArticle($sideEffect, $article);

        if ($blocked = $this->blockContentManagerWordPressSync()) {
            return $blocked;
        }

        $context = $this->resolveEditorSyncContext($article);
        if (! ($context['success'] ?? false)) {
            return [
                'success' => false,
                'message' => (string) ($context['message'] ?? 'Không thể đồng bộ lên WordPress.'),
            ];
        }

        $prepared = $this->prepareEditorSyncPayload($article, $seoOverride);
        if (($prepared['request_payload'] ?? []) === [] && ($prepared['local_media_sync_errors'] ?? []) !== []) {
            return [
                'success' => false,
                'message' => implode(' | ', $prepared['local_media_sync_errors']),
            ];
        }

        try {
            $httpResult = $this->executeEditorSyncRequest($article, $sideEffect, $context, $prepared);
        } catch (WordPressSlugFixRequiredException) {
            return $this->slugFixRequiredResponse();
        }
        if (! ($httpResult['success'] ?? false)) {
            return $httpResult;
        }

        return $this->completeEditorSyncResponse(
            $article,
            $prepared,
            is_array($httpResult['decoded'] ?? null) ? $httpResult['decoded'] : [],
        );
    }

    /**
     * Post-publish editorial UPDATE only — never calls article.create_post.
     *
     * @param  array{seo_title?: string, meta_description?: string, focus_keyword?: string}|null  $seoOverride
     * @return array<string, mixed>
     */
    public function updatePublishedArticleOnly(
        SeoArticle $article,
        WordPressExecutionContext $sideEffect,
        ?array $seoOverride = null,
    ): array {
        $this->assertSideEffectArticle($sideEffect, $article);

        if ($blocked = $this->blockContentManagerWordPressSync()) {
            return $blocked;
        }

        $canonicalWpPostId = (int) ($article->wordpressLink?->wp_post_id ?? 0);
        if ($canonicalWpPostId <= 0) {
            return [
                'success' => false,
                'message' => 'Thiếu wp_post_id — không thể cập nhật bài WordPress đã xuất bản.',
                'create_post_called' => false,
            ];
        }

        $lock = Cache::lock('seo-wp-post-publish-sync-'.(int) $article->id.'-'.$canonicalWpPostId, 120);

        try {
            $lock->block(5);
        } catch (LockTimeoutException) {
            return [
                'success' => false,
                'message' => 'Đang có lượt đồng bộ cập nhật khác cho bài này.',
                'error_code' => 'sync_locked',
                'create_post_called' => false,
            ];
        }

        try {
            $article = $article->fresh() ?? $article;
            if ((int) ($article->wordpressLink?->wp_post_id ?? 0) !== $canonicalWpPostId) {
                return [
                    'success' => false,
                    'message' => 'wp_post_id đã đổi trong lúc đồng bộ — bỏ qua ghi đè cũ.',
                    'error_code' => 'stale_wp_post_id',
                    'create_post_called' => false,
                ];
            }

            $context = $this->resolveEditorSyncContext($article);
            if (! ($context['success'] ?? false)) {
                return [
                    'success' => false,
                    'message' => (string) ($context['message'] ?? 'Không thể đồng bộ lên WordPress.'),
                    'create_post_called' => false,
                ];
            }

            $prepared = $this->prepareEditorSyncPayload($article, $seoOverride, [
                'omit_publication_fields' => true,
                'force_editor_sync' => true,
            ]);
            if (($prepared['request_payload'] ?? []) === [] && ($prepared['local_media_sync_errors'] ?? []) !== []) {
                return [
                    'success' => false,
                    'message' => implode(' | ', $prepared['local_media_sync_errors']),
                    'create_post_called' => false,
                ];
            }

            $outgoingContent = (string) ($prepared['post_content'] ?? '');
            try {
                $httpResult = $this->executeEditorSyncRequest($article, $sideEffect, $context, $prepared);
            } catch (WordPressSlugFixRequiredException) {
                return array_merge($this->slugFixRequiredResponse(), [
                    'create_post_called' => false,
                    'outgoing_post_content' => $outgoingContent,
                    'canonical_wp_post_id' => $canonicalWpPostId,
                ]);
            }
            if (! ($httpResult['success'] ?? false)) {
                return array_merge($httpResult, [
                    'create_post_called' => false,
                    'outgoing_post_content' => $outgoingContent,
                    'canonical_wp_post_id' => $canonicalWpPostId,
                ]);
            }

            $completed = $this->completeEditorSyncResponse(
                $article,
                $prepared,
                is_array($httpResult['decoded'] ?? null) ? $httpResult['decoded'] : [],
            );

            $returnedWp = (int) ($completed['wp_post_id'] ?? $article->fresh()?->wordpressLink?->wp_post_id ?? 0);
            if ($returnedWp > 0 && $returnedWp !== $canonicalWpPostId) {
                return [
                    'success' => false,
                    'message' => 'WordPress trả về wp_post_id khác canonical — không chấp nhận.',
                    'error_code' => 'wp_post_id_mismatch',
                    'create_post_called' => false,
                    'canonical_wp_post_id' => $canonicalWpPostId,
                    'returned_wp_post_id' => $returnedWp,
                ];
            }

            return array_merge($completed, [
                'create_post_called' => false,
                'outgoing_post_content' => $outgoingContent,
                'canonical_wp_post_id' => $canonicalWpPostId,
                'returned_wp_post_id' => $returnedWp > 0 ? $returnedWp : $canonicalWpPostId,
            ]);
        } finally {
            $lock->release();
        }
    }

    /**
     * @param  array{seo_title?: string, meta_description?: string, focus_keyword?: string}|null  $seoOverride
     * @return array{
     *     request_payload: array<string, mixed>,
     *     post_content: string,
     *     faqs: array<int, array<string, string>>,
     *     faq_extract_debug: array<string, mixed>|null,
     *     wp_taxonomy: string|null,
     *     local_media_sync_errors: array<int, string>,
     *     synced_local_media_ids: array<int, int>
     * }
     */
    public function prepareEditorSyncPayload(SeoArticle $article, ?array $seoOverride = null, array $syncOptions = []): array
    {
        return $this->buildEditorSyncPayload($article, $seoOverride, $syncOptions);
    }

    /**
     * @param  array{success: bool, write_token?: string, url?: string, wp_post_id?: int, message?: string}  $context
     * @param  array{
     *     request_payload: array<string, mixed>,
     *     post_content: string,
     *     faqs: array<int, array<string, string>>,
     *     faq_extract_debug: array<string, mixed>|null,
     *     wp_taxonomy: string|null,
     *     local_media_sync_errors: array<int, string>,
     *     synced_local_media_ids: array<int, int>
     * }  $prepared
     * @return array{success: bool, message: string, decoded?: array<string, mixed>, step_detail?: string}
     */
    public function executeEditorSyncRequest(
        SeoArticle $article,
        WordPressExecutionContext $sideEffect,
        array $context,
        array $prepared,
    ): array {
        $this->assertSideEffectArticle($sideEffect, $article);

        if ($prepared['skip_editor_sync'] ?? false) {
            return [
                'success' => true,
                'message' => 'Bỏ qua editor-sync — nội dung/FAQ/SEO đã khớp WordPress (chưa chỉnh sửa local).',
                'decoded' => $this->buildSkippedEditorSyncDecoded($article),
                'step_detail' => 'skipped=1, reason='.(string) ($prepared['skip_editor_sync_reason'] ?? 'unchanged'),
                'skipped' => true,
            ];
        }

        $writeToken = (string) ($context['write_token'] ?? '');
        $url = (string) ($context['url'] ?? '');
        $wpPostId = (int) ($context['wp_post_id'] ?? 0);
        $payload = $prepared['request_payload'];
        $fieldConflicts = is_array($prepared['field_conflicts'] ?? null) ? $prepared['field_conflicts'] : [];
        if ($fieldConflicts !== []) {
            return [
                'success' => false,
                'message' => 'Phát hiện xung đột WordPress ở field: '.implode(', ', array_keys($fieldConflicts)).'. Tải lại bài hoặc kiểm tra thay đổi trên WordPress trước khi sync.',
                'step_detail' => 'field_conflict='.implode(',', array_keys($fieldConflicts)),
                'error_code' => 'wp_field_conflict',
            ];
        }

        try {
            $response = $this->gateway->postJson(
                $sideEffect,
                'article.editor_sync',
                $url,
                $writeToken,
                $payload,
                self::EDITOR_SYNC_HTTP_TIMEOUT_SECONDS,
                (int) $article->id,
                (int) ($article->site_id ?? 0),
            );

            if (! $response->successful()) {
                $message = WordPressRestResponseParser::formatHttpErrorMessage(
                    $response->status(),
                    $response,
                );

                Log::warning('WordPress article sync failed', [
                    'article_id' => $article->id,
                    'wp_post_id' => $wpPostId,
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);

                return [
                    'success' => false,
                    'message' => $message,
                    'step_detail' => 'HTTP '.$response->status(),
                ];
            }

            $decoded = $response->json();
            if (! is_array($decoded) || ! ($decoded['success'] ?? false)) {
                return [
                    'success' => false,
                    'message' => (string) ($decoded['message'] ?? 'WordPress từ chối đồng bộ.'),
                ];
            }

            return [
                'success' => true,
                'message' => (string) ($decoded['message'] ?? 'Đã đồng bộ nội dung lên WordPress.'),
                'decoded' => $decoded,
                'step_detail' => 'wp_post_id='.$wpPostId,
            ];
        } catch (Throwable $e) {
            Log::warning('WordPress article sync exception', [
                'article_id' => $article->id,
                'wp_post_id' => $wpPostId,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'message' => 'Không kết nối được WordPress: '.$e->getMessage(),
                'step_detail' => $url,
            ];
        }
    }

    /**
     * @param  array{
     *     request_payload: array<string, mixed>,
     *     post_content: string,
     *     faqs: array<int, array<string, string>>,
     *     faq_extract_debug: array<string, mixed>|null,
     *     wp_taxonomy: string|null,
     *     local_media_sync_errors: array<int, string>,
     *     synced_local_media_ids: array<int, int>
     * }  $prepared
     * @param  array<string, mixed>  $decoded
     * @return array{
     *     success: bool,
     *     message: string,
     *     faq_count?: int,
     *     faq_extract_debug?: array<string, mixed>|null,
     *     post_type?: string,
     *     post_type_changed?: bool,
     *     step_detail?: string
     * }
     */
    public function completeEditorSyncResponse(SeoArticle $article, array $prepared, array $decoded, array $syncOptions = []): array
    {
        $deferFinalizeMedia = (bool) ($syncOptions['defer_finalize_media'] ?? false);
        $skipFeaturedMediaPush = (bool) ($syncOptions['skip_featured_media_push'] ?? false);
        $postContent = (string) ($prepared['post_content'] ?? '');
        $hadLocalUnsyncedBody = trim((string) ($article->body ?? '')) !== '';
        $editorSyncSkipped = (bool) ($prepared['skip_editor_sync'] ?? false);
        $faqs = is_array($prepared['faqs'] ?? null) ? $prepared['faqs'] : [];
        $faqExtractDebug = $prepared['faq_extract_debug'] ?? null;
        $wpTaxonomy = $prepared['wp_taxonomy'] ?? null;
        $localMediaSyncErrors = is_array($prepared['local_media_sync_errors'] ?? null)
            ? $prepared['local_media_sync_errors']
            : [];
        $syncedLocalMediaIds = is_array($prepared['synced_local_media_ids'] ?? null)
            ? array_values(array_filter(array_map(static fn ($id): int => (int) $id, $prepared['synced_local_media_ids'])))
            : [];

        $requestedSlug = trim((string) (($prepared['request_payload']['slug'] ?? '') ?: ($article->slug ?? '')));
        $remoteSlug = trim((string) ($decoded['slug'] ?? ''));
        $remotePermalink = trim((string) ($decoded['permalink'] ?? ''));
        $remotePostType = null;

        if ($wpTaxonomy === null) {
            $requestedPostType = $this->resolveWordPressPostTypeForPush($article);
            $remotePostType = strtolower(trim((string) ($decoded['post_type'] ?? $requestedPostType)));
            if ($remotePostType === '') {
                $remotePostType = $requestedPostType;
            }

            $article->loadMissing('site');
            ArticleContentClassification::persist($article, [
                'content_type' => NativeContentTypeMapper::mapForSite(
                    $remotePostType,
                    $article->site instanceof Site ? $article->site : null,
                ),
                'wp_is_term' => false,
                'wp_post_type' => $remotePostType,
            ]);
        }
        if ($remoteSlug !== '') {
            $article->update(['slug' => $remoteSlug]);
            $article->articleMetas()->where('meta_key', 'wp_slug')->delete();
        }
        if ($remotePermalink !== '') {
            $article->articleMetas()->updateOrCreate(
                ['meta_key' => 'wp_permalink'],
                ['meta_value' => $remotePermalink],
            );
            app(ArticlePendingInternalLinkService::class)->resolveForMainArticle($article->fresh());
        }

        if ($postContent !== '' && trim((string) ($article->body ?? '')) !== $postContent) {
            try {
                app(\Omnichannel\Addons\Content\Services\ArticleEditor\Document\ArticleEditorDocumentWriter::class)
                    ->writeLegacyHtmlAndInvalidateDocument(
                        $article,
                        $postContent,
                        'wp_sync_store_post_content',
                        true,
                    );
            } catch (\Throwable) {
                $article->update(['body' => $postContent]);
            }
        }

        $message = (string) ($decoded['message'] ?? 'Đã đồng bộ lên WordPress.');
        if ($requestedSlug !== '' && $remoteSlug !== '' && \Illuminate\Support\Str::slug($requestedSlug) !== \Illuminate\Support\Str::slug($remoteSlug)) {
            $message .= ' WordPress đã đổi slug thành "'.$remoteSlug.'" do trùng hoặc canonical permalink; Laravel đã cập nhật theo slug này.';
        }
        $virtualCount = (int) ($decoded['virtual_count'] ?? 0);
        if ($virtualCount > 0) {
            $message .= ' Đã đồng bộ '.$virtualCount.' review ảo.';
        }
        $virtualError = trim((string) ($decoded['virtual_comments_error'] ?? ''));
        if ($virtualError !== '') {
            $message .= ' Review chưa lưu: '.mb_substr($virtualError, 0, 200);
        }

        $mediaPush = ['attempted' => false, 'success' => true, 'message' => '', 'synced_local_media_ids' => []];
        if (! $skipFeaturedMediaPush) {
            $mediaPush = app(ArticleMediaLocalService::class)->pushPendingMediaToWordPress($article->fresh());
        }

        $dirtySync = ['synced' => 0, 'errors' => []];
        $webpBackfill = ['synced_media_ids' => [], 'errors' => [], 'url_map' => []];
        $localMediaSync = app(WordPressLocalMediaSyncService::class);

        if (! $deferFinalizeMedia) {
            $dirtySync = $localMediaSync->syncDirtyLocalMediaForArticle($article->fresh());
            $webpBackfill = $localMediaSync->syncWebpBackfillMediaForArticle($article->fresh(), $syncedLocalMediaIds);
        }
        $syncedFromPending = is_array($mediaPush['synced_local_media_ids'] ?? null)
            ? array_values(array_filter(array_map(
                static fn ($id): int => (int) $id,
                $mediaPush['synced_local_media_ids'],
            )))
            : [];
        $syncedLocalMediaIds = array_values(array_unique(array_merge(
            $syncedLocalMediaIds,
            $syncedFromPending,
            is_array($webpBackfill['synced_media_ids'] ?? null) ? $webpBackfill['synced_media_ids'] : [],
        )));

        $webpUrlMap = is_array($webpBackfill['url_map'] ?? null) ? $webpBackfill['url_map'] : [];
        if ($webpUrlMap !== []) {
            $articleFresh = $article->fresh();
            if ($articleFresh instanceof SeoArticle) {
                $updatedBody = $localMediaSync->replaceUrlsInHtml((string) ($articleFresh->body ?? ''), $webpUrlMap);
                if ($updatedBody !== (string) ($articleFresh->body ?? '')) {
                    try {
                        app(\Omnichannel\Addons\Content\Services\ArticleEditor\Document\ArticleEditorDocumentWriter::class)
                            ->writeLegacyHtmlAndInvalidateDocument(
                                $articleFresh,
                                $updatedBody,
                                'wp_sync_webp_url_map',
                                true,
                            );
                    } catch (\Throwable) {
                        $articleFresh->update(['body' => $updatedBody]);
                    }
                    $postContent = $localMediaSync->replaceUrlsInHtml($postContent, $webpUrlMap);
                }

                app(ArticleMediaLocalService::class)->applyWordPressUrlMap($articleFresh, $webpUrlMap);
            }
        }

        $stepDetails = [];
        if (($webpBackfill['synced_media_ids'] ?? []) !== []) {
            $webpCount = count($webpBackfill['synced_media_ids']);
            $message .= " Đã chuyển {$webpCount} ảnh sang WebP trên WordPress.";
            $stepDetails[] = "webp={$webpCount}";
        }
        if (($webpBackfill['errors'] ?? []) !== []) {
            $message .= ' Một số ảnh WebP backfill chưa xong: '.mb_substr(implode(' | ', $webpBackfill['errors']), 0, 300);
        }

        if ($mediaPush['attempted']) {
            if ($mediaPush['success']) {
                $message .= ' Đã đẩy ảnh đại diện/album lên WordPress.';
                $stepDetails[] = 'featured/album=ok';
            } else {
                $message .= ' Ảnh chưa đẩy được: '.mb_substr((string) $mediaPush['message'], 0, 200);
            }
        }
        if (($dirtySync['synced'] ?? 0) > 0) {
            $message .= ' Đã ghi đè '.(int) $dirtySync['synced'].' ảnh local đã chỉnh sửa lên WordPress.';
            $stepDetails[] = 'dirty='.(int) $dirtySync['synced'];
        }
        if (($dirtySync['errors'] ?? []) !== []) {
            $message .= ' Một số ảnh local chỉnh sửa chưa ghi đè được: '.mb_substr(implode(' | ', $dirtySync['errors']), 0, 300);
        }
        if ($localMediaSyncErrors !== []) {
            $message .= ' Một số ảnh trong nội dung chưa sync được: '.mb_substr(implode(' | ', $localMediaSyncErrors), 0, 300);
        }

        if ($mediaPush['attempted'] && ! $mediaPush['success']) {
            return [
                'success' => false,
                'message' => $message,
                'error_code' => 'featured_media_sync_failed',
                'failed_stage' => 'featured_media_push',
                'faq_count' => count($faqs),
                'faq_extract_debug' => $faqExtractDebug,
                'post_type' => $wpTaxonomy === null ? $remotePostType : null,
                'post_type_changed' => $wpTaxonomy === null
                    ? (bool) ($decoded['post_type_changed'] ?? false)
                    : false,
                'step_detail' => implode(', ', $stepDetails),
            ];
        }

        if ($syncedLocalMediaIds !== []) {
            $updatedPromptMediaLinks = $this->syncPromptMediaLinksToWordPressUrls($article, $syncedLocalMediaIds);
            if ($updatedPromptMediaLinks > 0) {
                $message .= " Đã cập nhật {$updatedPromptMediaLinks} kết quả prompt sang URL ảnh WordPress.";
            }

            $restored = SeoMedia::query()
                ->whereIn('id', $syncedLocalMediaIds)
                ->where('status', 'trash')
                ->update([
                    'status' => 'completed',
                    'error_message' => null,
                ]);
            if ($restored > 0) {
                $stepDetails[] = 'media_restored='.$restored;
            }

            $stepDetails[] = 'media_synced='.count($syncedLocalMediaIds);
        }

        if ($wpTaxonomy === null && ! $editorSyncSkipped && ($hadLocalUnsyncedBody || trim($postContent) !== '')) {
            // Remember hash of pushed content BEFORE clearing body.
            $publishedHash = hash('sha256', trim($postContent !== '' ? $postContent : (string) ($article->body ?? '')));
            $article->update(['body' => null]);
            app(ArticleWpContentCacheService::class)->forget($article);
            app(ArticleWordPressSyncFlagService::class)->clearAll($article);
            app(ArticleWordPressSyncFlagService::class)->rememberPublishedContentHash(
                $article->fresh() ?? $article,
                $publishedHash,
            );
        } elseif ($wpTaxonomy === null) {
            // No local unsynced body / editor-sync skipped — keep temporary WP cache for editor reopen.
            app(ArticleWordPressSyncFlagService::class)->clearAll($article);
        } else {
            app(ArticleWordPressSyncFlagService::class)->clearAll($article);
            app(ArticleWordPressSyncFlagService::class)->rememberPublishedContentHash(
                $article->fresh() ?? $article,
                hash('sha256', trim((string) (($article->fresh() ?? $article)->body ?? $postContent ?? ''))),
            );
        }
        app(WordPressFieldConflictService::class)->rememberSuccessfulSync(
            $article->fresh() ?? $article,
            $decoded,
            is_array($prepared['request_payload'] ?? null) ? $prepared['request_payload'] : [],
            $postContent,
        );
        $fresh = $article->fresh() ?? $article;
        $fresh->loadMissing('articleMetas');
        $this->confirmContentProjectPublishDelivery($fresh);
        app(ArticleLastSavedTimestampService::class)->touchSynced($article);
        $this->timestampService->sync($article, $decoded);

        if ((string) ($article->status ?? '') === 'scheduled') {
            $message .= ' WordPress giữ bản nháp — Laravel sẽ tự đăng khi đến giờ lên lịch.';
        }

        $this->storeEditorSyncFingerprint($article->fresh(), $prepared);
        $this->storeLocalSaveFingerprint(
            $article->fresh(),
            (string) ($prepared['post_content'] ?? (string) ($article->body ?? '')),
            null,
        );

        $this->hydratePostImagesCatalogAfterSync($article->fresh(), $postContent);

        return [
            'success' => true,
            'message' => $message,
            'faq_count' => count($faqs),
            'faq_extract_debug' => $faqExtractDebug,
            'post_type' => $wpTaxonomy === null ? $remotePostType : null,
            'post_type_changed' => $wpTaxonomy === null
                ? (bool) ($decoded['post_type_changed'] ?? false)
                : false,
            'step_detail' => implode(', ', $stepDetails),
        ];
    }

    /**
     * Confirm Content Project publishing queue only after real WP sync success.
     *
     * Accepts processing and queued_for_delivery (publisher may have skipped
     * beginPublisherAttempt when task_id was missing from automation mapping).
     */
    public function confirmContentProjectPublishDelivery(
        SeoArticle $article,
        ?int $preferredTaskId = null,
        ?string $attemptToken = null,
        bool $reconciledFromTokenMismatch = false,
    ): void {
        if (! \Illuminate\Support\Facades\Schema::connection('omi_seo_ai')->hasColumn('seo_project_tasks', 'publish_queue_status')) {
            return;
        }

        $openStatuses = [
            \Omnichannel\Addons\ContentProjects\Enums\ContentProjectPublishQueueStatus::Processing->value,
            \Omnichannel\Addons\ContentProjects\Enums\ContentProjectPublishQueueStatus::QueuedForDelivery->value,
            \Omnichannel\Addons\ContentProjects\Enums\ContentProjectPublishQueueStatus::Waiting->value,
            \Omnichannel\Addons\ContentProjects\Enums\ContentProjectPublishQueueStatus::Retrying->value,
        ];

        $query = SeoProjectTask::query()
            ->where('article_id', (int) $article->id)
            ->whereIn('publish_queue_status', $openStatuses);

        if ($preferredTaskId !== null && $preferredTaskId > 0) {
            $query->whereKey($preferredTaskId);
        }

        $tasks = $query->get();
        if ($tasks->isEmpty() && $preferredTaskId !== null && $preferredTaskId > 0) {
            $one = SeoProjectTask::query()->find($preferredTaskId);
            if ($one instanceof SeoProjectTask
                && (int) ($one->article_id ?? 0) === (int) $article->id
                && (string) ($one->publish_queue_status ?? '') !== \Omnichannel\Addons\ContentProjects\Enums\ContentProjectPublishQueueStatus::Published->value
            ) {
                $tasks = collect([$one]);
            }
        }

        if ($tasks->isEmpty()) {
            return;
        }

        $queue = app(\Omnichannel\Addons\ContentProjects\Services\ContentProject\ContentProjectPublishingQueueService::class);
        $wpPostId = (int) ($article->wordpressLink?->wp_post_id ?? 0);
        $permalink = trim((string) ($article->articleMetas
            ->firstWhere('meta_key', 'wp_permalink')?->meta_value ?? ''));

        foreach ($tasks as $task) {
            if ($attemptToken !== null && $attemptToken !== '') {
                $current = trim((string) ($task->publish_attempt_token ?? ''));
                if ($current !== '' && ! hash_equals($current, $attemptToken) && $wpPostId <= 0) {
                    RuntimeLogger::info('publishing.confirm_skipped_token_mismatch_no_wp_post', [
                        'task_id' => (int) $task->getKey(),
                        'article_id' => (int) $article->id,
                    ]);

                    continue;
                }
                if ($current !== '' && ! hash_equals($current, $attemptToken) && $wpPostId > 0) {
                    $reconciledFromTokenMismatch = true;
                }
            }

            $queue->markPublished($task);
            RuntimeLogger::info('publishing.confirmed_after_wp_sync', [
                'task_id' => (int) $task->getKey(),
                'article_id' => (int) $article->id,
                'wp_post_id' => $wpPostId > 0 ? $wpPostId : null,
                'permalink' => $permalink !== '' ? $permalink : null,
                'reconciled_token_mismatch' => $reconciledFromTokenMismatch,
            ]);

            app(\Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Events\ContentProjectDomainEvents::class)
                ->dispatchAfterCommit(new \Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Events\ArticlePublished(
                    projectId: (int) ($task->project_id ?? 0),
                    itemId: (int) $task->getKey(),
                    articleId: (int) $article->id,
                    wpPostId: $wpPostId > 0 ? $wpPostId : 0,
                ));

            app(\Omnichannel\Addons\ContentProjects\Services\ContentProject\ContentProjectQueueHealthService::class)
                ->rememberSuccess(1);
        }
    }

    private function hydratePostImagesCatalogAfterSync(SeoArticle $article, string $postContent): void
    {
        $html = trim($postContent);
        if ($html === '') {
            $html = trim((string) ($article->body ?? ''));
        }
        if ($html === '') {
            return;
        }

        try {
            app(ArticlePostImagesService::class)->syncFromHtml($article, $html);
        } catch (Throwable $exception) {
            Log::warning('hydratePostImagesCatalogAfterSync failed', [
                'article_id' => $article->id,
                'error' => $exception->getMessage(),
            ]);
        }
    }

    /**
     * Đảm bảo bài đã có wp_post_id (tạo mới trên WP nếu cần).
     *
     * @param  array{seo_title?: string, meta_description?: string, focus_keyword?: string}|null  $seoOverride
     * @return array{success: bool, message: string, step_detail?: string, created?: bool}
     */
    public function ensureWordPressPostForArticle(
        SeoArticle $article,
        WordPressExecutionContext $sideEffect,
        ?array $seoOverride = null,
        array $syncOptions = [],
    ): array {
        $this->assertSideEffectArticle($sideEffect, $article);

        if ((int) ($article->wordpressLink?->wp_post_id ?? 0) > 0) {
            return [
                'success' => true,
                'message' => 'Đã có wp_post_id #'.(int) $article->wordpressLink?->wp_post_id,
                'step_detail' => 'wp_post_id='.(int) $article->wordpressLink?->wp_post_id,
                'created' => false,
            ];
        }

        $prepared = $this->prepareEditorSyncPayload($article, $seoOverride, $syncOptions);
        $created = $this->createForArticle($article, $sideEffect, $prepared['request_payload']);
        if (! ($created['success'] ?? false)) {
            return [
                'success' => false,
                'message' => (string) ($created['message'] ?? 'Không tạo được bài trên WordPress.'),
            ];
        }

        $article->unsetRelation('wordpressLink');

        return [
            'success' => true,
            'message' => 'Đã tạo bài WordPress #'.(int) ($created['wp_post_id'] ?? 0),
            'step_detail' => 'wp_post_id='.(int) ($created['wp_post_id'] ?? 0),
            'created' => true,
        ];
    }

    /**
     * @param  array{seo_title?: string, meta_description?: string, focus_keyword?: string}|null  $seoOverride
     * @return array{
     *     request_payload: array<string, mixed>,
     *     post_content: string,
     *     faqs: array<int, array<string, string>>,
     *     faq_extract_debug: array<string, mixed>|null,
     *     wp_taxonomy: string|null,
     *     local_media_sync_errors: array<int, string>,
     *     synced_local_media_ids: array<int, int>
     * }
     */
    private function buildEditorSyncPayload(SeoArticle $article, ?array $seoOverride = null, array $syncOptions = []): array
    {
        $deferInlineMedia = (bool) ($syncOptions['defer_inline_media_sync'] ?? false);
        $article->loadMissing('site');
        $site = $article->site;
        if (! $site instanceof Site) {
            return [
                'request_payload' => [],
                'post_content' => '',
                'faqs' => [],
                'faq_extract_debug' => null,
                'wp_taxonomy' => null,
                'local_media_sync_errors' => ['Không tìm thấy tên miền của bài viết.'],
                'synced_local_media_ids' => [],
            ];
        }

        $postContent = trim(app(\Omnichannel\Addons\Content\Services\ArticleEditor\Document\ArticleEditorDocumentWriter::class)
            ->ensureDerivedBodyForPublish($article));
        if ($postContent === '') {
            $postContent = trim((string) ($article->body ?? ''));
        }
        $localMediaSyncErrors = [];
        $syncedLocalMediaIds = [];
        if ($postContent !== '') {
            $postContent = app(ArticleEditorHtmlSanitizeService::class)->prepareHtmlForWordPressSync($postContent);
            $postContent = app(ArticleCtaPlaceholderService::class)->replaceInHtml($postContent, $site);
            $postContent = app(WorkflowParserService::class)->removeFaqAndAppendShortcodeFromContent($postContent);
            $postContent = app(ArticlePostContentFaqPlaceholder::class)->normalizeForWordPress($postContent);
            if (! $deferInlineMedia) {
                try {
                    $localMediaSync = app(WordPressLocalMediaSyncService::class)->syncHtml($article, $postContent);
                } catch (Throwable $mediaException) {
                    Log::warning('WordPress local media sync exception', [
                        'article_id' => $article->id,
                        'error' => $mediaException->getMessage(),
                    ]);
                    $localMediaSync = [
                        'html' => $postContent,
                        'errors' => ['Lỗi đồng bộ ảnh local: '.$mediaException->getMessage()],
                    ];
                }
                $postContent = (string) ($localMediaSync['html'] ?? $postContent);
                $localMediaSyncErrors = is_array($localMediaSync['errors'] ?? null)
                    ? $localMediaSync['errors']
                    : [];
                $syncedLocalMediaIds = is_array($localMediaSync['synced_media_ids'] ?? null)
                    ? array_values(array_filter(array_map(
                        static fn ($id): int => (int) $id,
                        $localMediaSync['synced_media_ids'],
                    )))
                    : [];
            }
        }

        $faqs = $article->resolveFaqs();
        $faqs = $this->sanitizeFaqsForWordPress($faqs);
        $faqs = app(ArticleCtaPlaceholderService::class)->replaceInFaqs($faqs, $site);
        $faqExtractDebug = null;

        if ($faqs === []) {
            $bodyForDiagnosis = trim((string) ($article->body ?? ''));
            if ($bodyForDiagnosis !== '') {
                $diagnosis = app(WorkflowParserService::class)->diagnoseManualFaqExtract($bodyForDiagnosis);
                $faqExtractDebug = app(ArticleFaqExtractDebugService::class)->recordFromContentDiagnosis(
                    $article,
                    $diagnosis,
                    'wp_sync_empty_faqs',
                    'sync',
                );
            }
        } else {
            app(ArticleFaqExtractDebugService::class)->clear($article);
        }

        $wpContentService = app(WordPressArticleContentService::class);
        $wpTaxonomy = $wpContentService->resolveWpTaxonomy($article);

        if ($wpTaxonomy !== null) {
            $payload = [
                'title' => (string) ($article->title ?? ''),
                'slug' => (string) ($article->slug ?? ''),
                'post_content' => $postContent !== '' ? $postContent : null,
                'faqs' => $faqs,
                'seo' => $this->resolveSeoPayloadForWordPress($article, $seoOverride),
                'parent_id' => $this->resolveTaxonomyParentIdForWordPress($article),
            ];
        } else {
            $requestedPostType = $this->resolveWordPressPostTypeForPush($article);

            $payload = [
                'title' => (string) ($article->title ?? ''),
                'slug' => (string) ($article->slug ?? ''),
                ...$this->resolveWordPressStatusPayload($article),
                'post_type' => $requestedPostType,
                'post_content' => $postContent !== '' ? $postContent : null,
                'faqs' => $faqs,
                'seo' => $this->resolveSeoPayloadForWordPress($article, $seoOverride),
            ];

            $categoryIds = $this->resolveCategoryIdsForWordPress($article);
            if ($categoryIds !== []) {
                $payload['category_ids'] = $categoryIds;
            }
        }

        // Post-publish editorial sync: update content only — preserve remote status/date.
        if ((bool) ($syncOptions['omit_publication_fields'] ?? false)) {
            unset($payload['status'], $payload['post_date'], $payload['post_date_gmt']);
        }

        $fieldConflicts = app(WordPressFieldConflictService::class)->detectConflicts(
            $article,
            app(WordPressFieldConflictService::class)->localSnapshotFromPayload([
                ...$payload,
                'post_content' => $postContent,
            ]),
        );
        foreach (array_keys($fieldConflicts) as $conflictField) {
            if ($conflictField === 'slug') {
                unset($payload['slug']);
            } elseif ($conflictField === 'post_content') {
                unset($payload['post_content']);
            } elseif (array_key_exists($conflictField, $payload)) {
                unset($payload[$conflictField]);
            } elseif (isset($payload['seo']) && is_array($payload['seo']) && array_key_exists($conflictField, $payload['seo'])) {
                unset($payload['seo'][$conflictField]);
            }
        }

        // FAQ rỗng thật trên Laravel → cho phép WP xóa meta; tránh giữ FAQ cũ lệch.
        if ($faqs === []) {
            $payload['clear_faqs'] = true;
        }

        $prepared = [
            'request_payload' => $payload,
            'post_content' => $postContent,
            'faqs' => $faqs,
            'faq_extract_debug' => $faqExtractDebug,
            'wp_taxonomy' => $wpTaxonomy,
            'local_media_sync_errors' => $localMediaSyncErrors,
            'synced_local_media_ids' => $syncedLocalMediaIds,
            'defer_inline_media_sync' => $deferInlineMedia,
            'field_conflicts' => $fieldConflicts,
        ];

        $skipCheck = (bool) ($syncOptions['force_editor_sync'] ?? false)
            ? ['skip' => false, 'reason' => 'force_editor_sync']
            : $this->shouldSkipEditorSyncRequest($article, $prepared);

        // Even force paths must not push empty content when Laravel has no local unsynced body.
        $article->loadMissing('wordpressLink');
        if (
            ! $skipCheck['skip']
            && (int) ($article->wordpressLink?->wp_post_id ?? 0) > 0
            && trim((string) ($article->body ?? '')) === ''
            && ! (bool) ($syncOptions['force_content_push'] ?? false)
        ) {
            $skipCheck = ['skip' => true, 'reason' => 'no_local_unsynced_body'];
        }

        return [
            ...$prepared,
            'skip_editor_sync' => $skipCheck['skip'],
            'skip_editor_sync_reason' => $skipCheck['reason'],
        ];
    }

    /**
     * @param  array{
     *     request_payload: array<string, mixed>,
     *     post_content: string,
     *     faqs: array<int, array<string, string>>,
     *     faq_extract_debug: array<string, mixed>|null,
     *     wp_taxonomy: string|null,
     *     local_media_sync_errors: array<int, string>,
     *     synced_local_media_ids: array<int, int>
     * }  $prepared
     * @return array{skip: bool, reason: string}
     */
    public function shouldSkipSaveLocalPhase(SeoArticle $article, string $html, ?array $seoAnalysis = null): array
    {
        if (app(ArticleWordPressSyncFlagService::class)->hasLocalEditPending($article)) {
            return ['skip' => false, 'reason' => 'local_edit_pending'];
        }

        $article->loadMissing('articleMetas');
        $storedFingerprint = trim((string) ($article->articleMetas
            ->firstWhere('meta_key', self::META_WP_LOCAL_SAVE_FINGERPRINT)?->meta_value ?? ''));
        $currentFingerprint = $this->localSaveFingerprint($article, $html, $seoAnalysis);

        if ($storedFingerprint !== '' && hash_equals($storedFingerprint, $currentFingerprint)) {
            return ['skip' => true, 'reason' => 'fingerprint_match'];
        }

        $existingBody = trim((string) ($article->body ?? ''));
        $incomingBody = trim(app(ArticleEditorHtmlSanitizeService::class)->stripTransientEditorMarkup($html));

        if (
            $storedFingerprint === ''
            && $existingBody !== ''
            && $this->normalizeEditorSyncContent($existingBody) === $this->normalizeEditorSyncContent($incomingBody)
        ) {
            return ['skip' => true, 'reason' => 'body_match'];
        }

        return ['skip' => false, 'reason' => 'changed'];
    }

    public function storeLocalSaveFingerprint(SeoArticle $article, string $html, ?array $seoAnalysis = null): void
    {
        $fingerprint = $this->localSaveFingerprint($article->fresh(), $html, $seoAnalysis);
        if ($fingerprint === '') {
            return;
        }

        $article->articleMetas()->updateOrCreate(
            ['meta_key' => self::META_WP_LOCAL_SAVE_FINGERPRINT],
            ['meta_value' => $fingerprint],
        );
    }

    private function localSaveFingerprint(SeoArticle $article, string $html, ?array $seoAnalysis = null): string
    {
        $article->loadMissing('articleMetas');
        $html = trim(app(ArticleEditorHtmlSanitizeService::class)->stripTransientEditorMarkup($html));

        $featuredUrl = trim((string) ($article->articleMetas
            ->firstWhere('meta_key', ArticleMediaLocalService::META_FEATURED_URL)?->meta_value ?? ''));
        $featuredId = (int) ($article->articleMetas
            ->firstWhere('meta_key', ArticleMediaLocalService::META_FEATURED_ATTACHMENT_ID)?->meta_value ?? 0);
        $galleryRaw = (string) ($article->articleMetas
            ->firstWhere('meta_key', ArticleMediaLocalService::META_PRODUCT_GALLERY_IDS)?->meta_value ?? '');

        $canonical = [
            'body' => $this->normalizeEditorSyncContent($html),
            'title' => trim((string) ($article->title ?? '')),
            'slug' => trim((string) ($article->slug ?? '')),
            'seo' => is_array($seoAnalysis) ? $this->normalizeSeoAnalysisFingerprint($seoAnalysis) : null,
            'featured' => [
                'url' => $featuredUrl,
                'id' => $featuredId,
            ],
            'gallery_ids' => $galleryRaw !== '' ? json_decode($galleryRaw, true) : [],
        ];

        return hash('sha256', json_encode($canonical, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '');
    }

    /**
     * @param  array<string, mixed>  $seoAnalysis
     * @return array<string, mixed>
     */
    private function normalizeSeoAnalysisFingerprint(array $seoAnalysis): array
    {
        return [
            'score' => $seoAnalysis['score'] ?? null,
            'meta_description' => trim((string) ($seoAnalysis['meta_description'] ?? '')),
            'focus_keyword' => trim((string) ($seoAnalysis['focus_keyword'] ?? '')),
        ];
    }

    public function shouldSkipEditorSyncRequest(SeoArticle $article, array $prepared): array
    {
        $wpPostId = (int) ($article->wordpressLink?->wp_post_id ?? 0);
        if ($wpPostId <= 0) {
            return ['skip' => false, 'reason' => 'missing_wp_post_id'];
        }

        // WP-backed + no local unsynced body → do not push empty/cache content back to WP.
        $localBody = trim((string) ($article->body ?? ''));
        if ($localBody === '' && ! (bool) ($prepared['force_content_push'] ?? false)) {
            return ['skip' => true, 'reason' => 'no_local_unsynced_body'];
        }

        $payloadStatus = strtolower(trim((string) (
            is_array($prepared['request_payload'] ?? null)
                ? ($prepared['request_payload']['status'] ?? '')
                : ''
        )));
        // Luôn đẩy publish/private lên WP — đè draft cũ, không skip theo fingerprint.
        if (in_array($payloadStatus, ['publish', 'private'], true) && $localBody !== '') {
            return ['skip' => false, 'reason' => 'force_status_override'];
        }

        if (app(ArticleWordPressSyncFlagService::class)->hasLocalEditPending($article)) {
            return ['skip' => false, 'reason' => 'local_edit_pending'];
        }

        if (($prepared['local_media_sync_errors'] ?? []) !== []) {
            return ['skip' => false, 'reason' => 'media_sync_errors'];
        }

        $postContent = (string) ($prepared['post_content'] ?? '');
        if (app(WordPressLocalMediaSyncService::class)->htmlContainsLocalSeoMedia($postContent)) {
            return ['skip' => false, 'reason' => 'pending_local_media'];
        }

        $article->loadMissing('articleMetas');
        $storedFingerprint = trim((string) ($article->articleMetas
            ->firstWhere('meta_key', self::META_WP_EDITOR_SYNC_FINGERPRINT)?->meta_value ?? ''));
        $currentFingerprint = $this->editorSyncFingerprint($prepared);

        if ($storedFingerprint !== '' && hash_equals($storedFingerprint, $currentFingerprint)) {
            return ['skip' => true, 'reason' => 'fingerprint_match'];
        }

        $bodyContent = trim((string) ($article->body ?? ''));
        $preparedContent = trim((string) ($prepared['post_content'] ?? ''));

        if (
            $storedFingerprint === ''
            && $bodyContent !== ''
            && $this->normalizeEditorSyncContent($bodyContent) === $this->normalizeEditorSyncContent($preparedContent)
        ) {
            return ['skip' => true, 'reason' => 'body_content_match'];
        }

        return ['skip' => false, 'reason' => 'payload_changed'];
    }

    /**
     * @return array<string, mixed>
     */
    public function buildSkippedEditorSyncDecoded(SeoArticle $article): array
    {
        $article->loadMissing('articleMetas');
        $classification = ArticleContentClassification::for($article);

        return [
            'success' => true,
            'message' => 'editor-sync skipped',
            'slug' => trim((string) ($article->slug ?? '')),
            'permalink' => trim((string) ($article->articleMetas->firstWhere('meta_key', 'wp_permalink')?->meta_value ?? '')),
            'post_type' => $classification->isTerm()
                ? ($classification->wpPostType()
                    ?? ($classification->contentType() === ContentType::Product ? 'product_cat' : 'category'))
                : $this->resolveWordPressPostTypeForPush($article),
            'skipped' => true,
        ];
    }

    /**
     * Native WordPress post_type for push. Raw `wp_post_type` meta wins so a page stays a page
     * and a CPT (vd. `machine`) stays itself; otherwise derive from canonical content_type.
     */
    private function resolveWordPressPostTypeForPush(SeoArticle $article): string
    {
        $classification = ArticleContentClassification::for($article);
        $native = strtolower(trim((string) ($classification->wpPostType() ?? '')));

        if ($native !== '' && ! $classification->isTerm()) {
            return $native;
        }

        return match ($classification->contentType()) {
            ContentType::Product => 'product',
            ContentType::Page => 'page',
            ContentType::Post => 'post',
        };
    }

    /**
     * @param  array{
     *     request_payload: array<string, mixed>,
     *     post_content: string,
     *     faqs: array<int, array<string, string>>,
     *     faq_extract_debug: array<string, mixed>|null,
     *     wp_taxonomy: string|null,
     *     local_media_sync_errors: array<int, string>,
     *     synced_local_media_ids: array<int, int>
     * }  $prepared
     */
    private function storeEditorSyncFingerprint(SeoArticle $article, array $prepared): void
    {
        $fingerprint = $this->editorSyncFingerprint($prepared);
        if ($fingerprint === '') {
            return;
        }

        $article->articleMetas()->updateOrCreate(
            ['meta_key' => self::META_WP_EDITOR_SYNC_FINGERPRINT],
            ['meta_value' => $fingerprint],
        );
    }

    /**
     * @param  array{
     *     request_payload: array<string, mixed>,
     *     post_content: string,
     *     faqs: array<int, array<string, string>>,
     * }  $prepared
     */
    private function editorSyncFingerprint(array $prepared): string
    {
        $payload = is_array($prepared['request_payload'] ?? null) ? $prepared['request_payload'] : [];
        $canonical = [
            'title' => (string) ($payload['title'] ?? ''),
            'slug' => (string) ($payload['slug'] ?? ''),
            'status' => $payload['status'] ?? null,
            'post_type' => $payload['post_type'] ?? null,
            'post_content' => $this->normalizeEditorSyncContent((string) ($prepared['post_content'] ?? '')),
            'faqs' => is_array($prepared['faqs'] ?? null) ? $prepared['faqs'] : [],
            'seo' => is_array($payload['seo'] ?? null) ? $payload['seo'] : [],
            'category_ids' => $payload['category_ids'] ?? null,
            'parent_id' => $payload['parent_id'] ?? null,
        ];

        return hash('sha256', json_encode($canonical, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '');
    }

    private function normalizeEditorSyncContent(string $html): string
    {
        $html = trim($html);
        if ($html === '') {
            return '';
        }

        return preg_replace('/\s+/u', ' ', $html) ?? $html;
    }

    /**
     * @return array{success: bool, message?: string, url?: string, write_token?: string, wp_post_id?: int}
     */
    public function resolveEditorSyncContext(SeoArticle $article): array
    {
        $wpPostId = (int) ($article->wordpressLink?->wp_post_id ?? 0);
        if ($wpPostId <= 0) {
            return [
                'success' => false,
                'message' => 'Bài chưa liên kết WordPress (thiếu wp_post_id). Chạy đồng bộ domain trước.',
            ];
        }

        $article->loadMissing('site');
        $site = $article->site;
        if (! $site instanceof Site) {
            return [
                'success' => false,
                'message' => 'Không tìm thấy tên miền của bài viết.',
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

        $url = app(WordPressArticleContentService::class)->buildEditorSyncUrl($site, $article);
        if ($url === '') {
            return [
                'success' => false,
                'message' => 'Không xác định được URL WordPress.',
            ];
        }

        return [
            'success' => true,
            'url' => $url,
            'write_token' => $writeToken,
            'wp_post_id' => $wpPostId,
        ];
    }

    /**
     * Slug khi đăng bài mới: ưu tiên focus keyword, sau đó slug đã lưu, cuối cùng mới đến tiêu đề.
     * WordPress sẽ tự thêm hậu tố (-2, -3...) nếu trùng — slug trả về được ghi lại vào article.
     */
    private function resolveSlugForNewPost(SeoArticle $article): string
    {
        $keyword = trim((string) (app(SeoAnalyzerService::class)->resolveFocusKeywordForArticle($article) ?? ''));
        $slug = \Illuminate\Support\Str::slug($keyword);
        if ($slug !== '') {
            return $slug;
        }

        $slug = \Illuminate\Support\Str::slug((string) ($article->slug ?? ''));
        if ($slug !== '') {
            return $slug;
        }

        return \Illuminate\Support\Str::slug((string) ($article->title ?? ''));
    }

    /**
     * Đăng bài đã đến giờ lên WordPress (cron Laravel). Giữ status=scheduled nếu WP thất bại để retry.
     *
     * @return array{success: bool, message: string}
     */
    public function publishScheduledArticle(
        SeoArticle $article,
        WordPressExecutionContext $sideEffect,
    ): array {
        $this->assertSideEffectArticle($sideEffect, $article);

        if ($blocked = $this->blockContentManagerWordPressSync()) {
            return $blocked;
        }

        if ((string) ($article->status ?? '') !== 'scheduled') {
            return [
                'success' => false,
                'message' => 'Bài không ở trạng thái scheduled.',
            ];
        }

        if (! $article->publishingState?->published_at instanceof Carbon || $article->publishingState->published_at->isFuture()) {
            return [
                'success' => false,
                'message' => 'Chưa đến giờ đăng bài.',
            ];
        }

        $context = $this->resolveEditorSyncContext($article);
        if (! ($context['success'] ?? false)) {
            return [
                'success' => false,
                'message' => (string) ($context['message'] ?? 'Không thể đăng bài lên WordPress.'),
            ];
        }

        $payload = [
            'status' => 'publish',
            'post_date' => $this->formatPostDateForWordPress($article),
        ];

        try {
            $response = $this->gateway->postJson(
                $sideEffect,
                'article.publish_scheduled',
                (string) $context['url'],
                (string) $context['write_token'],
                array_filter(
                    $payload,
                    static fn (mixed $value): bool => $value !== null && $value !== '',
                ),
                30,
                (int) $article->id,
                (int) ($article->site_id ?? 0),
            );

            if (! $response->successful()) {
                return [
                    'success' => false,
                    'message' => WordPressRestResponseParser::formatHttpErrorMessage($response->status(), $response),
                ];
            }

            $decoded = $response->json();
            if (! is_array($decoded) || ! ($decoded['success'] ?? false)) {
                return [
                    'success' => false,
                    'message' => (string) ($decoded['message'] ?? 'WordPress từ chối đăng bài.'),
                ];
            }

            $article->update(['status' => 'published']);
            $this->timestampService->sync($article->fresh(), $decoded);

            return [
                'success' => true,
                'message' => (string) ($decoded['message'] ?? 'Đã đăng bài lên WordPress.'),
            ];
        } catch (Throwable $e) {
            Log::warning('WordPress scheduled publish exception', [
                'article_id' => $article->id,
                'wp_post_id' => $context['wp_post_id'] ?? null,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'message' => 'Không kết nối được WordPress: '.$e->getMessage(),
            ];
        }
    }

    /**
     * Outbound sync → WordPress: chỉ có một trạng thái `publish`.
     * Lịch đăng (scheduled) chỉ sống trên Laravel; cron tới giờ mới gọi sync.
     * Không gửi draft/future/private lên WP.
     *
     * @return array{status?: string, post_date?: string}
     */
    private function resolveWordPressStatusPayload(SeoArticle $article): array
    {
        $status = strtolower(trim((string) ($article->status ?? 'draft')));

        // Không bao giờ đẩy trash/delete lên WordPress.
        if (in_array($status, ['trash', 'deleted'], true)) {
            return [];
        }

        return [
            'status' => 'publish',
            'post_date' => $this->formatPostDateForWordPressPublish($article),
        ];
    }

    /**
     * Ngày đăng gửi WP khi publish. post_date tương lai khiến WP đổi thành `future` — clamp về now.
     */
    private function formatPostDateForWordPressPublish(SeoArticle $article): string
    {
        $tz = \Omnichannel\Addons\Seo\Support\SeoDisplayTimezone::name();
        $at = $article->publishingState?->published_at instanceof Carbon
            ? $article->publishingState->published_at->copy()->timezone($tz)
            : \Omnichannel\Addons\Seo\Support\SeoDisplayTimezone::now();

        if ($at->isFuture()) {
            $at = \Omnichannel\Addons\Seo\Support\SeoDisplayTimezone::now();
        }

        return $at->format('Y-m-d H:i:s');
    }

    private function formatPostDateForWordPress(SeoArticle $article): ?string
    {
        if (! $article->publishingState?->published_at instanceof Carbon) {
            return null;
        }

        return $article->publishingState->published_at
            ->copy()
            ->timezone(\Omnichannel\Addons\Seo\Support\SeoDisplayTimezone::name())
            ->format('Y-m-d H:i:s');
    }

    /**
     * @param  list<int>  $mediaIds
     */
    private function syncPromptMediaLinksToWordPressUrls(SeoArticle $article, array $mediaIds): int
    {
        $articleId = (int) ($article->id ?? 0);
        if ($articleId <= 0) {
            return 0;
        }

        $mediaIds = array_values(array_unique(array_filter(array_map(
            static fn (mixed $id): int => (int) $id,
            $mediaIds,
        ), static fn (int $id): bool => $id > 0)));
        if ($mediaIds === []) {
            return 0;
        }

        $mediaById = SeoMedia::query()
            ->whereIn('id', $mediaIds)
            ->get()
            ->keyBy(static fn (SeoMedia $media): int => (int) $media->id);

        if ($mediaById->isEmpty()) {
            return 0;
        }

        $article->loadMissing('site');
        $site = $article->site;
        if (! $site instanceof Site) {
            return 0;
        }

        $links = SeoPromptResultLink::query()
            ->where('article_id', $articleId)
            ->where('source', 'editor_media_generation')
            ->orderBy('id')
            ->get();

        if ($links->isEmpty()) {
            return 0;
        }

        $updated = 0;
        $resultCache = [];
        $wpUrlCache = [];
        foreach ($links as $link) {
            $meta = is_array($link->meta) ? $link->meta : [];
            $seoMediaId = (int) ($meta['seo_media_id'] ?? 0);
            if ($seoMediaId <= 0) {
                continue;
            }

            $media = $mediaById->get($seoMediaId);
            if (! $media instanceof SeoMedia) {
                continue;
            }

            if (! array_key_exists($seoMediaId, $wpUrlCache)) {
                $wpUrlCache[$seoMediaId] = $this->resolveWordPressMediaUrl($site, $media);
            }

            $wpUrl = trim((string) $wpUrlCache[$seoMediaId]);
            if ($wpUrl === '') {
                continue;
            }

            $resultId = (int) ($link->prompt_result_id ?? 0);
            if ($resultId <= 0) {
                continue;
            }

            if (! array_key_exists($resultId, $resultCache)) {
                $resultCache[$resultId] = PromptResult::query()->find($resultId);
            }

            $result = $resultCache[$resultId];
            if (! $result instanceof PromptResult) {
                continue;
            }

            $existingOutput = (string) ($result->output_text ?? '');
            $newOutput = $this->replaceFirstLocalMediaUrl($existingOutput, $wpUrl);
            if ($newOutput === null || $newOutput === $existingOutput) {
                continue;
            }

            $result->update(['output_text' => $newOutput]);
            $result->output_text = $newOutput;
            $resultCache[$resultId] = $result;

            $meta['wp_url'] = $wpUrl;
            $link->update(['meta' => $meta]);
            $updated++;
        }

        return $updated;
    }

    private function resolveWordPressMediaUrl(Site $site, SeoMedia $media): string
    {
        $candidate = trim((string) ($media->getAttribute('wp_url') ?? ''));
        if ($candidate !== '') {
            return $candidate;
        }

        $attachmentId = (int) ($media->wp_attachment_id ?? 0);
        if ($attachmentId <= 0) {
            return '';
        }

        $attachment = app(WordPressMediaLibraryService::class)->fetchAttachmentById($site, $attachmentId);
        if (! is_array($attachment)) {
            return '';
        }

        return trim((string) ($attachment['url'] ?? ''));
    }

    private function replaceFirstLocalMediaUrl(string $output, string $wpUrl): ?string
    {
        $wpUrl = trim($wpUrl);
        if ($wpUrl === '') {
            return null;
        }

        $normalized = trim($output);
        if ($normalized === '') {
            return null;
        }

        $lines = preg_split('/\R/', $normalized) ?: [];
        $firstLine = trim((string) ($lines[0] ?? ''));
        if ($firstLine === '' || ! $this->isLocalSeoMediaUrl($firstLine)) {
            return null;
        }

        if ($firstLine === $wpUrl) {
            return $normalized;
        }

        $lines[0] = $wpUrl;

        return implode("\n", $lines);
    }

    private function isLocalSeoMediaUrl(string $value): bool
    {
        $value = trim($value);
        if ($value === '') {
            return false;
        }

        $path = parse_url($value, PHP_URL_PATH);
        $path = is_string($path) ? $path : $value;

        return preg_match('#/storage/uploads/seo_media/#i', $path) === 1;
    }

    /**
     * @param  array{seo_title?: string, meta_description?: string, focus_keyword?: string}|null  $override
     * @return array{seo_title: string, meta_description: string, focus_keyword: string}
     */
    private function resolveSeoPayloadForWordPress(SeoArticle $article, ?array $override = null): array
    {
        $article->loadMissing('articleMetas');
        $override = $override ?? [];

        $seoTitle = '';
        if (array_key_exists('seo_title', $override)) {
            $seoTitle = trim((string) $override['seo_title']);
        }

        $metaDescription = trim((string) ($override['meta_description'] ?? ''));
        if ($metaDescription === '') {
            $metaDescription = trim((string) (
                $article->articleMetas->first(
                    static fn ($meta): bool => in_array((string) $meta->meta_key, [
                        'seo_meta_description',
                        'meta_description',
                    ], true),
                )?->meta_value ?? ''
            ));
        }

        $focusKeyword = trim((string) ($override['focus_keyword'] ?? ''));
        if ($focusKeyword === '') {
            $focusKeyword = app(SeoAnalyzerService::class)->resolveFocusKeywordForArticle($article) ?? '';
        }

        return [
            'seo_title' => $seoTitle,
            'meta_description' => $metaDescription,
            'focus_keyword' => $focusKeyword,
        ];
    }

    /**
     * @param  list<array{question: string, answer: string, more?: string|null}>  $faqs
     * @return list<array{question: string, answer: string, more?: string|null}>
     */
    private function sanitizeFaqsForWordPress(array $faqs): array
    {
        $sanitizer = app(ArticleEditorHtmlSanitizeService::class);

        return array_map(static function (array $faq) use ($sanitizer): array {
            if (isset($faq['answer']) && is_string($faq['answer'])) {
                $faq['answer'] = $sanitizer->prepareHtmlForWordPressSync($faq['answer']);
            }

            return $faq;
        }, $faqs);
    }

    /**
     * @return list<int>
     */
    private function resolveCategoryIdsForWordPress(SeoArticle $article): array
    {
        $article->loadMissing('articleMetas');
        $raw = (string) ($article->articleMetas->firstWhere('meta_key', 'category_ids')?->meta_value ?? '');
        if ($raw === '') {
            return [];
        }

        $decoded = json_decode($raw, true);
        if (! is_array($decoded)) {
            return [];
        }

        return collect($decoded)
            ->map(static fn (mixed $id): int => (int) $id)
            ->filter(static fn (int $id): bool => $id > 0)
            ->unique()
            ->values()
            ->all();
    }

    private function resolveTaxonomyParentIdForWordPress(SeoArticle $article): int
    {
        $article->loadMissing('articleMetas');

        return max(0, (int) (
            $article->articleMetas->firstWhere('meta_key', 'wp_parent_id')?->meta_value ?? 0
        ));
    }

    /**
     * @return array{success: bool, message: string}|null
     */
    private function assertSideEffectArticle(WordPressExecutionContext $sideEffect, SeoArticle $article): void
    {
        $ctxId = $sideEffect->articleId();
        if ($ctxId !== null && $ctxId > 0 && $ctxId !== (int) $article->id) {
            throw new UnauthorizedWordPressSideEffectException(
                UnauthorizedWordPressSideEffectException::ORIGIN_INVALID,
                "Context article_id [{$ctxId}] does not match article [{$article->id}].",
            );
        }
    }

    private function blockContentManagerWordPressSync(): ?array
    {
        if (SeoQueueContext::isWpSyncFromQueue()) {
            return null;
        }

        if (SeoAccessControl::canSyncArticlesToWordPress()) {
            return null;
        }

        return [
            'success' => false,
            'error_code' => 'WORDPRESS_SYNC_FORBIDDEN_ROLE',
            'failed_stage' => 'permission_gate',
            'message' => 'Vai trò Quản lý nội dung chỉ được lưu trên Laravel, không đồng bộ WordPress.',
        ];
    }

    /**
     * @return array{success: false, message: string, error_code: string}
     */
    private function slugFixRequiredResponse(): array
    {
        return [
            'success' => false,
            'message' => WordPressSlugFixRequiredException::MESSAGE,
            'error_code' => WordPressSlugFixRequiredException::ERROR_CODE,
        ];
    }

    /**
     * @param  array<string, mixed>  $item
     */
    public function applyMultilingualFromSyncPayload(SeoArticle $article, Site $site, array $item): void
    {
        app(ArticlePolylangSyncService::class)->applyFromSyncPayload($article, $site, $item);
    }
}
