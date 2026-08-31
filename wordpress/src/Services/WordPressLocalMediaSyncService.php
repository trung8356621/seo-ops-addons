<?php

declare(strict_types=1);

namespace Omnichannel\Addons\WordPress\Services;

use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\Media\Models\SeoImageOptimizationSetting;
use Omnichannel\Addons\Media\Models\SeoMedia;
use Omnichannel\Addons\Media\Services\SeoImageOptimizationService;
use Omnichannel\Addons\Media\Services\SeoMediaStorageService;
use Omnichannel\Addons\WordPress\Services\WordPressSlugFixRequiredException;
use Omnichannel\Addons\WordPress\Services\WordPressWriteReadinessGuard;
use App\Models\Site;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

final class WordPressLocalMediaSyncService
{
    /** @var array<int, array{success: bool, attachment_id: int, wp_url: string, seo_media_id: int|null, message: string}> */
    private array $cache = [];

    /**
     * @return array{html: string, synced_media_ids: list<int>, errors: list<string>}
     */
    public function syncHtml(SeoArticle $article, string $html): array
    {
        $html = trim($html);
        if ($html === '') {
            return [
                'html' => '',
                'synced_media_ids' => [],
                'errors' => [],
            ];
        }

        $syncedMediaIds = [];
        $syncedThisPass = [];
        $errors = [];
        $updatedHtml = $html;

        foreach ($this->extractLocalSeoMediaImageRefs($html) as $ref) {
            try {
                $originalUrl = trim((string) ($ref['url'] ?? ''));
                $refMediaId = (int) ($ref['seo_media_id'] ?? 0);

                $media = null;
                if ($refMediaId > 0) {
                    $media = SeoMedia::query()->whereKey($refMediaId)->first();
                }

                if (! $media instanceof SeoMedia && $originalUrl !== '') {
                    $path = $this->urlToSeoMediaPath($originalUrl);
                    if ($path !== '') {
                        $media = SeoMedia::query()->where('path', $path)->orderByDesc('id')->first();
                    }
                }

                if (! $media instanceof SeoMedia) {
                    if ($originalUrl !== '') {
                        $errors[] = "Không tìm thấy seo_media cho URL {$originalUrl}.";
                    }

                    continue;
                }

                $mediaId = (int) $media->id;
                if ($mediaId <= 0 || isset($syncedThisPass[$mediaId])) {
                    continue;
                }

                $result = $this->syncMedia($article, $media);
                $syncedThisPass[$mediaId] = true;
                if (! $result['success']) {
                    $errors[] = $result['message'];

                    continue;
                }

                if ($result['seo_media_id'] !== null) {
                    $syncedMediaIds[] = $result['seo_media_id'];
                }

                $wpUrl = trim($result['wp_url']);
                if ($wpUrl === '') {
                    $errors[] = "Ảnh #{$media->id}: không lấy được URL WordPress để thay src.";

                    continue;
                }

                if ($originalUrl !== '') {
                    $updatedHtml = str_replace($originalUrl, $wpUrl, $updatedHtml);
                }
            } catch (Throwable $exception) {
                Log::warning('WordPress syncHtml URL replace failed', [
                    'article_id' => $article->id,
                    'url' => $ref['url'] ?? '',
                    'error' => $exception->getMessage(),
                ]);
                $errors[] = 'Lỗi sync URL '.($ref['url'] ?? '').': '.$exception->getMessage();
            }
        }

        $byId = $this->applyWpUrlsToSeoMediaImages($article, $updatedHtml, $syncedThisPass);
        $updatedHtml = $byId['html'];
        $syncedMediaIds = array_merge($syncedMediaIds, $byId['synced_media_ids']);
        $errors = array_merge($errors, $byId['errors']);

        $backfill = $this->syncWebpBackfillMediaForArticle($article, $syncedMediaIds);
        $updatedHtml = $this->applyUrlReplacements($updatedHtml, $backfill['url_map']);
        $syncedMediaIds = array_merge($syncedMediaIds, $backfill['synced_media_ids']);
        $errors = array_merge($errors, $backfill['errors']);

        return [
            'html' => $updatedHtml,
            'synced_media_ids' => array_values(array_unique($syncedMediaIds)),
            'errors' => array_values(array_unique(array_filter($errors))),
        ];
    }

    /**
     * @return array{success: bool, attachment_id: int, wp_url: string, seo_media_id: int|null, message: string}
     */
    public function syncAttachmentRef(SeoArticle $article, int $refId): array
    {
        if ($refId <= 0) {
            return [
                'success' => false,
                'attachment_id' => 0,
                'wp_url' => '',
                'seo_media_id' => null,
                'message' => 'ID ảnh không hợp lệ.',
            ];
        }

        $media = SeoMedia::query()->whereKey($refId)->first();
        if (! $media instanceof SeoMedia) {
            return [
                'success' => true,
                'attachment_id' => $refId,
                'wp_url' => '',
                'seo_media_id' => null,
                'message' => '',
            ];
        }

        return $this->syncMedia($article, $media);
    }

    /**
     * @param  list<int>  $mediaIds
     */
    public function cleanupSyncedLocalMedia(array $mediaIds): int
    {
        $deleted = 0;

        foreach (array_values(array_unique(array_map(static fn ($id): int => (int) $id, $mediaIds))) as $mediaId) {
            if ($mediaId <= 0) {
                continue;
            }

            $media = SeoMedia::query()->whereKey($mediaId)->first();
            if (! $media instanceof SeoMedia) {
                continue;
            }

            $path = ltrim(str_replace('\\', '/', (string) ($media->path ?? '')), '/');
            $isUploadedFile = str_starts_with($path, 'uploads/seo_media/');
            if ($isUploadedFile && Storage::disk('public')->exists($path)) {
                Storage::disk('public')->delete($path);
            }

            $media->delete();
            $deleted++;
        }

        return $deleted;
    }

