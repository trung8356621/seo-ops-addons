<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Content\Support;

enum TypographyValidationLevel: string
{
    case Fast = 'fast';
    case Balanced = 'balanced';
    case Strict = 'strict';

    public static function fromMixed(mixed $value): self
    {
        return match (is_string($value) ? strtolower(trim($value)) : '') {
            self::Fast->value => self::Fast,
            self::Strict->value => self::Strict,
            default => self::Balanced,
        };
    }

    /**
     * @return array<string, string>
     */
    public static function selectOptions(): array
    {
        return [
            self::Fast->value => __('seo-content-ai::filament.settings_workflows.typography_validation_fast'),
            self::Balanced->value => __('seo-content-ai::filament.settings_workflows.typography_validation_balanced'),
            self::Strict->value => __('seo-content-ai::filament.settings_workflows.typography_validation_strict'),
        ];
    }

    public function toRenderingPreference(): RenderingPreference
    {
        return match ($this) {
            self::Fast => RenderingPreference::CostFirst,
            self::Strict => RenderingPreference::QualityFirst,
            self::Balanced => RenderingPreference::Balanced,
        };
    }

    public function defaultPassThreshold(): float
    {
        return match ($this) {
            self::Fast => 0.85,
            self::Strict => 0.95,
            self::Balanced => 0.90,
        };
    }
}
