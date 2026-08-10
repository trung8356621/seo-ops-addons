<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Media\Http\Controllers;

use Omnichannel\Addons\Media\Http\Requests\TestOptimizeLocalWebpRequest;
use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\Media\Models\SeoMedia;
use Omnichannel\Addons\Media\Services\ArticleEditorMediaAiService;
use Omnichannel\Addons\AiPrompt\Services\PromptPostProcessingApplyService;
use Omnichannel\Addons\Media\Services\SeoImageOptimizationService;
use Omnichannel\Addons\Media\Services\SeoImageSplitterService;
use Omnichannel\Addons\Media\Services\SeoMediaImageEditorResolverService;
use Omnichannel\Addons\Media\Services\SeoMediaLibraryImageActionService;
use Omnichannel\Addons\Media\Services\SeoMediaStorageService;
use Omnichannel\Addons\Content\Services\ArticleEditor\ArticleEditorSessionException;
use Omnichannel\Addons\Media\Services\SeoMediaArticleSlugFixService;
use Omnichannel\Addons\Media\Services\SeoMediaUrlImportResolverService;
use Omnichannel\Addons\Media\Services\SeoWpMediaEditedPendingService;
use Omnichannel\Addons\WordPress\Services\WordPressAttachmentMetaUpdateService;
use Omnichannel\Addons\Seo\Support\SeoAccessControl;
use App\Http\Controllers\Controller;
use App\Models\Site;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class SeoMediaController extends Controller
{
    public function __construct(
        private readonly SeoMediaStorageService $storage,
        private readonly SeoMediaArticleSlugFixService $slugFix,
        private readonly SeoMediaImageEditorResolverService $imageEditorResolver,
        private readonly SeoMediaLibraryImageActionService $imageActions,
        private readonly SeoImageSplitterService $imageSplitter,
        private readonly SeoMediaUrlImportResolverService $urlImportResolver,
    ) {}

    public function upload(Request $request): JsonResponse
    {
        $maxUploadKb = max(1, (int) config('seo-content-ai.media_max_upload_kb', 10240));

        $validated = $request->validate([
            'image' => 'required|image|mimes:jpeg,png,jpg,gif,webp|max:'.$maxUploadKb,
            'site_id' => 'nullable|integer',
            'article_id' => 'nullable|integer',
            'source' => 'nullable|string|max:50',
        ]);

        $siteId = isset($validated['site_id']) ? (int) $validated['site_id'] : null;
        $articleId = isset($validated['article_id']) ? (int) $validated['article_id'] : null;

        if ($articleId !== null) {
            $article = SeoArticle::query()->findOrFail($articleId);
            abort_unless(SeoAccessControl::canAccessArticle($article), 403);
            $siteId = (int) $article->site_id;
        } elseif ($siteId !== null) {
            abort_unless($this->canAccessSite($siteId), 403);
        } else {
            abort_unless(auth()->check(), 403);
        }

        try {
            $seoMedia = $this->storage->storeUpload(
                $request->file('image'),
                $siteId,
                $articleId,
                (string) ($validated['source'] ?? 'clipboard'),
            );
        } catch (\RuntimeException|\InvalidArgumentException $exception) {
            return response()->json([
                'success' => false,
                'message' => $exception->getMessage(),
            ], 422);
        }

        return response()->json([
            'success' => true,
            'id' => $seoMedia->id,
            'url' => $seoMedia->publicUrl(),
            'slug' => $seoMedia->slug,
            'alt_text' => $seoMedia->alt_text,
        ]);
    }

    public function importFromUrl(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'url' => ['required', 'string', 'max:2048', 'url'],
            'site_id' => 'nullable|integer',
            'article_id' => 'nullable|integer',
            'random_filename' => 'sometimes|boolean',
        ]);

        $siteId = isset($validated['site_id']) ? (int) $validated['site_id'] : null;
        $articleId = isset($validated['article_id']) ? (int) $validated['article_id'] : null;

        if ($articleId !== null) {
            $article = SeoArticle::query()->findOrFail($articleId);
            abort_unless(SeoAccessControl::canAccessArticle($article), 403);
            $siteId = (int) $article->site_id;
        } elseif ($siteId !== null) {
            abort_unless($this->canAccessSite($siteId), 403);
        } else {
            abort_unless(auth()->check(), 403);
        }

        $embedded = $this->urlImportResolver->resolveEmbeddedImport($siteId, (string) $validated['url']);
        if (is_array($embedded)) {
            return response()->json([
                'success' => true,
                ...$embedded,
            ]);
        }

        try {
            $seoMedia = $this->storage->storeFromRemoteUrl(
                (string) $validated['url'],
                $siteId,
                $articleId,
                (bool) ($validated['random_filename'] ?? false),
            );
        } catch (\InvalidArgumentException $e) {
            throw ValidationException::withMessages(['url' => $e->getMessage()]);
        } catch (\RuntimeException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        }

        return response()->json([
            'success' => true,
            'id' => $seoMedia->id,
            'url' => $seoMedia->publicUrl(),
            'slug' => $seoMedia->slug,
            'alt_text' => $seoMedia->alt_text,
            'message' => 'Đã tải và tối ưu ảnh vào thư viện.',
        ]);
    }

    public function rename(Request $request, int $media): JsonResponse
    {
        $record = SeoMedia::query()->find($media);
        if (! $record instanceof SeoMedia) {
            return response()->json([
                'success' => false,
                'message' => 'Không tìm thấy ảnh trong thư viện (seo_media). Thử đổi slug theo URL /storage.',
            ], 404);
        }

        abort_unless($this->canAccessMedia($record), 403);

        $validated = $request->validate([
            'new_slug' => ['required', 'string', 'regex:/^[a-z0-9\-]+$/i', 'max:200'],
            'article_id' => ['nullable', 'integer', 'min:1'],
            'editor_session_id' => ['nullable', 'string', 'max:64'],
        ]);

        $article = null;
        $articleId = (int) ($validated['article_id'] ?? 0);
        if ($articleId > 0) {
            $candidate = SeoArticle::query()->find($articleId);
            if ($candidate instanceof SeoArticle) {
                abort_unless(SeoAccessControl::canAccessArticle($candidate), 403);
                $article = $candidate;
            }
        }

        try {
            $result = $this->slugFix->renameOne(
                $record,
                (string) $validated['new_slug'],
                $article,
                $this->editorSessionContext($request, $validated),
            );
            $record = $result['media'];
        } catch (ArticleEditorSessionException $e) {
            return $this->editorSessionLockedResponse($e);
        } catch (\InvalidArgumentException $e) {
            throw ValidationException::withMessages(['new_slug' => $e->getMessage()]);
        } catch (\RuntimeException $e) {
            throw ValidationException::withMessages(['new_slug' => $e->getMessage()]);
        }

        return response()->json([
            'success' => true,
            'url' => $record->publicUrl(),
            'slug' => $record->slug,
            'id' => (int) $record->id,
            'replacement' => $result['replacement'] ?? null,
            'article_updated' => (bool) ($result['article_updated'] ?? false),
        ]);
    }

    public function updateMeta(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'items' => ['required', 'array', 'min:1'],
            'items.*.id' => ['required', 'integer', 'min:1'],
            'items.*.alt_text' => ['nullable', 'string', 'max:255'],
            'items.*.title' => ['nullable', 'string', 'max:255'],
        ]);

        $updated = [];
        /** @var array<int, list<array{attachment_id: int, alt_text: string, title: string}>> $wpItemsBySite */
        $wpItemsBySite = [];

        foreach ($validated['items'] as $item) {
            $media = SeoMedia::query()->find((int) $item['id']);
            if (! $media instanceof SeoMedia || ! $this->canAccessMedia($media)) {
                continue;
            }

            $altText = trim((string) ($item['alt_text'] ?? ''));
            $title = trim((string) ($item['title'] ?? ''));
            $media->alt_text = $altText;
            $media->save();

            $updated[] = [
                'id' => (int) $media->id,
                'alt_text' => (string) $media->alt_text,
                'title' => $title !== '' ? $title : $altText,
            ];

            $wpAttachmentId = (int) ($media->wp_attachment_id ?? 0);
            $siteId = (int) ($media->site_id ?? 0);
            if ($wpAttachmentId <= 0 || $siteId <= 0 || ($altText === '' && $title === '')) {
                continue;
            }

            $wpItemsBySite[$siteId] ??= [];
            $wpItemsBySite[$siteId][] = [
                'attachment_id' => $wpAttachmentId,
                'alt_text' => $altText,
                'title' => $title !== '' ? $title : $altText,
            ];
        }

        $wpSyncErrors = [];
        $wpUpdatedCount = 0;

        if ($wpItemsBySite !== []) {
            $wpService = app(WordPressAttachmentMetaUpdateService::class);

            foreach ($wpItemsBySite as $siteId => $wpItems) {
                $site = Site::query()->find((int) $siteId);
                if ($site === null) {
                    continue;
                }

                $result = $wpService->updateForSite($site, $wpItems);
                if ($result['success'] ?? false) {
                    $wpUpdatedCount += (int) ($result['updated_count'] ?? 0);

                    continue;
                }

                $wpSyncErrors[] = (string) ($result['message'] ?? 'Không cập nhật được alt/title WordPress.');
            }
        }

        return response()->json([
            'success' => true,
            'updated_count' => count($updated),
            'updated' => $updated,
            'wp_updated_count' => $wpUpdatedCount,
            'wp_sync_errors' => $wpSyncErrors,
        ]);
    }

    public function renameByUrl(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'url' => ['required', 'string', 'max:2048'],
            'new_slug' => ['required', 'string', 'regex:/^[a-z0-9\-]+$/i', 'max:200'],
            'site_id' => ['nullable', 'integer'],
            'article_id' => ['nullable', 'integer'],
            'seo_media_id' => ['nullable', 'integer', 'min:1'],
            'editor_session_id' => ['nullable', 'string', 'max:64'],
        ]);

        $url = trim((string) $validated['url']);
        $path = $url;
        if (Str::startsWith($url, ['http://', 'https://'])) {
            $parsed = parse_url($url, PHP_URL_PATH);
            $path = is_string($parsed) ? $parsed : '';
        }

        $path = trim($path);
        if (! Str::startsWith($path, '/storage/')) {
            throw ValidationException::withMessages(['url' => 'URL ảnh không hợp lệ (cần /storage/...).']);
        }

        $relativePath = ltrim(Str::after($path, '/storage/'), '/');
        if ($relativePath === '') {
            throw ValidationException::withMessages(['url' => 'Không xác định được đường dẫn ảnh.']);
        }

        $siteId = isset($validated['site_id']) ? (int) $validated['site_id'] : null;
        $articleId = isset($validated['article_id']) ? (int) $validated['article_id'] : null;
        if ($articleId !== null && $articleId > 0) {
            $article = SeoArticle::query()->find($articleId);
            if ($article instanceof SeoArticle) {
                abort_unless(SeoAccessControl::canAccessArticle($article), 403);
                $siteId = (int) $article->site_id;
            }
        }

        $media = null;
        $requestedSeoMediaId = isset($validated['seo_media_id']) ? (int) $validated['seo_media_id'] : 0;
        if ($requestedSeoMediaId > 0) {
            $candidate = SeoMedia::query()->find($requestedSeoMediaId);
            if ($candidate instanceof SeoMedia) {
                $media = $candidate;
            }
        }

        if (! $media instanceof SeoMedia) {
            $media = $this->resolveSeoMediaForStoragePath($relativePath, $siteId);
        }

        if (! $media instanceof SeoMedia && Storage::disk('public')->exists($relativePath)) {
            $filename = basename($relativePath);
            if ($filename !== '' && $filename !== '.' && $filename !== '..') {
                $media = SeoMedia::query()->create([
                    'site_id' => $siteId,
                    'article_id' => $articleId,
                    'filename' => $filename,
                    'slug' => Str::slug((string) pathinfo($filename, PATHINFO_FILENAME)),
                    'path' => $relativePath,
                    'url' => $this->storage->urlForPath($relativePath),
                    'source' => 'storage_adopt',
                ]);
            }
        }

        if (! $media instanceof SeoMedia) {
            return response()->json([
                'success' => false,
                'message' => 'Không tìm thấy ảnh nội bộ theo URL (thiếu bản ghi thư viện hoặc file không tồn tại).',
            ], 422);
        }

        abort_unless($this->canAccessMedia($media), 403);

        $articleForRewrite = null;
        if ($articleId !== null && $articleId > 0) {
            $candidate = SeoArticle::query()->find($articleId);
            if ($candidate instanceof SeoArticle) {
                $articleForRewrite = $candidate;
            }
        }

        try {
            $result = $this->slugFix->renameOne(
                $media,
                (string) $validated['new_slug'],
                $articleForRewrite,
                $this->editorSessionContext($request, $validated),
            );
            $media = $result['media'];
        } catch (ArticleEditorSessionException $e) {
            return $this->editorSessionLockedResponse($e);
        } catch (\InvalidArgumentException $e) {
            throw ValidationException::withMessages(['new_slug' => $e->getMessage()]);
        } catch (\RuntimeException $e) {
            throw ValidationException::withMessages(['new_slug' => $e->getMessage()]);
        }

        return response()->json([
            'success' => true,
            'id' => (int) $media->id,
            'url' => $media->publicUrl(),
            'slug' => $media->slug,
            'replacement' => $result['replacement'] ?? null,
            'article_updated' => (bool) ($result['article_updated'] ?? false),
        ]);
    }

    /**
     * Chuẩn bị URL trình chỉnh sửa (ảnh WP → staging trên Laravel nếu cần).
     */
    public function prepareEditor(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'site_id' => 'required|integer',
            'seo_media_id' => 'nullable|integer',
            'wp_attachment_id' => 'nullable|integer',
            'url' => 'nullable|string|max:2048',
            'slug' => 'nullable|string|max:200',
        ]);

        $siteId = (int) $validated['site_id'];
        abort_unless($this->canAccessSite($siteId), 403);

        $site = Site::query()->findOrFail($siteId);

        try {
            $resolved = $this->imageEditorResolver->resolve($site, [
                'seo_media_id' => (int) ($validated['seo_media_id'] ?? 0),
                'wp_attachment_id' => (int) ($validated['wp_attachment_id'] ?? 0),
                'url' => (string) ($validated['url'] ?? ''),
                'slug' => (string) ($validated['slug'] ?? ''),
                'kind' => (int) ($validated['wp_attachment_id'] ?? 0) > 0 ? 'wordpress' : 'local',
            ]);
        } catch (\InvalidArgumentException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage() ?: 'Không mở được trình chỉnh sửa.',
            ], 500);
        }

        return response()->json([
            'success' => true,
            'seo_media_id' => $resolved['seo_media_id'],
            'editor_url' => $resolved['editor_url'],
        ]);
    }

    /**
     * Tải metadata + URL ảnh nguồn cho trang tách lưới (Laravel seo_media hoặc WP).
     */
    public function splitterSource(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'site_id' => 'nullable|integer',
            'seo_media_id' => 'nullable|integer',
            'wp_attachment_id' => 'nullable|integer',
            'slug' => 'nullable|string|max:200',
        ]);

        $siteId = isset($validated['site_id']) ? (int) $validated['site_id'] : null;
        if ($siteId !== null && $siteId > 0) {
            abort_unless($this->canAccessSite($siteId), 403);
        }

        try {
            $resolved = $this->imageSplitter->resolveSource(
                ($siteId ?? 0) > 0 ? $siteId : null,
                isset($validated['seo_media_id']) ? (int) $validated['seo_media_id'] : null,
                isset($validated['wp_attachment_id']) ? (int) $validated['wp_attachment_id'] : null,
                (string) ($validated['slug'] ?? ''),
            );
        } catch (\InvalidArgumentException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        }

        return response()->json([
            'success' => true,
            ...$resolved,
        ]);
    }

    /**
     * Lưu các mảnh ảnh sau split vào thư viện và xóa ảnh gốc trên Laravel.
     */
    public function saveSplit(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'site_id' => 'required|integer',
            'article_id' => 'nullable|integer',
            'original_seo_media_id' => 'nullable|integer',
            'pieces' => 'required|array|min:1',
            'pieces.*' => 'required|image|mimes:jpeg,png,jpg,gif,webp|max:10240',
        ]);

        $siteId = (int) $validated['site_id'];
        abort_unless($this->canAccessSite($siteId), 403);

        $site = Site::query()->findOrFail($siteId);

        $articleId = $this->resolveSplitSaveArticleId(
            isset($validated['article_id']) ? (int) $validated['article_id'] : null,
            isset($validated['original_seo_media_id']) ? (int) $validated['original_seo_media_id'] : null,
        );
        $article = null;
        if ($articleId !== null) {
            $article = SeoArticle::query()->findOrFail($articleId);
            abort_unless(SeoAccessControl::canAccessArticle($article), 403);
        }

        /** @var list<\Illuminate\Http\UploadedFile> $pieceFiles */
        $pieceFiles = $request->file('pieces', []);
        if (! is_array($pieceFiles)) {
            $pieceFiles = [];
        }

        try {
            $deleteOriginal = SeoAccessControl::canDeleteSeoMedia();
            $originalSeoMediaId = isset($validated['original_seo_media_id'])
                ? (int) $validated['original_seo_media_id']
                : null;
            $originalMedia = ($originalSeoMediaId ?? 0) > 0
                ? SeoMedia::query()->find($originalSeoMediaId)
                : null;

            if ($articleId !== null && $article instanceof SeoArticle) {
                if ((string) ($article->type ?? '') === 'product') {
                    $deleteOriginal = false;
                }
            }

            $result = $this->imageSplitter->savePiecesAndDeleteOriginal(
                $site,
                array_values($pieceFiles),
                $articleId,
                $originalSeoMediaId,
                $deleteOriginal,
            );

            if (
                $article instanceof SeoArticle
                && (string) ($article->type ?? '') === 'product'
                && is_array($result['saved'] ?? null)
                && $result['saved'] !== []
            ) {
                if ($originalMedia instanceof SeoMedia) {
                    $galleryItems = app(\Omnichannel\Addons\Commerce\Services\ProductGallery\ProductGalleryPipelineService::class)
                        ->applyManualSplitRetry($article, $originalMedia, $result['saved']);
                } else {
                    $postProcessing = app(PromptPostProcessingApplyService::class);
                    $galleryItems = $postProcessing->finalizeProductGalleryManualSplit(
                        $article,
                        null,
                        $result['saved'],
                    );
                }
                $result['product_gallery_items'] = $galleryItems;
            }
        } catch (\InvalidArgumentException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        }

        return response()->json($result);
    }

    /**
     * Áp dụng đóng dấu cho một ảnh (WordPress hoặc nội bộ).
     */
    public function applyWatermark(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'site_id' => 'required|integer',
            'seo_media_id' => 'nullable|integer',
            'wp_attachment_id' => 'nullable|integer',
            'url' => 'nullable|string|max:2048',
            'slug' => 'nullable|string|max:200',
        ]);

        $siteId = (int) $validated['site_id'];
        abort_unless($this->canAccessSite($siteId), 403);

        $site = Site::query()->findOrFail($siteId);

        $imageRow = [
            'seo_media_id' => (int) ($validated['seo_media_id'] ?? 0),
            'wp_attachment_id' => (int) ($validated['wp_attachment_id'] ?? 0),
            'url' => (string) ($validated['url'] ?? ''),
            'slug' => (string) ($validated['slug'] ?? ''),
            'kind' => (int) ($validated['wp_attachment_id'] ?? 0) > 0 ? 'wordpress' : 'local',
        ];

        $result = $this->imageActions->applyWatermark($site, $imageRow);

        return response()->json([
            'success' => (bool) ($result['success'] ?? false),
            'message' => (string) ($result['message'] ?? ''),
            'url' => (string) ($result['url'] ?? $imageRow['url']),
            'can_restore' => (bool) ($result['can_restore'] ?? false),
            'can_optimize' => (bool) ($result['can_optimize'] ?? false),
        ], ($result['success'] ?? false) ? 200 : 422);
    }

    /**
     * Test pipeline sync WP: tạo sibling WebP local, không sửa file nguồn.
     */
    public function testOptimizeLocalWebp(
        TestOptimizeLocalWebpRequest $request,
        SeoImageOptimizationService $optimization,
    ): JsonResponse {
        $validated = $request->validated();
        $siteId = (int) $validated['site_id'];
        abort_unless($this->canAccessSite($siteId), 403);

        $media = SeoMedia::query()
            ->where('site_id', $siteId)
            ->findOrFail((int) $validated['seo_media_id']);
        abort_unless($this->canAccessMedia($media), 403);

        $relativePath = ltrim(str_replace('\\', '/', (string) $media->path), '/');
        if ($relativePath === '' || ! Storage::disk('public')->exists($relativePath)) {
            return response()->json([
                'success' => false,
                'message' => 'Không tìm thấy file ảnh local.',
            ], 422);
        }

        $sourcePath = Storage::disk('public')->path($relativePath);
        $config = $optimization->resolveForSite($siteId);
        // Đây là action test chủ động, không phụ thuộc toggle auto-convert hiện tại.
        $config->auto_convert_webp = true;
        $webpPath = $optimization->ensureLocalWebpCopy($sourcePath, $config);

        if (
            $webpPath !== null
            && (int) filesize($webpPath) > SeoImageOptimizationService::WORDPRESS_UPLOAD_FALLBACK_MAX_BYTES
        ) {
            $webpPath = $optimization->ensureLocalWebpUnderMaxBytes($sourcePath, $config);
        }

        if ($webpPath === null || ! $optimization->isUsableWebpFile($webpPath, $sourcePath)) {
            return response()->json([
                'success' => false,
                'message' => 'WebP local tạo thất bại hoặc ảnh kết quả bị trắng/không decode được.',
            ], 422);
        }

        $directory = pathinfo($relativePath, PATHINFO_DIRNAME);
        $webpRelativePath = ($directory !== '' && $directory !== '.' ? $directory.'/' : '')
            .basename($webpPath);
        $webpRelativePath = ltrim(str_replace('\\', '/', $webpRelativePath), '/');
        $dimensions = @getimagesize($webpPath);
        $publicUrl = '/storage/'.$webpRelativePath.'?v='.(string) ((int) filemtime($webpPath));

        return response()->json([
            'success' => true,
            'message' => 'Đã tạo WebP local cạnh file gốc (sibling .webp).',
            'url' => $publicUrl,
            'path' => $webpRelativePath,
            'source_path' => $relativePath,
            'bytes' => (int) filesize($webpPath),
            'width' => is_array($dimensions) ? (int) ($dimensions[0] ?? 0) : 0,
            'height' => is_array($dimensions) ? (int) ($dimensions[1] ?? 0) : 0,
        ]);
    }

    /**
     * Lưu ảnh sau khi người dùng edit/tô màu từ Editor (client-side).
     */
    public function saveEditedImage(Request $request, SeoMedia $media): JsonResponse
    {
        abort_unless($this->canAccessMedia($media), 403);

        $request->validate([
            'image' => 'required|image|mimes:jpeg,png,jpg,gif,webp|max:10240',
        ]);

        $path = ltrim(str_replace('\\', '/', (string) $media->path), '/');
        if ($path === '') {
            return response()->json([
                'success' => false,
                'message' => 'Ảnh không có đường dẫn lưu trữ trên server.',
            ], 422);
        }

        $editedFile = $request->file('image');
        Storage::disk('public')->put(
            $path,
            file_get_contents($editedFile->getRealPath()),
        );

        if ((int) ($media->wp_attachment_id ?? 0) > 0 && (int) ($media->site_id ?? 0) > 0) {
            app(SeoWpMediaEditedPendingService::class)->recordPendingEdit($media);
            $media->update(['wp_synced_at' => null]);
        }

        return response()->json([
            'success' => true,
            'message' => 'Lưu ảnh thành công',
            'url' => $media->fresh()->publicUrl().'?t='.time(),
        ]);
    }

    public function status(SeoMedia $media): JsonResponse
    {
        abort_unless($this->canAccessMedia($media), 403);

        return response()->json([
            'success' => true,
            ...$this->formatAiMediaPayload($media),
        ]);
    }

    public function articleAiJobs(SeoArticle $article): JsonResponse
    {
        abort_unless(SeoAccessControl::canAccessArticle($article), 403);

        app(ArticleEditorMediaAiService::class)->reconcileStaleAiMediaJobs((int) $article->id);

        // Kèm completed gần đây: editor reconcile placeholder khi poll/event miss.
        $recentCompletedAfter = now()->subHours(2);

        $items = SeoMedia::query()
            ->where('article_id', (int) $article->id)
            ->whereIn('source', ['ai_prompt', 'ai_video_prompt'])
            ->where(function ($query) use ($recentCompletedAfter): void {
                $query->whereIn('status', ['processing', 'failed'])
                    ->orWhere(function ($completed) use ($recentCompletedAfter): void {
                        $completed->where('status', 'completed')
                            ->where('updated_at', '>=', $recentCompletedAfter);
                    });
            })
            ->orderByDesc('id')
            ->limit(40)
            ->get()
            ->map(fn (SeoMedia $media): array => $this->formatAiMediaPayload($media))
            ->values()
            ->all();

        return response()->json([
            'success' => true,
            'items' => $items,
        ]);
    }

    public function retryGeneration(SeoMedia $media): JsonResponse
    {
        abort_unless($this->canAccessMedia($media), 403);

        $validated = request()->validate([
            'retry_input' => 'nullable|string|max:8000',
        ]);

        try {
            $media = app(ArticleEditorMediaAiService::class)->retryGeneration(
                $media,
                isset($validated['retry_input']) ? (string) $validated['retry_input'] : null,
            );
        } catch (\InvalidArgumentException $exception) {
            return response()->json([
                'success' => false,
                'message' => $exception->getMessage(),
            ], 422);
        }

        return response()->json([
            'success' => true,
            'message' => 'Đã đưa job vào hàng đợi lại.',
            ...$this->formatAiMediaPayload($media),
        ]);
    }

    public function deleteAiJob(SeoMedia $media): JsonResponse
    {
        abort_unless(SeoAccessControl::canDeleteSeoMedia(), 403);
        abort_unless($this->canAccessMedia($media), 403);

        if (! $media->isAiGenerationJob()) {
            return response()->json([
                'success' => false,
                'message' => __('seo-content-ai::common.ai_job_delete_only'),
            ], 422);
        }

        $path = ltrim(str_replace('\\', '/', (string) ($media->path ?? '')), '/');
        $isSharedPlaceholder = $path === SeoMedia::placeholderLoadingPath();
        $isUploadedFile = str_starts_with($path, 'uploads/seo_media/');

        if (! $isSharedPlaceholder && $isUploadedFile && Storage::disk('public')->exists($path)) {
            Storage::disk('public')->delete($path);
        }

        $media->delete();

        return response()->json([
            'success' => true,
            'message' => __('seo-content-ai::common.ai_job_deleted'),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function formatAiMediaPayload(SeoMedia $media): array
    {
        $status = (string) ($media->status ?? 'completed');
        $url = (string) ($media->url ?? '');

        if (str_contains($url, 'placeholder-loading')) {
            $url = SeoMedia::placeholderLoadingUrl();
        }

        if ($url === '') {
            $url = $media->publicUrl();
        }

        return [
            'id' => (int) $media->id,
            'status' => $status,
            'url' => $url,
            'error_message' => $media->error_message,
            'source' => (string) ($media->source ?? ''),
            'media_type' => $media->aiToolType(),
            'editor_block_id' => (string) ($media->editor_block_id ?? ''),
            'slug' => (string) ($media->slug ?? ''),
            'retry_input' => $this->extractRetryInput($media),
            'created_at' => $media->created_at?->toIso8601String(),
            'is_placeholder' => $status === 'processing' || str_contains($url, 'placeholder-loading'),
            'gallery_urls' => $this->resolvePostProcessingGalleryUrls($media),
            'product_gallery' => $this->resolveProductGalleryMode1Payload($media),
            'media_artifact_role' => \Omnichannel\Addons\Media\Support\ProductGallery\ProductGalleryReadyState::artifactRole($media),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function resolveProductGalleryMode1Payload(SeoMedia $media): ?array
    {
        $variables = is_array($media->prompt_variables) ? $media->prompt_variables : [];
        $state = \Omnichannel\Addons\Media\Support\ProductGallery\ProductGalleryReadyState::readFromVariables($variables);
        if (
            ($state['sprite_validation'] ?? null) === null
            && ! ($state['gallery_ready'] ?? false)
            && ($state['gallery_source'] ?? '') === \Omnichannel\Addons\Media\Support\ProductGallery\ProductGallerySource::Pending->value
            && ($state['fallback_snapshot']['urls'] ?? []) === []
            && ($state['child_media_ids'] ?? []) === []
        ) {
            return null;
        }

        return $state;
    }

    /**
     * @return list<array{id: int, url: string}>
     */
    private function resolvePostProcessingGalleryUrls(SeoMedia $media): array
    {
        $source = app(PromptPostProcessingApplyService::class)->resolveSourceMedia($media);
        $variables = is_array($source->prompt_variables) ? $source->prompt_variables : [];
        $pieceIds = is_array($variables['post_processing_piece_ids'] ?? null)
            ? $variables['post_processing_piece_ids']
            : [];

        $ids = array_values(array_filter(array_map(
            static fn (mixed $id): int => (int) $id,
            $pieceIds,
        ), static fn (int $id): bool => $id > 0));

        if ($ids === []) {
            return [];
        }

        return SeoMedia::query()
            ->whereIn('id', $ids)
            ->orderBy('id')
            ->get()
            ->map(static fn (SeoMedia $piece): array => [
                'id' => (int) $piece->id,
                'url' => $piece->publicUrl(),
            ])
            ->values()
            ->all();
    }

    private function extractRetryInput(SeoMedia $media): string
    {
        $variables = is_array($media->prompt_variables) ? $media->prompt_variables : [];
        if ($variables === []) {
            return '';
        }

        $preferredKeys = ['prompt', 'input', 'content', 'text', 'description', 'image_prompt'];
        foreach ($preferredKeys as $key) {
            $value = trim((string) ($variables[$key] ?? ''));
            if ($value !== '') {
                return $value;
            }
        }

        foreach ($variables as $value) {
            $value = trim((string) $value);
            if ($value !== '') {
                return $value;
            }
        }

        return '';
    }

    private function resolveSeoMediaForStoragePath(string $relativePath, ?int $siteId = null): ?SeoMedia
    {
        $relativePath = ltrim(str_replace('\\', '/', $relativePath), '/');
        if ($relativePath === '') {
            return null;
        }

        $query = SeoMedia::query()->where('path', $relativePath);
        if ($siteId !== null && $siteId > 0) {
            $query->where('site_id', $siteId);
        }

        $media = $query->first();
        if ($media instanceof SeoMedia) {
            return $media;
        }

        $filename = basename($relativePath);
        if ($filename === '' || $filename === '.' || $filename === '..') {
            return null;
        }

        $flatCandidate = 'uploads/seo_media/'.$filename;
        $flatQuery = SeoMedia::query()->where('path', $flatCandidate);
        if ($siteId !== null && $siteId > 0) {
            $flatQuery->where('site_id', $siteId);
        }

        $media = $flatQuery->first();
        if ($media instanceof SeoMedia) {
            return $media;
        }

        $filenameQuery = SeoMedia::query()->where('filename', $filename);
        if ($siteId !== null && $siteId > 0) {
            $filenameQuery->where('site_id', $siteId);
        }

        return $filenameQuery->orderByDesc('id')->first();
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array{editor_session_id: string|null, user: User|null}
     */
    private function editorSessionContext(Request $request, array $validated = []): array
    {
        $sessionId = trim((string) (
            $validated['editor_session_id']
            ?? $request->input('editor_session_id')
            ?? $request->header('X-Editor-Session-Id')
            ?? ''
        ));
        $user = $request->user();

        return [
            'editor_session_id' => $sessionId !== '' ? $sessionId : null,
            'user' => $user instanceof User ? $user : null,
        ];
    }

    private function editorSessionLockedResponse(ArticleEditorSessionException $exception): JsonResponse
    {
        return response()->json([
            'success' => false,
            'error' => $exception->errorCode,
            'code' => $exception->errorCode,
            'message' => $exception->getMessage(),
            'lock' => $exception->context['lock'] ?? null,
        ], $exception->httpStatus);
    }

    private function canAccessMedia(SeoMedia $media): bool
    {
        $articleId = $media->firstArticleId();
        if ($articleId !== null) {
            $article = SeoArticle::query()->find($articleId);

            return $article !== null && SeoAccessControl::canAccessArticle($article);
        }

        if ($media->site_id !== null) {
            return $this->canAccessSite((int) $media->site_id);
        }

        return auth()->check();
    }

    private function canAccessSite(int $siteId): bool
    {
        return SeoAccessControl::canAccessSite($siteId);
    }

    private function resolveSplitSaveArticleId(?int $requestedArticleId, ?int $originalSeoMediaId): ?int
    {
        if ($requestedArticleId !== null && $requestedArticleId > 0) {
            if (SeoArticle::query()->whereKey($requestedArticleId)->exists()) {
                return $requestedArticleId;
            }
        }

        if ($originalSeoMediaId === null || $originalSeoMediaId <= 0) {
            return null;
        }

        $media = SeoMedia::query()->find($originalSeoMediaId);

        return $media?->firstArticleId();
    }
}