    /**
     * @deprecated Ảnh local chỉ xóa khi duyệt bài (ArticleReviewService approve path). Không gọi sau đồng bộ WP.
     *
     * @param  list<int>  $mediaIds
     */
    public function markSyncedLocalMediaAsTrash(array $mediaIds): int
    {
        $ids = array_values(array_unique(array_filter(array_map(
            static fn ($id): int => (int) $id,
            $mediaIds,
        ), static fn (int $id): bool => $id > 0)));

        if ($ids === []) {
            return 0;
        }

        return SeoMedia::query()
            ->whereIn('id', $ids)
            ->where(function ($q): void {
                $q->whereNull('status')
                    ->orWhere('status', 'completed')
                    ->orWhere('status', 'processing')
                    ->orWhere('status', 'failed');
            })
            ->update([
                'status' => 'trash',
                'error_message' => null,
                'wp_synced_at' => now(),
            ]);
    }

    /**
     * Đồng bộ lại các ảnh local đã chỉnh sửa (có wp_attachment_id) lên WordPress.
     *
     * @return array{synced: int, errors: list<string>}
     */
    public function syncDirtyLocalMediaForArticle(SeoArticle $article): array
    {
        $articleId = (int) ($article->id ?? 0);
        if ($articleId <= 0) {
            return ['synced' => 0, 'errors' => []];
        }

        $errors = [];
        $synced = 0;

        $rows = SeoMedia::query()
            ->where('article_id', $articleId)
            ->whereNotNull('wp_attachment_id')
            ->where('wp_attachment_id', '>', 0)
            ->where(function ($query): void {
                $query->whereNull('status')
                    ->orWhere('status', 'completed');
            })
            ->where(function ($query): void {
                $query->whereNull('wp_synced_at')
                    ->orWhereColumnAfterMeta('updated_at', '>', 'wp_synced_at');
            })
            ->orderBy('id')
            ->get();

        foreach ($rows as $media) {
            try {
                $result = $this->syncMedia($article, $media);
                if (! ($result['success'] ?? false)) {
                    $errors[] = (string) ($result['message'] ?? ("Ảnh #{$media->id}: đồng bộ thất bại."));

                    continue;
                }

                $synced++;
            } catch (Throwable $exception) {
                $errors[] = "Ảnh #{$media->id}: {$exception->getMessage()}";
            }
        }

        return [
            'synced' => $synced,
            'errors' => array_values(array_unique(array_filter($errors))),
        ];
    }

    /**
     * Ép chuyển WebP các ảnh đã có wp_attachment_id nhưng URL WordPress vẫn PNG/JPG.
     *
     * @param  list<int>  $skipMediaIds
     * @return array{synced_media_ids: list<int>, url_map: array<string, string>, errors: list<string>}
     */
    public function syncWebpBackfillMediaForArticle(SeoArticle $article, array $skipMediaIds = []): array
    {
        $articleId = (int) ($article->id ?? 0);
        if ($articleId <= 0) {
            return [
                'synced_media_ids' => [],
                'url_map' => [],
                'errors' => [],
            ];
        }

        $article->loadMissing('site', 'articleMetas');
        $site = $article->site;
        if (! $site instanceof Site) {
            return [
                'synced_media_ids' => [],
                'url_map' => [],
                'errors' => ['Không tìm thấy site của bài viết để backfill WebP.'],
            ];
        }

        $optimization = app(SeoImageOptimizationService::class);
        $config = $optimization->resolveForSite((int) $site->id);
        if (! (bool) $config->auto_convert_webp) {
            return [
                'synced_media_ids' => [],
                'url_map' => [],
                'errors' => [],
            ];
        }

        $skip = array_fill_keys(array_values(array_unique(array_filter(array_map(
            static fn ($id): int => (int) $id,
            $skipMediaIds,
        ), static fn (int $id): bool => $id > 0))), true);

        $errors = [];
        $syncedMediaIds = [];
        $urlMap = [];

        $html = trim((string) ($article->body ?? ''));
        $mediaIdsFromHtml = $this->extractSeoMediaIdsFromHtml($html);

        $rows = SeoMedia::query()
            ->where('site_id', (int) $site->id)
            ->where(function ($query) use ($articleId, $mediaIdsFromHtml): void {
                $query->where('article_id', $articleId);
                if ($mediaIdsFromHtml !== []) {
                    $query->orWhereIn('id', $mediaIdsFromHtml);
                }
            })
            ->whereNotNull('wp_attachment_id')
            ->where('wp_attachment_id', '>', 0)
            ->where(function ($query): void {
                $query->whereNull('status')
                    ->orWhere('status', 'completed');
            })
            ->orderBy('id')
            ->get();

        foreach ($rows as $media) {
            $mediaId = (int) $media->id;
            if ($mediaId <= 0 || isset($skip[$mediaId])) {
                continue;
            }

            $path = ltrim(str_replace('\\', '/', (string) ($media->path ?? '')), '/');
            if ($path === '' || ! Storage::disk('public')->exists($path)) {
                continue;
            }

            $absolutePath = Storage::disk('public')->path($path);
            $attachmentId = (int) ($media->wp_attachment_id ?? 0);
            $oldUrl = $attachmentId > 0
                ? $this->fetchWordPressAttachmentUrl($site, $attachmentId)
                : '';

            if (! $optimization->needsWordPressWebpBackfill($config, $absolutePath, $oldUrl !== '' ? $oldUrl : null)) {
                continue;
            }

            $this->forgetMediaCache($mediaId);

            try {
                $result = $this->syncMedia($article, $media);
                if (! ($result['success'] ?? false)) {
                    $errors[] = (string) ($result['message'] ?? "Ảnh #{$mediaId}: backfill WebP thất bại.");

                    continue;
                }

                if ($result['seo_media_id'] !== null) {
                    $syncedMediaIds[] = (int) $result['seo_media_id'];
                }

                $newUrl = trim((string) ($result['wp_url'] ?? ''));
                if ($oldUrl !== '' && $newUrl !== '' && $oldUrl !== $newUrl) {
                    $urlMap[$oldUrl] = $newUrl;
                } elseif (
                    $newUrl !== ''
                    && ! $optimization->isWebpUrl($newUrl)
                    && (bool) $config->auto_convert_webp
                ) {
                    $errors[] = "Ảnh #{$mediaId}: WordPress vẫn trả URL không phải WebP ({$newUrl}) — kiểm tra plugin ≥ 1.0.50 và Imagick/GD hỗ trợ WebP.";
                }
            } catch (Throwable $exception) {
                $errors[] = "Ảnh #{$mediaId}: {$exception->getMessage()}";
            }
        }

        return [
            'synced_media_ids' => array_values(array_unique($syncedMediaIds)),
            'url_map' => $urlMap,
            'errors' => array_values(array_unique(array_filter($errors))),
        ];
    }

