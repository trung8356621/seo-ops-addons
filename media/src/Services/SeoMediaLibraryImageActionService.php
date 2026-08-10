<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Media\Services;

use Omnichannel\Addons\Media\Models\SeoMedia;
use Omnichannel\Addons\Media\Models\SeoWatermarkSetting;
use App\Models\Site;

final class SeoMediaLibraryImageActionService
{
    public function __construct(
        private readonly SeoWatermarkService $watermark,
        private readonly SeoImageOptimizationService $optimization,
        private readonly WordPressMediaWatermarkService $wpMedia,
        private readonly SeoMediaStorageService $mediaStorage,
        private readonly SeoMediaProcessingHistoryService $processingHistory,
        private readonly SeoMediaResizeService $resize,
    ) {}

    /**
     * @param  array<string, mixed>  $imageRow
     * @return array{success: bool, url: string, message: string}
     */
    public function applyWatermark(Site $site, array $imageRow): array
    {
        $setting = SeoWatermarkSetting::query()->where('site_id', $site->id)->first();
        if ($setting === null || ! $setting->isConfiguredForApply()) {
            $domain = trim((string) ($site->domain ?? ''));

            return [
                'success' => false,
                'url' => (string) ($imageRow['url'] ?? ''),
                'message' => $domain !== ''
                    ? "Chưa cấu hình đóng dấu cho domain «{$domain}». Vào SEO → Thư viện media → Thiết kế đóng dấu, chọn đúng domain, lưu thiết kế (overlay hoặc text/logo)."
                    : 'Chưa cấu hình đóng dấu cho domain này. Vào SEO → Thư viện media → Thiết kế đóng dấu và lưu cấu hình.',
            ];
        }

        return $this->process($site, $imageRow, applyWatermark: true, applyOptimize: false, watermarkSetting: $setting);
    }

    /**
     * @param  array<string, mixed>  $imageRow
     * @return array{can_restore: bool, can_optimize: bool, status: string}
     */
    public function previewState(Site $site, array $imageRow): array
    {
        return $this->processingHistory->previewState($site, $imageRow);
    }

    /**
     * @param  array<string, mixed>  $imageRow
     * @return array{success: bool, url: string, message: string, can_restore?: bool, can_optimize?: bool}
     */
    public function restore(Site $site, array $imageRow): array
    {
        $kind = $this->resolveImageKind($imageRow);
        $url = (string) ($imageRow['url'] ?? '');

        if ($kind === 'wordpress') {
            $attachmentId = (int) ($imageRow['wp_attachment_id'] ?? $imageRow['id'] ?? 0);
            if ($attachmentId <= 0) {
                return [
                    'success' => false,
                    'url' => $url,
                    'message' => 'Thiếu ID ảnh WordPress.',
                ];
            }

            if (! $this->canRestore($site, $imageRow)) {
                return [
                    'success' => false,
                    'url' => $url,
                    'message' => 'Không có bản backup hoặc ảnh chưa được xử lý.',
                ];
            }

            $outcome = $this->wpMedia->restoreSingleAttachment($site, $attachmentId, $url);
            $state = $this->previewState($site, $imageRow);

            return [
                'success' => (bool) ($outcome['success'] ?? false),
                'url' => $this->cacheBustUrl((string) ($outcome['url'] ?? $url)),
                'message' => (string) ($outcome['message'] ?? ''),
                'can_restore' => $state['can_restore'],
                'can_optimize' => $state['can_optimize'],
            ];
        }

        if ($kind === 'local') {
            $media = SeoMedia::query()
                ->where('site_id', $site->id)
                ->whereKey((int) ($imageRow['seo_media_id'] ?? $imageRow['id'] ?? 0))
                ->first();

            if ($media === null) {
                return [
                    'success' => false,
                    'url' => $url,
                    'message' => 'Không tìm thấy ảnh nội bộ.',
                ];
            }

            if (! $this->canRestore($site, $imageRow)) {
                return [
                    'success' => false,
                    'url' => $url,
                    'message' => 'Không có bản backup hoặc ảnh chưa được xử lý.',
                ];
            }

            if (! $this->watermark->restoreLocalMedia($media)) {
                return [
                    'success' => false,
                    'url' => $url,
                    'message' => 'Không khôi phục được ảnh nội bộ.',
                ];
            }

            $state = $this->previewState($site, $imageRow);

            return [
                'success' => true,
                'url' => $this->cacheBustUrl($media->fresh()->publicUrl()),
                'message' => 'Đã khôi phục ảnh gốc.',
                'can_restore' => $state['can_restore'],
                'can_optimize' => $state['can_optimize'],
            ];
        }

        return [
            'success' => false,
            'url' => $url,
            'message' => 'Không hỗ trợ khôi phục loại ảnh này.',
        ];
    }

