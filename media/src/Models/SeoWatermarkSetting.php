<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Media\Models;

use Omnichannel\Addons\SearchFoundation\Models\Concerns\BelongsToOnDefaultConnection;
use Omnichannel\Addons\Media\Services\SeoWatermarkDesignApplicator;
use Omnichannel\Addons\Media\Services\SeoWatermarkOverlayStorage;
use App\Models\Site;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

class SeoWatermarkSetting extends Model
{
    use BelongsToOnDefaultConnection;

    public const TYPE_NONE = 'none';

    public const TYPE_TEXT = 'text';

    public const TYPE_IMAGE = 'image';

    /** @var list<string> */
    public const POSITIONS = [
        'top-left',
        'top-center',
        'top-right',
        'center-left',
        'center',
        'center-right',
        'bottom-left',
        'bottom-center',
        'bottom-right',
    ];

    protected $connection = 'omi_seo_ai';

    protected $table = 'seo_watermark_settings';

    protected $guarded = [];

    protected $casts = [
        'auto_watermark' => 'boolean',
        'text_size' => 'integer',
        'logo_width_pct' => 'integer',
        'opacity' => 'float',
        'design_config' => 'array',
    ];

    public function site(): BelongsTo
    {
        return $this->belongsToOnDefaultConnection(Site::class, 'site_id');
    }

    public function logoUrl(): ?string
    {
        if (! filled($this->logo_path)) {
            return null;
        }

        return Storage::disk('public')->url((string) $this->logo_path);
    }

    /**
     * Có thể áp dụng đóng dấu: text/logo hoặc overlay đã lưu từ «Thiết kế đóng dấu».
     */
    public function isConfiguredForApply(): bool
    {
        if ($this->type !== self::TYPE_NONE) {
            return true;
        }

        return app(SeoWatermarkDesignApplicator::class)->hasOverlay($this);
    }

    /**
     * @return array<string, mixed>
     */
    public function toEditorPayload(): array
    {
        $design = is_array($this->design_config) ? $this->design_config : [];

        $design['overlay_previews'] = app(SeoWatermarkOverlayStorage::class)->variantsForEditor($design);

        return array_merge([
            'site_id' => (int) $this->site_id,
            'type' => (string) $this->type,
            'auto_watermark' => (bool) $this->auto_watermark,
            'text_content' => (string) ($this->text_content ?? ''),
            'text_color' => (string) ($this->text_color ?? '#ffffff'),
            'text_size' => (int) ($this->text_size ?? 20),
            'logo_path' => $this->logo_path,
            'logo_url' => $this->logoUrl(),
            'logo_width_pct' => (int) ($this->logo_width_pct ?? 20),
            'position' => (string) ($this->position ?? 'bottom-right'),
            'opacity' => (float) ($this->opacity ?? 0.7),
        ], $design);
    }

    /**
     * @return array<string, mixed>
     */
    public function defaultDesignConfig(): array
    {
        return [
            'activePattern' => 'cta_button',
            'watermarkType' => $this->type === self::TYPE_IMAGE ? 'image' : 'text',
            'opacity' => (float) ($this->opacity ?? 0.7),
            'rotation' => -15,
            'isPattern' => false,
            'patternSpacing' => 120,
            'gridSpacing' => 120,
            'positionType' => 'anchor',
            'positionAnchor' => 'bottom-right',
            'anchorOffset' => ['x' => 20, 'y' => 20],
            'presetPos' => (string) ($this->position ?? 'bottom-right'),
            'customCoords' => ['x' => 50, 'y' => 50],
            'margin' => 20,
            'text' => (string) ($this->text_content ?? '© OMI SEO'),
            'text1' => (string) ($this->text_content ?? '© OMI SEO'),
            'text2' => 'CHẤT LƯỢNG TIÊU CHUẨN',
            'textColor' => (string) ($this->text_color ?? '#ff2d55'),
            'textSize' => (int) ($this->text_size ?? 28),
            'fontFamily' => 'Arial',
            'selectedFont' => 'Arial',
            'borderWidth' => 3,
            'borderColor' => '#ff2d55',
            'backgroundColor' => '#ffffff',
            'bgOpacity' => 0,
            'hasStroke' => true,
            'strokeColor' => '#000000',
            'logoScale' => (int) ($this->logo_width_pct ?? 20),
        ];
    }
}