    public function forgetMediaCache(int $mediaId): void
    {
        if ($mediaId > 0) {
            unset($this->cache[$mediaId]);
        }
    }

    /**
     * @return array{success: bool, attachment_id: int, wp_url: string, seo_media_id: int|null, message: string}
     */
    private function syncMedia(SeoArticle $article, SeoMedia $media): array
    {
        try {
            app(WordPressWriteReadinessGuard::class)->assertCanWriteToWordPress($article, 'media.sync');
        } catch (WordPressSlugFixRequiredException) {
            return [
                'success' => false,
                'attachment_id' => 0,
                'wp_url' => '',
                'seo_media_id' => (int) ($media->id ?? 0) ?: null,
                'message' => WordPressSlugFixRequiredException::MESSAGE,
            ];
        }

        $article->loadMissing('site');
        $site = $article->site;
        $media = $this->hydrateMediaUsageForArticle($media, $article);
        $mediaId = (int) $media->id;

        if (! $site instanceof Site) {
            return $this->rememberMediaSyncResult($mediaId, null, [
                'success' => false,
                'attachment_id' => 0,
                'wp_url' => '',
                'seo_media_id' => $mediaId,
                'message' => "Ảnh #{$mediaId}: thiếu thông tin site.",
            ]);
        }

        $optimization = app(SeoImageOptimizationService::class);
        $config = $optimization->resolveForSite((int) $site->id);

        $path = ltrim(str_replace('\\', '/', (string) ($media->path ?? '')), '/');
        if ($path === '' || ! Storage::disk('public')->exists($path)) {
            $fallback = $this->cloneRemoteMediaToArticleSite($media, $article);
            if ($fallback instanceof SeoMedia) {
                $media = $fallback;
                $mediaId = (int) $media->id;
                $path = ltrim(str_replace('\\', '/', (string) ($media->path ?? '')), '/');
            }
        }

        $absolutePath = $path !== '' && Storage::disk('public')->exists($path)
            ? Storage::disk('public')->path($path)
            : '';

        $existingAttachmentId = (int) ($media->wp_attachment_id ?? 0);
        $existingWpUrl = '';
        if ($existingAttachmentId > 0) {
            $existingWpUrl = $this->fetchWordPressAttachmentUrl($site, $existingAttachmentId);
            if ($existingWpUrl === '') {
                Log::warning('WordPress attachment đã bị xóa trên WP — import mới.', [
                    'article_id' => $article->id,
                    'seo_media_id' => $mediaId,
                    'wp_attachment_id' => $existingAttachmentId,
                ]);
                $media->update([
                    'wp_attachment_id' => null,
                    'wp_synced_at' => null,
                ]);
                $existingAttachmentId = 0;
            }
        }

        $cached = $this->resolveCachedMediaSyncResult($mediaId, $config, $absolutePath, $existingWpUrl);
        if ($cached !== null) {
            return $cached;
        }

        $writeToken = trim((string) ($site->getMeta('seo_migration_token') ?? ''));
        if ($writeToken === '') {
            return $this->rememberMediaSyncResult($mediaId, $config, [
                'success' => false,
                'attachment_id' => 0,
                'wp_url' => '',
                'seo_media_id' => $mediaId,
                'message' => "Ảnh #{$mediaId}: thiếu migration token.",
            ]);
        }

        $base = app(WordPressArticleContentService::class)->getPermalinkBase($site);
        if ($base === '') {
            return $this->rememberMediaSyncResult($mediaId, $config, [
                'success' => false,
                'attachment_id' => 0,
                'wp_url' => '',
                'seo_media_id' => $mediaId,
                'message' => "Ảnh #{$mediaId}: không xác định được URL WordPress.",
            ]);
        }

        if ($absolutePath === '' || ! $optimization->isValidImageFile($absolutePath)) {
            return $this->rememberMediaSyncResult($mediaId, $config, [
                'success' => false,
                'attachment_id' => 0,
                'wp_url' => '',
                'seo_media_id' => $mediaId,
                'message' => "Ảnh #{$mediaId}: không tìm thấy file local hoặc file ảnh hỏng.",
            ]);
        }

        $uploadFile = $optimization->prepareWordPressUploadFile($absolutePath, $config);
        if ($uploadFile === null) {
            $detail = 'Không đọc được file ảnh gốc trên disk (thiếu hoặc undecodeable).';

            return $this->rememberMediaSyncResult($mediaId, $config, [
                'success' => false,
                'attachment_id' => 0,
                'wp_url' => '',
                'seo_media_id' => $mediaId,
                'message' => "Ảnh #{$mediaId}: {$detail}",
            ]);
        }

        $uploadPath = (string) ($uploadFile['path'] ?? $absolutePath);
        $uploadMime = (string) ($uploadFile['mime'] ?? $optimization->mimeFromPath($uploadPath));
        $cleanupTemp = (bool) ($uploadFile['temporary'] ?? false);

        $binary = @file_get_contents($uploadPath);
        if (! is_string($binary) || strlen($binary) < 256) {
            if ($cleanupTemp && is_file($uploadPath)) {
                @unlink($uploadPath);
            }

            return $this->rememberMediaSyncResult($mediaId, $config, [
                'success' => false,
                'attachment_id' => 0,
                'wp_url' => '',
                'seo_media_id' => $mediaId,
                'message' => "Ảnh #{$mediaId}: đọc file upload thất bại.",
            ]);
        }

        $response = null;
        $altText = trim((string) ($media->alt_text ?? ''));
        $slug = (string) ($media->slug ?? '');

        if ($existingAttachmentId > 0) {
            try {
                $replaceResponse = Http::timeout(120)
                    ->acceptJson()
                    ->withToken($writeToken)
                    ->attach('file', $binary, basename($uploadPath), [
                        'Content-Type' => $uploadMime,
                    ])
                    ->post($base.'/wp-json/omi-seo-ai/v1/attachments/'.$existingAttachmentId.'/replace-binary');

                if ($replaceResponse->successful()) {
                    $replaceBody = $replaceResponse->json();
                    $replaceUrl = is_array($replaceBody) ? trim((string) ($replaceBody['url'] ?? '')) : '';
                    if ($replaceUrl === '') {
                        $replaceUrl = $this->fetchWordPressAttachmentUrl($site, $existingAttachmentId);
                    }

                    if (
                        is_array($replaceBody)
                        && ($replaceBody['success'] ?? false)
                        && $replaceUrl !== ''
                    ) {
                        $needsWebpReimport = (bool) $config->auto_convert_webp
                            && $uploadMime === 'image/webp'
                            && ! $optimization->isWebpUrl($replaceUrl);

                        $needsMimeUrlFix = $this->uploadMimeMismatchesWpUrl($uploadMime, $replaceUrl);

                        if ($needsWebpReimport || $needsMimeUrlFix) {
                            if ($needsMimeUrlFix) {
                                Log::warning('WordPress replace mime/URL mismatch — reimport attachment.', [
                                    'article_id' => $article->id,
                                    'seo_media_id' => $mediaId,
                                    'wp_attachment_id' => $existingAttachmentId,
                                    'upload_mime' => $uploadMime,
                                    'wp_url' => $replaceUrl,
                                ]);
                            }

                            $reimported = $this->reimportRetiringOldAttachment(
                                $media,
                                $writeToken,
                                $base,
                                $binary,
                                basename($uploadPath),
                                $uploadMime,
                                $existingAttachmentId,
                                $slug,
                                $altText,
                            );
                            if ($reimported !== null) {
                                return $this->rememberMediaSyncResult($mediaId, $config, $reimported);
                            }

                            Log::warning('WordPress replace URL mismatch; reimport fallback failed.', [
                                'article_id' => $article->id,
                                'seo_media_id' => $mediaId,
                                'wp_attachment_id' => $existingAttachmentId,
                                'wp_url' => $replaceUrl,
                            ]);
                        } else {
                            $media->update([
                                'wp_attachment_id' => $existingAttachmentId,
                                'wp_synced_at' => now(),
                            ]);

                            return $this->rememberMediaSyncResult($mediaId, $config, [
                                'success' => true,
                                'attachment_id' => $existingAttachmentId,
                                'wp_url' => $replaceUrl,
                                'seo_media_id' => (int) $media->id,
                                'message' => '',
                            ]);
                        }
                    }
                }
            } catch (Throwable $exception) {
                Log::warning('WordPress replace attachment binary failed, fallback to import', [
                    'article_id' => $article->id,
                    'seo_media_id' => $mediaId,
                    'wp_attachment_id' => $existingAttachmentId,
                    'error' => $exception->getMessage(),
                ]);
            }
        }

        try {
            $response = Http::timeout(120)
                ->acceptJson()
                ->withToken($writeToken)
                ->attach('file', $binary, basename($uploadPath), [
                    'Content-Type' => $uploadMime,
                ])
                ->post($base.'/wp-json/omi-seo-ai/v1/attachments/import', [
                    'slug' => $slug,
                    'title' => $altText !== '' ? $altText : $slug,
                    'alt_text' => $altText !== '' ? $altText : $slug,
                ]);
        } catch (Throwable $exception) {
            Log::warning('WordPress local media import failed', [
                'article_id' => $article->id,
                'seo_media_id' => $mediaId,
                'error' => $exception->getMessage(),
            ]);

            return $this->rememberMediaSyncResult($mediaId, $config, [
                'success' => false,
                'attachment_id' => 0,
                'wp_url' => '',
                'seo_media_id' => $mediaId,
                'message' => "Ảnh #{$mediaId}: không kết nối được WordPress ({$exception->getMessage()}).",
            ]);
        } finally {
            if ($cleanupTemp && is_file($uploadPath)) {
                @unlink($uploadPath);
            }
        }

        if (! $response->successful()) {
            $message = (string) ($response->json('message') ?? $response->body());

            return $this->rememberMediaSyncResult($mediaId, $config, [
                'success' => false,
                'attachment_id' => 0,
                'wp_url' => '',
                'seo_media_id' => $mediaId,
                'message' => "Ảnh #{$mediaId}: WordPress lỗi HTTP {$response->status()} ({$message}).",
            ]);
        }

        $body = $response->json();
        $attachmentId = (int) ($body['attachment_id'] ?? 0);
        $wpUrl = trim((string) ($body['url'] ?? ''));
        if ($wpUrl === '' && $attachmentId > 0) {
            $wpUrl = $this->fetchWordPressAttachmentUrl($site, $attachmentId);
        }
        if (! is_array($body) || ! ($body['success'] ?? false) || $attachmentId <= 0 || $wpUrl === '') {
            $message = is_array($body) ? (string) ($body['message'] ?? 'Phản hồi không hợp lệ.') : 'Phản hồi không hợp lệ.';
            if ($existingAttachmentId > 0 && $existingWpUrl !== '') {
                return $this->rememberMediaSyncResult($mediaId, $config, [
                    'success' => true,
                    'attachment_id' => $existingAttachmentId,
                    'wp_url' => $existingWpUrl,
                    'seo_media_id' => $mediaId,
                    'message' => '',
                ]);
            }

            return $this->rememberMediaSyncResult($mediaId, $config, [
                'success' => false,
                'attachment_id' => 0,
                'wp_url' => '',
                'seo_media_id' => $mediaId,
                'message' => "Ảnh #{$mediaId}: {$message}",
            ]);
        }

        $media->update([
            'wp_attachment_id' => $attachmentId,
            'wp_synced_at' => now(),
        ]);

        return $this->rememberMediaSyncResult($mediaId, $config, [
            'success' => true,
            'attachment_id' => $attachmentId,
            'wp_url' => $wpUrl,
            'seo_media_id' => (int) $media->id,
            'message' => '',
        ]);
    }

