<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Support;

enum AiUsageMode: string
{
    case Economy = 'economy';
    case QualityFirst = 'quality_first';

    public static function tryFromMixed(mixed $value): ?self
    {
        $normalized = strtolower(trim((string) $value));

        return match ($normalized) {
            self::Economy->value, 'cost_first', 'cost-first' => self::Economy,
            self::QualityFirst->value, 'quality', 'quality-first' => self::QualityFirst,
            default => self::tryFrom($normalized),
        };
    }

    public static function defaultForProfile(AiExecutionProfile $profile): self
    {
        return $profile === AiExecutionProfile::TextReasoning
            ? self::QualityFirst
            : self::Economy;
    }

    /**
     * @return array<string, string>
     */
    public static function selectOptions(): array
    {
        return [
            self::Economy->value => __('seo-content-ai::filament.ai_model_ux.mode_economy'),
            self::QualityFirst->value => __('seo-content-ai::filament.ai_model_ux.mode_quality_first'),
        ];
    }
}
