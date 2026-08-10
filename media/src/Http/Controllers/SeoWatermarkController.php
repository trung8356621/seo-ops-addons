<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Media\Http\Controllers;

use Omnichannel\Addons\Media\Models\SeoMedia;
use Omnichannel\Addons\Media\Models\SeoWatermarkSetting;
use Omnichannel\Addons\Media\Services\SeoWatermarkOverlayRatioCatalog;
use Omnichannel\Addons\Media\Services\SeoWatermarkService;
use Omnichannel\Addons\Seo\Support\SeoAccessControl;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class SeoWatermarkController extends Controller
{
    public function __construct(
        private readonly SeoWatermarkService $watermark,
    ) {}

    public function showSettings(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'site_id' => 'required|integer',
        ]);

        $siteId = (int) $validated['site_id'];
        abort_unless($this->canAccessSite($siteId), 403);

        $setting = SeoWatermarkSetting::query()->where('site_id', $siteId)->first();

        return response()->json([
            'success' => true,
            'settings' => $this->watermark->settingsPayload($setting),
        ]);
    }

    public function saveSettings(Request $request): JsonResponse
    {
        $rules = [
            'site_id' => 'required|integer',
            'type' => ['nullable', Rule::in(['text', 'image', 'none'])],
            'auto_watermark' => 'nullable|boolean',
            'text_content' => 'nullable|string|max:500',
            'text_color' => 'nullable|string|max:7',
            'text_size' => 'nullable|integer|min:8|max:200',
            'logo_width_pct' => 'nullable|integer|min:5|max:80',
            'position' => ['nullable', 'string', Rule::in(SeoWatermarkSetting::POSITIONS)],
            'opacity' => 'nullable|numeric|min:0.1|max:1',
            'design_config' => 'nullable',
            'logo' => 'nullable|image|max:2048',
            'overlay' => 'nullable|image|mimes:png|max:8192',
        ];

        foreach (SeoWatermarkOverlayRatioCatalog::keys() as $ratioKey) {
            $rules['overlay_'.$ratioKey] = 'nullable|image|mimes:png|max:8192';
        }

        $validated = $request->validate($rules);

        if (! isset($validated['type']) && empty($validated['design_config'])) {
            return response()->json([
                'success' => false,
                'message' => 'Thiếu loại đóng dấu hoặc design_config.',
            ], 422);
        }

        $siteId = (int) $validated['site_id'];
        abort_unless($this->canAccessSite($siteId), 403);

        $overlayVariants = [];
        foreach (SeoWatermarkOverlayRatioCatalog::keys() as $ratioKey) {
            $file = $request->file('overlay_'.$ratioKey);
            if ($file !== null) {
                $overlayVariants[$ratioKey] = $file;
            }
        }

        $setting = $this->watermark->saveSettings(
            $siteId,
            $validated,
            $request->file('logo'),
            $request->file('overlay'),
            $overlayVariants,
        );

        return response()->json([
            'success' => true,
            'message' => 'Đã lưu cấu hình đóng dấu thành công.',
            'settings' => $setting->toEditorPayload(),
        ]);
    }

    public function applyBatch(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'site_ids' => 'required|array|min:1',
            'site_ids.*' => 'integer',
            'apply_watermark' => 'nullable|boolean',
        ]);

        $applyWatermark = (bool) ($validated['apply_watermark'] ?? true);
        $total = 0;
        $siteCount = 0;

        foreach ($validated['site_ids'] as $siteId) {
            $siteId = (int) $siteId;
            if (! $this->canAccessSite($siteId)) {
                continue;
            }

            $result = $this->watermark->applyBatchAllForSite($siteId, $applyWatermark);
            $applied = (int) ($result['local_watermark'] ?? 0)
                + (int) ($result['local_optimize'] ?? 0)
                + (int) ($result['wp_watermark'] ?? 0)
                + (int) ($result['wp_optimize'] ?? 0);
            if ($applied > 0) {
                $siteCount++;
                $total += $applied;
            }
        }

        return response()->json([
            'success' => true,
            'message' => "Đã xử lý {$total} ảnh (tối ưu".($applyWatermark ? ' + watermark' : '').") trên {$siteCount} website.",
            'count' => $total,
            'sites' => $siteCount,
        ]);
    }

    public function saveMediaWatermark(Request $request, SeoMedia $media): JsonResponse
    {
        abort_unless($this->canAccessMedia($media), 403);

        $validated = $request->validate([
            'image' => 'required|image|mimes:jpeg,png,jpg,gif,webp|max:10240',
            'mode' => ['nullable', Rule::in(['overwrite', 'new'])],
        ]);

        $mode = (string) ($validated['mode'] ?? 'overwrite');

        try {
            $result = $this->watermark->saveWatermarkedUpload(
                $media,
                $request->file('image'),
                $mode,
            );
        } catch (\RuntimeException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        }

        return response()->json([
            'success' => true,
            'message' => $mode === 'new' ? 'Đã lưu ảnh mới có đóng dấu.' : 'Đã lưu đè ảnh có đóng dấu.',
            'media' => [
                'id' => $result->id,
                'url' => $result->publicUrl(),
                'slug' => $result->slug,
            ],
        ]);
    }

    public function saveNewFromCanvas(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'image' => 'required|image|mimes:jpeg,png,jpg,gif,webp|max:10240',
            'site_id' => 'required|integer',
        ]);

        $siteId = (int) $validated['site_id'];
        abort_unless($this->canAccessSite($siteId), 403);

        $media = app(\Omnichannel\Addons\Media\Services\SeoMediaStorageService::class)->storeUpload(
            $request->file('image'),
            $siteId,
            null,
            'watermark',
        );

        return response()->json([
            'success' => true,
            'message' => 'Đã lưu ảnh đóng dấu vào thư viện nội bộ.',
            'media' => [
                'id' => $media->id,
                'url' => $media->publicUrl(),
                'slug' => $media->slug,
            ],
        ]);
    }

    private function canAccessMedia(SeoMedia $media): bool
    {
        if ($media->site_id !== null) {
            return $this->canAccessSite((int) $media->site_id);
        }

        return auth()->check();
    }

    private function canAccessSite(int $siteId): bool
    {
        return SeoAccessControl::canAccessSite($siteId);
    }
}