    /**
     * @param  array{success: bool, attachment_id: int, wp_url: string, seo_media_id: int|null, message: string}  $result
     * @return array{success: bool, attachment_id: int, wp_url: string, seo_media_id: int|null, message: string}
     */
    private function rememberMediaSyncResult(
        int $mediaId,
        ?SeoImageOptimizationSetting $config,
        array $result,
    ): array {
        $optimization = app(SeoImageOptimizationService::class);
        $wpUrl = trim((string) ($result['wp_url'] ?? ''));

        if (
            $config instanceof SeoImageOptimizationSetting
            && (bool) $config->auto_convert_webp
            && ($result['success'] ?? false)
            && $wpUrl !== ''
            && ! $optimization->isWebpUrl($wpUrl)
        ) {
            Log::warning('WordPress media sync succeeded but URL is not WebP; cache skipped for retry.', [
                'seo_media_id' => $mediaId,
                'wp_url' => $wpUrl,
            ]);

            return $result;
        }

        $this->cache[$mediaId] = $result;

        return $result;
    }

    /**
     * @return array{success: bool, attachment_id: int, wp_url: string, seo_media_id: int|null, message: string}|null
     */
    private function resolveCachedMediaSyncResult(
        int $mediaId,
        SeoImageOptimizationSetting $config,
        string $absolutePath,
        string $existingWpUrl,
    ): ?array {
        if (! isset($this->cache[$mediaId])) {
            return null;
        }

        $cached = $this->cache[$mediaId];
        $cachedUrl = trim((string) ($cached['wp_url'] ?? ''));
        $referenceUrl = $cachedUrl !== '' ? $cachedUrl : $existingWpUrl;

        if (
            $absolutePath !== ''
            && ($cached['success'] ?? false)
            && app(SeoImageOptimizationService::class)->needsWordPressWebpBackfill(
                $config,
                $absolutePath,
                $referenceUrl !== '' ? $referenceUrl : null,
            )
        ) {
            unset($this->cache[$mediaId]);

            return null;
        }

        return $cached;
    }