    /**
     * @param  array<string, mixed>  $imageRow
     */
    public function canRestore(Site $site, array $imageRow): bool
    {
        return $this->processingHistory->previewState($site, $imageRow)['can_restore'];
    }

    public function canOptimize(Site $site, array $imageRow): bool
    {
        return $this->processingHistory->previewState($site, $imageRow)['can_optimize'];
    }

    public function optimize(Site $site, array $imageRow): array
    {
        $url = (string) ($imageRow['url'] ?? '');
        if ($this->isWebpUrl($url)) {
            return [
                'success' => false,
                'url' => $url,
                'message' => 'Ảnh đã là .webp — bỏ qua tối ưu.',
            ];
        }

        return $this->process($site, $imageRow, applyWatermark: false, applyOptimize: true, watermarkSetting: null);
    }

    /**
     * @param  array<string, mixed>  $imageRow
     * @return array{success: bool, url: string, message: string}
     */
    public function resize(Site $site, array $imageRow, ?int $width, ?int $height): array
    {
        $kind = $this->resolveImageKind($imageRow);

        if ($kind === 'generated') {
            return [
                'success' => false,
                'url' => (string) ($imageRow['url'] ?? ''),
                'message' => 'Ảnh Gen AI chỉ xem trước — hãy gán vào thư viện nội bộ để resize.',
            ];
        }

        if ($kind === 'local') {
            $media = SeoMedia::query()
                ->where('site_id', $site->id)
                ->whereKey((int) ($imageRow['seo_media_id'] ?? $imageRow['id'] ?? 0))
                ->first();

            if ($media === null) {
                return [
                    'success' => false,
                    'url' => (string) ($imageRow['url'] ?? ''),
                    'message' => 'Không tìm thấy ảnh nội bộ.',
                ];
            }

            return $this->resize->resizeLocal($media, $width, $height);
        }

        return $this->resize->resizeWordPress($site, $imageRow, $width, $height);
    }

    /**
     * @param  array<string, mixed>  $imageRow
     * @return array{success: bool, url: string, message: string}
     */
    private function process(
        Site $site,
        array $imageRow,
        bool $applyWatermark,
        bool $applyOptimize,
        ?SeoWatermarkSetting $watermarkSetting,
    ): array {
        $kind = $this->resolveImageKind($imageRow);

        if ($kind === 'local') {
            return $this->processLocal($site, $imageRow, $applyWatermark, $applyOptimize, $watermarkSetting);
        }

        if ($kind === 'generated') {
            return [
                'success' => false,
                'url' => (string) ($imageRow['url'] ?? ''),
                'message' => 'Ảnh Gen AI chỉ xem trước — hãy tải về thư viện nội bộ để xử lý.',
            ];
        }

        return $this->processWordPress($site, $imageRow, $applyWatermark, $applyOptimize, $watermarkSetting);
    }

    /**
     * @param  array<string, mixed>  $imageRow
     */
    private function resolveImageKind(array $imageRow): string
    {
        $kind = strtolower(trim((string) ($imageRow['kind'] ?? '')));

        if (in_array($kind, ['local', 'generated', 'wordpress'], true)) {
            return $kind;
        }

        $seoMediaId = (int) ($imageRow['seo_media_id'] ?? 0);
        $wpAttachmentId = (int) ($imageRow['wp_attachment_id'] ?? 0);

        if ($seoMediaId > 0) {
            return 'local';
        }

        if ($wpAttachmentId > 0) {
            return 'wordpress';
        }

        return 'local';
    }

