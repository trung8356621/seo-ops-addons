<?php

declare(strict_types=1);

namespace Omnichannel\Addons\WordPress\Services;

use Omnichannel\Addons\Media\Models\SeoImageOptimizationSetting;
use Omnichannel\Addons\Media\Models\SeoMediaProcessingHistory;
use Omnichannel\Addons\Media\Models\SeoWatermarkSetting;
use Omnichannel\Addons\WordPress\Services\WordPressWriteReadinessGuard;
use App\Models\Site;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

final class WordPressMediaWatermarkService
{
    public function __construct(
        private readonly WordPressMediaLibraryService $mediaLibrary,
        private readonly WordPressArticleContentService $wpContent,
        private readonly SeoWatermarkService $watermark,
        private readonly SeoImageOptimizationService $optimization,
        private readonly SeoMediaProcessingHistoryService $processingHistory,
    ) {}

    /**
     * @return array{wp_watermark: int, wp_optimize: int, wp_skipped: int, wp_errors: int, message: string}
     */
    public function applyBatchToWordPress(
        Site $site,
        ?SeoWatermarkSetting $watermarkSetting,
        bool $applyWatermark,
        SeoImageOptimizationSetting $optimizationConfig,
    ): array {
        $site->loadMissing('metas');
        $writeToken = trim((string) ($site->getMeta('seo_migration_token') ?? ''));
        if ($writeToken === '') {
            return [
                'wp_watermark' => 0,
                'wp_optimize' => 0,
                'wp_skipped' => 0,
                'wp_errors' => 0,
                'message' => 'Thiếu Migration/Write token trên domain.',
            ];
        }

        $replaceUrlTemplate = $this->buildReplaceUrl($site);
        if ($replaceUrlTemplate === '') {
            return [
                'wp_watermark' => 0,
                'wp_optimize' => 0,
                'wp_skipped' => 0,
                'wp_errors' => 0,
                'message' => 'Không xác định được URL WordPress.',
            ];
        }

        $stats = [
            'wp_watermark' => 0,
            'wp_optimize' => 0,
            'wp_skipped' => 0,
            'wp_errors' => 0,
            'message' => '',
        ];

        $page = 1;
        $totalPages = 1;

        do {
            $result = $this->mediaLibrary->fetch($site, null, $page, 50, null);
            if (filled($result['error'] ?? null)) {
                $stats['message'] = (string) $result['error'];

                return $stats;
            }

            foreach ($result['images'] ?? [] as $row) {
                if (! is_array($row)) {
                    continue;
                }

                $attachmentId = (int) ($row['wp_attachment_id'] ?? $row['id'] ?? 0);
                $url = trim((string) ($row['url'] ?? ''));
                if ($attachmentId <= 0 || $url === '') {
                    $stats['wp_skipped']++;

                    continue;
                }

                $outcome = $this->processWordPressAttachment(
                    $site,
                    $watermarkSetting,
                    $applyWatermark,
                    true,
                    $optimizationConfig,
                    $attachmentId,
                    $url,
                    $replaceUrlTemplate,
                    $writeToken,
                );

                if ($outcome['watermark']) {
                    $stats['wp_watermark']++;
                }
                if ($outcome['optimize']) {
                    $stats['wp_optimize']++;
                }
                if ($outcome['error']) {
                    $stats['wp_errors']++;
                }
                if (! $outcome['watermark'] && ! $outcome['optimize'] && ! $outcome['error']) {
                    $stats['wp_skipped']++;
                }
            }

            $totalPages = max(1, (int) ($result['total_pages'] ?? 1));
            $page++;
        } while ($page <= $totalPages);

        return $stats;
    }