    /**
     * Import attachment mới rồi xóa bản cũ — khi replace giữ sai extension/mime
     * (vd. JPEG ghi vào path .webp → ảnh trắng trên browser).
     *
     * @return array{success: bool, attachment_id: int, wp_url: string, seo_media_id: int|null, message: string}|null
     */
    private function reimportRetiringOldAttachment(
        SeoMedia $media,
        string $writeToken,
        string $base,
        string $binary,
        string $uploadFilename,
        string $uploadMime,
        int $oldAttachmentId,
        string $slug,
        string $altText,
    ): ?array {
        if ($oldAttachmentId <= 0 || $binary === '') {
            return null;
        }

        try {
            $importResponse = Http::timeout(120)
                ->acceptJson()
                ->withToken($writeToken)
                ->attach('file', $binary, $uploadFilename, [
                    'Content-Type' => $uploadMime !== '' ? $uploadMime : 'application/octet-stream',
                ])
                ->post($base.'/wp-json/omi-seo-ai/v1/attachments/import', [
                    'slug' => $slug !== '' ? $slug : pathinfo($uploadFilename, PATHINFO_FILENAME),
                    'title' => $altText !== '' ? $altText : ($slug !== '' ? $slug : $uploadFilename),
                    'alt_text' => $altText !== '' ? $altText : ($slug !== '' ? $slug : $uploadFilename),
                ]);
        } catch (Throwable $exception) {
            Log::warning('WordPress attachment reimport failed', [
                'seo_media_id' => (int) $media->id,
                'old_attachment_id' => $oldAttachmentId,
                'upload_mime' => $uploadMime,
                'error' => $exception->getMessage(),
            ]);

            return null;
        }

        if (! $importResponse->successful()) {
            return null;
        }

        $body = $importResponse->json();
        if (! is_array($body) || ! ($body['success'] ?? false)) {
            return null;
        }

        $newAttachmentId = (int) ($body['attachment_id'] ?? 0);
        $newUrl = trim((string) ($body['url'] ?? ''));
        if ($newAttachmentId <= 0 || $newUrl === '') {
            return null;
        }

        if ($this->uploadMimeMismatchesWpUrl($uploadMime, $newUrl)) {
            Log::warning('WordPress reimport still has mime/URL mismatch.', [
                'seo_media_id' => (int) $media->id,
                'upload_mime' => $uploadMime,
                'wp_url' => $newUrl,
            ]);

            return null;
        }

        $this->deleteWordPressAttachment($writeToken, $base, $oldAttachmentId);

        $media->update([
            'wp_attachment_id' => $newAttachmentId,
            'wp_synced_at' => now(),
        ]);

        return [
            'success' => true,
            'attachment_id' => $newAttachmentId,
            'wp_url' => $newUrl,
            'seo_media_id' => (int) $media->id,
            'message' => '',
        ];
    }

