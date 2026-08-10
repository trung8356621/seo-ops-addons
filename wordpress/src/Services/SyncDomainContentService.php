<?php

declare(strict_types=1);

namespace Omnichannel\Addons\WordPress\Services;


use Omnichannel\Addons\Seo\Services\SeoAnalyzerService;
use Omnichannel\Addons\SearchFoundation\Models\Keyword;
use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\SearchFoundation\Support\DomainSyncManifestComparator;
use Omnichannel\Addons\SearchIntelligence\Support\KeywordFocusAttach;
use Omnichannel\Addons\SearchIntelligence\Support\RankMathSeoValueNormalizer;
use Omnichannel\Addons\WordPress\Support\WordPressPermalinkBuilder;
use App\Models\Site;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class SyncDomainContentService
{
    private const INCREMENTAL_FETCH_CHUNK = 40;

    public const META_PULL_SYNC_AUDIT = 'wp_pull_sync_audit';

    public function __construct(
        private readonly WordPressArticleTimestampService $timestampService,
        private readonly ArticleTocExtractionService $tocExtraction,
        private readonly DomainSyncManifestComparator $manifestComparator,
    ) {}

    /**
     * Pull destructive một bài từ WordPress → Laravel (WP là nguồn sự thật).
     *
     * @return array{
     *     success: bool,
     *     message: string,
     *     inline_images?: int,
     *     category_ids?: list<int>,
     *     previous_checksum?: string,
     *     new_checksum?: string,
     * }
     */
    public function syncSingleArticleFromWordPress(SeoArticle $article): array
    {
        $stage = 'validate';

        try {
            $wpPostId = (int) ($article->wordpressLink?->wp_post_id ?? 0);
            if ($wpPostId <= 0) {
                return [
                    'success' => false,
                    'message' => 'Bài chưa liên kết WordPress.',
                ];
            }

            $article->loadMissing(['site', 'articleMetas']);
            $site = $article->site;
            if (! $site instanceof Site) {
                return [
                    'success' => false,
                    'message' => 'Không tìm thấy domain của bài viết.',
                ];
            }

            if ((int) ($article->site_id ?? 0) !== (int) $site->id) {
                return [
                    'success' => false,
                    'message' => 'Bài viết không thuộc domain hiện tại.',
                ];
            }

            $validationError = $this->validateWordPressSite($site);
            if ($validationError !== null) {
                return $validationError;
            }

            $site->loadMissing('metas');
            $readToken = trim((string) ($site->getMeta('seo_read_token') ?? ''));
            $wpEntity = trim((string) (
                $article->articleMetas->firstWhere('meta_key', 'wp_entity')?->meta_value ?? 'post'
            ));
            if ($wpEntity === '') {
                $wpEntity = 'post';
            }

            $ref = [
                'wp_id' => $wpPostId,
                'wp_entity' => $wpEntity,
            ];

            if ($wpEntity === 'term') {
                $taxonomy = trim((string) (
                    $article->articleMetas->firstWhere('meta_key', 'wp_taxonomy')?->meta_value
                    ?? match (strtolower(trim((string) ($article->type ?? '')))) {
                        'product_category', 'product_cat' => 'product_cat',
                        'category' => 'category',
                        default => '',
                    }
                ));
                if ($taxonomy !== '') {
                    $ref['wp_post_type'] = $taxonomy;
                }
            }

            $stage = 'fetch';
            $items = $this->fetchItemsByRefs($site, $readToken, [$ref]);
            if ($items === null || $items === []) {
                return [
                    'success' => false,
                    'message' => 'Không lấy được bài từ WordPress (post không tồn tại hoặc bridge lỗi).',
                ];
            }

            $item = $items[0];
            if (! is_array($item) || (int) ($item['wp_id'] ?? 0) !== $wpPostId) {
                return [
                    'success' => false,
                    'message' => 'WordPress trả về post không khớp với bài hiện tại.',
                ];
            }

            $item['wp_id'] = $wpPostId;
            $item['type'] = (string) ($article->type ?? $item['type'] ?? 'article');

            $scoring = app(SeoArticleScoringQueueService::class);
            $previousChecksum = $scoring->buildArticleFingerprint($article);
            $inlineImages = $this->countInlineImagesFromSyncItem($item);
            $categoryIds = $this->extractCategoryIdsFromSyncItem($item);

            $stage = 'import';
            $userId = (int) $site->user_id;
            $syncFlags = app(ArticleWordPressSyncFlagService::class);
            $synced = [
                'article' => 0,
                'product' => 0,
                'category' => 0,
                'product_category' => 0,
                'other' => 0,
            ];

            DB::connection($article->getConnectionName())->transaction(function () use (
                $site,
                $item,
                $wpPostId,
                &$synced,
                $userId,
                $syncFlags,
                $article,
                $categoryIds,
            ): void {
                $this->importSingleSyncItem(
                    site: $site,
                    item: $item,
                    wpId: $wpPostId,
                    synced: $synced,
                    userId: $userId,
                    syncFlags: $syncFlags,
                    forceOverwrite: true,
                );

                $article->refresh();
                $this->syncCategoryIdsFromSyncItem($article, $item, $categoryIds);
            });

            $article->refresh();
            $newChecksum = $scoring->buildArticleFingerprint($article);
            $wpModified = trim((string) ($item['post_modified'] ?? ''));

            $stage = 'audit';
            $this->persistPullSyncAudit($article, [
                'user_id' => (int) (auth()->id() ?? 0),
                'article_id' => (int) $article->id,
                'wp_post_id' => $wpPostId,
                'previous_content_checksum' => $previousChecksum,
                'new_content_checksum' => $newChecksum,
                'wordpress_modified_at' => $wpModified !== '' ? $wpModified : null,
                'inline_images' => $inlineImages,
                'synchronized_at' => now()->toIso8601String(),
            ]);

            Log::info('SeoContentAi single-article WordPress pull sync completed', [
                'user_id' => (int) (auth()->id() ?? 0),
                'article_id' => (int) $article->id,
                'wp_post_id' => $wpPostId,
                'site_id' => (int) $site->id,
                'previous_content_checksum' => $previousChecksum,
                'new_content_checksum' => $newChecksum,
                'wordpress_modified_at' => $wpModified !== '' ? $wpModified : null,
                'inline_images' => $inlineImages,
            ]);

            return [
                'success' => true,
                'message' => sprintf(
                    'Đã đồng bộ lại từ WordPress (ghi đè). Ảnh trong bài: %d.',
                    $inlineImages,
                ),
                'inline_images' => $inlineImages,
                'category_ids' => $categoryIds,
                'previous_checksum' => $previousChecksum,
                'new_checksum' => $newChecksum,
            ];
        } catch (Throwable $e) {
            Log::error('SeoContentAi single-article WordPress pull sync failed', [
                'article_id' => (int) ($article->id ?? 0),
                'wp_post_id' => (int) ($article->wordpressLink?->wp_post_id ?? 0),
                'site_id' => (int) ($article->site_id ?? 0),
                'stage' => $stage,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'message' => 'Đồng bộ từ WordPress thất bại: '.$e->getMessage(),
            ];
        }
    }

    /**
     * @param  array{is_test?:bool,limit_per_type?:int}  $options
     * @return array{success:bool,message:string,synced?:array<string,int>,counts?:array<string,int>}
     */
    public function sync(Site $site, array $options = []): array
    {
        $site->loadMissing('metas');

        $platform = (string) ($site->getMeta('seo_platform') ?? '');
        if ($platform !== 'wordpress') {
            return [
                'success' => false,
                'message' => 'Site chưa cấu hình nền tảng WordPress.',
            ];
        }

        $readToken = trim((string) ($site->getMeta('seo_read_token') ?? ''));
        if ($readToken === '') {
            return [
                'success' => false,
                'message' => 'Thiếu SEO Read Token. Hãy lưu token trong trang chỉnh sửa domain.',
            ];
        }

        $isTest = (bool) ($options['is_test'] ?? false);
        $limitPerType = (int) ($options['limit_per_type'] ?? 0);
        if ($isTest && $limitPerType <= 0) {
            $limitPerType = 2;
        }

        $url = $this->buildSyncUrl($site);

        try {
            $siteInfoResult = app(WordPressSiteInfoService::class)->fetchAndStore($site);
            if (! ($siteInfoResult['success'] ?? false)) {
                return [
                    'success' => false,
                    'message' => 'Không lấy được thông tin plugin SEO từ WordPress: '
                        .(string) ($siteInfoResult['message'] ?? 'Lỗi không xác định.'),
                ];
            }

            $response = Http::timeout(120)
                ->acceptJson()
                ->withToken($readToken)
                ->get($url, [
                    'is_test' => $isTest ? 1 : 0,
                    'limit_per_type' => $limitPerType,
                ]);

            if (! $response->successful()) {
                $message = (string) ($response->json('message') ?? $response->body());

                return [
                    'success' => false,
                    'message' => 'WordPress trả lỗi HTTP '.$response->status().': '.mb_substr($message, 0, 300),
                ];
            }

            $payload = $response->json();
            if (! is_array($payload) || ! ($payload['success'] ?? false)) {
                return [
                    'success' => false,
                    'message' => 'Phản hồi WordPress không hợp lệ.',
                ];
            }

            $items = $payload['items'] ?? [];
            if (! is_array($items)) {
                $items = [];
            }

            $synced = $this->importItems($site, $items);
            $counts = is_array($payload['counts'] ?? null) ? $payload['counts'] : [];

            $response = [
                'success' => true,
                'message' => sprintf(
                    'Đồng bộ thành công %d mục từ WordPress%s. Plugin SEO: %s.',
                    array_sum($synced),
                    $isTest ? ' (chế độ test)' : '',
                    (string) ($siteInfoResult['site_info']['active'] ?? 'none'),
                ),
                'synced' => $synced,
            ];

            if ($counts !== []) {
                $response['counts'] = $counts;
            }

            return $response;
        } catch (Throwable $e) {
            Log::error('SeoContentAi sync failed', [
                'site_id' => $site->id,
                'url' => $url,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'message' => 'Không kết nối được WordPress: '.$e->getMessage(),
            ];
        }
    }

    /**
     * Lập kế hoạch đồng bộ bổ sung (manifest + so sánh local).
     *
     * @return array{
     *     success: bool,
     *     message: string,
     *     refs?: array<int, array<string, mixed>>,
     *     skipped?: int,
     *     new_count?: int,
     *     update_count?: int,
     *     total?: int
     * }
     */
    public function prepareIncrementalSync(Site $site): array
    {
        $validation = $this->validateWordPressSite($site);
        if ($validation !== null) {
            return $validation;
        }

        $readToken = trim((string) ($site->getMeta('seo_read_token') ?? ''));
        $manifestUrl = $this->buildSyncManifestUrl($site);

        try {
            $siteInfoResult = app(WordPressSiteInfoService::class)->fetchAndStore($site);
            if (! ($siteInfoResult['success'] ?? false)) {
                return [
                    'success' => false,
                    'message' => 'Không lấy được thông tin plugin SEO từ WordPress: '
                        .(string) ($siteInfoResult['message'] ?? 'Lỗi không xác định.'),
                ];
            }

            $manifestResponse = Http::timeout(120)
                ->acceptJson()
                ->withToken($readToken)
                ->get($manifestUrl);

            if ($manifestResponse->status() === 404) {
                return [
                    'success' => false,
                    'message' => 'Plugin WordPress chưa hỗ trợ đồng bộ bổ sung (cần TVH SEO AI Bridge ≥ 1.0.41). '
                        .'Hãy cập nhật plugin hoặc dùng «Làm sạch & Đồng bộ lại».',
                ];
            }

            if (! $manifestResponse->successful()) {
                $message = (string) ($manifestResponse->json('message') ?? $manifestResponse->body());

                return [
                    'success' => false,
                    'message' => 'WordPress trả lỗi HTTP '.$manifestResponse->status().': '.mb_substr($message, 0, 300),
                ];
            }

            $manifestPayload = $manifestResponse->json();
            if (! is_array($manifestPayload) || ! ($manifestPayload['success'] ?? false)) {
                return [
                    'success' => false,
                    'message' => 'Phản hồi manifest WordPress không hợp lệ.',
                ];
            }

            $entries = is_array($manifestPayload['entries'] ?? null) ? $manifestPayload['entries'] : [];
            $manifestCounts = is_array($manifestPayload['counts'] ?? null) ? $manifestPayload['counts'] : [];
            $manifestTotals = is_array($manifestPayload['totals'] ?? null) ? $manifestPayload['totals'] : [];
            $this->persistManifestCounts($site, $manifestCounts, $manifestTotals);

            $localArticles = SeoArticle::query()
                ->leftJoin('wordpress_article_links as wal_fetch', 'wal_fetch.article_id', '=', 'articles.id')
                ->where('articles.site_id', $site->id)
                ->where('wal_fetch.wp_post_id', '>', 0)
                ->get(['wal_fetch.wp_post_id as wp_post_id', 'articles.type as type', 'articles.updated_at as updated_at']);

            $plan = $this->manifestComparator->resolveFetchRefs($entries, $localArticles);
            $refs = $plan['refs'];
            $manifestTotal = count($entries);
            $localArticleCount = $localArticles
                ->filter(static fn (object $article): bool => in_array((string) ($article->type ?? 'article'), ['article', ''], true))
                ->count();
            $accounted = $plan['skipped'] + count($refs);

            if ($accounted < $manifestTotal) {
                Log::warning('SeoContentAi incremental sync manifest unaccounted entries', [
                    'site_id' => $site->id,
                    'manifest_entries' => $manifestTotal,
                    'accounted' => $accounted,
                    'unaccounted' => $manifestTotal - $accounted,
                ]);
            }

            Log::info('SeoContentAi incremental sync plan', [
                'site_id' => $site->id,
                'manifest_entries' => $manifestTotal,
                'manifest_counts' => $manifestCounts,
                'to_fetch' => count($refs),
                'new_count' => $plan['new_count'],
                'update_count' => $plan['update_count'],
                'skipped' => $plan['skipped'],
            ]);

            if ($refs === []) {
                $gapMessage = $this->buildManifestGapMessage($manifestCounts, $localArticleCount);

                return [
                    'success' => true,
                    'message' => $gapMessage !== ''
                        ? $gapMessage
                        : 'Không có thay đổi mới trên WordPress. Đã bỏ qua '.$plan['skipped'].' mục.',
                    'refs' => [],
                    'skipped' => $plan['skipped'],
                    'new_count' => 0,
                    'update_count' => 0,
                    'total' => 0,
                    'manifest_total' => $manifestTotal,
                    'manifest_counts' => $manifestCounts,
                ];
            }

            return [
                'success' => true,
                'message' => sprintf(
                    'Sẽ đồng bộ %d mục (%d mới, %d cập nhật, %d bỏ qua).',
                    count($refs),
                    $plan['new_count'],
                    $plan['update_count'],
                    $plan['skipped'],
                ),
                'refs' => $refs,
                'skipped' => $plan['skipped'],
                'new_count' => $plan['new_count'],
                'update_count' => $plan['update_count'],
                'total' => count($refs),
                'manifest_total' => $manifestTotal,
                'manifest_counts' => $manifestCounts,
            ];
        } catch (Throwable $e) {
            Log::error('SeoContentAi incremental sync prepare failed', [
                'site_id' => $site->id,
                'url' => $manifestUrl,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'message' => 'Không kết nối được WordPress: '.$e->getMessage(),
            ];
        }
    }

    /**
     * Xử lý một lô refs (10–20 bài) — gọi từ Livewire nhiều lần.
     *
     * @param  array<int, array<string, mixed>>  $chunkRefs
     * @param  array{full_sync_items?: array<int, array<string, mixed>>|null}  $state
     * @return array{
     *     success: bool,
     *     message: string,
     *     synced?: array<string, int>,
     *     imported?: int,
     *     state?: array{full_sync_items?: array<int, array<string, mixed>>|null}
     * }
     */
    public function processIncrementalChunk(Site $site, array $chunkRefs, array $state = []): array
    {
        if ($chunkRefs === []) {
            return [
                'success' => true,
                'message' => 'Không có mục trong lô này.',
                'synced' => $this->emptySyncedCounts(),
                'imported' => 0,
                'state' => $state,
            ];
        }

        $readToken = trim((string) ($site->getMeta('seo_read_token') ?? ''));
        if ($readToken === '') {
            return [
                'success' => false,
                'message' => 'Thiếu SEO Read Token.',
            ];
        }

        try {
            $items = $this->fetchItemsByRefs($site, $readToken, $chunkRefs);

            if ($items === null) {
                $fullItems = $state['full_sync_items'] ?? null;
                if (! is_array($fullItems)) {
                    $fullItems = $this->fetchFullSyncItems($site, $readToken);
                    if ($fullItems === null) {
                        return [
                            'success' => false,
                            'message' => 'Không tải được nội dung từ WordPress.',
                        ];
                    }

                    $state['full_sync_items'] = $fullItems;
                }

                $items = $this->filterItemsByRefs($fullItems, $chunkRefs);
            }

            if ($items === []) {
                return [
                    'success' => false,
                    'message' => sprintf(
                        'WordPress không trả dữ liệu cho %d mục trong lô — sẽ thử lại khi tiếp tục đồng bộ.',
                        count($chunkRefs),
                    ),
                    'synced' => $this->emptySyncedCounts(),
                    'imported' => 0,
                    'state' => $state,
                ];
            }

            $synced = $this->importItems($site, $items);

            return [
                'success' => true,
                'message' => sprintf('Đã xử lý %d mục trong lô.', count($items)),
                'synced' => $synced,
                'imported' => count($items),
                'state' => $state,
            ];
        } catch (Throwable $e) {
            Log::error('SeoContentAi incremental sync chunk failed', [
                'site_id' => $site->id,
                'chunk_size' => count($chunkRefs),
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'message' => 'Lỗi khi xử lý lô đồng bộ: '.$e->getMessage(),
            ];
        }
    }

    public function incrementalSyncChunkSize(): int
    {
        $size = (int) config('seo-content-ai.incremental_sync_chunk_size', 15);

        return max(5, min(50, $size));
    }

    /**
     * Lập kế hoạch đồng bộ lại metadata/thành phần bài đã có local (ngôn ngữ, Polylang, SEO meta, trạng thái…).
     *
     * @return array{
     *     success: bool,
     *     message: string,
     *     refs?: array<int, array<string, mixed>>,
     *     total?: int
     * }
     */
    public function prepareMetadataResync(Site $site): array
    {
        $validation = $this->validateWordPressSite($site);
        if ($validation !== null) {
            return $validation;
        }

        $readToken = trim((string) ($site->getMeta('seo_read_token') ?? ''));
        $manifestUrl = $this->buildSyncManifestUrl($site);

        try {
            $siteInfoResult = app(WordPressSiteInfoService::class)->fetchAndStore($site);
            if (! ($siteInfoResult['success'] ?? false)) {
                return [
                    'success' => false,
                    'message' => 'Không lấy được thông tin plugin SEO từ WordPress: '
                        .(string) ($siteInfoResult['message'] ?? 'Lỗi không xác định.'),
                ];
            }

            $manifestResponse = Http::timeout(120)
                ->acceptJson()
                ->withToken($readToken)
                ->get($manifestUrl);

            if ($manifestResponse->status() === 404) {
                return [
                    'success' => false,
                    'message' => 'Plugin WordPress chưa hỗ trợ đồng bộ metadata (cần TVH SEO AI Bridge ≥ 1.0.41).',
                ];
            }

            if (! $manifestResponse->successful()) {
                $message = (string) ($manifestResponse->json('message') ?? $manifestResponse->body());

                return [
                    'success' => false,
                    'message' => 'WordPress trả lỗi HTTP '.$manifestResponse->status().': '.mb_substr($message, 0, 300),
                ];
            }

            $manifestPayload = $manifestResponse->json();
            if (! is_array($manifestPayload) || ! ($manifestPayload['success'] ?? false)) {
                return [
                    'success' => false,
                    'message' => 'Phản hồi manifest WordPress không hợp lệ.',
                ];
            }

            $entries = is_array($manifestPayload['entries'] ?? null) ? $manifestPayload['entries'] : [];
            $manifestCounts = is_array($manifestPayload['counts'] ?? null) ? $manifestPayload['counts'] : [];
            $manifestTotals = is_array($manifestPayload['totals'] ?? null) ? $manifestPayload['totals'] : [];
            $this->persistManifestCounts($site, $manifestCounts, $manifestTotals);

            $localArticles = SeoArticle::query()
                ->leftJoin('wordpress_article_links as wal_meta', 'wal_meta.article_id', '=', 'articles.id')
                ->where('articles.site_id', $site->id)
                ->where('wal_meta.wp_post_id', '>', 0)
                ->get(['wal_meta.wp_post_id as wp_post_id', 'articles.type as type', 'articles.updated_at as updated_at']);

            $plan = $this->manifestComparator->resolveMetadataRefreshRefs($entries, $localArticles);
            $refs = $plan['refs'];
            $total = (int) $plan['total'];

            if ($refs === []) {
                return [
                    'success' => true,
                    'message' => 'Không có bài local nào khớp manifest WordPress để cập nhật thành phần.',
                    'refs' => [],
                    'total' => 0,
                ];
            }

            return [
                'success' => true,
                'message' => sprintf('Sẽ cập nhật thành phần cho %d bài/term đã có trên SEO.', $total),
                'refs' => $refs,
                'total' => $total,
            ];
        } catch (Throwable $e) {
            Log::error('SeoContentAi metadata resync prepare failed', [
                'site_id' => $site->id,
                'url' => $manifestUrl,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'message' => 'Không kết nối được WordPress: '.$e->getMessage(),
            ];
        }
    }

    /**
     * @param  array<string, int>  $base
     * @param  array<string, int>  $add
     * @return array<string, int>
     */
    public function mergeSyncedCounts(array $base, array $add): array
    {
        foreach ($add as $key => $count) {
            $base[$key] = (int) ($base[$key] ?? 0) + (int) $count;
        }

        return $base;
    }

    /**
     * @deprecated Dùng prepareIncrementalSync + IncrementalDomainSyncRunner qua queue job.
     *
     * @return array{
     *     success: bool,
     *     message: string,
     *     synced?: array<string, int>,
     *     skipped?: int,
     *     new_count?: int,
     *     update_count?: int
     * }
     */
    public function syncIncremental(Site $site): array
    {
        $prepared = $this->prepareIncrementalSync($site);
        if (! ($prepared['success'] ?? false)) {
            return $prepared;
        }

        $refs = is_array($prepared['refs'] ?? null) ? $prepared['refs'] : [];
        if ($refs === []) {
            return [
                'success' => true,
                'message' => (string) ($prepared['message'] ?? ''),
                'synced' => $this->emptySyncedCounts(),
                'skipped' => (int) ($prepared['skipped'] ?? 0),
                'new_count' => (int) ($prepared['new_count'] ?? 0),
                'update_count' => (int) ($prepared['update_count'] ?? 0),
            ];
        }

        $accumulated = $this->emptySyncedCounts();
        $state = [];

        foreach (array_chunk($refs, $this->incrementalSyncChunkSize()) as $chunkRefs) {
            $chunk = $this->processIncrementalChunk($site, $chunkRefs, $state);
            if (! ($chunk['success'] ?? false)) {
                return $chunk;
            }

            $state = is_array($chunk['state'] ?? null) ? $chunk['state'] : $state;
            $accumulated = $this->mergeSyncedCounts(
                $accumulated,
                is_array($chunk['synced'] ?? null) ? $chunk['synced'] : [],
            );
        }

        return [
            'success' => true,
            'message' => sprintf(
                'Đồng bộ bổ sung xong: %d mục mới, %d cập nhật, %d bỏ qua.',
                (int) ($prepared['new_count'] ?? 0),
                (int) ($prepared['update_count'] ?? 0),
                (int) ($prepared['skipped'] ?? 0),
            ),
            'synced' => $accumulated,
            'skipped' => (int) ($prepared['skipped'] ?? 0),
            'new_count' => (int) ($prepared['new_count'] ?? 0),
            'update_count' => (int) ($prepared['update_count'] ?? 0),
        ];
    }

    /**
     * Xóa toàn bộ nội dung local của domain rồi đồng bộ full từ WordPress.
     *
     * @return array{success:bool,message:string,synced?:array<string,int>,deleted?:int}
     */
    public function resetAndFullSync(Site $site): array
    {
        $validation = $this->validateWordPressSite($site);
        if ($validation !== null) {
            return $validation;
        }

        $clearResult = app(ClearDomainArticlesService::class)->clear($site);
        if (! ($clearResult['success'] ?? false)) {
            return [
                'success' => false,
                'message' => (string) ($clearResult['message'] ?? 'Không dọn dẹp được dữ liệu local.'),
            ];
        }

        $syncResult = $this->sync($site, ['is_test' => false, 'limit_per_type' => 0]);
        if (! ($syncResult['success'] ?? false)) {
            return $syncResult;
        }

        return [
            'success' => true,
            'message' => sprintf(
                'Đã xóa %d bản ghi local và tải lại từ WordPress. %s',
                (int) ($clearResult['deleted'] ?? 0),
                (string) ($syncResult['message'] ?? ''),
            ),
            'synced' => is_array($syncResult['synced'] ?? null) ? $syncResult['synced'] : [],
            'deleted' => (int) ($clearResult['deleted'] ?? 0),
        ];
    }

    /**
     * @return array{success:bool,message:string}|null
     */
    private function validateWordPressSite(Site $site): ?array
    {
        $site->loadMissing('metas');

        $platform = (string) ($site->getMeta('seo_platform') ?? '');
        if ($platform !== 'wordpress') {
            return [
                'success' => false,
                'message' => 'Site chưa cấu hình nền tảng WordPress.',
            ];
        }

        $readToken = trim((string) ($site->getMeta('seo_read_token') ?? ''));
        if ($readToken === '') {
            return [
                'success' => false,
                'message' => 'Thiếu SEO Read Token. Hãy lưu token trong trang chỉnh sửa domain.',
            ];
        }

        return null;
    }

    /**
     * @param  array<int, array<string, mixed>>  $refs
     * @return array<int, array<string, mixed>>|null
     */
    private function fetchItemsByRefs(Site $site, string $readToken, array $refs): ?array
    {
        $itemsUrl = $this->buildSyncItemsUrl($site);
        $items = [];

        foreach (array_chunk($refs, self::INCREMENTAL_FETCH_CHUNK) as $chunk) {
            $response = Http::timeout(120)
                ->acceptJson()
                ->withToken($readToken)
                ->post($itemsUrl, ['refs' => $chunk]);

            if ($response->status() === 404) {
                return null;
            }

            if (! $response->successful()) {
                Log::warning('SeoContentAi sync items chunk failed', [
                    'site_id' => $site->id,
                    'status' => $response->status(),
                    'body' => mb_substr((string) $response->body(), 0, 300),
                ]);

                return null;
            }

            $payload = $response->json();
            if (! is_array($payload) || ! ($payload['success'] ?? false)) {
                return null;
            }

            $batch = is_array($payload['items'] ?? null) ? $payload['items'] : [];
            foreach ($batch as $item) {
                if (is_array($item)) {
                    $items[] = $item;
                }
            }
        }

        return $items;
    }

    /**
     * @return array<int, array<string, mixed>>|null
     */
    private function fetchFullSyncItems(Site $site, string $readToken): ?array
    {
        $response = Http::timeout(300)
            ->acceptJson()
            ->withToken($readToken)
            ->get($this->buildSyncUrl($site), [
                'is_test' => 0,
                'limit_per_type' => 0,
            ]);

        if (! $response->successful()) {
            return null;
        }

        $payload = $response->json();
        if (! is_array($payload) || ! ($payload['success'] ?? false)) {
            return null;
        }

        $items = is_array($payload['items'] ?? null) ? $payload['items'] : [];

        return array_values(array_filter($items, static fn (mixed $item): bool => is_array($item)));
    }

    /**
     * @param  array<int, array<string, mixed>>  $items
     * @param  array<int, array<string, mixed>>  $refs
     * @return array<int, array<string, mixed>>
     */
    private function filterItemsByRefs(array $items, array $refs): array
    {
        $wanted = [];
        foreach ($refs as $ref) {
            $type = strtolower(trim((string) ($ref['type'] ?? '')));
            $wpId = (int) ($ref['wp_id'] ?? 0);
            if ($type !== '' && $wpId > 0) {
                $wanted[$type.'|'.$wpId] = true;
            }
        }

        if ($wanted === []) {
            return [];
        }

        return array_values(array_filter(
            $items,
            static function (mixed $item) use ($wanted): bool {
                if (! is_array($item)) {
                    return false;
                }

                $type = strtolower(trim((string) ($item['type'] ?? '')));
                $wpId = (int) ($item['wp_id'] ?? 0);

                return isset($wanted[$type.'|'.$wpId]);
            },
        ));
    }

    /**
     * @param  array<int, array<string, mixed>>  $refs
     * @return array<int, array<string, mixed>>
     */
    private function fetchItemsViaFullSyncFilter(Site $site, string $readToken, array $refs): array
    {
        $allItems = $this->fetchFullSyncItems($site, $readToken);

        return $allItems === null ? [] : $this->filterItemsByRefs($allItems, $refs);
    }

    /**
     * @return array<string, int>
     */
    private function emptySyncedCounts(): array
    {
        return [
            'article' => 0,
            'product' => 0,
            'category' => 0,
            'product_category' => 0,
            'other' => 0,
        ];
    }

    /**
     * Nhận payload đẩy từ plugin WordPress (hook save_post / term).
     *
     * @param  array<int, array<string, mixed>>  $items
     * @return array{success:bool,message:string,synced?:array<string,int>}
     */
    public function importPushedItems(Site $site, array $items): array
    {
        $platform = (string) ($site->getMeta('seo_platform') ?? '');
        if ($platform !== 'wordpress') {
            return [
                'success' => false,
                'message' => 'Site chưa cấu hình nền tảng WordPress.',
            ];
        }

        $synced = $this->importItems($site, $items);

        return $this->buildImportSuccessResponse($synced, false, []);
    }

    /**
     * @param  array<string, int>  $synced
     * @param  array<string, int>  $counts
     * @return array{success:bool,message:string,synced:array<string,int>,counts?:array<string,int>}
     */
    private function buildImportSuccessResponse(array $synced, bool $isTest, array $counts): array
    {
        $response = [
            'success' => true,
            'message' => sprintf(
                'Đồng bộ thành công %d mục từ WordPress%s.',
                array_sum($synced),
                $isTest ? ' (chế độ test)' : ''
            ),
            'synced' => $synced,
        ];

        if ($counts !== []) {
            $response['counts'] = $counts;
        }

        return $response;
    }

    /**
     * @param  array<int, array<string, mixed>>  $items
     * @return array<string, int>
     */
    public function importItems(Site $site, array $items): array
    {
        $synced = [
            'article' => 0,
            'product' => 0,
            'category' => 0,
            'product_category' => 0,
            'other' => 0,
            'trashed' => 0,
            'force_deleted' => 0,
            'restored' => 0,
        ];

        $userId = (int) $site->user_id;

        $syncFlags = app(ArticleWordPressSyncFlagService::class);

        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }

            $wpId = (int) ($item['wp_id'] ?? 0);
            if ($wpId <= 0) {
                continue;
            }

            $lifecycleAction = $this->resolveWordPressLifecycleAction($item);
            if ($lifecycleAction !== null) {
                try {
                    $this->applyWordPressLifecycleAction($site, $wpId, $lifecycleAction, $synced);
                } catch (Throwable $e) {
                    Log::warning('SeoContentAi sync lifecycle failed', [
                        'site_id' => $site->id,
                        'wp_id' => $wpId,
                        'action' => $lifecycleAction,
                        'error' => $e->getMessage(),
                    ]);
                }

                continue;
            }

            try {
                $this->importSingleSyncItem(
                    site: $site,
                    item: $item,
                    wpId: $wpId,
                    synced: $synced,
                    userId: $userId,
                    syncFlags: $syncFlags,
                    forceOverwrite: false,
                );
            } catch (Throwable $e) {
                Log::warning('SeoContentAi sync item failed', [
                    'site_id' => $site->id,
                    'wp_id' => $wpId,
                    'type' => (string) ($item['type'] ?? 'article'),
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $synced;
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private function resolveWordPressLifecycleAction(array $item): ?string
    {
        $action = strtolower(trim((string) ($item['action'] ?? '')));
        if (in_array($action, ['trash', 'force_delete', 'restore'], true)) {
            return $action;
        }

        $status = strtolower(trim((string) ($item['status'] ?? '')));
        if ($status === 'trash') {
            return 'trash';
        }

        return null;
    }

    /**
     * @param  array<string, int>  $synced
     */
    private function applyWordPressLifecycleAction(
        Site $site,
        int $wpId,
        string $action,
        array &$synced,
    ): void {
        $articles = SeoArticle::withTrashed()
            ->where('site_id', $site->id)
            ->whereWpPostId($wpId)
            ->get();

        if ($articles->isEmpty()) {
            return;
        }

        foreach ($articles as $article) {
            match ($action) {
                'trash' => $this->trashSyncedArticle($article, $synced),
                'force_delete' => $this->forceDeleteSyncedArticle($article, $synced),
                'restore' => $this->restoreSyncedArticle($article, $synced),
                default => null,
            };
        }
    }

    /**
     * @param  array<string, int>  $synced
     */
    private function trashSyncedArticle(SeoArticle $article, array &$synced): void
    {
        if ($article->trashed()) {
            return;
        }

        $article->delete();
        $synced['trashed']++;
    }

    /**
     * @param  array<string, int>  $synced
     */
    private function forceDeleteSyncedArticle(SeoArticle $article, array &$synced): void
    {
        $article->forceDelete();
        $synced['force_deleted']++;
    }

    /**
     * @param  array<string, int>  $synced
     */
    private function restoreSyncedArticle(SeoArticle $article, array &$synced): void
    {
        if (! $article->trashed()) {
            return;
        }

        $article->restore();
        $synced['restored']++;
    }

    /**
     * @param  array<string, mixed>  $item
     * @param  array<string, int>  $synced
     */
    private function importSingleSyncItem(
        Site $site,
        array $item,
        int $wpId,
        array &$synced,
        int $userId,
        ArticleWordPressSyncFlagService $syncFlags,
        bool $forceOverwrite = false,
    ): void {
        $type = $this->normalizeType((string) ($item['type'] ?? 'article'));
        $publishedAt = $this->parsePublishedAt($item['published_at'] ?? null);

        $existing = SeoArticle::query()
            ->where('site_id', $site->id)
            ->whereWpPostId($wpId)
            ->where('type', $type)
            ->first();

        if (
            ! $forceOverwrite
            && $existing instanceof SeoArticle
            && $syncFlags->shouldBlockWordPressImport($existing)
        ) {
            $syncFlags->markDataOutOfSync($existing);

            if (array_key_exists('conflict', $synced)) {
                $synced['conflict']++;
            } else {
                $synced['conflict'] = 1;
            }

            return;
        }

        $title = $this->resolveSyncItemTitle($item, $syncFlags);
        $hasLocalBody = ! $forceOverwrite
            && $existing instanceof SeoArticle
            && $syncFlags->hasLocalEditorContent($existing);

        // Bài chưa có nội dung editor trên SEO (hoặc forceOverwrite): ghi đè scoring (xóa slug/body).
        // Bài đã có body: chỉ cập nhật tiêu đề/trạng thái.
        $articleAttributes = [
            'type' => $type,
            'title' => $title !== '' ? $title : 'Untitled',
            'status' => $this->normalizeStatus((string) ($item['status'] ?? 'draft')),
        ];

        if (! $hasLocalBody) {
            $articleAttributes['slug'] = null;
            $articleAttributes['excerpt'] = null;
            $articleAttributes['body'] = null;
            $articleAttributes['blocks'] = null;
        }

        $article = SeoArticle::query()->updateOrCreate(
            [
                'site_id' => $site->id,
                'wp_post_id' => $wpId,
                'type' => $type,
            ],
            $articleAttributes,
        );

        // Extension attrs via Eloquent: RoutesArticleExtensionAttributes → writers.
        $article->forceFill([
            'wp_post_id' => $wpId,
            'published_at' => $publishedAt,
        ])->save();

        if ($title !== '') {
            $article->articleMetas()->updateOrCreate(
                ['meta_key' => 'wp_post_title'],
                ['meta_value' => $title],
            );
        }

        $wpPostType = (string) ($item['wp_post_type'] ?? '');
        // articles.type already set via updateOrCreate; wp_post_type meta retired.
        if ($wpPostType !== '') {
            $article->articleMetas()->where('meta_key', 'wp_post_type')->delete();
        }

        $wpEntity = trim((string) ($item['wp_entity'] ?? ''));
        if ($wpEntity !== '') {
            $article->articleMetas()->updateOrCreate(
                ['meta_key' => 'wp_entity'],
                ['meta_value' => $wpEntity],
            );
        }

        if ($wpEntity === 'term' && $wpPostType !== '') {
            $article->articleMetas()->updateOrCreate(
                ['meta_key' => 'wp_taxonomy'],
                ['meta_value' => $wpPostType],
            );
        }

        if ($forceOverwrite) {
            $scoring = is_array($item['scoring'] ?? null) ? $item['scoring'] : [];
            $preparedHtml = app(ArticlePostImagesService::class)->prepareEditorHtmlFromWordPressSources(
                $article,
                trim((string) ($item['post_content'] ?? '')),
                trim((string) ($scoring['body'] ?? '')),
                is_array($item['post_images'] ?? null) ? $item['post_images'] : [],
            );
            if ($preparedHtml !== '') {
                $item['post_content'] = $preparedHtml;
            }
        }

        $this->syncWordPressPostMeta($article, $item, $forceOverwrite);
        $this->syncSchemaAndWooCommerceMeta($article, $item);
        app(ArticlePostImagesService::class)->importFromSyncItem($article, $item);

        $postImages = $item['post_images'] ?? null;
        if ($forceOverwrite && (! is_array($postImages) || $postImages === [])) {
            app(ArticlePostImagesService::class)->persistForArticle($article, []);
        }

        if (! $hasLocalBody) {
            app(ArticleFaqWordPressImportService::class)->importFromWordPressSyncItem($article, $item);
        }

        if ($forceOverwrite) {
            // FAQ import có thể ghi body; giữ body null — editor đọc wp_post_content đã chuẩn hóa.
            $preparedMeta = trim((string) (
                $article->articleMetas()->where('meta_key', 'wp_post_content')->value('meta_value') ?? ''
            ));
            if ($preparedMeta === '' && filled($item['post_content'] ?? null)) {
                $preparedMeta = trim((string) $item['post_content']);
                $article->articleMetas()->updateOrCreate(
                    ['meta_key' => 'wp_post_content'],
                    ['meta_value' => $preparedMeta],
                );
            }

            $article->update([
                'body' => null,
                'blocks' => null,
                'excerpt' => null,
                'slug' => null,
            ]);
        }

        $this->syncSeoMetaFromWordPress($article, $item);
        $this->syncFocusKeyword($site, $userId, $article, $item);
        $this->scoreSyncedItemWithPhp($article, $item);
        app(WordPressArticleSyncService::class)->applyMultilingualFromSyncPayload($article, $site, $item);

        $syncFlags->clearAll($article);
        app(ArticleLastSavedTimestampService::class)->touchSynced($article);

        if (array_key_exists($type, $synced)) {
            $synced[$type]++;
        } else {
            $synced['other']++;
        }

        try {
            app(\Omnichannel\Addons\Agent\Automation\BusinessHook\Support\BusinessHookEmitter::class)
                ->articleContentUpdated($article);
        } catch (Throwable $e) {
            Log::warning('business_hook.emit_failed after domain sync', [
                'article_id' => $article->id,
                'error' => $e->getMessage(),
            ]);
        }

        $this->timestampService->sync($article, $item);
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private function scoreSyncedItemWithPhp(SeoArticle $article, array $item): void
    {
        if (! $article->countsTowardSeoScore()) {
            return;
        }

        try {
            $fresh = $article->fresh(['articleMetas', 'faqs']) ?? $article;
            app(SeoAnalyzerService::class)->analyzeFromSyncItem($fresh, $item);
            app(SeoArticleScoringQueueService::class)->markCompleted($fresh->fresh(['articleMetas', 'faqs']) ?? $fresh);
        } catch (Throwable $e) {
            Log::warning('SeoContentAi sync item PHP scoring failed', [
                'article_id' => (int) $article->id,
                'site_id' => (int) ($article->site_id ?? 0),
                'wp_id' => (int) ($article->wordpressLink?->wp_post_id ?? 0),
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private function syncWordPressPostMeta(SeoArticle $article, array $item, bool $forceOverwrite = false): void
    {
        $type = $this->normalizeType((string) ($item['type'] ?? 'article'));
        $isTaxonomy = in_array($type, ['category', 'product_category'], true);
        $content = $this->resolveSyncItemContent($item);

        if ($forceOverwrite || $isTaxonomy || $content !== '') {
            $article->articleMetas()->updateOrCreate(
                ['meta_key' => 'wp_post_content'],
                ['meta_value' => $content],
            );

            app(ArticleFaqWordPressRestoreService::class)->persistWordPressSourceSnapshot($article, $content);
            if ($content !== '') {
                $this->extractTocAfterWordPressContentSync($article);
            }
        }

        $slug = trim((string) ($item['slug'] ?? ''));
        $normalizedSlug = RankMathSeoValueNormalizer::normalizeSlug($slug);
        if ($normalizedSlug !== null && $normalizedSlug !== '') {
            if (trim((string) ($article->slug ?? '')) === '' || $forceOverwrite) {
                $article->update(['slug' => $normalizedSlug]);
            }
            $article->articleMetas()->where('meta_key', 'wp_slug')->delete();
        } elseif ($slug !== '' && RankMathSeoValueNormalizer::containsRankMathVariable($slug)) {
            $article->articleMetas()->where('meta_key', 'wp_slug')->delete();
        }

        $permalink = trim((string) ($item['permalink'] ?? ''));
        if ($permalink !== '') {
            $article->loadMissing('site');
            if ($article->site instanceof Site) {
                $permalink = app(WordPressPermalinkBuilder::class)->resolveFromSyncItem($article->site, $item);
            }
            $article->articleMetas()->updateOrCreate(
                ['meta_key' => 'wp_permalink'],
                ['meta_value' => $permalink],
            );
        }

        if ($isTaxonomy) {
            // Preserve parent=0 as explicit meta. Never delete on zero — Site MCP
            // fail-closed treats missing wp_parent_id as incomplete (not root).
            $this->persistTaxonomyParentMeta($article, $item, $forceOverwrite);

            if (array_key_exists('post_count', $item) || array_key_exists('count', $item)) {
                $postCount = (int) ($item['post_count'] ?? $item['count'] ?? 0);
                $article->articleMetas()->updateOrCreate(
                    ['meta_key' => 'wp_term_count'],
                    ['meta_value' => (string) $postCount],
                );
            }

            $article->articleMetas()->whereIn('meta_key', [
                'wp_featured_image_url',
                'wp_product_gallery',
            ])->delete();
            $article->unsetRelation('articleMetas');
            app(\Omnichannel\Addons\Media\Services\ArticleFeaturedImageProjection::class)->rebuildAndPersist($article);

            return;
        }

        $featuredImageUrl = trim((string) ($item['featured_image_url'] ?? ''));
        if ($featuredImageUrl !== '') {
            $article->articleMetas()->updateOrCreate(
                ['meta_key' => 'wp_featured_image_url'],
                ['meta_value' => $featuredImageUrl],
            );
        } elseif ($forceOverwrite) {
            $article->articleMetas()->where('meta_key', 'wp_featured_image_url')->delete();
        }

        $gallery = $item['product_gallery'] ?? null;
        if (is_array($gallery) && $gallery !== []) {
            $article->articleMetas()->updateOrCreate(
                ['meta_key' => 'wp_product_gallery'],
                ['meta_value' => json_encode($gallery, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)],
            );
        } elseif ($forceOverwrite) {
            $article->articleMetas()->where('meta_key', 'wp_product_gallery')->delete();
        }

        $article->unsetRelation('articleMetas');
        app(\Omnichannel\Addons\Media\Services\ArticleFeaturedImageProjection::class)->rebuildAndPersist($article);
    }

    /**
     * Persist taxonomy parent identity. Integer 0 must survive as meta value "0".
     * Missing parent keys leave incomplete identity (Site MCP fail-closed excludes them).
     *
     * @param  array<string, mixed>  $item
     */
    private function persistTaxonomyParentMeta(SeoArticle $article, array $item, bool $forceOverwrite): void
    {
        $hasParent = array_key_exists('parent_term_id', $item) || array_key_exists('parent_id', $item);
        if (! $hasParent) {
            if ($forceOverwrite) {
                $article->articleMetas()->where('meta_key', 'wp_parent_id')->delete();
            }

            return;
        }

        $raw = array_key_exists('parent_term_id', $item)
            ? $item['parent_term_id']
            : $item['parent_id'];

        if ($raw === null || $raw === '') {
            $article->articleMetas()->where('meta_key', 'wp_parent_id')->delete();

            return;
        }

        $parentId = (int) $raw;
        $article->articleMetas()->updateOrCreate(
            ['meta_key' => 'wp_parent_id'],
            ['meta_value' => (string) $parentId],
        );
    }

    /**
     * @param  array<string, mixed>  $item
     * @return list<int>
     */
    private function extractCategoryIdsFromSyncItem(array $item): array
    {
        $raw = $item['category_ids'] ?? [];
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

    /**
     * @param  array<string, mixed>  $item
     * @param  list<int>  $categoryIds
     */
    private function syncCategoryIdsFromSyncItem(SeoArticle $article, array $item, array $categoryIds): void
    {
        $type = $this->normalizeType((string) ($item['type'] ?? 'article'));
        $isTaxonomy = in_array($type, ['category', 'product_category'], true);

        if ($categoryIds === []) {
            $article->articleMetas()->whereIn('meta_key', ['wp_category_ids', 'category_ids'])->delete();

            return;
        }

        $encoded = json_encode($categoryIds, JSON_THROW_ON_ERROR);
        $article->articleMetas()->updateOrCreate(
            ['meta_key' => 'wp_category_ids'],
            ['meta_value' => $encoded],
        );

        if (! $isTaxonomy) {
            $article->articleMetas()->updateOrCreate(
                ['meta_key' => 'category_ids'],
                ['meta_value' => $encoded],
            );
        }
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private function countInlineImagesFromSyncItem(array $item): int
    {
        $images = $item['post_images'] ?? null;
        if (! is_array($images)) {
            return 0;
        }

        return count(app(ArticlePostImagesService::class)->normalizeList($images));
    }

    /**
     * @param  array<string, mixed>  $audit
     */
    private function persistPullSyncAudit(SeoArticle $article, array $audit): void
    {
        $article->articleMetas()->updateOrCreate(
            ['meta_key' => self::META_PULL_SYNC_AUDIT],
            [
                'meta_value' => json_encode(
                    $audit,
                    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
                ),
            ],
        );
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private function resolveSyncItemTitle(array $item, ArticleWordPressSyncFlagService $syncFlags): string
    {
        $raw = (string) ($item['title'] ?? $item['post_title'] ?? '');

        return $syncFlags->decodeWordPressText($raw);
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private function resolveSyncItemContent(array $item): string
    {
        $content = trim((string) ($item['post_content'] ?? ''));
        if ($content !== '') {
            return $content;
        }

        $scoring = is_array($item['scoring'] ?? null) ? $item['scoring'] : [];

        return trim((string) ($scoring['body'] ?? ''));
    }

    /**
     * @param  array<string, mixed>  $item
     */
    /**
     * @param  array<string, mixed>  $item
     */
    private function syncSchemaAndWooCommerceMeta(SeoArticle $article, array $item): void
    {
        $woocommerce = is_array($item['woocommerce'] ?? null) ? $item['woocommerce'] : [];
        if ($woocommerce === []) {
            return;
        }

        $currency = strtoupper(trim((string) ($woocommerce['currency'] ?? 'VND')));

        $map = [
            '_price' => (string) ($woocommerce['price'] ?? ''),
            'regular_price' => (string) ($woocommerce['regular_price'] ?? ''),
            '_regular_price' => (string) ($woocommerce['regular_price'] ?? ''),
            'sale_price' => (string) ($woocommerce['sale_price'] ?? ''),
            '_sale_price' => (string) ($woocommerce['sale_price'] ?? ''),
            'min_price' => (string) ($woocommerce['min_price'] ?? ''),
            'max_price' => (string) ($woocommerce['max_price'] ?? ''),
            'price_currency' => $currency !== '' ? $currency : 'VND',
        ];

        foreach ($map as $metaKey => $metaValue) {
            $metaValue = trim($metaValue);
            if ($metaValue === '') {
                continue;
            }

            $article->articleMetas()->updateOrCreate(
                ['meta_key' => $metaKey],
                ['meta_value' => $metaValue],
            );
        }
    }

    private function extractTocAfterWordPressContentSync(SeoArticle $article): void
    {
        try {
            $this->tocExtraction->extractForArticle($article);
        } catch (Throwable $e) {
            Log::warning('TOC extraction failed after WordPress content sync', [
                'article_id' => $article->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function syncSeoMetaFromWordPress(SeoArticle $article, array $item): void
    {
        $article->articleMetas()->where('meta_key', 'seo_plugin')->delete();

        $seo = is_array($item['seo'] ?? null) ? $item['seo'] : [];

        $seoTitle = RankMathSeoValueNormalizer::normalizeTitle(
            (string) ($seo['seo_title'] ?? ''),
        );

        $metaMap = [
            'seo_meta_description' => (string) ($seo['meta_description'] ?? ''),
            'seo_focus_keyword' => Keyword::preparePhraseForStorage((string) ($seo['focus_keyword'] ?? '')),
        ];

        // seo_title meta retired — articles.title is SoT (Rank Math title may equal post title).
        if ($seoTitle !== null && $seoTitle !== '' && trim((string) ($article->title ?? '')) === '') {
            $article->update(['title' => $seoTitle]);
        }

        $article->articleMetas()->where('meta_key', 'seo_title')->delete();

        foreach ($metaMap as $metaKey => $metaValue) {
            $metaValue = trim($metaValue);
            if ($metaValue === '') {
                if ($metaKey === 'seo_focus_keyword') {
                    $article->articleMetas()->where('meta_key', $metaKey)->delete();
                }

                continue;
            }

            $article->articleMetas()->updateOrCreate(
                ['meta_key' => $metaKey],
                ['meta_value' => $metaValue]
            );
        }
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private function syncFocusKeyword(Site $site, int $userId, SeoArticle $article, array $item): void
    {
        $seo = is_array($item['seo'] ?? null) ? $item['seo'] : [];
        $scoring = is_array($item['scoring'] ?? null) ? $item['scoring'] : [];

        $phrase = Keyword::preparePhraseForStorage(
            (string) ($seo['focus_keyword'] ?? $scoring['focus_keyword'] ?? ''),
        );
        if ($phrase === '') {
            return;
        }

        KeywordFocusAttach::syncMainKeyword($article, $site->id, $userId, $phrase);
    }

    /**
     * @param  array<string, int>  $counts
     * @param  array<string, int>  $totals
     */
    private function persistManifestCounts(Site $site, array $counts, array $totals = []): void
    {
        $payload = [
            'counts' => $counts,
            'totals' => $totals,
            'fetched_at' => now()->toIso8601String(),
        ];

        $site->metas()->updateOrCreate(
            ['meta_key' => 'seo_wp_manifest_counts'],
            ['meta_value' => json_encode($payload, JSON_UNESCAPED_UNICODE)],
        );
    }

    /**
     * @param  array<string, int>  $manifestCounts
     */
    private function buildManifestGapMessage(array $manifestCounts, int $localArticleCount): string
    {
        $wpPosts = (int) ($manifestCounts['article'] ?? 0);
        $wpPages = (int) ($manifestCounts['page'] ?? 0);
        $wpArticleTotal = $wpPosts + $wpPages;

        if ($wpArticleTotal <= 0 || $localArticleCount >= $wpArticleTotal) {
            return '';
        }

        $missing = $wpArticleTotal - $localArticleCount;

        return sprintf(
            'WordPress có %d bài (post %d + page %d) nhưng local chỉ có %d — thiếu %d bài. Hãy cập nhật plugin WP hoặc dùng «Làm sạch & Đồng bộ lại» nếu vẫn lệch.',
            $wpArticleTotal,
            $wpPosts,
            $wpPages,
            $localArticleCount,
            $missing,
        );
    }

    private function buildSyncUrl(Site $site): string
    {
        return $this->buildSiteBaseUrl($site).'/wp-json/omi-seo-ai/v1/sync';
    }

    private function buildSyncManifestUrl(Site $site): string
    {
        return $this->buildSiteBaseUrl($site).'/wp-json/omi-seo-ai/v1/sync/manifest';
    }

    private function buildSyncItemsUrl(Site $site): string
    {
        return $this->buildSiteBaseUrl($site).'/wp-json/omi-seo-ai/v1/sync/items';
    }

    private function buildSiteBaseUrl(Site $site): string
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

    private function normalizeType(string $type): string
    {
        $type = strtolower(trim($type));

        return match ($type) {
            'article', 'post', 'page' => 'article',
            'product' => 'product',
            'category' => 'category',
            'product_category', 'product_cat' => 'product_category',
            default => $type !== '' ? $type : 'article',
        };
    }

    private function normalizeStatus(string $status): string
    {
        $status = strtolower(trim($status));

        return match ($status) {
            'publish', 'published' => 'published',
            'future' => 'scheduled',
            'private' => 'private',
            default => 'draft',
        };
    }

    private function parsePublishedAt(mixed $value): ?Carbon
    {
        if ($value === null || $value === '') {
            return null;
        }

        try {
            return Carbon::parse((string) $value);
        } catch (Throwable) {
            return null;
        }
    }
}