    /**
     * Xử lý một attachment WordPress (đóng dấu và/hoặc tối ưu).
     *
     * @return array{watermark: bool, optimize: bool, error: bool, url?: string, message?: string}
     */
    public function processSingleAttachment(
        Site $site,
        ?SeoWatermarkSetting $watermarkSetting,
        bool $applyWatermark,
        bool $applyOptimize,
        SeoImageOptimizationSetting $optimizationConfig,
        int $attachmentId,
        string $url,
    ): array {
        $site->loadMissing('metas');
        $writeToken = trim((string) ($site->getMeta('seo_migration_token') ?? ''));
        if ($writeToken === '') {
            return [
                'watermark' => false,
                'optimize' => false,
                'error' => true,
                'message' => 'Thiếu Migration/Write token trên domain.',
            ];
        }

        $replaceUrlTemplate = $this->buildReplaceUrl($site);
        if ($replaceUrlTemplate === '') {
            return [
                'watermark' => false,
                'optimize' => false,
                'error' => true,
                'message' => 'Không xác định được URL WordPress.',
            ];
        }

        $result = $this->processWordPressAttachment(
            $site,
            $watermarkSetting,
            $applyWatermark,
            $applyOptimize,
            $optimizationConfig,
            $attachmentId,
            $url,
            $replaceUrlTemplate,
            $writeToken,
        );

        if ($result['error'] ?? false) {
            $result['message'] = $result['message'] ?? 'Không cập nhật được ảnh trên WordPress.';

            return $result;
        }

        if (! filled($result['url'] ?? null)) {
            $result['url'] = $url;
        }

        return $result;
    }

    /**
     * Khôi phục ảnh WordPress từ backup Laravel.
     *
     * @return array{success: bool, url: string, message: string}
     */
    public function restoreSingleAttachment(Site $site, int $attachmentId, string $url): array
    {
        $history = $this->processingHistory->find(
            (int) $site->id,
            SeoMediaProcessingHistory::SOURCE_WORDPRESS,
            $attachmentId,
        );

        if (! $this->processingHistory->canRestore($history)) {
            return [
                'success' => false,
                'url' => $url,
                'message' => 'Không có bản backup hoặc ảnh chưa được xử lý.',
            ];
        }

        $backupAbsolute = $this->processingHistory->backupAbsolutePath($history);
        if ($backupAbsolute === null) {
            return [
                'success' => false,
                'url' => $url,
                'message' => 'File backup không còn trên server.',
            ];
        }

        $site->loadMissing('metas');
        $writeToken = trim((string) ($site->getMeta('seo_migration_token') ?? ''));
        if ($writeToken === '') {
            return [
                'success' => false,
                'url' => $url,
                'message' => 'Thiếu Migration/Write token trên domain.',
            ];
        }

        $replaceUrlTemplate = $this->buildReplaceUrl($site);
        if ($replaceUrlTemplate === '') {
            return [
                'success' => false,
                'url' => $url,
                'message' => 'Không xác định được URL WordPress.',
            ];
        }

        $mime = filled($history->mime_type) ? (string) $history->mime_type : $this->guessMimeFromUrl($url);
        $replaceUrl = str_replace('{id}', (string) $attachmentId, $replaceUrlTemplate);

        $upload = $this->postReplacementBinary($site, $writeToken, $replaceUrl, $backupAbsolute, $mime);

        if (! $upload->successful() || ! ($upload->json('success') ?? false)) {
            return [
                'success' => false,
                'url' => $url,
                'message' => $this->parseWordPressUploadError($upload),
            ];
        }

        $this->processingHistory->markRestored(
            (int) $site->id,
            SeoMediaProcessingHistory::SOURCE_WORDPRESS,
            $attachmentId,
        );

        $newUrl = (string) ($upload->json('url') ?? $url);

        return [
            'success' => true,
            'url' => $newUrl,
            'message' => 'Đã khôi phục ảnh gốc trên WordPress.',
        ];
    }