    private function uploadMimeMismatchesWpUrl(string $uploadMime, string $wpUrl): bool
    {
        $uploadMime = strtolower(trim($uploadMime));
        $wpUrl = trim($wpUrl);
        if ($uploadMime === '' || $wpUrl === '') {
            return false;
        }

        $path = parse_url($wpUrl, PHP_URL_PATH);
        if (! is_string($path) || $path === '') {
            return false;
        }

        $extension = strtolower((string) pathinfo($path, PATHINFO_EXTENSION));
        if ($extension === 'jpeg') {
            $extension = 'jpg';
        }

        $expected = match ($uploadMime) {
            'image/webp' => 'webp',
            'image/png' => 'png',
            'image/gif' => 'gif',
            'image/jpeg', 'image/jpg' => 'jpg',
            default => '',
        };

        if ($expected === '' || $extension === '') {
            return false;
        }

        return $expected !== $extension;
    }

    private function deleteWordPressAttachment(string $writeToken, string $base, int $attachmentId): void
    {
        if ($attachmentId <= 0) {
            return;
        }

        try {
            Http::timeout(30)
                ->acceptJson()
                ->withToken($writeToken)
                ->post($base.'/wp-json/omi-seo-ai/v1/attachments/'.$attachmentId.'/delete');
        } catch (Throwable $exception) {
            Log::warning('WordPress delete attachment failed after reimport', [
                'attachment_id' => $attachmentId,
                'error' => $exception->getMessage(),
            ]);
        }
    }

    /**
     * @return list<int>
     */
    private function extractSeoMediaIdsFromHtml(string $html): array
    {
        $html = trim($html);
        if ($html === '' || ! preg_match_all('/\bdata-seo-media-id\s*=\s*["\']?(\d+)["\']?/i', $html, $matches)) {
            return [];
        }

        return array_values(array_unique(array_filter(array_map(
            static fn (string $id): int => (int) $id,
            $matches[1] ?? [],
        ), static fn (int $id): bool => $id > 0)));
    }

    private function hydrateMediaUsageForArticle(SeoMedia $media, SeoArticle $article): SeoMedia
    {
        $payload = [];
        $siteId = (int) ($article->site_id ?? 0);
        if ($siteId > 0 && (int) ($media->site_id ?? 0) <= 0) {
            $payload['site_id'] = $siteId;
        }

        $articleId = (int) ($article->id ?? 0);
        if ($articleId > 0) {
            $ids = SeoMedia::normalizeArticleIds($media->article_id);
            if (! in_array($articleId, $ids, true)) {
                $ids[] = $articleId;
                $payload['article_id'] = $ids;
            }
        }

        if ($payload === []) {
            return $media;
        }

        $media->update($payload);

        return $media->fresh() ?? $media;
    }

