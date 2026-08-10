<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Media\Services;


use Omnichannel\Addons\Seo\Services\SeoAnalyzerService;
use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\Media\Models\SeoImageOptimizationSetting;
use Omnichannel\Addons\Media\Support\SeoConvertedImageValidator;
use Omnichannel\Addons\Media\Support\SeoImagePipeline;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class SeoImageOptimizationService
{
    public const WORDPRESS_UPLOAD_FALLBACK_MAX_BYTES = 102400;

    public const WORDPRESS_UPLOAD_PORTRAIT_MIN_WIDTH = 1000;

    /**
     * Max long-edge (px) khi WebP/JPEG upload vẫn > 100KB — thử lần lượt nhỏ dần.
     *
     * @var list<int>
     */
    public const WORDPRESS_UPLOAD_MAX_EDGE_STEPS = [1920, 1280, 1024];

    public const LOG_WEBP_VALIDATION_FAILED = 'SEO_MEDIA_WEBP_VALIDATION_FAILED';

    public const LOG_WEBP_FALLBACK_ORIGINAL = 'SEO_MEDIA_WEBP_FALLBACK_ORIGINAL';

    public const LOG_SOURCE_DECODE_FAILED = 'SEO_MEDIA_SOURCE_DECODE_FAILED';

    public const LOG_SOURCE_VALIDATION_FAILED = 'SEO_MEDIA_SOURCE_VALIDATION_FAILED';

    public const LOG_WORKING_CANVAS_INVALID = 'SEO_MEDIA_WORKING_CANVAS_INVALID';

    public const LOG_JPEG_VALIDATION_FAILED = 'SEO_MEDIA_JPEG_VALIDATION_FAILED';

    public const LOG_CONTENT_COLLAPSED = 'SEO_MEDIA_CONTENT_COLLAPSED';

    public const LOG_FALLBACK_ORIGINAL = 'SEO_MEDIA_FALLBACK_ORIGINAL';

    public const LOG_FALLBACK_FROM_ORIGINAL = 'SEO_MEDIA_FALLBACK_FROM_ORIGINAL';

    public const LOG_FALLBACK_COMPRESSED = 'SEO_MEDIA_FALLBACK_COMPRESSED';

    public const LOG_FALLBACK_OVER_TARGET_SIZE = 'SEO_MEDIA_FALLBACK_OVER_TARGET_SIZE';

    public const LOG_SYNC_CONTINUED_WITH_FALLBACK = 'SEO_MEDIA_SYNC_CONTINUED_WITH_FALLBACK';

    public const LOG_WORDPRESS_UPLOAD_BLOCKED = 'SEO_MEDIA_WORDPRESS_UPLOAD_BLOCKED';

    public const LOG_REGENERATED_FROM_ORIGINAL = 'SEO_MEDIA_REGENERATED_FROM_ORIGINAL';

    public function __construct(
        private readonly SeoAnalyzerService $seoAnalyzer,
        private readonly SeoMediaPathAllocator $mediaPaths,
        private readonly SeoImagePipeline $imagePipeline,
        private readonly SeoConvertedImageValidator $convertedImageValidator = new SeoConvertedImageValidator,
    ) {}

    public function resolveForSite(?int $siteId): SeoImageOptimizationSetting
    {
        if ($siteId !== null) {
            $siteSetting = SeoImageOptimizationSetting::query()
                ->where('site_id', $siteId)
                ->first();

            if ($siteSetting instanceof SeoImageOptimizationSetting) {
                return $siteSetting;
            }
        }

        $global = SeoImageOptimizationSetting::query()
            ->whereNull('site_id')
            ->first();

        return $global ?? new SeoImageOptimizationSetting;
    }

    /**
     * @return array{slug: string, filename: string, relative_path: string, alt_text: string, binary: string}
     */
    public function processUpload(
        UploadedFile $file,
        SeoImageOptimizationSetting $config,
        ?SeoArticle $article = null,
        bool $randomFilename = false,
    ): array {
        // Clipboard OS thường gửi tên cố định (image.png) — random tránh URL trùng → browser cache ảnh cũ.
        $originalName = $randomFilename
            ? 'paste-'.bin2hex(random_bytes(8))
            : pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME);
        $clientExtension = strtolower($file->getClientOriginalExtension() ?: $file->extension() ?: 'png');

        if ($config->clean_filename) {
            $slug = Str::slug($originalName);
        } else {
            $slug = Str::slug($originalName) !== ''
                ? Str::slug($originalName)
                : 'img-'.time();
        }

        if ($slug === '') {
            $slug = 'img-'.time();
        }

        $sourcePath = $file->getRealPath();
        if ($sourcePath === false || ! is_file($sourcePath)) {
            logger()->warning(self::LOG_SOURCE_DECODE_FAILED, [
                'reason' => 'upload_unreadable',
                'client_extension' => $clientExtension,
            ]);
            throw new \RuntimeException('Không đọc được file upload.');
        }

        $originalBytes = file_get_contents($sourcePath);
        if (! is_string($originalBytes) || $originalBytes === '') {
            logger()->warning(self::LOG_SOURCE_DECODE_FAILED, [
                'reason' => 'upload_empty',
                'client_extension' => $clientExtension,
            ]);
            throw new \RuntimeException('File upload rỗng.');
        }

        return $this->processOriginalBytes(
            $originalBytes,
            $clientExtension,
            $config,
            $article,
            $slug,
        );
    }

    public function buildAltText(
        SeoImageOptimizationSetting $config,
        ?SeoArticle $article,
        string $fallbackSlug,
    ): string {
        if (! $config->auto_alt_tag || $article === null) {
            return $fallbackSlug;
        }

        $pattern = (string) ($config->alt_tag_pattern ?? '{post_title}');
        $focusKeyword = $this->seoAnalyzer->resolveFocusKeywordForArticle($article) ?? '';
        $title = trim((string) ($article->title ?? ''));

        $altText = str_replace(
            ['{post_title}', '{focus_keyword}'],
            [$title, $focusKeyword],
            $pattern,
        );

        $altText = trim(preg_replace('/\s*-\s*$/', '', trim($altText, " \t\n\r\0\x0B-")));

        return $altText !== '' ? $altText : $fallbackSlug;
    }

    private function normalizeExtension(string $extension): string
    {
        $extension = strtolower($extension);
        if ($extension === 'jpeg') {
            return 'jpg';
        }

        if (! in_array($extension, ['jpg', 'png', 'gif', 'webp'], true)) {
            return 'png';
        }

        return $extension;
    }

    public function isWebpPath(string $path): bool
    {
        return strtolower(pathinfo($path, PATHINFO_EXTENSION)) === 'webp';
    }

    public function isWebpUrl(string $url): bool
    {
        $url = trim($url);
        if ($url === '') {
            return false;
        }

        $path = parse_url($url, PHP_URL_PATH);

        return is_string($path) && $this->isWebpPath($path);
    }

    public function canEncodeWebp(): bool
    {
        if (extension_loaded('imagick')) {
            try {
                return in_array('WEBP', \Imagick::queryFormats(), true);
            } catch (\Throwable) {
                // fall through
            }
        }

        return function_exists('imagewebp');
    }

    /**
     * Ảnh đã có trên WordPress nhưng URL chưa .webp — chỉ khi bản WebP local thật sự dùng được.
     * Không ép backfill khi đã fallback JPEG tối ưu (tránh import trùng attachment).
     */
    public function needsWordPressWebpBackfill(
        SeoImageOptimizationSetting $config,
        string $absolutePath,
        ?string $existingWpUrl = null,
    ): bool {
        if (! (bool) $config->auto_convert_webp) {
            return false;
        }

        if (! is_file($absolutePath) || ! $this->isValidImageFile($absolutePath)) {
            return false;
        }

        if ($existingWpUrl !== null && $existingWpUrl !== '' && $this->isWebpUrl($existingWpUrl)) {
            return false;
        }

        if ($this->hasPersistentOptimizedUploadFallback($absolutePath)) {
            return false;
        }

        if ($this->isWebpPath($absolutePath)) {
            return false;
        }

        return $this->hasUsableLocalWebpCopy($absolutePath);
    }

    public function hasPersistentOptimizedUploadFallback(string $absolutePath): bool
    {
        $sourceMtime = @filemtime($absolutePath) ?: 0;
        foreach (['jpg', 'png', 'gif', 'webp'] as $ext) {
            $optimizedPath = $this->resolveSiblingOptimizedUploadAbsolutePath($absolutePath, $ext);
            if (
                is_file($optimizedPath)
                && (@filemtime($optimizedPath) ?: 0) >= $sourceMtime
                && $this->isValidImageFile($optimizedPath)
            ) {
                $validation = $this->convertedImageValidator->validate($optimizedPath, [
                    'source_path' => $absolutePath,
                ]);
                if ($validation['ok']) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Chẩn đoán media local: recoverable nếu còn source hợp lệ để regenerate.
     *
     * @return array{
     *     recoverable: bool,
     *     reason: string,
     *     local_path: string,
     *     local_ok: bool,
     *     local_signature: array<string, int|float|bool>|null,
     *     sibling_webp: array{path: string, ok: bool, signature: array<string, int|float|bool>|null}|null,
     *     optimized_upload: array{path: string, ok: bool, signature: array<string, int|float|bool>|null}|null
     * }
     */
    public function diagnoseLocalMedia(string $absolutePath): array
    {
        $localValidation = is_file($absolutePath)
            ? $this->convertedImageValidator->validateSource($absolutePath)
            : ['ok' => false, 'reason' => SeoConvertedImageValidator::REASON_MISSING, 'signature' => null];

        $webpPath = $this->resolveSiblingWebpAbsolutePath($absolutePath);
        $siblingWebp = null;
        if (is_file($webpPath)) {
            $webpValidation = $this->convertedImageValidator->validate($webpPath, [
                'source_path' => is_file($absolutePath) ? $absolutePath : null,
            ]);
            $siblingWebp = [
                'path' => $webpPath,
                'ok' => $webpValidation['ok'],
                'signature' => $webpValidation['signature'] ?? null,
                'reason' => $webpValidation['reason'] ?? '',
            ];
        }

        $optimizedUpload = null;
        foreach (['jpg', 'png'] as $ext) {
            $opt = $this->resolveSiblingOptimizedUploadAbsolutePath($absolutePath, $ext);
            if (! is_file($opt)) {
                continue;
            }
            $optValidation = $this->convertedImageValidator->validate($opt, [
                'source_path' => is_file($absolutePath) ? $absolutePath : null,
            ]);
            $optimizedUpload = [
                'path' => $opt,
                'ok' => $optValidation['ok'],
                'signature' => $optValidation['signature'] ?? null,
                'reason' => $optValidation['reason'] ?? '',
            ];
            break;
        }

        $recoverable = (bool) ($localValidation['ok'] ?? false);

        return [
            'recoverable' => $recoverable,
            'reason' => $recoverable
                ? 'local_source_valid_can_regenerate'
                : 'unrecoverable_local_source_invalid_reupload_required',
            'local_path' => $absolutePath,
            'local_ok' => (bool) ($localValidation['ok'] ?? false),
            'local_signature' => $localValidation['signature'] ?? null,
            'sibling_webp' => $siblingWebp,
            'optimized_upload' => $optimizedUpload,
        ];
    }

    /**
     * Regenerate sibling WebP/optimized từ original hợp lệ.
     *
     * @return array{ok: bool, webp_path: ?string, optimized_path: ?string, message: string}
     */
    public function regenerateOptimizedFromOriginal(
        string $absolutePath,
        SeoImageOptimizationSetting $config,
    ): array {
        $diagnosis = $this->diagnoseLocalMedia($absolutePath);
        if (! $diagnosis['recoverable']) {
            return [
                'ok' => false,
                'webp_path' => null,
                'optimized_path' => null,
                'message' => $diagnosis['reason'],
            ];
        }

        $webpPath = null;
        if ((bool) $config->auto_convert_webp) {
            $sibling = $this->resolveSiblingWebpAbsolutePath($absolutePath);
            if (is_file($sibling)) {
                @unlink($sibling);
            }
            $webpPath = $this->ensureLocalWebpCopy($absolutePath, $config);
        }

        foreach (['jpg', 'png', 'gif', 'webp'] as $ext) {
            $opt = $this->resolveSiblingOptimizedUploadAbsolutePath($absolutePath, $ext);
            if (is_file($opt)) {
                @unlink($opt);
            }
        }

        $optimizedPath = null;
        if ((int) filesize($absolutePath) > self::WORDPRESS_UPLOAD_FALLBACK_MAX_BYTES) {
            $optimizedPath = $this->ensureLocalOptimizedUploadCopy($absolutePath, $config);
        }

        logger()->info(self::LOG_REGENERATED_FROM_ORIGINAL, [
            'path' => $absolutePath,
            'webp_path' => $webpPath,
            'optimized_path' => $optimizedPath,
            'source_signature' => $diagnosis['local_signature'],
            'driver' => $this->imagePipeline->lastDriver(),
        ]);

        return [
            'ok' => true,
            'webp_path' => $webpPath,
            'optimized_path' => $optimizedPath,
            'message' => 'regenerated_from_original',
        ];
    }

    private function hasUsableLocalWebpCopy(string $absolutePath): bool
    {
        if (! $this->canEncodeWebp()) {
            return false;
        }

        $webpPath = $this->resolveSiblingWebpAbsolutePath($absolutePath);
        $sourceMtime = @filemtime($absolutePath) ?: 0;
        if (! is_file($webpPath) || (@filemtime($webpPath) ?: 0) < $sourceMtime) {
            return false;
        }

        return $this->isUsableWebpFile($webpPath, $absolutePath);
    }

    /**
     * WebP decode được, không toàn alpha=0 / canvas rỗng so với nguồn.
     */
    public function isUsableWebpFile(string $webpPath, ?string $sourcePath = null): bool
    {
        if (! $this->isWebpPath($webpPath)) {
            return false;
        }

        $meta = [];
        if ($sourcePath !== null && is_file($sourcePath)) {
            $meta['source_path'] = $sourcePath;
        }

        return $this->convertedImageValidator->validate($webpPath, $meta)['ok'];
    }

    /**
     * @param  array{expected_width?: int, expected_height?: int, source_path?: string, source_bytes?: string}|null  $sourceMetadata
     * @return array{ok: bool, reason: string, width: int, height: int, bytes: int}
     */
    public function validateConvertedImage(string $path, ?array $sourceMetadata = null): array
    {
        return $this->convertedImageValidator->validate($path, $sourceMetadata);
    }

    /**
     * Tối ưu file trên đĩa Laravel (resize, nén) — giữ nguyên định dạng, không chuyển WebP.
     *
     * @return array{applied: bool, absolute_path: string}
     */
    public function optimizeAbsolutePath(string $absolutePath, SeoImageOptimizationSetting $config): array
    {
        if (! is_file($absolutePath) || $this->isWebpPath($absolutePath)) {
            return ['applied' => false, 'absolute_path' => $absolutePath];
        }

        try {
            $encoded = $this->encodeOptimizedImage($absolutePath, $config, false);
        } catch (\Throwable) {
            return ['applied' => false, 'absolute_path' => $absolutePath];
        }

        if ($encoded === null) {
            return ['applied' => false, 'absolute_path' => $absolutePath];
        }

        $currentExtension = strtolower(pathinfo($absolutePath, PATHINFO_EXTENSION) ?: 'jpg');
        if ($currentExtension === 'jpeg') {
            $currentExtension = 'jpg';
        }

        $targetExtension = $this->normalizeExtension($currentExtension);

        $directory = dirname($absolutePath);
        $basename = pathinfo($absolutePath, PATHINFO_FILENAME);
        $newAbsolutePath = $directory.DIRECTORY_SEPARATOR.$basename.'.'.$targetExtension;

        file_put_contents($newAbsolutePath, $encoded);

        if ($newAbsolutePath !== $absolutePath && is_file($absolutePath)) {
            @unlink($absolutePath);
        }

        return ['applied' => true, 'absolute_path' => $newAbsolutePath];
    }

    /**
     * Tạo (hoặc tái sử dụng) bản WebP cạnh file gốc trên disk Laravel — không đổi file PNG/JPG gốc.
     */
    public function ensureLocalWebpCopy(string $absolutePath, SeoImageOptimizationSetting $config): ?string
    {
        if (! (bool) $config->auto_convert_webp) {
            return null;
        }

        if ($this->isWebpPath($absolutePath)) {
            return $this->isUsableWebpFile($absolutePath) ? $absolutePath : null;
        }

        if (! $this->canEncodeWebp() || ! $this->isValidImageFile($absolutePath)) {
            return null;
        }

        $webpPath = $this->resolveSiblingWebpAbsolutePath($absolutePath);
        $sourceMtime = @filemtime($absolutePath) ?: 0;
        if (
            is_file($webpPath)
            && (@filemtime($webpPath) ?: 0) >= $sourceMtime
            && $this->isUsableWebpFile($webpPath, $absolutePath)
        ) {
            return $webpPath;
        }

        if (is_file($webpPath) && ! $this->isUsableWebpFile($webpPath, $absolutePath)) {
            @unlink($webpPath);
        }

        $sourceTemp = $this->copyToTempWorkspace($absolutePath);
        if ($sourceTemp === null) {
            return null;
        }

        $outputTemp = $this->createTempImagePath('wp-webp', 'webp');
        $quality = max(10, min(100, (int) $config->quality));
        $sourceSize = @getimagesize($absolutePath);
        $sourceBytes = (int) filesize($absolutePath);

        try {
            $this->applyConfiguredDimensionLimitsToPath($sourceTemp, $config);
            $expected = @getimagesize($sourceTemp);
            $expectedWidth = is_array($expected) ? (int) ($expected[0] ?? 0) : 0;
            $expectedHeight = is_array($expected) ? (int) ($expected[1] ?? 0) : 0;

            if (! $this->imagePipeline->encodeSourceToPath($sourceTemp, $outputTemp, 'webp', $quality)) {
                logger()->warning(self::LOG_WEBP_VALIDATION_FAILED, [
                    'reason' => 'encode_failed',
                    'path' => $absolutePath,
                    'source_mime' => $this->mimeFromPath($absolutePath),
                    'source_bytes' => $sourceBytes,
                    'source_width' => is_array($sourceSize) ? (int) ($sourceSize[0] ?? 0) : 0,
                    'source_height' => is_array($sourceSize) ? (int) ($sourceSize[1] ?? 0) : 0,
                    'driver' => $this->imagePipeline->lastDriver(),
                    'can_encode_webp' => $this->canEncodeWebp(),
                ]);

                return null;
            }

            $validation = $this->convertedImageValidator->validate($outputTemp, [
                'expected_width' => $expectedWidth,
                'expected_height' => $expectedHeight,
                'source_path' => $absolutePath,
            ]);

            if (! $validation['ok']) {
                $logCode = match ($validation['reason']) {
                    SeoConvertedImageValidator::REASON_CONTENT_COLLAPSED,
                    SeoConvertedImageValidator::REASON_CONTENT_COLLAPSED_UNIFORM => self::LOG_CONTENT_COLLAPSED,
                    default => self::LOG_WEBP_VALIDATION_FAILED,
                };
                logger()->warning($logCode, [
                    'reason' => $validation['reason'],
                    'path' => $absolutePath,
                    'source_mime' => $this->mimeFromPath($absolutePath),
                    'source_bytes' => $sourceBytes,
                    'source_width' => is_array($sourceSize) ? (int) ($sourceSize[0] ?? 0) : 0,
                    'source_height' => is_array($sourceSize) ? (int) ($sourceSize[1] ?? 0) : 0,
                    'destination_bytes' => $validation['bytes'],
                    'destination_width' => $validation['width'],
                    'destination_height' => $validation['height'],
                    'signature' => $validation['signature'] ?? null,
                    'source_signature' => $validation['source_signature'] ?? null,
                    'driver' => $this->imagePipeline->lastDriver(),
                    'stage' => 'ensureLocalWebpCopy',
                ]);

                return null;
            }

            $directory = dirname($webpPath);
            if (! is_dir($directory) && ! @mkdir($directory, 0755, true) && ! is_dir($directory)) {
                return null;
            }

            // Atomic-ish: copy validated temp → sibling, rồi mới xóa temp trong finally.
            if (! @copy($outputTemp, $webpPath) && @file_put_contents($webpPath, (string) file_get_contents($outputTemp)) === false) {
                return null;
            }

            return $webpPath;
        } catch (\Throwable $exception) {
            logger()->warning(self::LOG_WEBP_VALIDATION_FAILED, [
                'reason' => 'exception',
                'path' => $absolutePath,
                'message' => $exception->getMessage(),
                'driver' => $this->imagePipeline->lastDriver(),
            ]);

            return null;
        } finally {
            if (is_file($sourceTemp)) {
                @unlink($sourceTemp);
            }
            if (is_file($outputTemp)) {
                @unlink($outputTemp);
            }
        }
    }

    public function resolveSiblingWebpAbsolutePath(string $absolutePath): string
    {
        $directory = dirname($absolutePath);
        $basename = pathinfo($absolutePath, PATHINFO_FILENAME);

        return $directory.DIRECTORY_SEPARATOR.$basename.'.webp';
    }

    public function resolveSiblingWebpRelativePath(string $relativePath): string
    {
        $relativePath = ltrim(str_replace('\\', '/', $relativePath), '/');
        $directory = pathinfo($relativePath, PATHINFO_DIRNAME);
        $basename = pathinfo($relativePath, PATHINFO_FILENAME);

        if ($directory === '' || $directory === '.') {
            return $basename.'.webp';
        }

        return $directory.'/'.$basename.'.webp';
    }

    /**
     * Ép sibling WebP (hoặc `-wp-upload.webp` nếu nguồn đã là WebP) xuống ≤ maxBytes
     * bằng ladder long-edge: 1920 → 1280 → 1024 → 800 → 640 → 480, rồi giảm quality.
     */
    public function ensureLocalWebpUnderMaxBytes(
        string $absolutePath,
        SeoImageOptimizationSetting $config,
        int $maxBytes = self::WORDPRESS_UPLOAD_FALLBACK_MAX_BYTES,
    ): ?string {
        if (! $this->canEncodeWebp() || ! is_file($absolutePath) || ! $this->isValidImageFile($absolutePath)) {
            return null;
        }

        $targetPath = $this->isWebpPath($absolutePath)
            ? $this->resolveSiblingOptimizedUploadAbsolutePath($absolutePath, 'webp')
            : $this->resolveSiblingWebpAbsolutePath($absolutePath);
        $sourceMtime = @filemtime($absolutePath) ?: 0;

        if (
            is_file($targetPath)
            && (@filemtime($targetPath) ?: 0) >= $sourceMtime
            && $this->isUsableWebpFile($targetPath, $absolutePath)
            && (int) filesize($targetPath) <= $maxBytes
        ) {
            return $targetPath;
        }

        if (is_file($targetPath) && ! $this->isUsableWebpFile($targetPath, $absolutePath)) {
            @unlink($targetPath);
        }

        $qualityBase = max(15, min(100, (int) $config->quality));
        $edgeSteps = $this->resolveWordPressUploadEdgeSteps($absolutePath);

        foreach ($edgeSteps as $maxEdge) {
            $written = $this->encodeWordPressWebpCandidate(
                $absolutePath,
                $config,
                $targetPath,
                $maxEdge,
                $qualityBase,
                $maxBytes,
            );
            if ($written !== null) {
                return $written;
            }
        }

        $lastEdge = $edgeSteps[array_key_last($edgeSteps)] ?? null;
        if ($lastEdge === null) {
            return null;
        }

        for ($quality = max(15, $qualityBase - 10); $quality >= 15; $quality -= 10) {
            $written = $this->encodeWordPressWebpCandidate(
                $absolutePath,
                $config,
                $targetPath,
                $lastEdge,
                $quality,
                $maxBytes,
            );
            if ($written !== null) {
                return $written;
            }
        }

        return null;
    }

    /**
     * Bản tối ưu cạnh file gốc (ưu tiên format gốc; JPEG khi cần).
     * Fresh-decode từ $absolutePath. Cố gắng ≤ maxBytes; nếu không đạt vẫn trả bản nhỏ nhất hợp lệ.
     */
    public function ensureLocalOptimizedUploadCopy(
        string $absolutePath,
        SeoImageOptimizationSetting $config,
        int $maxBytes = self::WORDPRESS_UPLOAD_FALLBACK_MAX_BYTES,
    ): ?string {
        if (! is_file($absolutePath) || ! $this->isValidImageFile($absolutePath)) {
            return null;
        }

        $preferredExtension = $this->resolveOptimizedUploadTargetExtension($absolutePath);
        $extensionsToTry = [$preferredExtension];
        if ($preferredExtension !== 'jpg') {
            $extensionsToTry[] = 'jpg';
        }

        $bestPath = null;
        $bestBytes = PHP_INT_MAX;

        foreach ($extensionsToTry as $targetExtension) {
            $optimizedPath = $this->resolveSiblingOptimizedUploadAbsolutePath($absolutePath, $targetExtension);
            $sourceMtime = @filemtime($absolutePath) ?: 0;

            if (
                is_file($optimizedPath)
                && (@filemtime($optimizedPath) ?: 0) >= $sourceMtime
                && $this->isValidImageFile($optimizedPath)
            ) {
                $cachedValidation = $this->convertedImageValidator->validate($optimizedPath, [
                    'source_path' => $absolutePath,
                ]);
                if ($cachedValidation['ok']) {
                    $cachedBytes = (int) filesize($optimizedPath);
                    if ($cachedBytes <= $maxBytes) {
                        return $optimizedPath;
                    }
                    if ($cachedBytes < $bestBytes) {
                        $bestPath = $optimizedPath;
                        $bestBytes = $cachedBytes;
                    }
                } else {
                    @unlink($optimizedPath);
                }
            }

            $qualityBase = max(15, min(100, (int) $config->quality));
            $edgeSteps = $this->resolveWordPressUploadEdgeSteps($absolutePath);
            $qualities = [$qualityBase];
            for ($q = max(15, $qualityBase - 6); $q >= 15; $q -= 6) {
                $qualities[] = $q;
            }

            foreach ($edgeSteps as $maxEdge) {
                foreach ($qualities as $quality) {
                    $written = $this->encodeWordPressUploadCandidate(
                        $absolutePath,
                        $config,
                        $optimizedPath,
                        $targetExtension,
                        $maxEdge,
                        $quality,
                        $maxBytes,
                        enforceMaxBytes: false,
                    );
                    if ($written === null) {
                        continue;
                    }

                    $bytes = (int) filesize($written);
                    $protectWidth = $this->shouldProtectPortraitUploadWidth($absolutePath);
                    if ($protectWidth && $this->candidateWidthAtLeast($written, self::WORDPRESS_UPLOAD_PORTRAIT_MIN_WIDTH)) {
                        if ($bytes > $maxBytes) {
                            logger()->warning(self::LOG_FALLBACK_OVER_TARGET_SIZE, [
                                'path' => $absolutePath,
                                'optimized_path' => $written,
                                'bytes' => $bytes,
                                'max_bytes' => $maxBytes,
                                'reason' => 'portrait_width_protected',
                                'target' => $targetExtension,
                                'max_edge' => $maxEdge,
                                'quality' => $quality,
                            ]);
                        }

                        return $written;
                    }

                    if ($protectWidth && ! $this->candidateWidthAtLeast($written, self::WORDPRESS_UPLOAD_PORTRAIT_MIN_WIDTH)) {
                        continue;
                    }

                    if ($bytes <= $maxBytes) {
                        logger()->info(self::LOG_FALLBACK_COMPRESSED, [
                            'path' => $absolutePath,
                            'optimized_path' => $written,
                            'bytes' => $bytes,
                            'max_bytes' => $maxBytes,
                            'target' => $targetExtension,
                            'max_edge' => $maxEdge,
                            'quality' => $quality,
                        ]);

                        return $written;
                    }

                    if ($bytes < $bestBytes) {
                        $bestPath = $written;
                        $bestBytes = $bytes;
                    }
                }
            }
        }

        if ($bestPath !== null) {
            logger()->warning(self::LOG_FALLBACK_OVER_TARGET_SIZE, [
                'path' => $absolutePath,
                'optimized_path' => $bestPath,
                'bytes' => $bestBytes,
                'max_bytes' => $maxBytes,
            ]);

            return $bestPath;
        }

        return null;
    }

    public function resolveSiblingOptimizedUploadAbsolutePath(string $absolutePath, string $targetExtension): string
    {
        $directory = dirname($absolutePath);
        $basename = pathinfo($absolutePath, PATHINFO_FILENAME);
        $targetExtension = $this->normalizeExtension($targetExtension);

        return $directory.DIRECTORY_SEPARATOR.$basename.'-wp-upload.'.$targetExtension;
    }

    private function resolveOptimizedUploadTargetExtension(string $absolutePath): string
    {
        $extension = strtolower(pathinfo($absolutePath, PATHINFO_EXTENSION) ?: 'jpg');
        if ($extension === 'jpeg') {
            $extension = 'jpg';
        }

        // Ưu tiên giữ format gốc — không ép mọi fallback thành JPEG.
        if (in_array($extension, ['png', 'gif', 'jpg', 'webp'], true)) {
            return $extension === 'webp' ? 'png' : $extension;
        }

        return 'jpg';
    }

    /**
     * @return list<int>
     */
    private function resolveWordPressUploadEdgeSteps(string $absolutePath): array
    {
        $size = @getimagesize($absolutePath);
        $width = is_array($size) ? (int) ($size[0] ?? 0) : 0;
        $height = is_array($size) ? (int) ($size[1] ?? 0) : 0;
        $longEdge = max($width, $height);

        $steps = [];
        if ($height > $width && $width >= self::WORDPRESS_UPLOAD_PORTRAIT_MIN_WIDTH) {
            $portraitWidthFloorEdge = (int) ceil($height * self::WORDPRESS_UPLOAD_PORTRAIT_MIN_WIDTH / $width);
            $portraitWidthFloorEdge = min($longEdge, max(1, $portraitWidthFloorEdge));
            $steps[] = $portraitWidthFloorEdge;
        }

        foreach (self::WORDPRESS_UPLOAD_MAX_EDGE_STEPS as $step) {
            if ($longEdge <= 0 || $step < $longEdge) {
                $steps[] = $step;
            }
        }

        if ($steps === [] && $longEdge > 0) {
            $steps[] = self::WORDPRESS_UPLOAD_MAX_EDGE_STEPS[array_key_last(self::WORDPRESS_UPLOAD_MAX_EDGE_STEPS)];
        }

        return array_values(array_unique($steps));
    }

    private function applyMaxLongEdgeToPath(string $absolutePath, int $maxEdge): void
    {
        if ($maxEdge <= 0 || ! is_file($absolutePath)) {
            return;
        }

        $size = @getimagesize($absolutePath);
        if (! is_array($size)) {
            return;
        }

        $width = (int) ($size[0] ?? 0);
        $height = (int) ($size[1] ?? 0);
        if ($width <= 0 || $height <= 0) {
            return;
        }

        $longEdge = max($width, $height);
        if ($longEdge <= $maxEdge) {
            return;
        }

        if ($width >= $height) {
            $this->imagePipeline->applyMaxDimensions($absolutePath, $maxEdge, 0, true, false);

            return;
        }

        $this->imagePipeline->applyMaxDimensions($absolutePath, 0, $maxEdge, false, true);
    }

    private function shouldProtectPortraitUploadWidth(string $absolutePath): bool
    {
        $size = @getimagesize($absolutePath);
        if (! is_array($size)) {
            return false;
        }

        $width = (int) ($size[0] ?? 0);
        $height = (int) ($size[1] ?? 0);

        return $width > 0
            && $height > $width
            && $width >= self::WORDPRESS_UPLOAD_PORTRAIT_MIN_WIDTH;
    }

    private function candidateWidthAtLeast(string $absolutePath, int $minWidth): bool
    {
        $size = @getimagesize($absolutePath);
        if (! is_array($size)) {
            return false;
        }

        return (int) ($size[0] ?? 0) >= $minWidth;
    }

    private function persistTempImageToPath(string $tempPath, string $destinationPath): bool
    {
        $directory = dirname($destinationPath);
        if (! is_dir($directory) && ! @mkdir($directory, 0755, true) && ! is_dir($directory)) {
            return false;
        }

        return @copy($tempPath, $destinationPath)
            || @file_put_contents($destinationPath, (string) file_get_contents($tempPath)) !== false;
    }

    private function encodeWordPressWebpCandidate(
        string $absolutePath,
        SeoImageOptimizationSetting $config,
        string $targetPath,
        int $maxEdge,
        int $quality,
        int $maxBytes,
    ): ?string {
        $sourceTemp = $this->copyToTempWorkspace($absolutePath);
        if ($sourceTemp === null) {
            return null;
        }

        $outputTemp = $this->createTempImagePath('wp-webp-edge', 'webp');

        try {
            $this->applyConfiguredDimensionLimitsToPath($sourceTemp, $config);
            $this->applyMaxLongEdgeToPath($sourceTemp, $maxEdge);

            if (! $this->imagePipeline->encodeSourceToPath($sourceTemp, $outputTemp, 'webp', $quality)) {
                return null;
            }

            $expected = @getimagesize($sourceTemp);
            $validation = $this->convertedImageValidator->validate($outputTemp, [
                'expected_width' => is_array($expected) ? (int) ($expected[0] ?? 0) : 0,
                'expected_height' => is_array($expected) ? (int) ($expected[1] ?? 0) : 0,
                'source_path' => $absolutePath,
            ]);

            $protectWidth = $this->shouldProtectPortraitUploadWidth($absolutePath)
                && $this->candidateWidthAtLeast($outputTemp, self::WORDPRESS_UPLOAD_PORTRAIT_MIN_WIDTH);

            if (! $validation['ok'] || ($validation['bytes'] > $maxBytes && ! $protectWidth)) {
                if (! $validation['ok']) {
                    logger()->warning(self::LOG_WEBP_VALIDATION_FAILED, [
                        'reason' => $validation['reason'],
                        'path' => $absolutePath,
                        'max_edge' => $maxEdge,
                        'quality' => $quality,
                        'destination_bytes' => $validation['bytes'],
                        'driver' => $this->imagePipeline->lastDriver(),
                    ]);
                }

                return null;
            }

            if ($validation['bytes'] > $maxBytes) {
                logger()->warning(self::LOG_FALLBACK_OVER_TARGET_SIZE, [
                    'path' => $absolutePath,
                    'webp_path' => $targetPath,
                    'bytes' => $validation['bytes'],
                    'max_bytes' => $maxBytes,
                    'reason' => 'portrait_width_protected',
                    'max_edge' => $maxEdge,
                    'quality' => $quality,
                ]);
            }

            if (! $this->persistTempImageToPath($outputTemp, $targetPath)) {
                return null;
            }

            logger()->info('WordPress upload WebP: đã ép long-edge dưới ngưỡng.', [
                'path' => $absolutePath,
                'webp_path' => $targetPath,
                'bytes' => (int) filesize($targetPath),
                'max_bytes' => $maxBytes,
                'max_edge' => $maxEdge,
                'quality' => $quality,
            ]);

            return $targetPath;
        } finally {
            if (is_file($sourceTemp)) {
                @unlink($sourceTemp);
            }
            if (is_file($outputTemp)) {
                @unlink($outputTemp);
            }
        }
    }

    /**
     * Fresh decode từ original path → resize → encode target → validate content.
     * Không nhận WebP canvas lỗi làm input.
     */
    private function encodeWordPressUploadCandidate(
        string $absolutePath,
        SeoImageOptimizationSetting $config,
        string $optimizedPath,
        string $targetExtension,
        int $maxEdge,
        int $quality,
        int $maxBytes,
        bool $enforceMaxBytes = true,
    ): ?string {
        $sourceTemp = $this->copyToTempWorkspace($absolutePath);
        if ($sourceTemp === null) {
            return null;
        }

        $outputTemp = $this->createTempImagePath('wp-opt', $targetExtension);
        $sourceSignature = $this->convertedImageValidator->signatureFromPath($absolutePath);

        try {
            if (! $this->isValidImageFile($sourceTemp)) {
                return null;
            }

            $this->applyConfiguredDimensionLimitsToPath($sourceTemp, $config);
            $this->applyMaxLongEdgeToPath($sourceTemp, $maxEdge);

            if (! $this->isValidImageFile($sourceTemp)) {
                return null;
            }

            if (! $this->imagePipeline->encodeSourceToPath($sourceTemp, $outputTemp, $targetExtension, $quality)) {
                return null;
            }

            $validation = $this->convertedImageValidator->validate($outputTemp, [
                'source_path' => $absolutePath,
                'source_signature' => $sourceSignature,
            ]);
            if (! $validation['ok']) {
                $logCode = match ($validation['reason'] ?? '') {
                    SeoConvertedImageValidator::REASON_CONTENT_COLLAPSED,
                    SeoConvertedImageValidator::REASON_CONTENT_COLLAPSED_UNIFORM => self::LOG_CONTENT_COLLAPSED,
                    default => $targetExtension === 'jpg'
                        ? self::LOG_JPEG_VALIDATION_FAILED
                        : self::LOG_WEBP_VALIDATION_FAILED,
                };
                logger()->warning($logCode, [
                    'reason' => $validation['reason'],
                    'path' => $absolutePath,
                    'target' => $targetExtension,
                    'stage' => 'upload_candidate_output',
                    'destination_bytes' => $validation['bytes'],
                    'driver' => $this->imagePipeline->lastDriver(),
                    'max_edge' => $maxEdge,
                    'quality' => $quality,
                ]);

                return null;
            }

            if ($enforceMaxBytes && $validation['bytes'] > $maxBytes) {
                return null;
            }

            if (! $this->persistTempImageToPath($outputTemp, $optimizedPath)) {
                return null;
            }

            return $optimizedPath;
        } finally {
            if (is_file($sourceTemp)) {
                @unlink($sourceTemp);
            }
            if (is_file($outputTemp)) {
                @unlink($outputTemp);
            }
        }
    }

    /** @deprecated Dùng encodeWordPressUploadCandidate */
    private function encodeWordPressJpegCandidate(
        string $absolutePath,
        SeoImageOptimizationSetting $config,
        string $optimizedPath,
        string $targetExtension,
        int $maxEdge,
        int $quality,
        int $maxBytes,
    ): ?string {
        return $this->encodeWordPressUploadCandidate(
            $absolutePath,
            $config,
            $optimizedPath,
            $targetExtension,
            $maxEdge,
            $quality,
            $maxBytes,
            enforceMaxBytes: false,
        );
    }

    /**
     * Chọn file upload WP: WebP ưu tiên → fallback original/compress → luôn sync nếu gốc decode được.
     * WebP fail KHÔNG chặn sync. Chỉ return null khi file gốc không đọc được.
     *
     * @return array{path: string, temporary: bool, mime: string}|null
     */
    public function prepareWordPressUploadFile(string $absolutePath, SeoImageOptimizationSetting $config): ?array
    {
        if (! is_file($absolutePath) || ! $this->isValidImageFile($absolutePath)) {
            logger()->warning(self::LOG_WORDPRESS_UPLOAD_BLOCKED, [
                'reason' => 'missing_or_undecodeable',
                'path' => $absolutePath,
                'stage' => 'prepareWordPressUploadFile',
            ]);

            return null;
        }

        $sourceMime = $this->mimeFromPath($absolutePath);

        if ((bool) $config->auto_convert_webp) {
            $webpPath = $this->ensureLocalWebpCopy($absolutePath, $config);

            if ($webpPath !== null && ! $this->isUsableWebpFile($webpPath, $absolutePath)) {
                logger()->warning(self::LOG_WEBP_VALIDATION_FAILED, [
                    'reason' => 'blank_or_invalid_webp',
                    'path' => $absolutePath,
                    'webp_path' => $webpPath,
                    'source_mime' => $sourceMime,
                    'stage' => 'prepareWordPressUploadFile',
                    'driver' => $this->imagePipeline->lastDriver(),
                ]);
                if (! $this->isWebpPath($absolutePath) && is_file($webpPath)) {
                    @unlink($webpPath);
                }
                $webpPath = null;
            }

            if ($webpPath !== null) {
                $webpBytes = (int) filesize($webpPath);
                if ($webpBytes > self::WORDPRESS_UPLOAD_FALLBACK_MAX_BYTES) {
                    $shrunk = $this->ensureLocalWebpUnderMaxBytes($absolutePath, $config);
                    if ($shrunk !== null && $this->isUsableWebpFile($shrunk, $absolutePath)) {
                        $webpPath = $shrunk;
                        $webpBytes = (int) filesize($webpPath);
                    }
                }

                if ($this->isUsableWebpFile($webpPath, $absolutePath)) {
                    if ($webpBytes > self::WORDPRESS_UPLOAD_FALLBACK_MAX_BYTES) {
                        logger()->warning(self::LOG_FALLBACK_OVER_TARGET_SIZE, [
                            'path' => $absolutePath,
                            'webp_path' => $webpPath,
                            'bytes' => $webpBytes,
                            'max_bytes' => self::WORDPRESS_UPLOAD_FALLBACK_MAX_BYTES,
                            'format' => 'webp',
                        ]);
                    }

                    return [
                        'path' => $webpPath,
                        'temporary' => false,
                        'mime' => 'image/webp',
                    ];
                }
            }

            logger()->warning(self::LOG_FALLBACK_FROM_ORIGINAL, [
                'code' => self::LOG_WEBP_FALLBACK_ORIGINAL,
                'path' => $absolutePath,
                'source_mime' => $sourceMime,
                'reason' => 'webp_unavailable_or_invalid',
                'can_encode_webp' => $this->canEncodeWebp(),
                'stage' => 'prepareWordPressUploadFile',
            ]);
        }

        // Fallback: ưu tiên file gốc nếu đã nhỏ; không thì compress fresh từ original.
        $originalBytes = (int) filesize($absolutePath);
        if ($originalBytes <= self::WORDPRESS_UPLOAD_FALLBACK_MAX_BYTES) {
            logger()->info(self::LOG_SYNC_CONTINUED_WITH_FALLBACK, [
                'path' => $absolutePath,
                'fallback_path' => $absolutePath,
                'mime' => $sourceMime,
                'bytes' => $originalBytes,
                'reason' => 'original_under_target',
            ]);

            return $this->fallbackWordPressUploadFile(
                $absolutePath,
                'WebP không dùng được; dùng file gốc ≤100KB.',
            );
        }

        $optimizedPath = $this->ensureLocalOptimizedUploadCopy($absolutePath, $config);
        if ($optimizedPath !== null && $this->isValidImageFile($optimizedPath)) {
            $optBytes = (int) filesize($optimizedPath);
            logger()->info(self::LOG_SYNC_CONTINUED_WITH_FALLBACK, [
                'path' => $absolutePath,
                'fallback_path' => $optimizedPath,
                'mime' => $this->mimeFromPath($optimizedPath),
                'bytes' => $optBytes,
                'reason' => 'compressed_from_original',
            ]);

            return [
                'path' => $optimizedPath,
                'temporary' => false,
                'mime' => $this->mimeFromPath($optimizedPath),
            ];
        }

        // Vẫn >100KB hoặc compress fail → sync file gốc (không chặn).
        logger()->warning(self::LOG_FALLBACK_OVER_TARGET_SIZE, [
            'path' => $absolutePath,
            'bytes' => $originalBytes,
            'max_bytes' => self::WORDPRESS_UPLOAD_FALLBACK_MAX_BYTES,
            'reason' => 'sync_original_despite_size',
        ]);
        logger()->info(self::LOG_SYNC_CONTINUED_WITH_FALLBACK, [
            'path' => $absolutePath,
            'fallback_path' => $absolutePath,
            'mime' => $sourceMime,
            'bytes' => $originalBytes,
            'reason' => 'original_over_target',
        ]);

        return $this->fallbackWordPressUploadFile(
            $absolutePath,
            'Dùng file gốc (compress không sẵn sàng hoặc vẫn lớn).',
        );
    }

    /**
     * @return array{path: string, temporary: bool, mime: string}
     */
    private function fallbackWordPressUploadFile(string $absolutePath, ?string $reason = null): array
    {
        if ($reason !== null) {
            logger()->warning($reason, ['path' => $absolutePath]);
        }

        return [
            'path' => $absolutePath,
            'temporary' => false,
            'mime' => $this->mimeFromPath($absolutePath),
        ];
    }

    private function copyToTempWorkspace(string $absolutePath): ?string
    {
        $extension = strtolower(pathinfo($absolutePath, PATHINFO_EXTENSION) ?: 'png');
        if ($extension === 'jpeg') {
            $extension = 'jpg';
        }

        $tempPath = $this->createTempImagePath('wp-source', $this->normalizeExtension($extension));
        if (! copy($absolutePath, $tempPath)) {
            return null;
        }

        return $tempPath;
    }

    private function createSystemTempPath(string $prefix, string $extension): ?string
    {
        $extension = $this->normalizeExtension($extension);
        $tempBase = tempnam(sys_get_temp_dir(), $prefix);
        if ($tempBase === false) {
            return null;
        }

        @unlink($tempBase);

        return $tempBase.'.'.$extension;
    }

    public function isValidImageFile(string $absolutePath): bool
    {
        if (! is_file($absolutePath)) {
            return false;
        }

        $size = (int) filesize($absolutePath);
        if ($size < 256) {
            return false;
        }

        $info = @getimagesize($absolutePath);

        return is_array($info) && (int) ($info[0] ?? 0) > 0 && (int) ($info[1] ?? 0) > 0;
    }

    /**
     * @return string|null Binary ảnh đã encode, null nếu thất bại.
     */
    private function encodeOptimizedImage(
        string $absolutePath,
        SeoImageOptimizationSetting $config,
        bool $convertToWebp,
    ): ?string {
        if (! is_file($absolutePath)) {
            return null;
        }

        $this->applyConfiguredDimensionLimitsToPath($absolutePath, $config);

        $currentExtension = strtolower(pathinfo($absolutePath, PATHINFO_EXTENSION) ?: 'png');
        if ($currentExtension === 'jpeg') {
            $currentExtension = 'jpg';
        }

        $targetExtension = $convertToWebp
            ? 'webp'
            : $this->normalizeExtension($currentExtension === 'webp' ? 'png' : $currentExtension);

        $quality = max(10, min(100, (int) $config->quality));
        if (! $this->imagePipeline->encodeFile($absolutePath, $targetExtension, $quality)) {
            return null;
        }

        $encoded = file_get_contents($absolutePath);

        return is_string($encoded) && strlen($encoded) >= 256 ? $encoded : null;
    }

    public function absoluteToPublicRelative(string $absolutePath): string
    {
        $publicRoot = Storage::disk('public')->path('');
        $normalized = str_replace('\\', '/', $absolutePath);
        $root = str_replace('\\', '/', $publicRoot);

        if (str_starts_with($normalized, $root)) {
            return ltrim(substr($normalized, strlen($root)), '/');
        }

        return ltrim(str_replace('\\', '/', $absolutePath), '/');
    }

    public function mimeFromPath(string $path): string
    {
        return match (strtolower(pathinfo($path, PATHINFO_EXTENSION))) {
            'png' => 'image/png',
            'gif' => 'image/gif',
            'webp' => 'image/webp',
            default => 'image/jpeg',
        };
    }

    /**
     * Giới hạn một chiều qua pipeline Imagick/GD (file trên đĩa).
     */
    private function applyConfiguredDimensionLimitsToPath(string $absolutePath, SeoImageOptimizationSetting $config): void
    {
        if (! $config->limit_dimensions || ! is_file($absolutePath)) {
            return;
        }

        $maxWidth = max(0, (int) $config->max_width);
        $maxHeight = max(0, (int) $config->max_height);

        $limitByWidth = $maxWidth > 0 && $maxHeight <= 0;
        $limitByHeight = $maxHeight > 0 && $maxWidth <= 0;

        if ($maxWidth > 0 && $maxHeight > 0) {
            $limitByWidth = true;
            $limitByHeight = false;
        }

        if (! $limitByWidth && ! $limitByHeight) {
            return;
        }

        $this->imagePipeline->applyMaxDimensions(
            $absolutePath,
            $maxWidth,
            $maxHeight,
            $limitByWidth,
            $limitByHeight,
        );
    }

    private function createTempImagePath(string $prefix, string $extension): string
    {
        $extension = $this->normalizeExtension($extension);
        $dir = storage_path('app/temp/seo-image-optimize');
        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        return $dir.DIRECTORY_SEPARATOR.$prefix.'-'.uniqid('', true).'.'.$extension;
    }

    /**
     * @return array{slug: string, filename: string, relative_path: string, alt_text: string, binary: string}
     */
    public function processBinary(
        string $binary,
        string $originalExtension,
        SeoImageOptimizationSetting $config,
        ?SeoArticle $article = null,
        ?string $slugSeed = null,
    ): array {
        $originalExtension = strtolower($originalExtension);
        if ($originalExtension === 'jpeg') {
            $originalExtension = 'jpg';
        }

        $seed = $slugSeed !== null && trim($slugSeed) !== ''
            ? Str::slug($slugSeed)
            : 'img-'.time();

        if ($config->clean_filename) {
            $slug = Str::slug($seed);
        } else {
            $slug = Str::slug($seed) !== '' ? Str::slug($seed) : 'img-'.time();
        }

        if ($slug === '') {
            $slug = 'img-'.time();
        }

        if ($binary === '') {
            logger()->warning(self::LOG_SOURCE_DECODE_FAILED, [
                'reason' => 'binary_empty',
                'client_extension' => $originalExtension,
            ]);
            throw new \RuntimeException('Binary ảnh rỗng.');
        }

        return $this->processOriginalBytes(
            $binary,
            $originalExtension,
            $config,
            $article,
            $slug,
        );
    }

    /**
     * Encode transactional: giữ original bytes → temp source → temp output → validate → atomic result.
     * Fail WebP/encode → fallback đúng MIME/extension gốc, không ghi bytes lạ vào .webp.
     *
     * @return array{slug: string, filename: string, relative_path: string, alt_text: string, binary: string}
     */
    private function processOriginalBytes(
        string $originalBytes,
        string $clientExtension,
        SeoImageOptimizationSetting $config,
        ?SeoArticle $article,
        string $slug,
    ): array {
        $detectedExtension = $this->convertedImageValidator->detectImageExtensionFromBytes($originalBytes)
            ?? $this->normalizeExtension($clientExtension);
        $extension = $this->normalizeExtension($detectedExtension);

        $sourceTemp = $this->createTempImagePath('src', $extension);
        if (@file_put_contents($sourceTemp, $originalBytes) === false) {
            throw new \RuntimeException('Không ghi được temp source ảnh.');
        }

        try {
            $sourceValidation = $this->convertedImageValidator->validate($sourceTemp);
            if (! $sourceValidation['ok']) {
                logger()->warning(self::LOG_SOURCE_DECODE_FAILED, [
                    'reason' => $sourceValidation['reason'],
                    'client_extension' => $clientExtension,
                    'detected_extension' => $extension,
                    'bytes' => strlen($originalBytes),
                    'driver' => $this->imagePipeline->lastDriver(),
                ]);
                throw new \RuntimeException('Ảnh nguồn không decode được hoặc bị hỏng — không lưu media.');
            }

            $workTemp = $this->createTempImagePath('work', $extension);
            $outputTemp = $this->createTempImagePath('out', $extension);

            try {
                if (! @copy($sourceTemp, $workTemp)) {
                    throw new \RuntimeException('Không sao chép được temp work ảnh.');
                }

                $this->applyConfiguredDimensionLimitsToPath($workTemp, $config);
                $expected = @getimagesize($workTemp);
                $expectedWidth = is_array($expected) ? (int) ($expected[0] ?? 0) : 0;
                $expectedHeight = is_array($expected) ? (int) ($expected[1] ?? 0) : 0;

                $quality = max(10, min(100, (int) $config->quality));
                $encodedOk = $this->imagePipeline->encodeSourceToPath(
                    $workTemp,
                    $outputTemp,
                    $extension,
                    $quality,
                );

                $validation = $encodedOk
                    ? $this->convertedImageValidator->validate($outputTemp, [
                        'expected_width' => $expectedWidth,
                        'expected_height' => $expectedHeight,
                        'source_path' => $sourceTemp,
                        'source_bytes' => $originalBytes,
                    ])
                    : [
                        'ok' => false,
                        'reason' => 'encode_failed',
                        'width' => 0,
                        'height' => 0,
                        'bytes' => is_file($outputTemp) ? (int) filesize($outputTemp) : 0,
                    ];

                if ($validation['ok']) {
                    $binary = file_get_contents($outputTemp);
                    if (! is_string($binary) || $binary === '') {
                        throw new \RuntimeException('Ảnh rỗng sau encode.');
                    }
                } else {
                    if ($extension === 'webp') {
                        logger()->warning(self::LOG_WEBP_VALIDATION_FAILED, [
                            'reason' => $validation['reason'],
                            'source_mime' => $this->mimeFromExtension($extension),
                            'source_bytes' => strlen($originalBytes),
                            'source_width' => $sourceValidation['width'],
                            'source_height' => $sourceValidation['height'],
                            'destination_bytes' => $validation['bytes'],
                            'destination_width' => $validation['width'],
                            'destination_height' => $validation['height'],
                            'driver' => $this->imagePipeline->lastDriver(),
                        ]);
                    }

                    // Fallback: giữ original bytes + extension thật (PNG→png, JPEG→jpg). Không ghi đè .webp bằng PNG.
                    $fallbackExtension = $this->convertedImageValidator->detectImageExtensionFromBytes($originalBytes)
                        ?? ($extension === 'webp' ? 'png' : $extension);
                    $fallbackExtension = $this->normalizeExtension($fallbackExtension);

                    logger()->warning(self::LOG_WEBP_FALLBACK_ORIGINAL, [
                        'reason' => $validation['reason'],
                        'requested_extension' => $extension,
                        'fallback_extension' => $fallbackExtension,
                        'source_bytes' => strlen($originalBytes),
                        'driver' => $this->imagePipeline->lastDriver(),
                    ]);

                    $extension = $fallbackExtension;
                    $binary = $originalBytes;
                }
            } finally {
                if (is_file($workTemp)) {
                    @unlink($workTemp);
                }
                if (is_file($outputTemp)) {
                    @unlink($outputTemp);
                }
            }
        } finally {
            if (is_file($sourceTemp)) {
                @unlink($sourceTemp);
            }
        }

        $allocated = $this->mediaPaths->allocate($slug, $extension);
        $altText = $this->buildAltText($config, $article, $allocated['slug']);

        return [
            'slug' => $allocated['slug'],
            'filename' => $allocated['filename'],
            'relative_path' => $allocated['relative_path'],
            'alt_text' => $altText,
            'binary' => $binary,
        ];
    }

    private function mimeFromExtension(string $extension): string
    {
        return match ($this->normalizeExtension($extension)) {
            'png' => 'image/png',
            'gif' => 'image/gif',
            'webp' => 'image/webp',
            default => 'image/jpeg',
        };
    }
}