    /**
     * Ghi đè attachment WordPress bằng file local (staging đã chỉnh sửa).
     *
     * @return array{success: bool, url: string, message: string}
     */
    public function replaceAttachmentFromLocalFile(
        Site $site,
        int $attachmentId,
        string $absolutePath,
        string $mime,
    ): array {
        if ($attachmentId <= 0 || ! is_file($absolutePath)) {
            return [
                'success' => false,
                'url' => '',
                'message' => 'File ảnh không hợp lệ để đồng bộ.',
            ];
        }

        $site->loadMissing('metas');
        $writeToken = trim((string) ($site->getMeta('seo_migration_token') ?? ''));
        if ($writeToken === '') {
            return [
                'success' => false,
                'url' => '',
                'message' => 'Thiếu Migration/Write token trên domain.',
            ];
        }

        $replaceUrlTemplate = $this->buildReplaceUrl($site);
        if ($replaceUrlTemplate === '') {
            return [
                'success' => false,
                'url' => '',
                'message' => 'Không xác định được URL WordPress.',
            ];
        }

        $mime = $mime !== '' ? $mime : $this->guessMimeFromUrl($absolutePath);
        $replaceUrl = str_replace('{id}', (string) $attachmentId, $replaceUrlTemplate);

        $upload = $this->postReplacementBinary($site, $writeToken, $replaceUrl, $absolutePath, $mime);

        if (! $upload->successful() || ! ($upload->json('success') ?? false)) {
            return [
                'success' => false,
                'url' => '',
                'message' => $this->parseWordPressUploadError($upload),
            ];
        }

        return [
            'success' => true,
            'url' => (string) ($upload->json('url') ?? ''),
            'message' => 'Đã đồng bộ ảnh lên WordPress.',
        ];
    }

    public function wpAttachmentHasBackup(int $siteId, int $attachmentId): bool
    {
        return $this->processingHistory->canRestore(
            $this->processingHistory->find($siteId, SeoMediaProcessingHistory::SOURCE_WORDPRESS, $attachmentId),
        );
    }

    /**
     * @return array{watermark: bool, optimize: bool, error: bool, url?: string}
     */
    private function processWordPressAttachment(
        Site $site,
        ?SeoWatermarkSetting $watermarkSetting,
        bool $applyWatermark,
        bool $applyOptimize,
        SeoImageOptimizationSetting $optimizationConfig,
        int $attachmentId,
        string $url,
        string $replaceUrlTemplate,
        string $writeToken,
    ): array {
        $tempPath = $this->createTempImagePath($url);
        if ($tempPath === '') {
            return [
                'watermark' => false,
                'optimize' => false,
                'error' => true,
                'message' => 'Không tạo được file tạm trên server.',
            ];
        }

        $result = ['watermark' => false, 'optimize' => false, 'error' => false];

        try {
            $response = Http::timeout(90)->get($url);
            if (! $response->successful()) {
                $result['error'] = true;
                $result['message'] = 'Không tải được ảnh từ WordPress (HTTP ' . $response->status() . ').';

                return $result;
            }

            $binary = $response->body();
            if ($binary === '') {
                $result['error'] = true;
                $result['message'] = 'Ảnh WordPress trả về rỗng.';

                return $result;
            }

            file_put_contents($tempPath, $binary);

            if (! $applyWatermark && ! $applyOptimize) {
                return $result;
            }

            if (! $applyWatermark && $applyOptimize && $this->optimization->isWebpPath($tempPath)) {
                $result['message'] = 'Ảnh đã là .webp.';

                return $result;
            }

            $this->processingHistory->ensureBackup(
                (int) $site->id,
                SeoMediaProcessingHistory::SOURCE_WORDPRESS,
                $attachmentId,
                $url,
                $tempPath,
            );

            $workingPath = $tempPath;
            $modified = false;

            if ($applyWatermark && $watermarkSetting instanceof SeoWatermarkSetting) {
                if ($this->watermark->applyToAbsolutePath($workingPath, $watermarkSetting)) {
                    $result['watermark'] = true;
                    $modified = true;
                }
            }

            if ($applyOptimize && ! $this->optimization->isWebpPath($workingPath)) {
                $optimized = $this->optimization->optimizeAbsolutePath($workingPath, $optimizationConfig);
                if ($optimized['applied']) {
                    $result['optimize'] = true;
                    $modified = true;
                    $workingPath = $optimized['absolute_path'];
                }
            }

            if (! $modified) {
                return $result;
            }

            $mime = $this->optimization->mimeFromPath($workingPath);
            $replaceUrl = str_replace('{id}', (string) $attachmentId, $replaceUrlTemplate);

            $upload = $this->postReplacementBinary($site, $writeToken, $replaceUrl, $workingPath, $mime);

            if ($upload->successful() && ($upload->json('success') ?? false)) {
                $newUrl = (string) ($upload->json('url') ?? $url);
                if ($newUrl !== '') {
                    $result['url'] = $newUrl;
                }

                if ($result['watermark']) {
                    $this->processingHistory->markWatermarked(
                        (int) $site->id,
                        SeoMediaProcessingHistory::SOURCE_WORDPRESS,
                        $attachmentId,
                    );
                }
                if ($result['optimize']) {
                    $this->processingHistory->markOptimized(
                        (int) $site->id,
                        SeoMediaProcessingHistory::SOURCE_WORDPRESS,
                        $attachmentId,
                    );
                }
            }

            if (! $upload->successful() || ! ($upload->json('success') ?? false)) {
                Log::warning('WP media batch replace failed', [
                    'site_id' => $site->id,
                    'attachment_id' => $attachmentId,
                    'status' => $upload->status(),
                    'body' => mb_substr($upload->body(), 0, 500),
                ]);
                $result['watermark'] = false;
                $result['optimize'] = false;
                $result['error'] = true;
                $result['message'] = $this->parseWordPressUploadError($upload);
            }
        } catch (Throwable $e) {
            Log::warning('WP media batch exception', [
                'site_id' => $site->id,
                'attachment_id' => $attachmentId,
                'error' => $e->getMessage(),
            ]);
            $result['error'] = true;
            $result['message'] = 'Lỗi xử lý: ' . $e->getMessage();
        } finally {
            if (isset($workingPath) && is_string($workingPath) && $workingPath !== $tempPath && is_file($workingPath)) {
                @unlink($workingPath);
            }
            if (is_file($tempPath)) {
                @unlink($tempPath);
            }
        }

        return $result;
    }