    private function cloneRemoteMediaToArticleSite(SeoMedia $media, SeoArticle $article): ?SeoMedia
    {
        $remoteUrl = trim((string) ($media->url ?? ''));
        if ($remoteUrl === '' || ! filter_var($remoteUrl, FILTER_VALIDATE_URL)) {
            return null;
        }

        try {
            return app(SeoMediaStorageService::class)->storeFromRemoteUrl(
                $remoteUrl,
                (int) ($article->site_id ?? 0) > 0 ? (int) $article->site_id : null,
                (int) ($article->id ?? 0) > 0 ? (int) $article->id : null,
            );
        } catch (Throwable $exception) {
            Log::warning('WordPress sync fallback remote clone failed', [
                'article_id' => (int) ($article->id ?? 0),
                'seo_media_id' => (int) ($media->id ?? 0),
                'url' => $remoteUrl,
                'error' => $exception->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Gán src WordPress cho thẻ img có data-seo-media-id (kể cả src rỗng / localhost bị WP gỡ).
     *
     * @param  array<int, bool>  $alreadySyncedMediaIds
     * @return array{html: string, synced_media_ids: list<int>, errors: list<string>}
     */
    private function applyWpUrlsToSeoMediaImages(SeoArticle $article, string $html, array $alreadySyncedMediaIds = []): array
    {
        $syncedMediaIds = [];
        $errors = [];

        if (! preg_match_all('/<img\b[^>]*\bdata-seo-media-id\s*=\s*["\']?(\d+)["\']?[^>]*>/i', $html, $matches, PREG_OFFSET_CAPTURE)) {
            return [
                'html' => $html,
                'synced_media_ids' => [],
                'errors' => [],
            ];
        }

        $replacements = [];
        foreach ($matches[0] as $index => $match) {
            $tag = (string) ($match[0] ?? '');
            $offset = (int) ($match[1] ?? 0);
            $seoMediaId = (int) ($matches[1][$index][0] ?? 0);
            if ($tag === '' || $seoMediaId <= 0) {
                continue;
            }

            try {
                $media = SeoMedia::query()->whereKey($seoMediaId)->first();
                if (! $media instanceof SeoMedia) {
                    $errors[] = "Không tìm thấy seo_media #{$seoMediaId} trong data-seo-media-id.";

                    continue;
                }

                if (! $this->imageTagNeedsResync($article, $tag, $media)) {
                    continue;
                }

                if (isset($alreadySyncedMediaIds[$seoMediaId])) {
                    $cached = $this->cache[$seoMediaId] ?? null;
                    if (
                        is_array($cached)
                        && ($cached['success'] ?? false)
                        && trim((string) ($cached['wp_url'] ?? '')) !== ''
                        && (int) ($cached['attachment_id'] ?? 0) > 0
                    ) {
                        $wpUrl = trim((string) $cached['wp_url']);
                        $attachmentId = (int) $cached['attachment_id'];
                        $newTag = $this->patchImageTagWithWpSrc($tag, $wpUrl, $attachmentId);
                        if ($newTag !== $tag) {
                            $replacements[$offset] = ['length' => strlen($tag), 'tag' => $newTag];
                        }
                    }

                    continue;
                }

                $this->forgetMediaCache($seoMediaId);

                $oldUrl = $this->extractImageSrcFromTag($tag);
                if ($oldUrl === '' && (int) ($media->wp_attachment_id ?? 0) > 0) {
                    $article->loadMissing('site');
                    if ($article->site instanceof Site) {
                        $oldUrl = $this->fetchWordPressAttachmentUrl($article->site, (int) $media->wp_attachment_id);
                    }
                }

                $result = $this->syncMedia($article, $media);
                if (! $result['success']) {
                    $errors[] = $result['message'];

                    continue;
                }

                if ($result['seo_media_id'] !== null) {
                    $syncedMediaIds[] = $result['seo_media_id'];
                }

                $wpUrl = trim($result['wp_url']);
                $attachmentId = (int) ($result['attachment_id'] ?? 0);
                if ($wpUrl === '' || $attachmentId <= 0) {
                    $errors[] = "Ảnh #{$seoMediaId}: thiếu URL WordPress sau sync.";

                    continue;
                }

                if ($oldUrl !== '' && $oldUrl !== $wpUrl) {
                    $replacements['__urls__'][$oldUrl] = $wpUrl;
                }

                $newTag = $this->patchImageTagWithWpSrc($tag, $wpUrl, $attachmentId);
                if ($newTag !== $tag) {
                    $replacements[$offset] = ['length' => strlen($tag), 'tag' => $newTag];
                }
            } catch (Throwable $exception) {
                Log::warning('WordPress syncHtml data-seo-media-id failed', [
                    'article_id' => $article->id,
                    'seo_media_id' => $seoMediaId,
                    'error' => $exception->getMessage(),
                ]);
                $errors[] = "Ảnh #{$seoMediaId}: {$exception->getMessage()}";
            }
        }

        if ($replacements === []) {
            return [
                'html' => $html,
                'synced_media_ids' => $syncedMediaIds,
                'errors' => $errors,
            ];
        }

        $urlMap = is_array($replacements['__urls__'] ?? null) ? $replacements['__urls__'] : [];
        unset($replacements['__urls__']);
        $html = $this->applyUrlReplacements($html, $urlMap);

        if ($replacements !== []) {
            krsort($replacements);
            foreach ($replacements as $offset => $item) {
                $html = substr_replace($html, $item['tag'], $offset, $item['length']);
            }
        }

        return [
            'html' => $html,
            'synced_media_ids' => $syncedMediaIds,
            'errors' => $errors,
        ];
    }

    /**
     * @param  array<string, string>  $urlMap
     */
    public function replaceUrlsInHtml(string $html, array $urlMap): string
    {
        return $this->applyUrlReplacements($html, $urlMap);
    }

    public function htmlContainsLocalSeoMedia(string $html): bool
    {
        $html = trim($html);
        if ($html === '') {
            return false;
        }

        return $this->extractLocalSeoMediaImageRefs($html) !== []
            || $this->extractLocalSeoMediaUrls($html) !== [];
    }

    private function imageTagNeedsWpSrc(string $tag): bool
    {
        if (! preg_match('/\bsrc\s*=\s*(["\']?)([^"\'>\s]*)\1/i', $tag, $srcMatch)) {
            return true;
        }

        $src = trim(html_entity_decode((string) ($srcMatch[2] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        if ($src === '' || $src === '#') {
            return true;
        }

        if (str_contains($src, 'placeholder-loading')) {
            return true;
        }

        return $this->isLocalSeoMediaSrc($src);
    }

    private function imageTagNeedsResync(SeoArticle $article, string $tag, SeoMedia $media): bool
    {
        if ($this->imageTagNeedsWpSrc($tag)) {
            return true;
        }

        $article->loadMissing('site');
        $site = $article->site;
        if (! $site instanceof Site) {
            return false;
        }

        $path = ltrim(str_replace('\\', '/', (string) ($media->path ?? '')), '/');
        if ($path === '' || ! Storage::disk('public')->exists($path)) {
            return false;
        }

        $optimization = app(SeoImageOptimizationService::class);
        $config = $optimization->resolveForSite((int) $site->id);
        $existingWpUrl = $this->extractImageSrcFromTag($tag);
        if ($existingWpUrl === '' && (int) ($media->wp_attachment_id ?? 0) > 0) {
            $existingWpUrl = $this->fetchWordPressAttachmentUrl($site, (int) $media->wp_attachment_id);
        }

        return $optimization->needsWordPressWebpBackfill(
            $config,
            Storage::disk('public')->path($path),
            $existingWpUrl !== '' ? $existingWpUrl : null,
        );
    }

    private function extractImageSrcFromTag(string $tag): string
    {
        if (! preg_match('/\bsrc\s*=\s*(["\']?)([^"\'>\s]*)\1/i', $tag, $srcMatch)) {
            return '';
        }

        return trim(html_entity_decode((string) ($srcMatch[2] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }

    /**
     * @param  array<string, string>  $urlMap
     */
    private function applyUrlReplacements(string $html, array $urlMap): string
    {
        if ($html === '' || $urlMap === []) {
            return $html;
        }

        uksort($urlMap, static fn (string $left, string $right): int => strlen($right) <=> strlen($left));

        foreach ($urlMap as $oldUrl => $newUrl) {
            $oldUrl = trim($oldUrl);
            $newUrl = trim($newUrl);
            if ($oldUrl === '' || $newUrl === '' || $oldUrl === $newUrl) {
                continue;
            }

            $html = str_replace($oldUrl, $newUrl, $html);
        }

        return $html;
    }

    private function isLocalSeoMediaSrc(string $src): bool
    {
        return preg_match('#/storage/uploads/seo_media/|uploads/seo_media/#i', $src) === 1;
    }

    private function patchImageTagWithWpSrc(string $tag, string $wpUrl, int $attachmentId): string
    {
        $wpUrl = trim($wpUrl);
        $escapedUrl = htmlspecialchars($wpUrl, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $wpClass = 'wp-image-'.$attachmentId;

        if (preg_match('/\bsrc\s*=/i', $tag) === 1) {
            if (preg_match('/\bsrc\s*=\s*("|\')/i', $tag) === 1) {
                $tag = (string) preg_replace('/\bsrc\s*=\s*("|\')[^"\']*\1/i', 'src="'.$escapedUrl.'"', $tag, 1);
            } else {
                $tag = (string) preg_replace('/\bsrc\s*=\s*[^\s>]+/i', 'src="'.$escapedUrl.'"', $tag, 1);
            }
        } else {
            $tag = preg_replace('/<img\b/i', '<img src="'.$escapedUrl.'"', $tag, 1) ?? $tag;
        }

        if (preg_match('/\bclass\s*=\s*("|\')([^"\']*)\1/i', $tag, $classMatch)) {
            $classes = trim((string) ($classMatch[2] ?? ''));
            if (! preg_match('/\b'.preg_quote($wpClass, '/').'\b/', $classes)) {
                $classes = trim($classes.' '.$wpClass);
            }
            $tag = (string) preg_replace(
                '/\bclass\s*=\s*("|\')[^"\']*\1/i',
                'class="'.htmlspecialchars($classes, ENT_QUOTES | ENT_HTML5, 'UTF-8').'"',
                $tag,
                1,
            );
        } else {
            $tag = preg_replace('/<img\b/i', '<img class="'.$wpClass.'"', $tag, 1) ?? $tag;
        }

        if (preg_match('/\bdata-id\s*=/i', $tag) === 1) {
            $tag = (string) preg_replace('/\bdata-id\s*=\s*("|\')[^"\']*\1/i', 'data-id="'.$attachmentId.'"', $tag, 1);
        } else {
            $tag = preg_replace('/<img\b/i', '<img data-id="'.$attachmentId.'"', $tag, 1) ?? $tag;
        }

        return $tag;
    }

    private function fetchWordPressAttachmentUrl(Site $site, int $attachmentId): string
    {
        if ($attachmentId <= 0) {
            return '';
        }

        $site->loadMissing('metas');
        $base = app(WordPressArticleContentService::class)->getPermalinkBase($site);
        if ($base === '') {
            return '';
        }

        $tokens = array_values(array_unique(array_filter([
            trim((string) ($site->getMeta('seo_read_token') ?? '')),
            trim((string) ($site->getMeta('seo_migration_token') ?? '')),
        ])));
        if ($tokens === []) {
            $tokens = [''];
        }

        foreach ($tokens as $token) {
            try {
                $request = Http::timeout(30)->acceptJson();
                if ($token !== '') {
                    $request = $request->withToken($token);
                }

                $response = $request->get($base.'/wp-json/wp/v2/media/'.$attachmentId);
            } catch (Throwable $exception) {
                Log::warning('WordPress attachment URL fetch failed', [
                    'attachment_id' => $attachmentId,
                    'error' => $exception->getMessage(),
                ]);

                continue;
            }

            if (! $response->successful()) {
                continue;
            }

            $payload = $response->json();
            if (! is_array($payload)) {
                continue;
            }

            $source = trim((string) ($payload['source_url'] ?? ''));
            if ($source !== '') {
                return $source;
            }

            $guid = $payload['guid']['rendered'] ?? '';
            $guid = is_string($guid) ? trim($guid) : '';
            if ($guid !== '') {
                return $guid;
            }
        }

        return '';
    }

    /**
     * @return list<string>
     */
    /**
     * @return list<array{url: string, seo_media_id: int}>
     */
    private function extractLocalSeoMediaImageRefs(string $html): array
    {
        $refs = [];
        $seen = [];

        if (preg_match_all('/<img\b[^>]*>/i', $html, $tags)) {
            foreach ($tags[0] as $tag) {
                $tag = (string) $tag;
                $src = $this->extractImageSrcFromTag($tag);
                if ($src === '' || ! $this->isLocalSeoMediaSrc($src)) {
                    continue;
                }

                $seoMediaId = 0;
                if (preg_match('/\bdata-seo-media-id\s*=\s*["\']?(\d+)["\']?/i', $tag, $idMatch)) {
                    $seoMediaId = (int) ($idMatch[1] ?? 0);
                }

                $key = $seoMediaId > 0 ? 'id:'.$seoMediaId : 'url:'.$src;
                if (isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;

                $refs[] = [
                    'url' => $src,
                    'seo_media_id' => $seoMediaId,
                ];
            }
        }

        if ($refs !== []) {
            return $refs;
        }

        foreach ($this->extractLocalSeoMediaUrls($html) as $url) {
            $refs[] = [
                'url' => $url,
                'seo_media_id' => 0,
            ];
        }

        return $refs;
    }

    /**
     * @return list<string>
     */
    private function extractLocalSeoMediaUrls(string $html): array
    {
        if (! preg_match_all(
            '#https?://[^\s"\'<>]*?/storage/uploads/seo_media/[^\s"\'<>]+|/storage/uploads/seo_media/[^\s"\'<>]+#i',
            $html,
            $matches,
        )) {
            return [];
        }

        $urls = array_map(static fn ($url): string => trim((string) $url), $matches[0] ?? []);
        $urls = array_values(array_unique(array_filter($urls, static fn (string $url): bool => $url !== '')));

        return $urls;
    }

    private function urlToSeoMediaPath(string $url): string
    {
        $url = trim($url);
        if ($url === '') {
            return '';
        }

        $path = $url;
        if (preg_match('#^https?://#i', $url) === 1) {
            $parsed = parse_url($url, PHP_URL_PATH);
            $path = is_string($parsed) ? $parsed : '';
        } else {
            $path = explode('?', $path, 2)[0];
        }

        if (! str_starts_with($path, '/storage/uploads/seo_media/')) {
            return '';
        }

        return ltrim(substr($path, strlen('/storage/')), '/');
    }
}