    /**
     * @param  array<string, mixed>  $imageRow
     * @return array{success: bool, url: string, message: string}
     */
    private function processLocal(
        Site $site,
        array $imageRow,
        bool $applyWatermark,
        bool $applyOptimize,
        ?SeoWatermarkSetting $watermarkSetting,
    ): array {
        $media = SeoMedia::query()
            ->where('site_id', $site->id)
            ->whereKey((int) ($imageRow['seo_media_id'] ?? $imageRow['id'] ?? 0))
            ->first();

        if ($media === null) {
            return [
                'success' => false,
                'url' => (string) ($imageRow['url'] ?? ''),
                'message' => 'Không tìm thấy ảnh nội bộ.',
            ];
        }

        $optimizationConfig = $this->optimization->resolveForSite((int) $site->id);
        $outcome = $this->watermark->processLocalMediaFile(
            $media,
            $watermarkSetting,
            $applyWatermark,
            $applyOptimize,
            $optimizationConfig,
        );

        if (! $outcome['watermark'] && ! $outcome['optimize']) {
            return [
                'success' => false,
                'url' => $media->fresh()->publicUrl(),
                'message' => $applyOptimize
                    ? 'Không tối ưu được (có thể đã là WebP).'
                    : 'Không áp dụng được đóng dấu.',
            ];
        }

        $fresh = $media->fresh();
        $state = $this->previewState($site, array_merge($imageRow, [
            'url' => $fresh->publicUrl(),
            'seo_media_id' => $fresh->id,
        ]));

        return [
            'success' => true,
            'url' => $this->cacheBustUrl($fresh->publicUrl()),
            'message' => $this->buildSuccessMessage($outcome),
            'can_restore' => $state['can_restore'],
            'can_optimize' => $state['can_optimize'],
        ];
    }

    /**
     * @param  array<string, mixed>  $imageRow
     * @return array{success: bool, url: string, message: string}
     */
    private function processWordPress(
        Site $site,
        array $imageRow,
        bool $applyWatermark,
        bool $applyOptimize,
        ?SeoWatermarkSetting $watermarkSetting,
    ): array {
        $attachmentId = (int) ($imageRow['wp_attachment_id'] ?? $imageRow['id'] ?? 0);
        $url = trim((string) ($imageRow['url'] ?? ''));

        if ($attachmentId <= 0 || $url === '') {
            return [
                'success' => false,
                'url' => $url,
                'message' => 'Thiếu ID ảnh WordPress.',
            ];
        }

        $optimizationConfig = $this->optimization->resolveForSite((int) $site->id);

        $outcome = $this->wpMedia->processSingleAttachment(
            $site,
            $watermarkSetting,
            $applyWatermark,
            $applyOptimize,
            $optimizationConfig,
            $attachmentId,
            $url,
        );

        if ($outcome['error']) {
            return [
                'success' => false,
                'url' => $url,
                'message' => (string) ($outcome['message'] ?? 'Xử lý WordPress thất bại.'),
            ];
        }

        if (! $outcome['watermark'] && ! $outcome['optimize']) {
            return [
                'success' => false,
                'url' => $url,
                'message' => (string) ($outcome['message'] ?? 'Không có thay đổi.'),
            ];
        }

        $newUrl = filled($outcome['url'] ?? null)
            ? (string) $outcome['url']
            : $url;

        $state = $this->previewState($site, array_merge($imageRow, ['url' => $newUrl]));

        return [
            'success' => true,
            'url' => $this->cacheBustUrl($newUrl),
            'message' => $this->buildSuccessMessage($outcome),
            'can_restore' => $state['can_restore'],
            'can_optimize' => $state['can_optimize'],
        ];
    }

    /**
     * @param  array{watermark: bool, optimize: bool}  $outcome
     */
    private function buildSuccessMessage(array $outcome): string
    {
        $parts = [];
        if ($outcome['watermark'] ?? false) {
            $parts[] = 'đã đóng dấu';
        }
        if ($outcome['optimize'] ?? false) {
            $parts[] = 'đã tối ưu';
        }

        return 'Ảnh ' . implode(' và ', $parts) . '.';
    }

    private function cacheBustUrl(string $url): string
    {
        $separator = str_contains($url, '?') ? '&' : '?';

        return $url . $separator . 'v=' . time();
    }

    private function isWebpUrl(string $url): bool
    {
        $path = parse_url($url, PHP_URL_PATH) ?: $url;

        return str_ends_with(strtolower($path), '.webp');
    }
}