    private function postReplacementBinary(
        Site $site,
        string $writeToken,
        string $replaceUrl,
        string $path,
        string $mime,
    ): \Illuminate\Http\Client\Response {
        app(WordPressWriteReadinessGuard::class)->assertCanWriteToWordPress($site, 'media.watermark_replace');

        $mime = $mime !== '' ? $mime : 'image/jpeg';
        $binary = (string) file_get_contents($path);

        return Http::timeout(120)
            ->acceptJson()
            ->withToken($writeToken)
            ->withHeaders(['Content-Type' => $mime])
            ->withBody($binary, $mime)
            ->post($replaceUrl);
    }

    private function parseWordPressUploadError(\Illuminate\Http\Client\Response $response): string
    {
        $json = $response->json();
        if (is_array($json)) {
            if (filled($json['message'] ?? null)) {
                return (string) $json['message'];
            }
            if (filled($json['code'] ?? null)) {
                $code = (string) $json['code'];
                if ($code === 'rest_forbidden') {
                    return 'WordPress từ chối (HTTP ' . $response->status() . '): kiểm tra Migration/Write token khớp với plugin TVH SEO AI.';
                }

                return 'WordPress: ' . $code . ' (HTTP ' . $response->status() . ')';
            }
        }

        $body = trim(mb_substr($response->body(), 0, 300));

        return $body !== ''
            ? 'WordPress HTTP ' . $response->status() . ': ' . $body
            : 'WordPress HTTP ' . $response->status() . ' — không cập nhật được ảnh.';
    }

    private function createTempImagePath(string $sourceUrl): string
    {
        $ext = strtolower(pathinfo(parse_url($sourceUrl, PHP_URL_PATH) ?: '', PATHINFO_EXTENSION) ?: 'jpg');
        if ($ext === 'jpeg') {
            $ext = 'jpg';
        }
        if (! in_array($ext, ['jpg', 'png', 'gif', 'webp'], true)) {
            $ext = 'jpg';
        }

        $path = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'seo_wm_' . uniqid('', true) . '.' . $ext;
        if (@touch($path) !== true) {
            return '';
        }

        return $path;
    }

    private function guessMimeFromUrl(string $url): string
    {
        $ext = strtolower(pathinfo(parse_url($url, PHP_URL_PATH) ?: '', PATHINFO_EXTENSION));

        return match ($ext) {
            'png' => 'image/png',
            'gif' => 'image/gif',
            'webp' => 'image/webp',
            default => 'image/jpeg',
        };
    }

    private function buildReplaceUrl(Site $site): string
    {
        $base = rtrim($this->wpContent->getPermalinkBase($site), '/');
        if ($base === '') {
            return '';
        }

        return $base . '/wp-json/omi-seo-ai/v1/attachments/{id}/replace-binary';
    }
}
