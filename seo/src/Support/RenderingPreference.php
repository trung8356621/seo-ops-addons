<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Support;

/**
 * Preference chi phí/chất lượng cho khách (Settings AI / Model).
 * Chỉ điều chỉnh thứ tự tier — không chứa logic typography.
 */
enum RenderingPreference: string
{
    case CostFirst = 'cost_first';
    case Balanced = 'balanced';
    case QualityFirst = 'quality_first';

    public static function fromMixed(mixed $value): self
    {
        $normalized = strtolower(trim((string) $value));

        return match ($normalized) {
            self::CostFirst->value, 'economy', 'tiet_kiem' => self::CostFirst,
            self::QualityFirst->value, 'quality', 'uu_tien_chat_luong' => self::QualityFirst,
            default => self::Balanced,
        };
    }

    /**
     * @return array<string, string>
     */
    public static function selectOptions(): array
    {
        return [
            self::CostFirst->value => __('seo-content-ai::filament.settings_workflows.rendering_preference_cost_first'),
            self::Balanced->value => __('seo-content-ai::filament.settings_workflows.rendering_preference_balanced'),
            self::QualityFirst->value => __('seo-content-ai::filament.settings_workflows.rendering_preference_quality_first'),
        ];
    }
}
